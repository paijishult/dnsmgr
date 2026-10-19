<?php

namespace app\controller;

use app\BaseController;
use think\facade\Db;
use think\facade\View;
use think\facade\Cache;
use app\lib\DnsHelper;
use app\service\ExpireNoticeService;
use app\utils\DnsQueryUtils;
use Exception;

class Domain extends BaseController
{

    public function account()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        return view();
    }

    public function account_data()
    {
        if (!checkPermission(2)) return json(['total' => 0, 'rows' => []]);
        $kw = $this->request->post('kw', null, 'trim');
        $offset = input('post.offset/d');
        $limit = input('post.limit/d');
        $sort = input('post.sortName', null, 'trim');
        $orderDir = strtolower(input('post.sortOrder', 'desc')) === 'asc' ? 'asc' : 'desc';

        $select = Db::name('account');
        if (!empty($kw)) {
            // API不允许按账户名称搜索，防止通过搜索结果逐位猜出AccessKey
            $select->whereLike($this->request->isApi ? 'remark' : 'name|remark', '%' . $kw . '%');
        }
        if (api_domains() !== null) {
            $select->where('id', 'in', Db::name('domain')->where('name', 'in', api_domains())->column('aid') ?: [0]);
        }
        $total = $select->count();
        $allowedSort = ['id' => 'id', 'typename' => 'type', 'name' => 'name', 'remark' => 'remark', 'addtime' => 'addtime'];
        if ($this->request->isApi) unset($allowedSort['name']);
        if ($sort && isset($allowedSort[$sort])) {
            $select->order($allowedSort[$sort], $orderDir);
        } else {
            $select->order('id', 'desc');
        }
        $rows = $select->limit($offset, $limit)->select();

        $list = [];
        foreach ($rows as $row) {
            if (!empty($row['type']) && isset(DnsHelper::$dns_config[$row['type']])) {
                $row['typename'] = DnsHelper::$dns_config[$row['type']]['name'];
                $row['icon'] = DnsHelper::$dns_config[$row['type']]['icon'];
            } else {
                $row['typename'] = $row['type'] ?: '未知';
                $row['icon'] = '';
            }
            // 列表中不输出账户密钥配置；API只返回账户ID、类型和备注，不返回账户名称（通常是AccessKey或面板地址）
            unset($row['config']);
            if ($this->request->isApi) {
                unset($row['name']);
            }
            $list[] = $row;
        }

        return json(['total' => $total, 'rows' => $list]);
    }

    public function account_add()
    {
        if (!checkPermission(2)) return json(['total' => 0, 'rows' => []]);
        $action = input('param.action');

        $account = null;
        if ($action == 'edit') {
            $id = input('get.id/d');
            $account = Db::name('account')->where('id', $id)->find();
            if (empty($account)) return $this->alert('error', '域名账户不存在');
        }

        View::assign('info', $account);
        View::assign('typeList', DnsHelper::getList());
        View::assign('action', $action);
        return View::fetch();
    }

    public function account_op()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $action = input('param.action');
        if ($action == 'add') {
            $type = input('post.type');
            $name = input('post.name', null, 'trim');
            $config = input('post.config', null, 'trim');
            $remark = input('post.remark', null, 'trim');
            if (empty($name) || empty($config)) return json(['code' => -1, 'msg' => '必填参数不能为空']);
            if (Db::name('account')->where('type', $type)->where('name', $name)->find()) {
                return json(['code' => -1, 'msg' => '域名账户已存在']);
            }
            Db::startTrans();
            $id = Db::name('account')->insertGetId([
                'type' => $type,
                'name' => $name,
                'config' => $config,
                'remark' => $remark,
                'addtime' => date('Y-m-d H:i:s'),
            ]);
            $dns = DnsHelper::getModel($id);
            if ($dns) {
                if ($dns->check()) {
                    Db::commit();
                    return json(['code' => 0, 'msg' => '添加域名账户成功！']);
                } else {
                    Db::rollback();
                    return json(['code' => -1, 'msg' => '验证域名账户失败，' . $dns->getError()]);
                }
            } else {
                Db::rollback();
                return json(['code' => -1, 'msg' => 'DNS模块(' . $type . ')不存在']);
            }
        } elseif ($action == 'edit') {
            $id = input('post.id/d');
            $row = Db::name('account')->where('id', $id)->find();
            if (!$row) return json(['code' => -1, 'msg' => '域名账户不存在']);
            $type = input('post.type');
            $name = input('post.name', null, 'trim');
            $config = input('post.config', null, 'trim');
            $remark = input('post.remark', null, 'trim');
            if (empty($name) || empty($config)) return json(['code' => -1, 'msg' => '必填参数不能为空']);
            if (Db::name('account')->where('type', $type)->where('name', $name)->where('id', '<>', $id)->find()) {
                return json(['code' => -1, 'msg' => '域名账户已存在']);
            }
            Db::startTrans();
            Db::name('account')->where('id', $id)->update([
                'type' => $type,
                'name' => $name,
                'config' => $config,
                'remark' => $remark,
            ]);
            $dns = DnsHelper::getModel($id);
            if ($dns) {
                if ($dns->check()) {
                    Db::commit();
                    return json(['code' => 0, 'msg' => '修改域名账户成功！']);
                } else {
                    Db::rollback();
                    return json(['code' => -1, 'msg' => '验证域名账户失败，' . $dns->getError()]);
                }
            } else {
                Db::rollback();
                return json(['code' => -1, 'msg' => 'DNS模块(' . $type . ')不存在']);
            }
        } elseif ($action == 'check') {
            // 测试已保存的域名账户是否可用（只调用查询接口）
            $id = input('post.id/d');
            $row = Db::name('account')->where('id', $id)->find();
            if (!$row) return json(['code' => -1, 'msg' => '域名账户不存在']);
            $result = $this->testDnsModel(DnsHelper::getModel($id), $row['type']);
            if ($result['code'] == 0) {
                $added = Db::name('domain')->where('aid', $id)->count();
                $result['details'][] = ['level' => 'ok', 'text' => '本系统中已添加该账户下的 ' . $added . ' 个域名'];
            }
            return json($result);
        } elseif ($action == 'test') {
            // 添加/编辑页面上，用当前填写但尚未保存的配置测试
            $type = input('post.type');
            $config = input('post.config', '', 'trim');
            if (empty($type) || !isset(DnsHelper::$dns_config[$type])) return json(['code' => -1, 'msg' => 'DNS模块不存在']);
            if (!is_array(json_decode($config, true))) return json(['code' => -1, 'msg' => '账户配置不能为空']);
            return json($this->testDnsModel(DnsHelper::getModel2(['type' => $type, 'config' => $config, 'name' => null, 'thirdid' => null]), $type));
        } elseif ($action == 'del') {
            $id = input('post.id/d');
            $dcount = DB::name('domain')->where('aid', $id)->count();
            if ($dcount > 0) return json(['code' => -1, 'msg' => '该域名账户下存在域名，无法删除']);
            Db::name('account')->where('id', $id)->delete();
            return json(['code' => 0]);
        }
        return json(['code' => -3]);
    }


    public function domain()
    {
        if (request()->user['type'] == 'domain') {
            return redirect('/record/' . request()->user['id']);
        }
        $list = Db::name('account')->select();
        $accounts = [];
        $types = [];
        foreach ($list as $row) {
            if (empty($row['type']) || !isset(DnsHelper::$dns_config[$row['type']])) continue;
            $name = $row['id'] . '_' . DnsHelper::$dns_config[$row['type']]['name'];
            if (!array_key_exists($row['type'], $types)) {
                $types[$row['type']] = DnsHelper::$dns_config[$row['type']]['name'];
            }
            if (!empty($row['remark'])) {
                $name .= '（' . $row['remark'] . '）';
            }
            $accounts[] = ['id' => $row['id'], 'name' => $name, 'type' => DnsHelper::$dns_config[$row['type']]['name'], 'add' => DnsHelper::$dns_config[$row['type']]['add']];
        }
        $categorys = Db::name('domain_category')->order('sort', 'asc')->order('id', 'desc')->select();
        View::assign('accounts', $accounts);
        View::assign('types', $types);
        View::assign('categorys', $categorys);
        return view();
    }

    public function domain_add()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $list = Db::name('account')->select();
        $accounts = [];
        $types = [];
        foreach ($list as $row) {
            if (empty($row['type']) || !isset(DnsHelper::$dns_config[$row['type']])) continue;
            $accounts[$row['id']] = $row['id'] . '_' . DnsHelper::$dns_config[$row['type']]['name'];
            if (!array_key_exists($row['type'], $types)) {
                $types[$row['type']] = DnsHelper::$dns_config[$row['type']]['name'];
            }
            if (!empty($row['remark'])) {
                $accounts[$row['id']] .= '（' . $row['remark'] . '）';
            }
        }
        View::assign('accounts', $accounts);
        View::assign('types', $types);
        return view();
    }

    public function domain_data()
    {
        if (!checkPermission(1)) return json(['total' => 0, 'rows' => []]);
        $kw = input('post.kw', null, 'trim');
        $type = input('post.type', null, 'trim');
        $status = input('post.status', null, 'trim');
        $cid = input('post.cid', null, 'trim');
        $sort = input('post.sortName', null, 'trim');
        $orderDir = strtolower(input('post.sortOrder', 'desc')) === 'asc' ? 'asc' : 'desc';
        $offset = input('post.offset/d', 0);
        $limit = input('post.limit/d', 10);
        $id = input('post.id');
        $aid = input('post.aid', null, 'trim');

        $select = Db::name('domain')->alias('A')->join('account B', 'A.aid = B.id');
        if (!empty($id)) {
            $select->where('A.id', $id);
        } elseif (!empty($kw)) {
            $select->whereLike('A.name|A.remark', '%' . $kw . '%');
        }
        if (!empty($aid)) {
            $select->where('A.aid', $aid);
        }
        if (!empty($type)) {
            $select->whereLike('B.type', $type);
        }
        if (!isNullOrEmpty($cid)) {
            $select->where('A.cid', $cid);
        }
        if (request()->user['level'] == 1) {
            $select->where('is_hide', 0)->where('A.name', 'in', request()->user['permission']);
        }
        if (api_domains() !== null) {
            $select->where('A.name', 'in', api_domains());
        }
        if (!isNullOrEmpty($status)) {
            if ($status == '2') {
                $select->where('A.expiretime', '<=', date('Y-m-d H:i:s'));
            } elseif ($status == '1') {
                $select->where('A.expiretime', '<=', date('Y-m-d H:i:s', time() + 86400 * 30))->where('A.expiretime', '>', date('Y-m-d H:i:s'));
            }
        }
        $total = $select->count();
        $allowedSort = ['id' => 'A.id', 'name' => 'A.name', 'recordcount' => 'A.recordcount', 'addtime' => 'A.addtime', 'regtime' => 'A.regtime', 'expiretime' => 'A.expiretime', 'is_notice' => 'A.is_notice', 'is_hide' => 'A.is_hide', 'is_sso' => 'A.is_sso', 'typename' => 'B.type', 'category_name' => 'A.cid', 'remark' => 'A.remark'];
        if ($sort && isset($allowedSort[$sort])) {
            $select->order($allowedSort[$sort], $orderDir);
        } else {
            $select->order('A.id', 'desc');
        }
        $rows = $select->fieldRaw('A.*,B.type,B.remark aremark')->limit($offset, $limit)->select();

        $categorys = Db::name('domain_category')->column('name', 'id');
        $list = [];
        foreach ($rows as $row) {
            if (!empty($row['type']) && isset(DnsHelper::$dns_config[$row['type']])) {
                $row['typename'] = DnsHelper::$dns_config[$row['type']]['name'];
                $row['icon'] = DnsHelper::$dns_config[$row['type']]['icon'];
            } else {
                $row['typename'] = $row['type'] ?: '未知';
                $row['icon'] = '';
            }
            $row['category_name'] = isset($categorys[$row['cid']]) ? $categorys[$row['cid']] : '';
            $list[] = $row;
        }

        return json(['total' => $total, 'rows' => $list]);
    }

    public function domain_op()
    {
        $act = input('param.act');
        if (api_domains() !== null) {
            if ($act == 'add' && !api_domain_allowed(input('post.name', '', 'trim'))) {
                return json(['code' => -1, 'msg' => '该域名不在API密钥允许的范围内']);
            }
            if (in_array($act, ['batchadd'])) {
                foreach ((array)input('post.domains') as $row) {
                    if (!is_array($row) || !api_domain_allowed($row['name'] ?? '')) return json(['code' => -1, 'msg' => '部分域名不在API密钥允许的范围内']);
                }
            }
            $ids = input('?post.ids') ? (array)input('post.ids') : (input('?post.id') ? [input('post.id/d')] : []);
            foreach (Db::name('domain')->where('id', 'in', $ids ?: [0])->column('name') as $name) {
                if (!api_domain_allowed($name)) return json(['code' => -1, 'msg' => '域名' . $name . '不在API密钥允许的范围内']);
            }
        }
        if ($this->request->isApi && $act != 'get') {
            api_write_log('域名管理:' . $act, (string)(input('post.name') ?: (input('post.id') ?: implode(',', (array)input('post.ids', [])))));
        }
        if ($act == 'get') {
            $id = input('post.id/d');
            $row = Db::name('domain')->where('id', $id)->find();
            if (!$row) return json(['code' => -1, 'msg' => '域名不存在']);
            if (!checkPermission(0, $row['name'])) return json(['code' => -1, 'msg' => '无权限']);
            return json(['code' => 0, 'data' => $row]);
        } elseif ($act == 'add') {
            if (!checkPermission(2)) return $this->alert('error', '无权限');
            $aid = input('post.aid/d');
            $method = input('post.method/d', 0);
            $name = input('post.name', null, 'trim');
            $thirdid = input('post.thirdid', null, 'trim');
            $recordcount = input('post.recordcount/d', 0);
            if ($method == 1 && empty($name) || $method == 0 && (empty($name) || empty($thirdid))) return json(['code' => -1, 'msg' => '参数不能为空']);
            if (Db::name('domain')->where('aid', $aid)->where('name', $name)->find()) {
                return json(['code' => -1, 'msg' => '域名已存在']);
            }
            if ($method == 1) {
                $dns = DnsHelper::getModel($aid);
                $result = $dns->addDomain($name);
                if (!$result) return json(['code' => -1, 'msg' => '添加域名失败，' . $dns->getError()]);
                $name = $result['name'];
                $thirdid = $result['id'];
            }
            Db::name('domain')->insert([
                'aid' => $aid,
                'name' => $name,
                'thirdid' => $thirdid,
                'addtime' => date('Y-m-d H:i:s'),
                'is_hide' => 0,
                'is_sso' => 1,
                'recordcount' => $recordcount,
            ]);
            return json(['code' => 0, 'msg' => '添加域名成功！']);
        } elseif ($act == 'edit') {
            if (!checkPermission(2)) return $this->alert('error', '无权限');
            $id = input('post.id/d');
            $row = Db::name('domain')->where('id', $id)->find();
            if (!$row) return json(['code' => -1, 'msg' => '域名不存在']);
            $is_hide = input('post.is_hide/d');
            $is_sso = input('post.is_sso/d');
            $is_notice = input('post.is_notice/d');
            $cid = input('post.cid/d', 0);
            $expiretime = input('post.expiretime', null, 'trim');
            $remark = input('post.remark', null, 'trim');
            if (empty($remark)) $remark = null;
            if ($expiretime && strtotime($expiretime) === false) return json(['code' => -1, 'msg' => '到期时间格式不正确']);
            $expiretime = $expiretime ? date('Y-m-d H:i:s', strtotime($expiretime)) : null;
            $update = [
                'is_hide' => $is_hide,
                'is_sso' => $is_sso,
                'is_notice' => $is_notice,
                'cid' => $cid,
                'expiretime' => $expiretime,
                'remark' => $remark,
            ];
            // 手动修改了到期时间：标记为手动（WHOIS/RDAP查不到时不会被覆盖为“查询失败”）；清空则重新自动查询
            $oldExpire = $row['expiretime'] ? date('Y-m-d H:i:s', strtotime($row['expiretime'])) : null;
            if ($expiretime !== $oldExpire) {
                $update['checkstatus'] = $expiretime ? 3 : 0;
            }
            Db::name('domain')->where('id', $id)->update($update);
            return json(['code' => 0, 'msg' => '修改域名配置成功！']);
        } elseif ($act == 'del') {
            if (!checkPermission(2)) return $this->alert('error', '无权限');
            $id = input('post.id/d');
            Db::name('domain')->where('id', $id)->delete();
            Db::name('domain_alias')->where('did', $id)->delete();
            Db::name('dmtask')->where('did', $id)->delete();
            Db::name('optimizeip')->where('did', $id)->delete();
            Db::name('sctask')->where('did', $id)->delete();
            return json(['code' => 0]);
        } elseif ($act == 'batchadd') {
            if (!checkPermission(2)) return $this->alert('error', '无权限');
            $aid = input('post.aid/d');
            $domains = input('post.domains');
            if (empty($domains)) return json(['code' => -1, 'msg' => '参数不能为空']);
            $data = [];
            foreach ($domains as $row) {
                $data[] = [
                    'aid' => $aid,
                    'name' => $row['name'],
                    'thirdid' => $row['id'],
                    'addtime' => date('Y-m-d H:i:s'),
                    'is_hide' => 0,
                    'is_sso' => 1,
                    'recordcount' => $row['recordcount'],
                ];
            }
            Db::name('domain')->insertAll($data);
            return json(['code' => 0, 'msg' => '成功添加' . count($data) . '个域名！']);
        } elseif ($act == 'batchedit') {
            if (!checkPermission(2)) return $this->alert('error', '无权限');
            $ids = input('post.ids');
            if (empty($ids)) return json(['code' => -1, 'msg' => '参数不能为空']);
            $remark = input('post.remark', null, 'trim');
            if (empty($remark)) $remark = null;
            $count = Db::name('domain')->where('id', 'in', $ids)->update(['remark' => $remark]);
            return json(['code' => 0, 'msg' => '成功修改' . $count . '个域名！']);
        } elseif ($act == 'batchsetnotice') {
            if (!checkPermission(2)) return $this->alert('error', '无权限');
            $ids = input('post.ids');
            $is_notice = input('post.is_notice/d', 0);
            if (empty($ids)) return json(['code' => -1, 'msg' => '参数不能为空']);
            $count = Db::name('domain')->where('id', 'in', $ids)->update(['is_notice' => $is_notice]);
            return json(['code' => 0, 'msg' => '成功修改' . $count . '个域名！']);
        } elseif ($act == 'batchdel') {
            if (!checkPermission(2)) return $this->alert('error', '无权限');
            $ids = input('post.ids');
            if (empty($ids)) return json(['code' => -1, 'msg' => '参数不能为空']);
            Db::name('domain')->where('id', 'in', $ids)->delete();
            Db::name('domain_alias')->where('did', 'in', $ids)->delete();
            Db::name('dmtask')->where('did', 'in', $ids)->delete();
            Db::name('optimizeip')->where('did', 'in', $ids)->delete();
            Db::name('sctask')->where('did', 'in', $ids)->delete();
            return json(['code' => 0, 'msg' => '成功删除' . count($ids) . '个域名！']);
        } elseif ($act == 'updateexpire') {
            if (!checkPermission(2)) return $this->alert('error', '无权限');
            $ids = input('post.ids');
            if (empty($ids)) return json(['code' => -1, 'msg' => '参数不能为空']);
            $count = Db::name('domain')->where('id', 'in', $ids)->update(['checkstatus' => 0]);
            return json(['code' => 0, 'msg' => '已提交' . $count . '个域名，约' . ceil($count / 5) . '分钟后刷新完成。']);
        }
        return json(['code' => -3]);
    }

    public function domain_list()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $aid = input('post.aid/d');
        $kw = input('post.kw', null, 'trim');
        $page = input('?post.page') ? input('post.page/d') : 1;
        $pagesize = input('?post.pagesize') ? input('post.pagesize/d') : 10;
        $dns = DnsHelper::getModel($aid);
        $result = $dns->getDomainList($kw, $page, $pagesize);
        if (!$result) return json(['code' => -1, 'msg' => '获取域名列表失败，' . $dns->getError()]);

        if (api_domains() !== null) {
            $result['list'] = array_values(array_filter($result['list'], fn($row) => api_domain_allowed($row['Domain'])));
            $result['total'] = count($result['list']);
        }
        foreach ($result['list'] as &$row) {
            $row['disabled'] = Db::name('domain')->where('aid', $aid)->where('name', $row['Domain'])->find() != null;
        }
        return json(['code' => 0, 'data' => ['total' => $result['total'], 'list' => $result['list']]]);
    }

    //获取解析线路和最小TTL
    private function get_line_and_ttl($drow)
    {
        $recordLine = cache('record_line_' . $drow['id']);
        $minTTL = cache('min_ttl_' . $drow['id']);
        if (empty($recordLine)) {
            $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);
            if (!$dns) throw new Exception('DNS模块不存在');
            $recordLine = $dns->getRecordLine();
            if (!$recordLine) throw new Exception('获取解析线路列表失败，' . $dns->getError());
            cache('record_line_' . $drow['id'], $recordLine, 604800);
            $minTTL = $dns->getMinTTL();
            if ($minTTL) {
                cache('min_ttl_' . $drow['id'], $minTTL, 604800);
            }
        }
        return [$recordLine, $minTTL];
    }

    public function domain_info()
    {
        $id = input('param.id/d');
        $drow = Db::name('domain')->where('id', $id)->find();
        if (!$drow) {
            return $this->alert('error', '域名不存在');
        }
        $dnstype = Db::name('account')->where('id', $drow['aid'])->value('type');
        if (!checkPermission(0, $drow['name'])) return $this->alert('error', '无权限');
        if (empty($dnstype) || !isset(DnsHelper::$dns_config[$dnstype])) {
            return $this->alert('error', 'DNS账户类型不存在或已失效');
        }

        list($recordLine, $minTTL) = $this->get_line_and_ttl($drow);

        $recordLineArr = [];
        foreach ($recordLine as $key => $item) {
            $recordLineArr[] = ['id' => strval($key), 'name' => $item['name'], 'parent' => $item['parent']];
        }

        $dnsconfig = DnsHelper::$dns_config[$dnstype];
        $dnsconfig['type'] = $dnstype;

        $drow['config'] = $dnsconfig;
        $drow['recordLine'] = $recordLineArr;
        $drow['minTTL'] = $minTTL ? $minTTL : 1;
        if (input('?post.loginurl') && input('post.loginurl') == '1' && api_has_scope('record:write')) {
            $token = getSid();
            cache('quicklogin_' . $drow['name'], $token, 3600);
            $timestamp = time();
            $sign = md5(config_get('sys_key') . $drow['name'] . $timestamp . $token . config_get('sys_key'));
            $drow['loginurl'] = request()->root(true) . '/quicklogin?domain=' . $drow['name'] . '&timestamp=' . $timestamp . '&token=' . $token . '&sign=' . $sign;
        }

        return json(['code' => 0, 'data' => $drow]);
    }

    public function record()
    {
        $id = input('param.id/d');
        $drow = Db::name('domain')->where('id', $id)->find();
        if (!$drow) {
            return $this->alert('error', '域名不存在');
        }
        $dnstype = Db::name('account')->where('id', $drow['aid'])->value('type');
        if (!checkPermission(0, $drow['name'])) return $this->alert('error', '无权限');
        if (empty($dnstype) || !isset(DnsHelper::$dns_config[$dnstype])) {
            return $this->alert('error', 'DNS账户类型不存在或已失效');
        }

        list($recordLine, $minTTL) = $this->get_line_and_ttl($drow);

        $recordLineArr = [];
        foreach ($recordLine as $key => $item) {
            $recordLineArr[] = ['id' => strval($key), 'name' => $item['name'], 'parent' => $item['parent']];
        }

        $dnsconfig = DnsHelper::$dns_config[$dnstype];
        $dnsconfig['type'] = $dnstype;

        View::assign('domainId', $id);
        View::assign('domainName', $drow['name']);
        View::assign('recordLine', $recordLineArr);
        View::assign('minTTL', $minTTL ? $minTTL : 1);
        View::assign('dnsconfig', $dnsconfig);
        if ($dnstype == 'qingcloud') {
            return view('qingcloud');
        }
        return view();
    }

    public function record_data()
    {
        $id = input('param.id/d');
        $keyword = input('post.keyword', null, 'trim');
        $subdomain = input('post.subdomain', null, 'trim');
        $value = input('post.value', null, 'trim');
        $type = input('post.type', null, 'trim');
        $line = input('post.line', null, 'trim');
        $status = input('post.status', null, 'trim');
        $sort = input('post.sortName', null, 'trim');
        $sortOrder = strtolower(input('post.sortOrder', 'asc')) === 'desc' ? 'desc' : 'asc';
        $offset = input('post.offset/d', 0);
        $limit = input('post.limit/d', 10);
        if ($limit == 0) {
            $page = 1;
        } else {
            $page = $offset / $limit + 1;
        }

        $drow = Db::name('domain')->where('id', $id)->find();
        if (!$drow) {
            return json(['total' => 0, 'rows' => []]);
        }
        if (!checkPermission(0, $drow['name'])) return json(['total' => 0, 'rows' => []]);

        $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);
        $dnstype = Db::name('account')->where('id', $drow['aid'])->value('type');
        if (!$dns || empty($dnstype) || !isset(DnsHelper::$dns_config[$dnstype])) {
            return json(['total' => 0, 'rows' => []]);
        }
        if (DnsHelper::$dns_config[$dnstype]['sort']) {
            $allowedSort = ['Name', 'Type', 'LineName', 'Value', 'UpdateTime'];
            $sort = in_array($sort, $allowedSort, true) ? $sort : null;
            $domainRecords = $dns->getDomainRecords($page, $limit, $keyword, $subdomain, $value, $type, $line, $status, $sort, $sortOrder);
        } else {
            $domainRecords = $dns->getDomainRecords($page, $limit, $keyword, $subdomain, $value, $type, $line, $status);
        }
        if (!$domainRecords) return json(['total' => 0, 'rows' => []]);

        if (empty($keyword) && empty($subdomain) && empty($type) && isNullOrEmpty($line) && empty($status) && empty($value) && $domainRecords['total'] != $drow['recordcount']) {
            Db::name('domain')->where('id', $id)->update(['recordcount' => $domainRecords['total']]);
        }

        $recordLine = cache('record_line_' . $id);

        foreach ($domainRecords['list'] as &$row) {
            $row['LineName'] = isset($recordLine[$row['Line']]) ? $recordLine[$row['Line']]['name'] : $row['Line'];
        }

        if (DnsHelper::$dns_config[$dnstype]['page']) {
            return json($domainRecords['list']);
        }

        return json(['total' => $domainRecords['total'], 'rows' => $domainRecords['list']]);
    }

    public function record_list()
    {
        $id = input('post.id/d');
        $rr = input('post.rr', null, 'trim');

        $drow = Db::name('domain')->where('id', $id)->find();
        if (!$drow) {
            return json(['code' => -1, 'msg' => '域名不存在']);
        }
        if (!checkPermission(0, $drow['name'])) return json(['code' => -1, 'msg' => '无权限']);

        $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);
        $domainRecords = $dns->getSubDomainRecords($rr, 1, 100);
        if (!$domainRecords) return json(['code' => -1, 'msg' => '获取记录列表失败，' . $dns->getError()]);

        list($recordLine, $minTTL) = $this->get_line_and_ttl($drow);

        $list = [];
        foreach ($domainRecords['list'] as &$row) {
            if ($rr == '@' && ($row['Type'] == 'NS' || $row['Type'] == 'SOA')) continue;
            $row['LineName'] = isset($recordLine[$row['Line']]) ? $recordLine[$row['Line']]['name'] : $row['Line'];
            $list[] = $row;
        }

        return json(['code' => 0, 'data' => $list]);
    }

    public function record_add()
    {
        $id = input('param.id/d');
        $drow = Db::name('domain')->where('id', $id)->find();
        if (!$drow) {
            return json(['code' => -1, 'msg' => '域名不存在']);
        }
        if (!checkPermission(0, $drow['name'])) return $this->alert('error', '无权限');

        $name = input('post.name', null, 'trim');
        $type = input('post.type', null, 'trim');
        $value = input('post.value', null, 'trim');
        $line = input('post.line', null, 'trim');
        $ttl = input('post.ttl/d', 600);
        $weight = input('post.weight/d', 0);
        $mx = input('post.mx/d', 1);
        $remark = input('post.remark', null, 'trim');

        if (empty($name) || empty($type) || empty($value)) {
            return json(['code' => -1, 'msg' => '参数不能为空']);
        }

        $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);
        $recordid = $dns->addDomainRecord($name, $type, $value, $line, $ttl, $mx, $weight, $remark);
        if ($recordid) {
            $this->add_log($drow['name'], '添加解析', $name.' ['.$type.'] '.$value.' (线路:'.$line.' TTL:'.$ttl.')');
            return json(['code' => 0, 'msg' => '添加解析记录成功！']);
        } else {
            return json(['code' => -1, 'msg' => '添加解析记录失败，' . $dns->getError()]);
        }
    }

    public function record_update()
    {
        $id = input('param.id/d');
        $drow = Db::name('domain')->where('id', $id)->find();
        if (!$drow) {
            return json(['code' => -1, 'msg' => '域名不存在']);
        }
        if (!checkPermission(0, $drow['name'])) return $this->alert('error', '无权限');

        $recordid = input('post.recordid', null, 'trim');
        $name = input('post.name', null, 'trim');
        $type = input('post.type', null, 'trim');
        $value = input('post.value', null, 'trim');
        $line = input('post.line', null, 'trim');
        $ttl = input('post.ttl/d', 600);
        $weight = input('post.weight/d', 0);
        $mx = input('post.mx/d', 1);
        $remark = input('post.remark', null, 'trim');

        $recordinfo = input('post.recordinfo', null, 'trim');

        if (empty($recordid) || empty($name) || empty($type) || empty($value)) {
            return json(['code' => -1, 'msg' => '参数不能为空']);
        }

        $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);
        $recordid = $dns->updateDomainRecord($recordid, $name, $type, $value, $line, $ttl, $mx, $weight, $remark);
        if ($recordid) {
            if ($recordinfo) {
                $recordinfo = json_decode($recordinfo, true);
                if (is_array($recordinfo)) {
                    if (isset($recordinfo['Value']) && is_array($recordinfo['Value'])) $recordinfo['Value'] = implode(',', $recordinfo['Value']);
                    if (($recordinfo['Name'] ?? '') != $name || ($recordinfo['Type'] ?? '') != $type || ($recordinfo['Value'] ?? '') != $value) {
                        $this->add_log($drow['name'], '修改解析', ($recordinfo['Name'] ?? '') . ' [' . ($recordinfo['Type'] ?? '') . '] ' . ($recordinfo['Value'] ?? '') . ' → '.$name.' ['.$type.'] '.$value.' (线路:'.$line.' TTL:'.$ttl.')');
                    } elseif (($recordinfo['Line'] ?? '') != $line || ($recordinfo['TTL'] ?? '') != $ttl) {
                        $this->add_log($drow['name'], '修改解析', $name.' ['.$type.'] '.$value.' (线路:'.$line.' TTL:'.$ttl.')');
                    }
                } else {
                    $this->add_log($drow['name'], '修改解析', $name.' ['.$type.'] '.$value.' (线路:'.$line.' TTL:'.$ttl.')');
                }
            } else {
                $this->add_log($drow['name'], '修改解析', $name.' ['.$type.'] '.$value.' (线路:'.$line.' TTL:'.$ttl.')');
            }
            return json(['code' => 0, 'msg' => '修改解析记录成功！']);
        } else {
            return json(['code' => -1, 'msg' => '修改解析记录失败，' . $dns->getError()]);
        }
    }

    public function record_delete()
    {
        $id = input('param.id/d');
        $drow = Db::name('domain')->where('id', $id)->find();
        if (!$drow) {
            return json(['code' => -1, 'msg' => '域名不存在']);
        }
        if (!checkPermission(0, $drow['name'])) return $this->alert('error', '无权限');

        $recordid = input('post.recordid', null, 'trim');
        $recordinfo = input('post.recordinfo', null, 'trim');

        if (empty($recordid)) {
            return json(['code' => -1, 'msg' => '参数不能为空']);
        }

        $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);
        if ($dns->deleteDomainRecord($recordid)) {
            if ($recordinfo) {
                $recordinfo = json_decode($recordinfo, true);
                if (is_array($recordinfo)) {
                    if (isset($recordinfo['Value']) && is_array($recordinfo['Value'])) $recordinfo['Value'] = implode(',', $recordinfo['Value']);
                    $this->add_log($drow['name'], '删除解析', ($recordinfo['Name'] ?? '') . ' [' . ($recordinfo['Type'] ?? '') . '] ' . ($recordinfo['Value'] ?? '') . ' (线路:' . ($recordinfo['Line'] ?? '') . ' TTL:' . ($recordinfo['TTL'] ?? '') . ')');
                } else {
                    $this->add_log($drow['name'], '删除解析', '记录ID:'.$recordid);
                }
            } else {
                $this->add_log($drow['name'], '删除解析', '记录ID:'.$recordid);
            }
            return json(['code' => 0, 'msg' => '删除解析记录成功！']);
        } else {
            return json(['code' => -1, 'msg' => '删除解析记录失败，' . $dns->getError()]);
        }
    }

    public function record_status()
    {
        $id = input('param.id/d');
        $drow = Db::name('domain')->where('id', $id)->find();
        if (!$drow) {
            return json(['code' => -1, 'msg' => '域名不存在']);
        }
        if (!checkPermission(0, $drow['name'])) return $this->alert('error', '无权限');

        $recordid = input('post.recordid', null, 'trim');
        $status = input('post.status', null, 'trim');
        $recordinfo = input('post.recordinfo', null, 'trim');

        if (empty($recordid)) {
            return json(['code' => -1, 'msg' => '参数不能为空']);
        }

        $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);
        if ($dns->setDomainRecordStatus($recordid, $status)) {
            $action = $status == '1' ? '启用解析' : '暂停解析';
            if ($recordinfo) {
                $recordinfo = json_decode($recordinfo, true);
                if (is_array($recordinfo)) {
                    if (isset($recordinfo['Value']) && is_array($recordinfo['Value'])) $recordinfo['Value'] = implode(',', $recordinfo['Value']);
                    $this->add_log($drow['name'], $action, ($recordinfo['Name'] ?? '') . ' [' . ($recordinfo['Type'] ?? '') . '] ' . ($recordinfo['Value'] ?? '') . ' (线路:' . ($recordinfo['Line'] ?? '') . ' TTL:' . ($recordinfo['TTL'] ?? '') . ')');
                } else {
                    $this->add_log($drow['name'], $action, '记录ID:'.$recordid);
                }
            } else {
                $this->add_log($drow['name'], $action, '记录ID:'.$recordid);
            }
            return json(['code' => 0, 'msg' => '操作成功！']);
        } else {
            return json(['code' => -1, 'msg' => '操作失败，' . $dns->getError()]);
        }
    }

    public function record_remark()
    {
        $id = input('param.id/d');
        $drow = Db::name('domain')->where('id', $id)->find();
        if (!$drow) {
            return json(['code' => -1, 'msg' => '域名不存在']);
        }
        if (!checkPermission(0, $drow['name'])) return $this->alert('error', '无权限');

        $recordid = input('post.recordid', null, 'trim');
        $remark = input('post.remark', null, 'trim');

        if (empty($recordid)) {
            return json(['code' => -1, 'msg' => '参数不能为空']);
        }
        if (empty($remark)) $remark = null;

        $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);
        if ($dns->updateDomainRecordRemark($recordid, $remark)) {
            return json(['code' => 0, 'msg' => '操作成功！']);
        } else {
            return json(['code' => -1, 'msg' => '操作失败，' . $dns->getError()]);
        }
    }

    public function record_batch()
    {
        $id = input('param.id/d');
        $drow = Db::name('domain')->where('id', $id)->find();
        if (!$drow) {
            return json(['code' => -1, 'msg' => '域名不存在']);
        }
        if (!checkPermission(0, $drow['name'])) return $this->alert('error', '无权限');

        $action = input('post.action', null, 'trim');
        $recordinfo = input('post.recordinfo', null, 'trim');
        $recordinfo = json_decode($recordinfo, true);

        if (empty($recordinfo) || empty($action)) {
            return json(['code' => -1, 'msg' => '参数不能为空']);
        }

        $success = 0;
        $fail = 0;
        $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);
        if ($action == 'open') {
            foreach ($recordinfo as $record) {
                if ($dns->setDomainRecordStatus($record['RecordId'], '1')) {
                    if (is_array($record['Value'])) $record['Value'] = implode(',', $record['Value']);
                    $this->add_log($drow['name'], '启用解析', $record['Name'].' ['.$record['Type'].'] '.$record['Value'].' (线路:'.$record['Line'].' TTL:'.$record['TTL'].')');
                    $success++;
                }
            }
            $msg = '成功启用' . $success . '条解析记录';
        } else if ($action == 'pause') {
            foreach ($recordinfo as $record) {
                if ($dns->setDomainRecordStatus($record['RecordId'], '0')) {
                    if (is_array($record['Value'])) $record['Value'] = implode(',', $record['Value']);
                    $this->add_log($drow['name'], '暂停解析', $record['Name'].' ['.$record['Type'].'] '.$record['Value'].' (线路:'.$record['Line'].' TTL:'.$record['TTL'].')');
                    $success++;
                }
            }
            $msg = '成功暂停' . $success . '条解析记录';
        } else if ($action == 'delete') {
            foreach ($recordinfo as $record) {
                if ($dns->deleteDomainRecord($record['RecordId'])) {
                    if (is_array($record['Value'])) $record['Value'] = implode(',', $record['Value']);
                    $this->add_log($drow['name'], '删除解析', $record['Name'].' ['.$record['Type'].'] '.$record['Value'].' (线路:'.$record['Line'].' TTL:'.$record['TTL'].')');
                    $success++;
                }
            }
            $msg = '成功删除' . $success . '条解析记录';
        } else if ($action == 'remark') {
            $remark = input('post.remark', null, 'trim');
            if (empty($remark)) $remark = null;
            foreach ($recordinfo as $record) {
                if ($dns->updateDomainRecordRemark($record['RecordId'], $remark)) {
                    $success++;
                } else {
                    $fail++;
                }
            }
            $msg = '批量修改备注，成功' . $success . '条，失败' . $fail . '条';
        } else if ($action == 'group') {
            $dnstype = Db::name('account')->where('id', $drow['aid'])->value('type');
            if (!in_array($dnstype, ['aliyun', 'dnspod'])) {
                return json(['code' => -1, 'msg' => '该DNS类型不支持分组']);
            }
            $groupid = input('post.groupid', '', 'trim');
            $recordIdList = array_column($recordinfo, 'RecordId');
            if ($dns->changeRecordGroup($recordIdList, $groupid)) {
                $msg = '成功修改' . count($recordIdList) . '条记录的分组';
            } else {
                return json(['code' => -1, 'msg' => '修改分组失败，' . $dns->getError()]);
            }
        }
        return json(['code' => 0, 'msg' => $msg]);
    }

    public function record_batch_edit()
    {
        $id = input('param.id/d');
        $drow = Db::name('domain')->where('id', $id)->find();
        if (!$drow) {
            return json(['code' => -1, 'msg' => '域名不存在']);
        }
        if (!checkPermission(0, $drow['name'])) return $this->alert('error', '无权限');

        $action = input('post.action', null, 'trim');
        $recordinfo = input('post.recordinfo', null, 'trim');
        $recordinfo = json_decode($recordinfo, true);

        if ($action == 'value') {
            $type = input('post.type', null, 'trim');
            $value = input('post.value', null, 'trim');

            if (empty($recordinfo) || empty($type) || empty($value)) {
                return json(['code' => -1, 'msg' => '参数不能为空']);
            }

            $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);

            $success = 0;
            $fail = 0;
            foreach ($recordinfo as $record) {
                $recordid = $dns->updateDomainRecord($record['RecordId'], $record['Name'], $type, $value, $record['Line'], $record['TTL'], $record['MX'], $record['Weight'], $record['Remark']);
                if ($recordid) {
                    if (is_array($record['Value'])) $record['Value'] = implode(',', $record['Value']);
                    $this->add_log($drow['name'], '修改解析', $record['Name'].' ['.$record['Type'].'] '.$record['Value'].' → '.$record['Name'].' ['.$type.'] '.$value.' (线路:'.$record['Line'].' TTL:'.$record['TTL'].')');
                    $success++;
                } else {
                    $fail++;
                }
            }
            return json(['code' => 0, 'msg' => '批量修改解析记录，成功' . $success . '条，失败' . $fail . '条']);
        } else if ($action == 'line') {
            $line = input('post.line', null, 'trim');

            if (empty($recordinfo) || isNullOrEmpty($line)) {
                return json(['code' => -1, 'msg' => '参数不能为空']);
            }

            $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);

            $success = 0;
            $fail = 0;
            foreach ($recordinfo as $record) {
                $recordid = $dns->updateDomainRecord($record['RecordId'], $record['Name'], $record['Type'], $record['Value'], $line, $record['TTL'], $record['MX'], $record['Weight'], $record['Remark']);
                if ($recordid) {
                    if (is_array($record['Value'])) $record['Value'] = implode(',', $record['Value']);
                    $this->add_log($drow['name'], '修改解析', $record['Name'].' ['.$record['Type'].'] '.$record['Value'].' (线路:'.$line.' TTL:'.$record['TTL'].')');
                    $success++;
                } else {
                    $fail++;
                }
            }
            return json(['code' => 0, 'msg' => '批量修改解析线路，成功' . $success . '条，失败' . $fail . '条']);
        }
    }

    public function record_batch_add()
    {
        $id = input('param.id/d');
        $drow = Db::name('domain')->where('id', $id)->find();
        if (!$drow) {
            return $this->alert('error', '域名不存在');
        }
        $dnstype = Db::name('account')->where('id', $drow['aid'])->value('type');
        if (!checkPermission(0, $drow['name'])) return $this->alert('error', '无权限');

        if (request()->isAjax()) {
            $record = input('post.record', null, 'trim');
            $type = input('post.type', null, 'trim');
            $line = input('post.line', null, 'trim');
            $ttl = input('post.ttl/d', 600);
            $mx = input('post.mx/d', 1);
            $remark = input('post.remark', null, 'trim');
            $recordlist = explode("\n", $record);

            if (empty($record) || empty($recordlist)) {
                return json(['code' => -1, 'msg' => '参数不能为空']);
            }
            if (is_null($line)) {
                $line = DnsHelper::$line_name[$dnstype]['DEF'] ?? 'default';
                if ($dnstype == 'cloudflare' && input('post.proxy/d', 0) == 1) {
                    $line = '1';
                }
            }

            $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);

            $success = 0;
            $fail = 0;
            foreach ($recordlist as $record) {
                $record = trim($record);
                $arr = explode(' ', $record);
                if (empty($record) || empty($arr[0]) || empty($arr[1])) continue;
                $thistype = empty($type) ? getDnsType($arr[1]) : $type;
                $recordid = $dns->addDomainRecord($arr[0], $thistype, $arr[1], $line, $ttl, $mx, null, $remark);
                if ($recordid) {
                    $this->add_log($drow['name'], '添加解析', $arr[0].' ['.$thistype.'] '.$arr[1].' (线路:'.$line.' TTL:'.$ttl.')');
                    $success++;
                } else {
                    $fail++;
                }
            }
            if ($success > 0) {
                return json(['code' => 0, 'msg' => '批量添加解析，成功' . $success . '条，失败' . $fail . '条']);
            } elseif($fail > 0) {
                return json(['code' => -1, 'msg' => '批量添加解析失败，' . $dns->getError()]);
            } else {
                return json(['code' => -1, 'msg' => '批量添加解析失败，没有可添加的记录']);
            }
        }

        list($recordLine, $minTTL) = $this->get_line_and_ttl($drow);

        $recordLineArr = [];
        foreach ($recordLine as $key => $item) {
            $recordLineArr[] = ['id' => strval($key), 'name' => $item['name'], 'parent' => $item['parent']];
        }

        $dnsconfig = DnsHelper::$dns_config[$dnstype];
        $dnsconfig['type'] = $dnstype;

        View::assign('domainId', $id);
        View::assign('domainName', $drow['name']);
        View::assign('recordLine', $recordLineArr);
        View::assign('minTTL', $minTTL ? $minTTL : 1);
        View::assign('dnsconfig', $dnsconfig);
        return view('batchadd');
    }

    public function record_batch_add2()
    {
        return view('batchadd2');
    }

    public function record_import()
    {
        $id = input('param.id/d');
        $drow = Db::name('domain')->where('id', $id)->find();
        if (!$drow) {
            return $this->alert('error', '域名不存在');
        }
        $dnstype = Db::name('account')->where('id', $drow['aid'])->value('type');
        if (!checkPermission(0, $drow['name'])) return $this->alert('error', '无权限');
        if (empty($dnstype) || !isset(DnsHelper::$dns_config[$dnstype])) {
            return $this->alert('error', 'DNS账户类型不存在或已失效');
        }

        list($recordLine, $minTTL) = $this->get_line_and_ttl($drow);
        $recordLineArr = [];
        foreach ($recordLine as $key => $item) {
            $recordLineArr[] = ['id' => strval($key), 'name' => $item['name'], 'parent' => $item['parent']];
        }

        $dnsconfig = DnsHelper::$dns_config[$dnstype];
        $dnsconfig['type'] = $dnstype;

        View::assign('domainId', $id);
        View::assign('domainName', $drow['name']);
        View::assign('recordLine', $recordLineArr);
        View::assign('minTTL', $minTTL ? $minTTL : 1);
        View::assign('dnsconfig', $dnsconfig);
        View::assign('defaultLine', strval(DnsHelper::$line_name[$dnstype]['DEF'] ?? ''));
        return view('record_import');
    }

    public function record_batch_edit2()
    {
        if (request()->isAjax()) {
            $id = input('post.id/d');
            $drow = Db::name('domain')->where('id', $id)->find();
            if (!$drow) {
                return json(['code' => -1, 'msg' => '域名不存在']);
            }
            $dnstype = Db::name('account')->where('id', $drow['aid'])->value('type');
            if (!checkPermission(0, $drow['name'])) return json(['code' => -1, 'msg' => '无权限']);

            $name = input('post.name', null, 'trim');
            $type = input('post.type', null, 'trim');
            $value = input('post.value', null, 'trim');
            $ttl = input('post.ttl/d', 0);
            $mx = input('post.mx/d', 0);

            if (empty($name) || empty($type) || empty($value)) {
                return json(['code' => -1, 'msg' => '必填参数不能为空']);
            }
            $line = DnsHelper::$line_name[$dnstype]['DEF'] ?? 'default';

            $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);
            $domainRecords = $dns->getSubDomainRecords($name, 1, 100);
            if (!$domainRecords) return json(['code' => -1, 'msg' => '获取记录列表失败，' . $dns->getError()]);
            if (empty($domainRecords['list'])) return json(['code' => -1, 'msg' => '没有可修改的记录']);

            if ($type == 'A' || $type == 'AAAA' || $type == 'CNAME') {
                $list2 = array_filter($domainRecords['list'], function ($item) use ($type) {
                    return $item['Type'] == $type;
                });
                if (!empty($list2)) {
                    $list = $list2;
                } else {
                    $list = array_filter($domainRecords['list'], function ($item) {
                        return $item['Type'] == 'A' || $item['Type'] == 'AAAA' || $item['Type'] == 'CNAME';
                    });
                }
            } else {
                $list = array_filter($domainRecords['list'], function ($item) use ($type) {
                    return $item['Type'] == $type;
                });
            }
            if (empty($list)) return json(['code' => -1, 'msg' => '没有可修改的'.$type.'记录']);

            $list2 = array_filter($domainRecords['list'], function ($item) use ($line) {
                return $item['Line'] == $line;
            });
            if (!empty($list2)) $list = $list2;

            $success = 0;
            $fail = 0;
            foreach ($list as $record) {
                if ($name == '@' && ($record['Type'] == 'NS' || $record['Type'] == 'SOA')) continue;
                
                if ($ttl > 0) $record['TTL'] = $ttl;
                if ($mx > 0) $record['MX'] = $mx;
                $recordid = $dns->updateDomainRecord($record['RecordId'], $record['Name'], $type, $value, $record['Line'], $record['TTL'], $record['MX'], $record['Weight'], $record['Remark']);
                if ($recordid) {
                    if (is_array($record['Value'])) $record['Value'] = implode(',', $record['Value']);
                    $this->add_log($drow['name'], '修改解析', $record['Name'].' ['.$record['Type'].'] '.$record['Value'].' → '.$record['Name'].' ['.$type.'] '.$value.' (线路:'.$record['Line'].' TTL:'.$record['TTL'].')');
                    $success++;
                } else {
                    $fail++;
                }
            }
            if ($success > 0) {
                return json(['code' => 0, 'msg' => '成功修改' . $success . '条解析记录']);
            } elseif($fail > 0) {
                return json(['code' => -1, 'msg' => $dns->getError()]);
            } else {
                return json(['code' => -1, 'msg' => '没有可修改的记录']);
            }
        }

        return view('batchedit');
    }

    public function record_search()
    {
        if (request()->user['type'] == 'domain') {
            return redirect('/record/' . request()->user['id']);
        }
        if (!checkPermission(1)) return $this->alert('error', '无权限');

        $list = Db::name('domain')->alias('A')->join('account B', 'A.aid = B.id')
            ->field('A.id, A.name, B.type')
            ->order('A.name', 'asc')
            ->select();

        $domainList = [];
        foreach ($list as $row) {
            if (request()->user['level'] == 1 && !in_array($row['name'], request()->user['permission'])) {
                continue;
            }
            $domainList[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'type' => $row['type'],
                'dnsType' => isset(DnsHelper::$dns_config[$row['type']]) ? DnsHelper::$dns_config[$row['type']]['name'] : $row['type'],
                'icon' => isset(DnsHelper::$dns_config[$row['type']]) ? DnsHelper::$dns_config[$row['type']]['icon'] : ''
            ];
        }

        View::assign('domainList', $domainList);
        return view('record_search');
    }

    public function record_log()
    {
        $id = input('param.id/d');
        $drow = Db::name('domain')->where('id', $id)->find();
        if (!$drow) {
            return $this->alert('error', '域名不存在');
        }
        if (!checkPermission(0, $drow['name'])) return $this->alert('error', '无权限');

        if (request()->isPost()) {
            $offset = input('post.offset/d');
            $limit = input('post.limit/d');
            $page = $offset / $limit + 1;
            $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);
            $domainRecords = $dns->getDomainRecordLog($page, $limit);
            if (!$domainRecords) return json(['total' => 0, 'rows' => []]);
            return json(['total' => $domainRecords['total'], 'rows' => $domainRecords['list']]);
        }

        View::assign('domainId', $id);
        View::assign('domainName', $drow['name']);
        return view('log');
    }

    public function smartparse()
    {
        if (request()->user['type'] == 'domain') {
            return redirect('/record/' . request()->user['id']);
        }

        $list = Db::name('domain')->alias('A')->join('account B', 'A.aid = B.id')
            ->field('A.id, A.name, A.aid, B.type')
            ->order('A.name', 'asc')
            ->select();

        $domainList = [];
        foreach ($list as $row) {
            if (request()->user['level'] == 1 && !in_array($row['name'], request()->user['permission'])) {
                continue;
            }
            $dnsTypeName = isset(DnsHelper::$dns_config[$row['type']]) ? DnsHelper::$dns_config[$row['type']]['name'] : $row['type'];
            $domainList[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'dnsType' => $dnsTypeName
            ];
        }

        View::assign('domainList', $domainList);
        return view();
    }

    public function quickinfo()
    {
        $id = input('param.id/d');
        $drow = Db::name('domain')->where('id', $id)->find();
        if (!$drow) {
            return json(['code' => -1, 'msg' => '域名不存在']);
        }
        if (!checkPermission(0, $drow['name'])) return json(['code' => -1, 'msg' => '无权限']);

        try {
            list($recordLine, $minTTL) = $this->get_line_and_ttl($drow);

            $recordLineArr = [];
            foreach ($recordLine as $key => $item) {
                $recordLineArr[] = ['id' => strval($key), 'name' => $item['name'], 'parent' => $item['parent']];
            }

            $dnstype = Db::name('account')->where('id', $drow['aid'])->value('type');
            if (empty($dnstype) || !isset(DnsHelper::$dns_config[$dnstype])) {
                return json(['code' => -1, 'msg' => 'DNS账户类型不存在或已失效']);
            }
            $dnsconfig = DnsHelper::$dns_config[$dnstype];

            return json([
                'code' => 0,
                'data' => [
                    'recordLine' => $recordLineArr,
                    'minTTL' => $minTTL ? $minTTL : 1,
                    'weight' => $dnsconfig['weight'] ?? false,
                    'remark' => $dnsconfig['remark'] ?? 0
                ]
            ]);
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    public function record_groups()
    {
        $id = input('param.id/d');
        $drow = Db::name('domain')->where('id', $id)->find();
        if (!$drow) {
            return json(['code' => -1, 'msg' => '域名不存在']);
        }
        if (!checkPermission(0, $drow['name'])) return json(['code' => -1, 'msg' => '无权限']);

        $dnstype = Db::name('account')->where('id', $drow['aid'])->value('type');
        if (!in_array($dnstype, ['aliyun', 'dnspod'])) {
            return json(['code' => -1, 'msg' => '该DNS类型不支持分组']);
        }

        $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);
        $groups = $dns->getRecordGroups();
        if ($groups === false) {
            return json(['code' => -1, 'msg' => '获取分组列表失败，' . $dns->getError()]);
        }
        $groupList = [];
        if ($dnstype == 'dnspod') {
            $groupList[] = ['id' => '', 'name' => '全部记录'];
        }
        foreach ($groups as $group) {
            $groupList[] = [
                'id' => $group['GroupId'],
                'name' => $group['GroupName'] . (isset($group['RecordCount']) ? '(' . $group['RecordCount'] . ')' : ''),
            ];
        }
        return json(['code' => 0, 'data' => $groupList]);
    }

    private function add_log($domain, $action, $data)
    {
        if (!empty($this->request->apiKey)) {
            if (config_get('api_log', '1') != '1') return;
            $data .= ' [API:' . $this->request->apiKey['name'] . ']';
        } elseif ($this->request->isApi) {
            $data .= ' [API]';
        }
        if (strlen($data) > 500) $data = mb_strcut($data, 0, 500);
        Db::name('log')->insert(['uid' => request()->user['id'], 'domain' => $domain, 'action' => $action, 'data' => $data, 'addtime' => date("Y-m-d H:i:s")]);
    }


    public function weight()
    {
        $id = input('param.id/d');
        $drow = Db::name('domain')->where('id', $id)->find();
        if (!$drow) {
            return $this->alert('error', '域名不存在');
        }
        if (!checkPermission(0, $drow['name'])) return $this->alert('error', '无权限');
        if (request()->isAjax()) {
            $act = input('param.act');
            $weightModel = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);
            if (!$weightModel || !method_exists($weightModel, 'setWeightStatus')) {
                return json(['code' => -1, 'msg' => '该DNS平台不支持权重设置']);
            }
            if ($act == 'status') {
                $subdomain = input('post.subdomain', null, 'trim');
                $status = input('post.status', null, 'trim');
                $type = input('post.type', null, 'trim');
                $line = input('post.line', null, 'trim');
                $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);
                if ($dns->setWeightStatus($subdomain, $status, $type, $line)) {
                    return json(['code' => 0, 'msg' => '操作成功']);
                } else {
                    return json(['code' => -1, 'msg' => '操作失败，' . $dns->getError()]);
                }
            } elseif ($act == 'update') {
                $subdomain = input('post.subdomain', null, 'trim');
                $status = input('post.status', '0', 'trim');
                $type = input('post.type', null, 'trim');
                $line = input('post.line', null, 'trim');
                $weight = input('post.weight');
                if (empty($subdomain) || empty($type) || empty($line) || $status == '1' && empty($weight)) {
                    return json(['code' => -1, 'msg' => '参数不能为空']);
                }
                $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);
                if ($type == 'CNAME' || $dns->setWeightStatus($subdomain, $status, $type, $line)) {
                    if ($status == '1') {
                        $success = 0;
                        foreach($weight as $recordid => $weight) {
                            if ($dns->updateRecordWeight($recordid, $weight)) {
                                $success++;
                            }
                        }
                        if ($success > 0) {
                            return json(['code' => 0, 'msg' => '成功修改' . $success . '条解析记录权重']);
                        } else {
                            return json(['code' => -1, 'msg' => '修改权重失败，' . $dns->getError()]);
                        }
                    }
                    return json(['code' => 0, 'msg' => '修改成功']);
                } else {
                    return json(['code' => -1, 'msg' => '修改失败，' . $dns->getError()]);
                }
            } else {
                return json(['code' => -1, 'msg' => '参数错误']);
            }
        }

        $dnstype = Db::name('account')->where('id', $drow['aid'])->value('type');
        if ($dnstype != 'aliyun') {
            return $this->alert('error', '仅支持阿里云解析的域名');
        }
        list($recordLine, $minTTL) = $this->get_line_and_ttl($drow);

        $recordLineArr = [];
        foreach ($recordLine as $key => $item) {
            $recordLineArr[] = ['id' => strval($key), 'name' => $item['name'], 'parent' => $item['parent']];
        }

        $dnsconfig = DnsHelper::$dns_config[$dnstype];
        $dnsconfig['type'] = $dnstype;

        View::assign('domainId', $id);
        View::assign('domainName', $drow['name']);
        View::assign('recordLine', $recordLineArr);
        View::assign('dnsconfig', $dnsconfig);
        return view();
    }

    public function weight_data()
    {
        $id = input('param.id/d');
        $keyword = input('post.keyword', null, 'trim');
        $offset = input('post.offset/d');
        $limit = input('post.limit/d');
        if ($limit == 0) {
            $page = 1;
        } else {
            $page = $offset / $limit + 1;
        }

        $drow = Db::name('domain')->where('id', $id)->find();
        if (!$drow) {
            return json(['total' => 0, 'rows' => []]);
        }
        if (!checkPermission(0, $drow['name'])) return json(['total' => 0, 'rows' => []]);

        $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);
        if (!$dns || !method_exists($dns, 'getWeightSubDomains')) return json(['total' => 0, 'rows' => []]);
        $domainRecords = $dns->getWeightSubDomains($page, $limit, $keyword);
        if (!$domainRecords) return json(['total' => 0, 'rows' => []]);
        return json(['total' => $domainRecords['total'], 'rows' => $domainRecords['list']]);
    }
    
    public function alias()
    {
        $id = input('param.id/d');
        $drow = Db::name('domain')->where('id', $id)->find();
        if (!$drow) {
            return $this->alert('error', '域名不存在');
        }
        if (!checkPermission(0, $drow['name'])) return $this->alert('error', '无权限');
        if (request()->isAjax()) {
            $act = input('param.act');
            $aliasModel = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);
            if (!$aliasModel || !method_exists($aliasModel, 'domainAliasList')) {
                return json(['code' => -1, 'msg' => '该DNS平台不支持域名别名']);
            }
            if ($act == 'add') {
                $alias = input('post.alias', null, 'trim');
                if (empty($alias)) {
                    return json(['code' => -1, 'msg' => '参数不能为空']);
                }
                $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);
                if ($dns->addDomainAlias($alias)) {
                    return json(['code' => 0, 'msg' => '添加域名别名成功']);
                } else {
                    return json(['code' => -1, 'msg' => '添加域名别名失败，' . $dns->getError()]);
                }
            } elseif ($act == 'delete') {
                $alias_id = input('post.alias_id/d');
                if (empty($alias_id)) {
                    return json(['code' => -1, 'msg' => '参数不能为空']);
                }
                $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);
                if ($dns->deleteDomainAlias($alias_id)) {
                    return json(['code' => 0, 'msg' => '删除域名别名成功']);
                } else {
                    return json(['code' => -1, 'msg' => '删除域名别名失败，' . $dns->getError()]);
                }
            } elseif ($act == 'list' || $this->request->isApi) {
                $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);
                $domainAliasList = $dns->domainAliasList();
                if ($domainAliasList === false) return json(['code' => -1, 'msg' => '获取域名别名失败，' . $dns->getError()]);
                $this->updateAliasList($id, $domainAliasList);
                return json(['code' => 0, 'data' => $domainAliasList]);
            }
        }

        $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);
        $domainAliasList = $dns->domainAliasList();
        if ($domainAliasList === false) $domainAliasList = [];

        $this->updateAliasList($id, $domainAliasList);

        View::assign('domainId', $id);
        View::assign('domainName', $drow['name']);
        View::assign('domainAliasList', $domainAliasList);
        return view();
    }

    private function updateAliasList($id, $domainAliasList)
    {
        $domainAliases = array_column($domainAliasList, 'DomainAlias');
        $addList = [];
        $deleteList = [];
        $existList = Db::name('domain_alias')->where('did', $id)->select()->toArray();
        $existAliases = array_column($existList, 'name');
        foreach ($existList as $item) {
            if (!in_array($item['name'], $domainAliases)) {
                $deleteList[] = $item['id'];
            }
        }
        foreach ($domainAliases as $item) {
            if (!in_array($item, $existAliases)) {
                $addList[] = $item;
            }
        }
        if (!empty($deleteList)) {
            Db::name('domain_alias')->where('id', 'in', $deleteList)->delete();
        }
        if (!empty($addList)) {
            $dataList = [];
            foreach ($addList as $item) {
                $dataList[] = [
                    'did' => $id,
                    'name' => $item,
                ];
            }
            Db::name('domain_alias')->insertAll($dataList);
        }
    }

    public function expire_notice()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        if ($this->request->isPost()) {
            $params = input('post.');
            foreach ($params as $key => $value) {
                if (empty($key)) {
                    continue;
                }
                config_set($key, $value);
                Cache::delete('configs');
            }
            return json(['code' => 0, 'msg' => 'succ']);
        }
        return View::fetch();
    }

    private function testDnsModel($dns, $type)
    {
        if (!$dns) return ['code' => -1, 'msg' => 'DNS模块(' . $type . ')不存在'];
        @set_time_limit(120);
        $start = microtime(true);
        $ms = function () use ($start) {
            return intval(round((microtime(true) - $start) * 1000));
        };
        try {
            if (!$dns->check()) {
                $err = trim((string)$dns->getError());
                return ['code' => -1, 'msg' => $err !== '' ? $err : '验证失败（未返回错误信息）', 'time' => $ms()];
            }
        } catch (\Throwable $e) {
            return ['code' => -1, 'msg' => $e->getMessage(), 'time' => $ms()];
        }
        $time = $ms();
        $details = [['level' => 'ok', 'text' => '接口认证通过，耗时 ' . $time . 'ms']];
        try {
            $list = $dns->getDomainList(null, 1, 1);
            if (is_array($list) && isset($list['total'])) {
                $details[] = ['level' => 'ok', 'text' => '平台账户中共有 ' . intval($list['total']) . ' 个域名'];
            }
        } catch (\Throwable $e) {
        }
        return ['code' => 0, 'msg' => '账户可用', 'time' => $time, 'details' => $details];
    }

    /**
     * 各DNS服务商的NS服务器特征（只列出确定的，未列出的平台只展示NS不做比对）
     */
    private static $nsPatterns = [
        'aliyun' => ['/(\.|^)(alidns\.com|hichina\.com)$/i', '阿里云'],
        'dnspod' => ['/(\.|^)(dnspod\.(net|com)|dnsv\d\.com)$/i', '腾讯云DNSPod'],
        'huawei' => ['/(\.|^)huaweicloud-dns\.(com|cn|net|org)$/i', '华为云'],
        'west' => ['/(\.|^)myhostadmin\.net$/i', '西部数码'],
        'cloudflare' => ['/\.ns\.cloudflare\.com$/i', 'Cloudflare'],
        'aws' => ['/(\.|^)awsdns-\d+\.(com|net|org|co\.uk)$/i', 'AWS Route 53'],
        'namesilo' => ['/(\.|^)dnsowl\.com$/i', 'NameSilo'],
        'henet' => ['/(\.|^)he\.net$/i', 'HE DNS'],
        'dnsla' => ['/(\.|^)dns\.la$/i', 'DNSLA'],
        'dynv6' => ['/(\.|^)dynv6\.com$/i', 'dynv6'],
    ];

    /**
     * 测试域名是否有效：DNS账户能否管理该域名、NS是否指向当前平台、是否已到期
     */
    public function domain_check()
    {
        $id = input('post.id/d');
        $drow = Db::name('domain')->where('id', $id)->find();
        if (!$drow) return json(['code' => -1, 'msg' => '域名不存在']);
        if (!checkPermission(0, $drow['name'])) return json(['code' => -1, 'msg' => '无权限']);
        $account = Db::name('account')->where('id', $drow['aid'])->find();
        @set_time_limit(120);
        $start = microtime(true);
        $details = [];

        // 1. 通过所属DNS账户读取解析记录
        $apiOk = false;
        $dns = $account ? DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']) : false;
        if (!$dns) {
            $details[] = ['level' => 'fail', 'text' => '所属域名账户不存在或DNS模块不可用'];
        } else {
            try {
                $res = $dns->getDomainRecords(1, 1);
                if ($res === false) {
                    $details[] = ['level' => 'fail', 'text' => '无法通过域名账户读取解析记录：' . $dns->getError()];
                } else {
                    $apiOk = true;
                    $total = is_array($res) && isset($res['total']) ? intval($res['total']) : null;
                    $details[] = ['level' => 'ok', 'text' => '域名账户可正常管理该域名' . ($total !== null ? '（共 ' . $total . ' 条解析记录）' : '')];
                    if ($total !== null && $total != $drow['recordcount']) {
                        Db::name('domain')->where('id', $id)->update(['recordcount' => $total]);
                    }
                }
            } catch (\Throwable $e) {
                $details[] = ['level' => 'fail', 'text' => '无法通过域名账户读取解析记录：' . $e->getMessage()];
            }
        }

        // 2. NS 是否指向当前DNS平台
        $nsList = [];
        $records = @dns_get_record($drow['name'], DNS_NS);
        if (is_array($records)) {
            foreach ($records as $r) {
                if (!empty($r['target'])) $nsList[] = strtolower(rtrim($r['target'], '.'));
            }
        }
        if (empty($nsList)) {
            $doh = DnsQueryUtils::query_dns_doh($drow['name'], 'NS');
            if (is_array($doh)) {
                foreach ($doh as $v) $nsList[] = strtolower(rtrim($v, '.'));
            }
        }
        $nsList = array_values(array_unique($nsList));
        if (empty($nsList)) {
            $details[] = ['level' => 'warn', 'text' => '未查询到NS记录，域名可能未生效、已过期或被注册局暂停解析'];
        } else {
            $nsText = implode('、', $nsList);
            $type = $account['type'] ?? '';
            if (isset(self::$nsPatterns[$type])) {
                [$pattern, $pname] = self::$nsPatterns[$type];
                $matched = array_filter($nsList, fn($ns) => preg_match($pattern, $ns));
                if (count($matched) == count($nsList)) {
                    $details[] = ['level' => 'ok', 'text' => 'NS已指向' . $pname . '：' . $nsText];
                } elseif (!empty($matched)) {
                    $details[] = ['level' => 'warn', 'text' => 'NS只有部分指向' . $pname . '：' . $nsText];
                } else {
                    $details[] = ['level' => 'warn', 'text' => 'NS未指向' . $pname . '，在本系统修改的解析不会生效。当前NS：' . $nsText];
                }
            } else {
                $details[] = ['level' => 'ok', 'text' => '当前NS：' . $nsText];
            }
        }

        // 3. 到期时间
        if (!empty($drow['expiretime'])) {
            $days = (int)floor((strtotime($drow['expiretime']) - time()) / 86400);
            $date = substr($drow['expiretime'], 0, 10);
            if ($days < 0) {
                $details[] = ['level' => 'fail', 'text' => '域名已于 ' . $date . ' 到期'];
            } elseif ($days <= 30) {
                $details[] = ['level' => 'warn', 'text' => '域名将在 ' . $days . ' 天后（' . $date . '）到期'];
            } else {
                $details[] = ['level' => 'ok', 'text' => '到期时间 ' . $date . '（还有 ' . $days . ' 天）'];
            }
        }

        $time = intval(round((microtime(true) - $start) * 1000));
        $levels = array_column($details, 'level');
        if (in_array('fail', $levels)) {
            $first = $details[array_search('fail', $levels)]['text'];
            return json(['code' => -1, 'msg' => $first, 'label' => $apiOk ? '异常' : '无法管理', 'time' => $time, 'details' => $details]);
        }
        if (in_array('warn', $levels)) {
            $first = $details[array_search('warn', $levels)]['text'];
            return json(['code' => 0, 'level' => 'warn', 'msg' => $first, 'label' => '有警告', 'time' => $time, 'details' => $details]);
        }
        return json(['code' => 0, 'level' => 'ok', 'msg' => '域名正常', 'label' => '正常', 'time' => $time, 'details' => $details]);
    }

    public function update_date()
    {
        $id = input('param.id/d');
        $drow = Db::name('domain')->where('id', $id)->find();
        if (!$drow) {
            return json(['code' => -1, 'msg' => '域名不存在']);
        }
        if (!checkPermission(0, $drow['name'])) return json(['code' => -1, 'msg' => '无权限']);
        $result = (new ExpireNoticeService())->updateDomainDate($id, $drow['name']);
        return json($result);
    }

    public function record_check()
    {
        $id = input('param.id/d');
        $drow = Db::name('domain')->where('id', $id)->find();
        if (!$drow) {
            return json(['code' => -1, 'msg' => '域名不存在']);
        }
        if (!checkPermission(0, $drow['name'])) return json(['code' => -1, 'msg' => '无权限']);

        $recordid = input('post.recordid', null, 'trim');
        $name = input('post.name', null, 'trim');
        $type = input('post.type', null, 'trim');
        $value = input('post.value', null, 'trim');

        if (empty($recordid) || empty($name) || empty($type)) {
            return json(['code' => -1, 'msg' => '参数不能为空']);
        }

        $domain = $name === '@' ? $drow['name'] : $name . '.' . $drow['name'];
        $domain = strtolower($domain);

        $supported_types = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SOA', 'SRV', 'CAA', 'PTR', 'LOC', 'LUA'];
        if (!in_array($type, $supported_types)) {
            return json(['code' => -1, 'msg' => '该记录类型暂不支持检测']);
        }

        $dns_records = DnsQueryUtils::get_dns_records($domain, $type);
        if ($dns_records === false || empty($dns_records)) {
            $dns_records = DnsQueryUtils::query_dns_doh($domain, $type);
        }

        if ($dns_records === false || empty($dns_records)) {
            return json(['code' => 0, 'data' => ['status' => 'not_found', 'message' => '未查询到该解析记录', 'actual' => []]]);
        }

        $dns_records = array_map('strtolower', $dns_records);
        $expected_value = strtolower(rtrim(trim($value), '.'));

        if (self::recordValueMatches($expected_value, $dns_records)) {
            return json(['code' => 0, 'data' => ['status' => 'active', 'actual' => $dns_records]]);
        } else {
            return json(['code' => 0, 'data' => ['status' => 'mismatch', 'expected' => $expected_value, 'actual' => $dns_records]]);
        }
    }

    private static function recordValueMatches($expected, $result)
    {
        if (in_array($expected, $result)) return true;
        $expectedBin = @inet_pton($expected);
        if ($expectedBin !== false) {
            $normalized = array_map(fn($v) => @inet_pton($v) ?: $v, $result);
            return in_array($expectedBin, $normalized);
        }
        return false;
    }

    public function category()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        return view();
    }

    public function category_data()
    {
        if (!checkPermission(2)) return json(['total' => 0, 'rows' => []]);
        $offset = input('post.offset/d', 0);
        $limit = input('post.limit/d', 10);
        $sort = input('post.sortName', null, 'trim');
        $orderDir = strtolower(input('post.sortOrder', 'desc')) === 'asc' ? 'asc' : 'desc';

        $select = Db::name('domain_category');
        $total = $select->count();
        $allowedSort = ['id' => 'id', 'name' => 'name', 'remark' => 'remark', 'sort' => 'sort', 'addtime' => 'addtime'];
        if ($sort && isset($allowedSort[$sort])) {
            $select->order($allowedSort[$sort], $orderDir);
        } else {
            $select->order('id', 'desc');
        }
        $rows = $select->limit($offset, $limit)->select()->toArray();

        foreach ($rows as &$row) {
            $row['domain_count'] = Db::name('domain')->where('cid', $row['id'])->count();
        }

        return json(['total' => $total, 'rows' => $rows]);
    }

    public function category_op()
    {
        if (!checkPermission(2)) return json(['code' => -1, 'msg' => '无权限']);
        if (api_domains() !== null) return json(['code' => -1, 'msg' => '限定了域名范围的API密钥不能管理域名分类']);
        $action = input('param.action');
        if ($this->request->isApi && in_array($action, ['add', 'edit', 'del'])) {
            api_write_log('域名分类:' . $action, (string)(input('post.name') ?: input('post.id')));
        }
        if ($action == 'add') {
            $name = input('post.name', null, 'trim');
            $remark = input('post.remark', null, 'trim');
            $sort = input('post.sort/d', 0);
            if (empty($name)) return json(['code' => -1, 'msg' => '分类名称不能为空']);
            if (Db::name('domain_category')->where('name', $name)->find()) {
                return json(['code' => -1, 'msg' => '分类名称已存在']);
            }
            Db::name('domain_category')->insert([
                'name' => $name,
                'remark' => $remark,
                'sort' => $sort,
                'addtime' => date('Y-m-d H:i:s'),
            ]);
            return json(['code' => 0, 'msg' => '添加分类成功！']);
        } elseif ($action == 'edit') {
            $id = input('post.id/d');
            $row = Db::name('domain_category')->where('id', $id)->find();
            if (!$row) return json(['code' => -1, 'msg' => '分类不存在']);
            $name = input('post.name', null, 'trim');
            $remark = input('post.remark', null, 'trim');
            $sort = input('post.sort/d', 0);
            if (empty($name)) return json(['code' => -1, 'msg' => '分类名称不能为空']);
            if (Db::name('domain_category')->where('name', $name)->where('id', '<>', $id)->find()) {
                return json(['code' => -1, 'msg' => '分类名称已存在']);
            }
            Db::name('domain_category')->where('id', $id)->update([
                'name' => $name,
                'remark' => $remark,
                'sort' => $sort,
            ]);
            return json(['code' => 0, 'msg' => '修改分类成功！']);
        } elseif ($action == 'del') {
            $id = input('post.id/d');
            $count = Db::name('domain')->where('cid', $id)->count();
            if ($count > 0) return json(['code' => -1, 'msg' => '该分类下存在域名，无法删除']);
            Db::name('domain_category')->where('id', $id)->delete();
            return json(['code' => 0, 'msg' => '删除分类成功！']);
        }
        return json(['code' => -3]);
    }

    public function category_list()
    {
        if (!checkPermission(2)) return json(['code' => -1, 'msg' => '无权限']);
        $list = Db::name('domain_category')->order('sort', 'asc')->order('id', 'desc')->select();
        foreach ($list as &$row) {
            $row['domain_count'] = Db::name('domain')->where('cid', $row['id'])->count();
        }
        return json(['code' => 0, 'data' => $list]);
    }

    public function domain_set_category()
    {
        if (!checkPermission(2)) return json(['code' => -1, 'msg' => '无权限']);
        $ids = input('post.ids');
        $cid = input('post.cid/d', 0);
        if (empty($ids)) return json(['code' => -1, 'msg' => '请选择要操作的域名']);
        foreach (Db::name('domain')->where('id', 'in', (array)$ids)->column('name') as $name) {
            if (!api_domain_allowed($name)) return json(['code' => -1, 'msg' => '域名' . $name . '不在API密钥允许的范围内']);
        }
        $count = Db::name('domain')->where('id', 'in', $ids)->update(['cid' => $cid]);
        return json(['code' => 0, 'msg' => '成功设置' . $count . '个域名的分类！']);
    }
}
