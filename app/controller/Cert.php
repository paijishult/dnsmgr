<?php

namespace app\controller;

use app\BaseController;
use app\lib\CertHelper;
use app\lib\DeployHelper;
use app\service\CertOrderService;
use app\service\CertDeployService;
use Exception;
use think\facade\Db;
use think\facade\View;
use think\facade\Cache;

class Cert extends BaseController
{
    public function certaccount()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        return view();
    }

    public function deployaccount()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        return view();
    }

    public function account_data()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $deploy = input('get.deploy/d', 0);
        $kw = $this->request->post('kw', null, 'trim');
        $offset = input('post.offset/d');
        $limit = input('post.limit/d');
        $sort = input('post.sortName', null, 'trim');
        $orderDir = strtolower(input('post.sortOrder', 'desc')) === 'asc' ? 'asc' : 'desc';

        $select = Db::name('cert_account')->where('deploy', $deploy);
        if (!empty($kw)) {
            $select->where(function ($query) use ($kw) {
                // API不允许按账户名称搜索，防止通过搜索结果逐位猜出账户名称（AccessKey、面板地址等）
                $query->whereLike($this->request->isApi ? 'remark' : 'name|remark', '%' . $kw . '%')->whereOr('id', $kw);
            });
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
            if ($deploy == 1) {
                if (!empty($row['type']) && isset(DeployHelper::$deploy_config[$row['type']])) {
                    $row['typename'] = DeployHelper::$deploy_config[$row['type']]['name'];
                    $row['icon'] = DeployHelper::$deploy_config[$row['type']]['icon'];
                }
            } else {
                if (!empty($row['type']) && isset(CertHelper::$cert_config[$row['type']])) {
                    $row['typename'] = CertHelper::$cert_config[$row['type']]['name'];
                    $row['icon'] = CertHelper::$cert_config[$row['type']]['icon'];
                }
            }
            // 列表中不输出账户密钥配置；API只返回账户ID、类型和备注，不返回账户名称（面板地址、AccessKey、邮箱等）
            unset($row['config'], $row['ext']);
            if ($this->request->isApi) {
                unset($row['name']);
            }
            $list[] = $row;
        }

        return json(['total' => $total, 'rows' => $list]);
    }

    public function account_op()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $action = input('param.action');
        $deploy = input('post.deploy/d', 0);
        $title = $deploy == 1 ? '自动部署账户' : 'SSL证书账户';

        if ($action == 'add') {
            $type = input('post.type');
            $name = input('post.name', null, 'trim');
            $config = input('post.config', null, 'trim');
            $remark = input('post.remark', null, 'trim');
            if ($type == 'local') $name = '复制到本机';
            if (empty($name) || empty($config)) return json(['code' => -1, 'msg' => '必填参数不能为空']);
            if (Db::name('cert_account')->where('type', $type)->where('config', $config)->find()) {
                return json(['code' => -1, 'msg' => $title . '已存在']);
            }
            Db::startTrans();
            $id = Db::name('cert_account')->insertGetId([
                'type' => $type,
                'name' => $name,
                'config' => $config,
                'remark' => $remark,
                'deploy' => $deploy,
                'addtime' => date('Y-m-d H:i:s'),
            ]);
            try {
                $this->checkAccount($id, $type, $deploy);
                Db::commit();
                return json(['code' => 0, 'msg' => '添加' . $title . '成功！']);
            } catch (Exception $e) {
                Db::rollback();
                return json(['code' => -1, 'msg' => $e->getMessage()]);
            }
        } elseif ($action == 'edit') {
            $id = input('post.id/d');
            $row = Db::name('cert_account')->where('id', $id)->find();
            if (!$row) return json(['code' => -1, 'msg' => $title . '不存在']);
            $type = input('post.type');
            $name = input('post.name', null, 'trim');
            $config = input('post.config', null, 'trim');
            $remark = input('post.remark', null, 'trim');
            if ($type == 'local') $name = '复制到本机';
            if (empty($name) || empty($config)) return json(['code' => -1, 'msg' => '必填参数不能为空']);
            if (Db::name('cert_account')->where('type', $type)->where('config', $config)->where('id', '<>', $id)->find()) {
                return json(['code' => -1, 'msg' => $title . '已存在']);
            }
            Db::startTrans();
            Db::name('cert_account')->where('id', $id)->update([
                'type' => $type,
                'name' => $name,
                'config' => $config,
                'remark' => $remark,
            ]);
            try {
                $this->checkAccount($id, $type, $deploy);
                Db::commit();
                return json(['code' => 0, 'msg' => '修改' . $title . '成功！']);
            } catch (Exception $e) {
                Db::rollback();
                return json(['code' => -1, 'msg' => $e->getMessage()]);
            }
        } elseif ($action == 'del') {
            $id = input('post.id/d');
            if ($deploy == 0) {
                $dcount = DB::name('cert_order')->where('aid', $id)->count();
                if ($dcount > 0) return json(['code' => -1, 'msg' => '该' . $title . '下存在证书订单，无法删除']);
            } else {
                $dcount = DB::name('cert_deploy')->where('aid', $id)->count();
                if ($dcount > 0) return json(['code' => -1, 'msg' => '该' . $title . '下存在自动部署任务，无法删除']);
            }
            Db::name('cert_account')->where('id', $id)->delete();
            return json(['code' => 0]);
        } elseif ($action == 'check') {
            // 测试已保存的账户是否可用：部署账户只做连通性/登录校验，不会部署证书；
            // SSL证书账户只查询账户状态（ACME 用 onlyReturnExisting），不会注册新账户或申请证书
            $id = input('post.id/d');
            $row = Db::name('cert_account')->where('id', $id)->where('deploy', $deploy)->find();
            if (!$row) return json(['code' => -1, 'msg' => $title . '不存在']);
            if ($deploy == 1) {
                return json($this->testDeployModel(DeployHelper::getModel($id), $row['type']));
            }
            return json($this->testCertModel(CertHelper::getModel($id), $row['type']));
        } elseif ($action == 'test') {
            // 添加/编辑页面上，用当前填写但尚未保存的配置测试
            if ($deploy != 1) return json(['code' => -1, 'msg' => '仅支持测试自动部署账户']);
            $type = input('post.type');
            $config = json_decode(input('post.config', '', 'trim'), true);
            if (empty($type) || !isset(DeployHelper::$deploy_config[$type])) return json(['code' => -1, 'msg' => '部署类型不存在']);
            if (!is_array($config)) return json(['code' => -1, 'msg' => '账户配置不能为空']);
            return json($this->testDeployModel(DeployHelper::getModel2($type, $config), $type));
        }
        return json(['code' => -3]);
    }

    private function testCertModel($mod, $type)
    {
        if (!$mod) return ['code' => -1, 'msg' => 'SSL证书申请模块' . $type . '不存在'];
        if (!method_exists($mod, 'check')) return ['code' => -1, 'msg' => '该类型暂不支持在线测试'];
        $mod->setLogger(function ($txt) {
        });
        @set_time_limit(120);
        $start = microtime(true);
        try {
            $mod->check();
            return ['code' => 0, 'msg' => '账户可用', 'time' => intval(round((microtime(true) - $start) * 1000))];
        } catch (\Throwable $e) {
            $msg = trim($e->getMessage());
            return ['code' => -1, 'msg' => $msg !== '' ? $msg : '测试失败（未返回错误信息）', 'time' => intval(round((microtime(true) - $start) * 1000))];
        }
    }

    private function testDeployModel($mod, $type)
    {
        if (!$mod) return ['code' => -1, 'msg' => '自动部署模块' . $type . '不存在'];
        @set_time_limit(120);
        $start = microtime(true);
        try {
            $mod->check();
            return ['code' => 0, 'msg' => '账户可用', 'time' => intval(round((microtime(true) - $start) * 1000))];
        } catch (\Throwable $e) {
            $msg = trim($e->getMessage());
            return ['code' => -1, 'msg' => $msg !== '' ? $msg : '测试失败（未返回错误信息）', 'time' => intval(round((microtime(true) - $start) * 1000))];
        }
    }

    public function account_form()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $action = input('param.action');
        $deploy = input('get.deploy/d', 0);
        $title = $deploy == 1 ? '自动部署账户' : 'SSL证书账户';

        $account = null;
        if ($action == 'edit') {
            $id = input('get.id/d');
            $account = Db::name('cert_account')->where('id', $id)->find();
            if (empty($account)) return $this->alert('error', $title . '不存在');
        }

        $typeList = $deploy == 1 ? DeployHelper::getList() : CertHelper::getList();
        $classList = $deploy == 1 ? DeployHelper::$class_config : CertHelper::$class_config;

        View::assign('title', $title);
        View::assign('info', $account);
        View::assign('typeList', $typeList);
        View::assign('classList', $classList);
        View::assign('action', $action);
        View::assign('deploy', $deploy);
        return View::fetch();
    }

    private function checkAccount($id, $type, $deploy)
    {
        if ($deploy == 0) {
            $mod = CertHelper::getModel($id);
            if ($mod) {
                try {
                    $ext = $mod->register();
                    if (is_array($ext)) {
                        Db::name('cert_account')->where('id', $id)->update(['ext' => json_encode($ext)]);
                    }
                    return true;
                } catch (Exception $e) {
                    throw new Exception('验证SSL证书账户失败，' . $e->getMessage());
                }
            } else {
                throw new Exception('SSL证书申请模块' . $type . '不存在');
            }
        } else {
            $mod = DeployHelper::getModel($id);
            if ($mod) {
                try {
                    $mod->check();
                    return true;
                } catch (Exception $e) {
                    throw new Exception('验证自动部署账户失败，' . $e->getMessage());
                }
            } else {
                throw new Exception('SSL证书申请模块' . $type . '不存在');
            }
        }
    }

    public function certorder()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $types = [];
        foreach (CertHelper::$cert_config as $key => $value) {
            $types[$key] = $value['name'];
        }
        View::assign('types', $types);
        return view();
    }

    public function order_data()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $domain = $this->request->post('domain', null, 'trim');
        $id = input('post.id');
        $aid = input('post.aid', null, 'trim');
        $type = input('post.type', null, 'trim');
        $status = input('post.status', null, 'trim');
        $offset = input('post.offset/d');
        $limit = input('post.limit/d');
        $sort = input('post.sortName', null, 'trim');
        $orderDir = strtolower(input('post.sortOrder', 'desc')) === 'asc' ? 'asc' : 'desc';

        $select = Db::name('cert_order')->alias('A')->leftJoin('cert_account B', 'A.aid = B.id');
        if (api_domains() !== null) {
            $select->whereIn('A.id', $this->apiAllowedOrderIds() ?: [0]);
        }
        if (!empty($id)) {
            $select->where('A.id', $id);
        } elseif (!empty($domain)) {
            $oids = Db::name('cert_domain')->where('domain', 'like', '%' . $domain . '%')->column('oid');
            $select->whereIn('A.id', $oids);
        }
        if (!empty($aid)) {
            $select->where('A.aid', $aid);
        }
        if (!empty($type)) {
            $select->where('B.type', $type);
        }
        if (!isNullOrEmpty($status)) {
            if ($status == '5') {
                $select->where('A.status', '<', 0);
            } elseif ($status == '6') {
                $select->where('A.expiretime', '<', date('Y-m-d H:i:s', time() + 86400 * 7))->where('A.expiretime', '>=', date('Y-m-d H:i:s'));
            } elseif ($status == '7') {
                $select->where('A.expiretime', '<', date('Y-m-d H:i:s'));
            } else {
                $select->where('A.status', $status);
            }
        }
        $total = $select->count();
        $allowedSort = ['id' => 'A.id', 'typename' => 'B.type', 'keytype' => 'A.keytype', 'isauto' => 'A.isauto', 'issuetime' => 'A.issuetime', 'end_day' => 'A.expiretime', 'status' => 'A.status'];
        if ($sort && isset($allowedSort[$sort])) {
            $select->order($allowedSort[$sort], $orderDir);
        } else {
            $select->order('A.id', 'desc');
        }
        $rows = $select->fieldRaw('A.*,B.type,B.remark aremark')->limit($offset, $limit)->select()->toArray();
        $domainsByOrder = $this->orderDomainMap(array_column($rows, 'id'));

        $list = [];
        foreach ($rows as $row) {
            if (!empty($row['type']) && isset(CertHelper::$cert_config[$row['type']])) {
                $row['typename'] = CertHelper::$cert_config[$row['type']]['name'];
                $row['icon'] = CertHelper::$cert_config[$row['type']]['icon'];
            } else {
                $row['typename'] = null;
            }
            unset($row['fullchain'], $row['privatekey']);
            $row['domains'] = $domainsByOrder[$row['id']] ?? [];
            $row['end_day'] = $row['expiretime'] ? ceil((strtotime($row['expiretime']) - time()) / 86400) : null;
            if ($row['error']) $row['error'] = htmlspecialchars(str_replace("'", "\\'", $row['error']));
            $list[] = $row;
        }

        return json(['total' => $total, 'rows' => $list]);
    }

    public function order_info()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $id = input('post.id/d');
        $row = Db::name('cert_order')->where('id', $id)->find();
        if (!$row || !$this->apiOrderAllowed($id)) return json(['code' => -1, 'msg' => '证书订单不存在']);
        if (empty($row['fullchain']) || empty($row['privatekey'])) return json(['code' => -1, 'msg' => '证书尚未签发']);
        $this->apiLog('获取证书', '订单ID:' . $id);
        $pfx = CertHelper::getPfx($row['fullchain'], $row['privatekey']);
        $row['pfx'] = base64_encode($pfx);
        return json(['code' => 0, 'data' => ['id' => $row['id'], 'crt' => $row['fullchain'], 'key' => $row['privatekey'], 'pfx' => $row['pfx'], 'issuetime' => $row['issuetime'], 'expiretime' => $row['expiretime'], 'domains' => Db::name('cert_domain')->where('oid', $row['id'])->order('sort', 'ASC')->column('domain')]]);
    }

    public function order_op()
    {
        try {
            if (checkPermission(2) && in_array(input('param.action'), ['edit', 'setauto'], true)) {
                return $this->certificateMutation('cert-order:' . input('post.id/d'), fn() => $this->orderOperation());
            }
            return $this->orderOperation();
        } catch (\Throwable $e) { return json(['code' => -1, 'msg' => $e->getMessage()]); }
    }

    private function orderOperation()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $action = input('param.action');
        if (api_domains() !== null) {
            $ids = input('?post.ids') ? (array)input('post.ids') : (input('?post.id') ? [input('post.id/d')] : []);
            foreach ($ids as $oid) {
                if (!$this->apiOrderAllowed($oid)) return json(['code' => -1, 'msg' => '证书订单不存在或不在API密钥允许的域名范围内']);
            }
            if (in_array($action, ['add', 'edit'])) {
                $checkDomains = input('post.aid/d') == -1 ? [] : (array)input('post.domains', []);
                if (input('post.aid/d') == -1 && input('post.fullchain')) {
                    $info = @openssl_x509_parse((string)input('post.fullchain'));
                    $san = $info['extensions']['subjectAltName'] ?? '';
                    $checkDomains = array_map(fn($v) => trim(str_replace('DNS:', '', $v)), explode(',', $san));
                }
                foreach ($checkDomains as $d) {
                    if (!is_string($d) || trim($d) === '') continue;
                    if (!api_domain_allowed($d)) return json(['code' => -1, 'msg' => '域名' . $d . '不在API密钥允许的范围内']);
                }
            }
        }
        if ($this->request->isApi && in_array($action, ['add', 'edit', 'del', 'setauto', 'reset', 'revoke', 'operation'])) {
            $this->apiLog('证书订单:' . $action, $action == 'add' ? implode(',', (array)input('post.domains', [])) : 'ID:' . (input('post.id') ?: implode(',', (array)input('post.ids', []))));
        }

        if ($action == 'get') {
            $id = input('post.id/d');
            $row = Db::name('cert_order')->where('id', $id)->field('fullchain,privatekey')->find();
            if (!$row) return $this->alert('error', '证书订单不存在');
            $pfx = CertHelper::getPfx($row['fullchain'], $row['privatekey']);
            $row['pfx'] = base64_encode($pfx);
            return json(['code' => 0, 'data' => $row]);
        } elseif ($action == 'add') {
            $aid = input('post.aid/d');

            if ($aid == -1) {
                $fullchain = input('post.fullchain', null, 'trim');
                $privatekey = input('post.privatekey', null, 'trim');
                $certInfo = $this->parse_cert_key($fullchain, $privatekey);
                if ($certInfo['code'] == -1) return json($certInfo);
                $domains = $certInfo['domains'];

                $order_ids = Db::name('cert_order')->where('issuetime', $certInfo['issuetime'])->column('id');
                if (!empty($order_ids)) {
                    foreach ($order_ids as $order_id) {
                        $domains2 = Db::name('cert_domain')->where('oid', $order_id)->column('domain');
                        if (arrays_are_equal($domains2, $domains)) {
                            return json(['code' => -1, 'msg' => '该证书已存在，无需重复添加']);
                        }
                    }
                }

                $order = [
                    'aid' => 0,
                    'keytype' => $certInfo['keytype'],
                    'keysize' => $certInfo['keysize'],
                    'addtime' => date('Y-m-d H:i:s'),
                    'updatetime' => date('Y-m-d H:i:s'),
                    'issuetime' => $certInfo['issuetime'],
                    'expiretime' => $certInfo['expiretime'],
                    'issuer' => $certInfo['issuer'],
                    'status' => 3,
                    'isauto' => 1,
                    'fullchain' => $fullchain,
                    'privatekey' => $privatekey,
                ];
            } else {
                $order = [
                    'aid' => $aid,
                    'keytype' => input('post.keytype'),
                    'keysize' => input('post.keysize'),
                    'addtime' => date('Y-m-d H:i:s'),
                    'issuer' => '',
                    'status' => 0,
                    'isauto' => 1,
                ];
                $domains = input('post.domains', [], 'trim');
                $domains = array_map('trim', $domains);
                $domains = array_filter($domains, function ($v) {
                    return !empty($v);
                });
                $domains = array_unique($domains);
                if (empty($domains)) return json(['code' => -1, 'msg' => '绑定域名不能为空']);
                $res = $this->check_order($order, $domains);
                if (is_array($res)) return json($res);
            }
            if (empty($order['keytype']) || empty($order['keysize'])) return json(['code' => -1, 'msg' => '必填参数不能为空']);

            Db::startTrans();
            $id = Db::name('cert_order')->insertGetId($order);
            $domainList = [];
            $i = 1;
            foreach ($domains as $domain) {
                $domainList[] = [
                    'oid' => $id,
                    'domain' => convertDomainToAscii($domain),
                    'sort' => $i++,
                ];
            }
            Db::name('cert_domain')->insertAll($domainList);
            Db::commit();
            return json(['code' => 0, 'msg' => '添加证书订单成功！']);
        } elseif ($action == 'edit') {
            $id = input('post.id/d');
            $row = Db::name('cert_order')->where('id', $id)->find();
            if (!$row) return json(['code' => -1, 'msg' => '证书订单不存在']);

            $aid = input('post.aid/d');
            if ($aid == -1) {
                $fullchain = input('post.fullchain', null, 'trim');
                $privatekey = input('post.privatekey', null, 'trim');
                $certInfo = $this->parse_cert_key($fullchain, $privatekey);
                if ($certInfo['code'] == -1) return json($certInfo);
                $domains = $certInfo['domains'];

                $order = [
                    'aid' => 0,
                    'keytype' => $certInfo['keytype'],
                    'keysize' => $certInfo['keysize'],
                    'updatetime' => date('Y-m-d H:i:s'),
                    'issuetime' => $certInfo['issuetime'],
                    'expiretime' => $certInfo['expiretime'],
                    'issuer' => $certInfo['issuer'],
                    'status' => 3,
                    'issend' => 0,
                    'fullchain' => $fullchain,
                    'privatekey' => $privatekey,
                ];
            } else {
                $domains = input('post.domains', [], 'trim');
                $order = [
                    'aid' => $aid,
                    'keytype' => input('post.keytype'),
                    'keysize' => input('post.keysize'),
                    'updatetime' => date('Y-m-d H:i:s'),
                ];
                $domains = array_map('trim', $domains);
                $domains = array_filter($domains, function ($v) {
                    return !empty($v);
                });
                $domains = array_unique($domains);
                if (empty($domains)) return json(['code' => -1, 'msg' => '绑定域名不能为空']);
                $res = $this->check_order($order, $domains);
                if (is_array($res)) return json($res);
            }
            if (empty($order['keytype']) || empty($order['keysize'])) return json(['code' => -1, 'msg' => '必填参数不能为空']);

            Db::startTrans();
            Db::name('cert_order')->where('id', $id)->update($order);
            Db::name('cert_domain')->where('oid', $id)->delete();
            $domainList = [];
            $i = 1;
            foreach ($domains as $domain) {
                $domainList[] = [
                    'oid' => $id,
                    'domain' => convertDomainToAscii($domain),
                    'sort' => $i++,
                ];
            }
            Db::name('cert_domain')->insertAll($domainList);
            if (isset($order['fullchain']) && $order['fullchain'] !== ($row['fullchain'] ?? null)) {
                Db::name('cert_deploy')->where('oid', $id)->update(['status' => 0, 'retry' => 0, 'retrytime' => null, 'issend' => 0]);
            }
            Db::commit();
            return json(['code' => 0, 'msg' => '修改证书订单成功！']);
        } elseif ($action == 'del') {
            $id = input('post.id/d');
            $this->deleteCertificateOrder($id);
            return json(['code' => 0]);
        } elseif ($action == 'setauto') {
            $id = input('post.id/d');
            $isauto = input('post.isauto/d');
            Db::name('cert_order')->where('id', $id)->update(['isauto' => $isauto]);
            return json(['code' => 0]);
        } elseif ($action == 'reset') {
            $id = input('post.id/d');
            try {
                $service = new CertOrderService($id, $this->certificateOrderAuthorization());
                $service->cancel();
                $service->reset();
                return json(['code' => 0]);
            } catch (Exception $e) {
                return json(['code' => -1, 'msg' => $e->getMessage()]);
            }
        } elseif ($action == 'revoke') {
            $id = input('post.id/d');
            try {
                $service = new CertOrderService($id, $this->certificateOrderAuthorization());
                $service->revoke();
                return json(['code' => 0]);
            } catch (Exception $e) {
                return json(['code' => -1, 'msg' => $e->getMessage()]);
            }
        } elseif ($action == 'show_log') {
            $processid = input('post.processid');
            if ($this->request->isApi && !$processid && input('post.id/d')) {
                $processid = Db::name('cert_order')->where('id', input('post.id/d'))->value('processid');
            }
            if ($this->request->isApi && api_domains() !== null) {
                $oid = Db::name('cert_order')->where('processid', (string)$processid)->value('id');
                if (!$oid || !$this->apiOrderAllowed($oid)) return json(['code' => -1, 'msg' => '日志文件不存在']);
            }
            $file = $this->getProcessLogFile($processid);
            if ($file === false) return json(['code' => -1, 'msg' => '日志文件不存在']);
            return json(['code' => 0, 'data' => $this->filterLog(file_get_contents($file)), 'time' => filemtime($file)]);
        } elseif ($action == 'operation') {
            $ids = input('post.ids');
            if (!is_array($ids) || empty($ids)) return json(['code' => -1, 'msg' => '请选择要操作的证书订单']);
            $success = 0;
            foreach ($ids as $id) {
                $id = (int)$id;
                try {
                if (input('post.act') == 'delete') {
                    $this->deleteCertificateOrder($id);
                    $success++;
                } elseif (input('post.act') == 'reset') {
                    try {
                        $service = new CertOrderService($id, $this->certificateOrderAuthorization());
                        $service->cancel();
                        $service->reset();
                        $success++;
                    } catch (Exception $e) {
                    }
                } elseif (input('post.act') == 'open' || input('post.act') == 'close') {
                    $isauto = input('post.act') == 'open' ? 1 : 0;
                    $this->certificateMutation('cert-order:' . $id, fn() => Db::name('cert_order')->where('id', $id)->update(['isauto' => $isauto]));
                    $success++;
                }
                } catch (\Throwable $e) { /* Busy orders are retained for a later retry. */ }
            }
            return json(['code' => 0, 'msg' => '成功操作' . $success . '个证书订单']);
        }
        return json(['code' => -3]);
    }

    private function check_order($order, $domains)
    {
        $account = Db::name('cert_account')->where('id', $order['aid'])->find();
        if (!$account) return ['code' => -1, 'msg' => 'SSL证书账户不存在'];
        if (empty($account['type']) || !isset(CertHelper::$cert_config[$account['type']])) {
            return ['code' => -1, 'msg' => 'SSL证书账户类型不存在'];
        }
        $max_domains = CertHelper::$cert_config[$account['type']]['max_domains'];
        $wildcard = CertHelper::$cert_config[$account['type']]['wildcard'];
        $cname = CertHelper::$cert_config[$account['type']]['cname'];
        if (count($domains) > $max_domains) {
            if (!(count($domains) == 2 && $max_domains == 1 && ltrim($domains[0], 'www.') == ltrim($domains[1], 'www.'))) {
                return ['code' => -1, 'msg' => '域名数量不能超过' . $max_domains . '个'];
            }
        }

        foreach ($domains as $domain) {
            if (!$wildcard && strpos($domain, '*') !== false) return ['code' => -1, 'msg' => '该证书账户类型不支持泛域名'];
            $mainDomain = getMainDomain($domain);
            $drow = Db::name('domain')->where('name', $mainDomain)->find();
            if (!$drow) {
                $drow = Db::name('domain_alias')->alias('A')->join('domain B', 'A.did = B.id')->where('A.name', $mainDomain)->find();
                if (!$drow) {
                    if (substr($domain, 0, 2) == '*.') $domain = substr($domain, 2);
                    if (!$cname || !Db::name('cert_cname')->where('domain', $domain)->where('status', 1)->find()) {
                        return ['code' => -1, 'msg' => '域名' . $domain . '未在本系统添加'];
                    }
                }
            }
        }
        return true;
    }

    private function parse_cert_key($fullchain, $privatekey)
    {
        if (!openssl_x509_read($fullchain)) return ['code' => -1, 'msg' => '证书内容填写错误'];
        if (!openssl_get_privatekey($privatekey)) return ['code' => -1, 'msg' => '私钥内容填写错误'];
        if (!openssl_x509_check_private_key($fullchain, $privatekey)) return ['code' => -1, 'msg' => 'SSL证书与私钥不匹配'];
        $certInfo = openssl_x509_parse($fullchain, true);
        if (!$certInfo || !isset($certInfo['extensions']['subjectAltName'])) return ['code' => -1, 'msg' => '证书内容解析失败'];

        $pubKey = openssl_pkey_get_public($fullchain);
        if (!$pubKey) return ['code' => -1, 'msg' => '证书公钥解析失败'];
        $keyDetails = openssl_pkey_get_details($pubKey);
        $keytype = null;
        $keysize = 0;
        switch ($keyDetails['type']) {
            case OPENSSL_KEYTYPE_RSA:
                $keytype = 'RSA';
                $keysize = $keyDetails['bits'];
                break;
            case OPENSSL_KEYTYPE_EC:
                $keytype = 'ECC';
                $keysize = $keyDetails['bits'];
                break;
            case OPENSSL_KEYTYPE_DSA:
                $keytype = 'DSA';
                $keysize = $keyDetails['bits'];
                break;
            default:
                $keytype = 'Unknown';
        }

        $domains = [];
        $subjectAltName = explode(',', $certInfo['extensions']['subjectAltName']);
        foreach ($subjectAltName as $domain) {
            $domain = trim($domain);
            if (strpos($domain, 'DNS:') === 0) $domain = substr($domain, 4);
            if (!empty($domain)) {
                $domains[] = $domain;
            }
        }
        $domains = array_unique($domains);
        if (empty($domains)) return ['code' => -1, 'msg' => '证书绑定域名不能为空'];
        $issuetime = date('Y-m-d H:i:s', $certInfo['validFrom_time_t']);
        $expiretime = date('Y-m-d H:i:s', $certInfo['validTo_time_t']);
        $issuer = $certInfo['issuer']['CN'] ?? ($certInfo['issuer']['O'] ?? '');
        return [
            'code' => 0,
            'keytype' => $keytype,
            'keysize' => $keysize,
            'issuetime' => $issuetime,
            'expiretime' => $expiretime,
            'issuer' => $issuer,
            'domains' => $domains,
        ];
    }

    public function order_process()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        if (function_exists("set_time_limit")) {
            @set_time_limit(0);
        }
        if (function_exists("ignore_user_abort")) {
            @ignore_user_abort(true);
        }
        $id = input('post.id/d');
        $reset = input('post.reset/d', 0);
        if (!$this->apiOrderAllowed($id)) return json(['code' => -1, 'msg' => '证书订单不存在']);
        $this->apiLog($reset == 1 ? '重新申请证书' : '执行证书申请', '订单ID:' . $id);
        try {
            $service = new CertOrderService($id, $this->certificateOrderAuthorization());
            if ($reset == 1) {
                $service->reset();
            }
            $retcode = $service->process(true);
            if ($retcode == 3) {
                return json(['code' => 0, 'msg' => '证书已签发成功！']);
            } elseif ($retcode == 1) {
                return json(['code' => 0, 'msg' => '添加DNS记录成功！请等待DNS生效后点击验证']);
            }
            return json(['code' => 0, 'msg' => '证书处理完成']);
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => \app\lib\AuditHelper::scrub($e->getMessage())]);
        }
    }

    public function order_form()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $action = input('param.action');

        $order = null;
        if ($action == 'edit') {
            $id = input('get.id/d');
            $order = Db::name('cert_order')->where('id', $id)->fieldRaw('id,aid,keytype,keysize,status,fullchain,privatekey')->find();
            if (empty($order)) return $this->alert('error', '证书订单不存在');
            $order['domains'] = Db::name('cert_domain')->where('oid', $order['id'])->order('sort', 'ASC')->column('domain');
            if ($order['aid'] == 0) $order['aid'] = -1;
        }

        $accounts = [];
        foreach (Db::name('cert_account')->where('deploy', 0)->select() as $row) {
            if (empty($row['type']) || !isset(CertHelper::$cert_config[$row['type']])) continue;
            $accounts[$row['id']] = ['name' => $row['id'] . '_' . CertHelper::$cert_config[$row['type']]['name'], 'type' => $row['type']];
            if (!empty($row['remark'])) {
                $accounts[$row['id']]['name'] .= '（' . $row['remark'] . '）';
            }
        }
        View::assign('accounts', $accounts);

        View::assign('info', $order);
        View::assign('action', $action);
        return View::fetch();
    }

    public function deploytask()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $types = [];
        foreach (DeployHelper::$deploy_config as $key => $value) {
            $types[$key] = $value['name'];
        }
        View::assign('types', $types);
        return view();
    }

    public function deploy_data()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $domain = $this->request->post('domain', null, 'trim');
        $oid = input('post.oid');
        $aid = input('post.aid', null, 'trim');
        $type = input('post.type', null, 'trim');
        $status = input('post.status', null, 'trim');
        $remark = input('post.remark', null, 'trim');
        $offset = input('post.offset/d');
        $limit = input('post.limit/d');
        $sort = input('post.sortName', null, 'trim');
        $orderDir = strtolower(input('post.sortOrder', 'desc')) === 'asc' ? 'asc' : 'desc';

        $select = Db::name('cert_deploy')->alias('A')->leftJoin('cert_account B', 'A.aid = B.id')->leftJoin('cert_order C', 'A.oid = C.id')->leftJoin('cert_account D', 'C.aid = D.id');
        if (api_domains() !== null) {
            $select->whereIn('A.oid', $this->apiAllowedOrderIds() ?: [0]);
        }
        if (!empty($oid)) {
            $select->where('A.oid', $oid);
        } elseif (!empty($domain)) {
            $oids = Db::name('cert_domain')->where('domain', 'like', '%' . $domain . '%')->column('oid');
            $select->whereIn('oid', $oids);
        }
        if (!empty($aid)) {
            $select->where('A.aid', $aid);
        }
        if (!empty($type)) {
            $select->where('B.type', $type);
        }
        if (!isNullOrEmpty($status)) {
            $select->where('A.status', $status);
        }
        if (!empty($remark)) {
            $select->where('A.remark', $remark);
        }
        $total = $select->count();
        $allowedSort = ['id' => 'A.id', 'typename' => 'B.type', 'remark' => 'A.remark', 'active' => 'A.active', 'lasttime' => 'A.lasttime', 'status' => 'A.status'];
        if ($sort && isset($allowedSort[$sort])) {
            $select->order($allowedSort[$sort], $orderDir);
        } else {
            $select->order('A.id', 'desc');
        }
        $rows = $select->fieldRaw('A.*,B.type,B.remark aremark,B.name aname,D.type certtype,D.id certaid')->limit($offset, $limit)->select()->toArray();
        $domainsByOrder = $this->orderDomainMap(array_column($rows, 'oid'));

        $list = [];
        foreach ($rows as $row) {
            if (!empty($row['type']) && isset(DeployHelper::$deploy_config[$row['type']])) {
                $row['typename'] = DeployHelper::$deploy_config[$row['type']]['name'];
                $row['icon'] = DeployHelper::$deploy_config[$row['type']]['icon'];
            }
            if (!empty($row['certtype']) && isset(CertHelper::$cert_config[$row['certtype']])) {
                $row['certtypename'] = CertHelper::$cert_config[$row['certtype']]['name'];
            } else {
                $row['certtypename'] = '手动续期';
            }
            $row['domains'] = $domainsByOrder[$row['oid']] ?? [];
            if ($row['error']) $row['error'] = htmlspecialchars(str_replace("'", "\\'", $row['error']));
            $info = json_decode($row['info'] ?? '', true);
            $row['verification'] = isset($info['_tls_verification']) && is_array($info['_tls_verification']) ? $info['_tls_verification'] : null;
            unset($row['info']);
            if ($this->request->isApi) {
                // API只输出脱敏后的任务配置与部署账户名称
                $config = json_decode($row['config'] ?? '', true);
                $row['config'] = is_array($config) ? \app\lib\ApiKeyHelper::redact($config) : null;
                unset($row['aname']);
                $row['api_writable'] = self::deployTypeApiWritable($row['type']);
            }
            $list[] = $row;
        }

        return json(['total' => $total, 'rows' => $list]);
    }

    public function deploy_op()
    {
        try {
            $action = input('param.action');
            if (checkPermission(2) && in_array($action, ['edit', 'del', 'setactive'], true)) {
                return $this->certificateMutation('cert-deploy:' . input('post.id/d'), function () use ($action) {
                    if ($action === 'edit') return $this->certificateMutation('cert-order:' . input('post.oid/d'), fn() => $this->deployOperation());
                    return $this->deployOperation();
                });
            }
            if (checkPermission(2) && $action === 'add') return $this->certificateMutation('cert-order:' . input('post.oid/d'), fn() => $this->deployOperation());
            return $this->deployOperation();
        } catch (\Throwable $e) { return json(['code' => -1, 'msg' => $e->getMessage()]); }
    }

    private function deployOperation()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $action = input('param.action');
        if ($this->request->isApi) {
            $res = $this->apiDeployCheck($action);
            if ($res !== true) return json(['code' => -1, 'msg' => $res]);
        }

        $normalizedConfig = null;
        if (in_array($action, ['add', 'edit'], true)) {
            try { $normalizedConfig = $this->normalizeDeployConfig(input('post.config'), input('post.aid/d'), input('post.oid/d')); }
            catch (\Throwable $e) { return json(['code' => -1, 'msg' => $e->getMessage()]); }
        }
        if ($action == 'add') {
            $task = [
                'aid' => input('post.aid/d'),
                'oid' => input('post.oid/d'),
                'config' => $normalizedConfig,
                'remark' => input('post.remark', null, 'trim'),
                'addtime' => date('Y-m-d H:i:s'),
                'status' => 0,
                'active' => 1
            ];
            if (empty($task['aid']) || empty($task['oid']) || empty($task['config'])) return json(['code' => -1, 'msg' => '必填参数不能为空']);
            Db::name('cert_deploy')->insert($task);
            return json(['code' => 0, 'msg' => '添加自动部署任务成功！']);
        } elseif ($action == 'edit') {
            $id = input('post.id/d');
            $row = Db::name('cert_deploy')->where('id', $id)->find();
            if (!$row) return json(['code' => -1, 'msg' => '自动部署任务不存在']);

            $task = [
                'aid' => input('post.aid/d'),
                'oid' => input('post.oid/d'),
                'config' => $normalizedConfig,
                'remark' => input('post.remark', null, 'trim'),
            ];
            if (empty($task['aid']) || empty($task['oid']) || empty($task['config'])) return json(['code' => -1, 'msg' => '必填参数不能为空']);
            $oldConfig = json_decode($row['config'], true) ?: [];
            $newConfig = json_decode($task['config'], true);
            $oldVerification = $oldConfig['_verify_tls'] ?? ['enabled' => false];
            $newVerification = $newConfig['_verify_tls'];
            unset($oldConfig['_verify_tls'], $newConfig['_verify_tls']);
            if ((int)$row['aid'] !== (int)$task['aid'] || (int)$row['oid'] !== (int)$task['oid'] || $oldConfig != $newConfig) {
                $task += ['status' => 0, 'info' => null, 'retry' => 0, 'retrytime' => null, 'error' => null, 'issend' => 0];
            } elseif ($oldVerification != $newVerification && in_array((int)$row['status'], [1, 2], true)) {
                $info = json_decode($row['info'] ?? '', true) ?: [];
                if (!empty($newVerification['enabled'])) {
                    $pem = Db::name('cert_order')->where('id', $task['oid'])->value('fullchain');
                    $info['_tls_verification'] = ['status' => 'pending', 'host' => $newVerification['host'], 'port' => $newVerification['port'], 'expected_sha256' => \app\service\CertVerificationService::fingerprint((string)$pem), 'observed_sha256' => null, 'checked_at' => null, 'error' => null];
                } else unset($info['_tls_verification']);
                $task += ['status' => empty($newVerification['enabled']) ? 1 : 2, 'info' => json_encode($info), 'retry' => 0, 'retrytime' => null, 'error' => null, 'issend' => 0];
            }
            Db::name('cert_deploy')->where('id', $id)->update($task);
            return json(['code' => 0, 'msg' => '修改自动部署任务成功！']);
        } elseif ($action == 'del') {
            $id = input('post.id/d');
            Db::name('cert_deploy')->where('id', $id)->delete();
            return json(['code' => 0]);
        } elseif ($action == 'setactive') {
            $id = input('post.id/d');
            $active = input('post.active/d');
            Db::name('cert_deploy')->where('id', $id)->update(['active' => $active]);
            return json(['code' => 0]);
        } elseif ($action == 'reset') {
            $id = input('post.id/d');
            try {
                $service = new CertDeployService($id, $this->certificateOrderAuthorization());
                $service->reset();
                return json(['code' => 0]);
            } catch (Exception $e) {
                return json(['code' => -1, 'msg' => $e->getMessage()]);
            }
        } elseif ($action == 'show_log') {
            $processid = input('post.processid');
            if ($this->request->isApi && !$processid && input('post.id/d')) {
                $processid = Db::name('cert_deploy')->where('id', input('post.id/d'))->value('processid');
            }
            if ($this->request->isApi && api_domains() !== null) {
                $oid = Db::name('cert_deploy')->where('processid', (string)$processid)->value('oid');
                if (!$oid || !$this->apiOrderAllowed($oid)) return json(['code' => -1, 'msg' => '日志文件不存在']);
            }
            $file = $this->getProcessLogFile($processid);
            if ($file === false) return json(['code' => -1, 'msg' => '日志文件不存在']);
            return json(['code' => 0, 'data' => $this->filterLog(file_get_contents($file)), 'time' => filemtime($file)]);
        } elseif ($action == 'operation') {
            $ids = input('post.ids');
            if (!is_array($ids) || empty($ids)) return json(['code' => -1, 'msg' => '请选择要操作的任务']);
            $success = 0;
            $certid = 0;
            if (input('post.act') == 'cert') {
                $certid = input('post.certid/d');
                $cert = Db::name('cert_order')->where('id', $certid)->find();
                if (!$cert) return json(['code' => -1, 'msg' => '证书订单不存在']);
            }
            foreach ($ids as $id) {
                $id = (int)$id;
                try {
                if (input('post.act') == 'delete') {
                    $this->certificateMutation('cert-deploy:' . $id, function () use ($id) {
                        $this->authorizeDeploymentMutation($id);
                        Db::name('cert_deploy')->where('id', $id)->delete();
                    });
                    $success++;
                } elseif (input('post.act') == 'reset') {
                    try {
                        $service = new CertDeployService($id, $this->certificateOrderAuthorization());
                        $service->reset();
                        $success++;
                    } catch (Exception $e) {
                    }
                } elseif (input('post.act') == 'open' || input('post.act') == 'close') {
                    $active = input('post.act') == 'open' ? 1 : 0;
                    $this->certificateMutation('cert-deploy:' . $id, function () use ($id, $active) {
                        $this->authorizeDeploymentMutation($id);
                        Db::name('cert_deploy')->where('id', $id)->update(['active' => $active]);
                    });
                    $success++;
                } elseif (input('post.act') == 'cert') {
                    $this->certificateMutation('cert-deploy:' . $id, function () use ($id, $certid) {
                        $this->authorizeDeploymentMutation($id);
                        $this->certificateMutation('cert-order:' . $certid, function () use ($id, $certid) {
                            if (!$this->apiOrderAllowed($certid)) throw new \RuntimeException('证书订单不存在');
                            $task = Db::name('cert_deploy')->where('id', $id)->find();
                            if (!$task) throw new \RuntimeException('自动部署任务不存在');
                            $config = $this->normalizeDeployConfig($task['config'], (int)$task['aid'], $certid);
                            Db::name('cert_deploy')->where('id', $id)->update(['oid' => $certid, 'config' => $config, 'status' => 0, 'retry' => 0, 'retrytime' => null, 'error' => null, 'issend' => 0]);
                        });
                    });
                    $success++;
                }
                } catch (\Throwable $e) { /* Busy or invalid tasks remain unchanged. */ }
            }
            return json(['code' => 0, 'msg' => '成功操作' . $success . '个任务']);
        }
        return json(['code' => -3]);
    }

    public function deploy_process()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        if (function_exists("set_time_limit")) {
            @set_time_limit(0);
        }
        if (function_exists("ignore_user_abort")) {
            @ignore_user_abort(true);
        }
        $id = input('post.id/d');
        $reset = input('post.reset/d', 0);
        if ($this->request->isApi) {
            $oid = Db::name('cert_deploy')->where('id', $id)->value('oid');
            if (!$oid || !$this->apiOrderAllowed($oid)) return json(['code' => -1, 'msg' => '自动部署任务不存在']);
            $this->apiLog('执行证书部署', '任务ID:' . $id);
        }
        try {
            $service = new CertDeployService($id, $this->certificateOrderAuthorization());
            if ($reset == 1) {
                $service->reset();
            }
            $service->process(true);
            return json(['code' => 0, 'msg' => 'SSL证书部署任务执行成功！']);
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => \app\lib\AuditHelper::scrub($e->getMessage())]);
        }
    }

    public function deploy_form()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $action = input('param.action');

        $task = null;
        if ($action == 'edit') {
            $id = input('get.id/d');
            $task = Db::name('cert_deploy')->alias('A')->join('cert_account B', 'A.aid = B.id')->where('A.id', $id)->fieldRaw('A.id,A.aid,A.oid,A.config,A.remark,B.type')->find();
            if (empty($task)) return $this->alert('error', '自动部署任务不存在');
        }

        $accounts = [];
        foreach (Db::name('cert_account')->where('deploy', 1)->select() as $row) {
            if (empty($row['type']) || !isset(DeployHelper::$deploy_config[$row['type']])) continue;
            $accounts[$row['id']] = ['name' => $row['id'] . '_' . DeployHelper::$deploy_config[$row['type']]['name'], 'type' => $row['type']];
            if (!empty($row['remark'])) {
                $accounts[$row['id']]['name'] .= '（' . $row['remark'] . '）';
            }
        }
        View::assign('accounts', $accounts);

        $orders = [];
        $orderRows = Db::name('cert_order')->alias('A')->leftJoin('cert_account B', 'A.aid = B.id')->where('status', '<>', 4)->fieldRaw('A.id,A.aid,B.type,B.remark aremark')->order('id', 'desc')->select()->toArray();
        $domainsByOrder = $this->orderDomainMap(array_column($orderRows, 'id'));
        foreach ($orderRows as $row) {
            $domains = $domainsByOrder[$row['id']] ?? [];
            $domainstr = count($domains) > 2 ? implode('、', array_slice($domains, 0, 2)) . '等' . count($domains) . '个域名' : implode('、', $domains);
            if ($row['aid'] == 0) {
                $name = $row['id'] . '_' . $domainstr . '（手动续期）';
            } else {
                $certName = (!empty($row['type']) && isset(CertHelper::$cert_config[$row['type']])) ? CertHelper::$cert_config[$row['type']]['name'] : ($row['type'] ?: '未知');
                $name = $row['id'] . '_' . $domainstr . '（' . $certName . '）';
            }
            $orders[$row['id']] = ['name' => $name];
        }
        View::assign('orders', $orders);

        View::assign('info', $task);
        View::assign('action', $action);
        View::assign('typeList', DeployHelper::getList());
        return View::fetch();
    }

    public function cname()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $domains = [];
        foreach (Db::name('domain')->field('id,name')->select() as $row) {
            $domains[$row['id']] = $row['name'];
        }
        View::assign('domains', $domains);
        return view();
    }

    public function cname_data()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $kw = $this->request->post('kw', null, 'trim');
        $offset = input('post.offset/d');
        $limit = input('post.limit/d');
        $sort = input('post.sortName', null, 'trim');
        $orderDir = strtolower(input('post.sortOrder', 'desc')) === 'asc' ? 'asc' : 'desc';

        $select = Db::name('cert_cname')->alias('A')->leftJoin('domain B', 'A.did = B.id');
        if (api_domains() !== null) {
            $allowed = [];
            foreach (Db::name('cert_cname')->column('domain', 'id') as $cid => $cdomain) {
                if (api_domain_allowed($cdomain)) $allowed[] = $cid;
            }
            $select->whereIn('A.id', $allowed ?: [0]);
        }
        if (!empty($kw)) {
            $select->whereLike('A.domain', '%' . $kw . '%');
        }
        $total = $select->count();
        $allowedSort = ['id' => 'A.id', 'domain' => 'A.domain', 'status' => 'A.status', 'addtime' => 'A.addtime'];
        if ($sort && isset($allowedSort[$sort])) {
            $select->order($allowedSort[$sort], $orderDir);
        } else {
            $select->order('A.id', 'desc');
        }
        $rows = $select->limit($offset, $limit)->field('A.*,B.name cnamedomain')->select();

        $list = [];
        foreach ($rows as $row) {
            $row['host'] = $this->getCnameHost($row['domain']);
            $row['record'] = $row['rr'] . '.' . $row['cnamedomain'];
            $list[] = $row;
        }

        return json(['total' => $total, 'rows' => $list]);
    }

    private function getCnameHost($domain)
    {
        $main = getMainDomain($domain);
        if ($main == $domain) {
            return '_acme-challenge';
        } else {
            return '_acme-challenge.' . substr($domain, 0, -strlen($main) - 1);
        }
    }

    public function cname_op()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $action = input('param.action');
        if (api_domains() !== null) {
            if ($action == 'add' && !api_domain_allowed(input('post.domain', '', 'trim'))) {
                return json(['code' => -1, 'msg' => '该域名不在API密钥允许的范围内']);
            }
            if (input('?post.id')) {
                $cdomain = Db::name('cert_cname')->where('id', input('post.id/d'))->value('domain');
                if (!$cdomain || !api_domain_allowed($cdomain)) return json(['code' => -1, 'msg' => 'CNAME代理不存在']);
            }
            if (input('?post.did') && !api_domain_allowed((string)Db::name('domain')->where('id', input('post.did/d'))->value('name'))) {
                return json(['code' => -1, 'msg' => '验证记录所在域名不在API密钥允许的范围内']);
            }
        }
        if ($this->request->isApi && in_array($action, ['add', 'edit', 'del'])) {
            $this->apiLog('CNAME代理:' . $action, (string)(input('post.domain') ?: input('post.id')));
        }

        if ($action == 'add') {
            $data = [
                'domain' => input('post.domain', null, 'trim'),
                'rr' => input('post.rr', null, 'trim'),
                'did' => input('post.did/d'),
                'addtime' => date('Y-m-d H:i:s'),
                'status' => 0
            ];
            if (empty($data['domain']) || empty($data['rr']) || empty($data['did'])) return json(['code' => -1, 'msg' => '必填参数不能为空']);
            if (!checkDomain($data['domain'])) return json(['code' => -1, 'msg' => '域名格式不正确']);
            if (Db::name('cert_cname')->where('domain', $data['domain'])->find()) {
                return json(['code' => -1, 'msg' => '域名' . $data['domain'] . '已存在']);
            }
            if (Db::name('cert_cname')->where('rr', $data['rr'])->where('did', $data['did'])->find()) {
                return json(['code' => -1, 'msg' => '已存在相同CNAME记录值']);
            }
            Db::name('cert_cname')->insert($data);
            return json(['code' => 0, 'msg' => '添加CMAME代理成功！']);
        } elseif ($action == 'edit') {
            $id = input('post.id/d');
            $row = Db::name('cert_cname')->where('id', $id)->find();
            if (!$row) return json(['code' => -1, 'msg' => 'CMAME代理不存在']);

            $data = [
                'rr' => input('post.rr', null, 'trim'),
                'did' => input('post.did/d'),
            ];
            if ($row['rr'] != $data['rr'] || $row['did'] != $data['did']) {
                $data['status'] = 0;
            }
            if (empty($data['rr']) || empty($data['did'])) return json(['code' => -1, 'msg' => '必填参数不能为空']);
            if (Db::name('cert_cname')->where('rr', $data['rr'])->where('did', $data['did'])->where('id', '<>', $id)->find()) {
                return json(['code' => -1, 'msg' => '已存在相同CNAME记录值']);
            }
            Db::name('cert_cname')->where('id', $id)->update($data);
            return json(['code' => 0, 'msg' => '修改CMAME代理成功！']);
        } elseif ($action == 'del') {
            $id = input('post.id/d');
            Db::name('cert_cname')->where('id', $id)->delete();
            return json(['code' => 0]);
        } elseif ($action == 'check') {
            $id = input('post.id/d');
            $row = Db::name('cert_cname')->alias('A')->join('domain B', 'A.did = B.id')->where('A.id', $id)->field('A.*,B.name cnamedomain')->find();
            if (!$row) return json(['code' => -1, 'msg' => '自动部署任务不存在']);

            $status = 1;
            $domain = '_acme-challenge.' . $row['domain'];
            $record = $row['rr'] . '.' . $row['cnamedomain'];
            $result = \app\utils\DnsQueryUtils::get_dns_records($domain, 'CNAME');
            if (!$result || !in_array($record, $result)) {
                $result = \app\utils\DnsQueryUtils::query_dns_doh($domain, 'CNAME');
                if (!$result || !in_array($record, $result)) {
                    $status = 0;
                }
            }
            if ($status != $row['status']) {
                Db::name('cert_cname')->where('id', $id)->update(['status' => $status]);
            }
            return json(['code' => 0, 'status' => $status]);
        }
        return json(['code' => -3]);
    }

    private function orderDomainMap(array $ids): array
    {
        if (!$ids) return [];
        $map = [];
        foreach (Db::name('cert_domain')->whereIn('oid', array_values(array_unique($ids)))->field('oid,domain')->order('sort', 'asc')->order('id', 'asc')->select() as $row) {
            $map[$row['oid']][] = $row['domain'];
        }
        return $map;
    }

    /** Controller edits/deletes use the same mutex as background workers. */
    private function certificateMutation(string $resource, \Closure $callback)
    {
        $connection = \app\service\TaskLockService::connection();
        $lock = new \app\service\TaskLockService($connection, $resource);
        try { $lock->acquire(); return $callback(); }
        finally { try { $lock->release(); } finally { $connection->close(); } }
    }

    private function certificateOrderAuthorization(): \Closure
    {
        return function (int $oid, array $domains): void {
            if (api_domains() === null) return;
            if (!$domains) throw new \RuntimeException('证书订单不存在或不在API密钥域名范围内');
            foreach ($domains as $domain) if (!api_domain_allowed($domain)) throw new \RuntimeException('证书订单不存在或不在API密钥域名范围内');
        };
    }

    private function deleteCertificateOrder(int $id): void
    {
        $row = Db::name('cert_order')->where('id', $id)->find();
        if (!$row) throw new \RuntimeException('证书订单不存在');
        if (!empty($row['aid'])) { (new CertOrderService($id, $this->certificateOrderAuthorization()))->remove(); return; }
        $this->certificateMutation('cert-order:' . $id, function () use ($id) {
            if (!$this->apiOrderAllowed($id)) throw new \RuntimeException('证书订单不存在');
            if (Db::name('cert_deploy')->where('oid', $id)->count()) throw new \RuntimeException('该证书关联了自动部署任务，无法删除');
            Db::transaction(function () use ($id) {
                Db::name('cert_order')->where('id', $id)->delete();
                Db::name('cert_domain')->where('oid', $id)->delete();
            });
        });
    }

    private function authorizeDeploymentMutation(int $id): void
    {
        $oid = Db::name('cert_deploy')->where('id', $id)->value('oid');
        if (!$oid || !$this->apiOrderAllowed($oid)) throw new \RuntimeException('自动部署任务不存在');
    }

    private function normalizeDeployConfig($raw, int $aid, int $oid): string
    {
        if (!is_string($raw) || strlen($raw) > 2097152) throw new \InvalidArgumentException('部署任务配置不能为空或超过2MB');
        $config = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($config) || !str_starts_with(ltrim($raw), '{')) throw new \InvalidArgumentException('部署任务配置应为JSON对象');
        $account = Db::name('cert_account')->where('id', $aid)->where('deploy', 1)->find();
        if (!$account || !isset(DeployHelper::$deploy_config[$account['type']])) throw new \InvalidArgumentException('部署账户不存在');
        if (!Db::name('cert_order')->where('id', $oid)->find()) throw new \InvalidArgumentException('证书订单不存在');
        if ($this->request->isApi) {
            $allowed = array_keys(DeployHelper::$deploy_config[$account['type']]['taskinputs'] ?? []);
            $allowed[] = '_verify_tls';
            foreach (array_keys($config) as $field) if (!in_array($field, $allowed, true)) throw new \InvalidArgumentException('不支持的部署任务参数：' . $field);
        }
        if (isset($config['_verify_tls']) && !is_array($config['_verify_tls'])) throw new \InvalidArgumentException('线上验收配置格式不正确');
        $domains = Db::name('cert_domain')->where('oid', $oid)->column('domain');
        $config['_verify_tls'] = \app\service\CertVerificationService::normalizeConfig($config['_verify_tls'] ?? null, $domains);
        return json_encode($config, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * API密钥限定域名范围时，可访问的证书订单ID
     */
    private function apiAllowedOrderIds()
    {
        $ids = [];
        $orders = [];
        foreach (Db::name('cert_domain')->field('oid,domain')->select() as $row) {
            $orders[$row['oid']][] = $row['domain'];
        }
        foreach ($orders as $oid => $domains) {
            $ok = true;
            foreach ($domains as $d) {
                if (!api_domain_allowed($d)) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) $ids[] = $oid;
        }
        return $ids;
    }

    private function apiOrderAllowed($oid)
    {
        if (api_domains() === null) return true;
        $domains = Db::name('cert_domain')->where('oid', intval($oid))->column('domain');
        if (empty($domains)) return false;
        foreach ($domains as $d) {
            if (!api_domain_allowed($d)) return false;
        }
        return true;
    }

    /**
     * 可以在服务器上执行命令或写本机文件的部署类型，不允许通过API创建或修改
     */
    public static function deployTypeApiWritable($type)
    {
        // 这些类型可以把证书私钥写到任意路径或执行命令，只允许在后台配置
        if (in_array($type, ['local', 'ssh', 'ftp', 's3storage'])) return false;
        foreach (array_keys(DeployHelper::$deploy_config[$type]['taskinputs'] ?? []) as $field) {
            if (preg_match('/(^|_)(cmd|command|script|shell|exec)($|_)/i', $field)) return false;
        }
        return true;
    }

    /**
     * API调用部署任务管理接口时的安全检查
     * @return true|string
     */
    private function apiDeployCheck($action)
    {
        $ids = input('?post.ids') ? (array)input('post.ids') : (input('?post.id') ? [input('post.id/d')] : []);
        foreach ($ids as $tid) {
            $task = Db::name('cert_deploy')->alias('A')->leftJoin('cert_account B', 'A.aid = B.id')->where('A.id', intval($tid))->field('A.oid,B.type')->find();
            if (!$task || !$this->apiOrderAllowed($task['oid'])) return '自动部署任务不存在';
            if ($action == 'edit' && !self::deployTypeApiWritable($task['type'])) return '出于安全考虑，该类型（SSH/FTP/复制到本机/S3存储）的部署任务只能在后台修改';
        }
        if (in_array($action, ['add', 'edit'])) {
            $type = Db::name('cert_account')->where('id', input('post.aid/d'))->where('deploy', 1)->value('type');
            if (!$type) return '自动部署账户不存在';
            if (!self::deployTypeApiWritable($type)) return '出于安全考虑，「' . (DeployHelper::$deploy_config[$type]['name'] ?? $type) . '」类型的部署任务只能在后台创建和修改';
            $config = json_decode((string)input('post.config'), true);
            if (!is_array($config)) return '任务配置 config 需要是JSON字符串';
            if (!$this->apiOrderAllowed(input('post.oid/d'))) return '证书订单不存在';
            if (!Db::name('cert_order')->where('id', input('post.oid/d'))->find()) return '证书订单不存在';
        }
        if ($action == 'operation' && input('post.act') == 'cert') {
            if (!$this->apiOrderAllowed(input('post.certid/d'))) return '证书订单不存在';
        }
        if (in_array($action, ['add', 'edit', 'del', 'setactive', 'reset', 'operation'])) {
            $this->apiLog('部署任务:' . $action, 'ID:' . (input('post.id') ?: implode(',', $ids)));
        }
        return true;
    }

    /**
     * 过滤日志中可能出现的私钥、密码等敏感信息
     */
    private function filterLog($content)
    {
        $content = preg_replace('/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s', '[PRIVATE KEY]', (string)$content);
        if ($this->request->isApi) {
            $content = preg_replace('/("?(?:password|passwd|secret|token|apikey|api_key|accesskey|access_key|secretkey|secret_key)"?\s*[:=]\s*"?)[^"\s,&}]+/i', '$1******', $content);
        }
        return $content;
    }

    private function apiLog($action, $data)
    {
        api_write_log($action, $data);
    }

    private function getProcessLogFile($processid)
    {
        if (!is_string($processid) || !preg_match('/^[a-f0-9]{32}$/i', $processid)) {
            return false;
        }
        $logDir = realpath(app()->getRuntimePath() . 'log');
        if ($logDir === false) {
            return false;
        }
        $file = $logDir . DIRECTORY_SEPARATOR . $processid . '.log';
        if (!is_file($file)) {
            return false;
        }
        $realFile = realpath($file);
        if ($realFile === false || !str_starts_with($realFile, $logDir . DIRECTORY_SEPARATOR)) {
            return false;
        }
        return $realFile;
    }

    public function certset()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        return View::fetch();
    }
}
