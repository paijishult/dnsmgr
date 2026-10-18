<?php

namespace app\controller;

use app\BaseController;
use app\lib\ApiDoc;
use app\lib\ApiKeyHelper;
use think\facade\Db;
use think\facade\View;

/**
 * 开放API密钥管理（仅管理员）
 */
class Apikey extends BaseController
{
    public function index()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        db_update_check();
        $scopes = [];
        foreach (ApiKeyHelper::$scopes as $k => $v) {
            $scopes[$k] = $v + ['nodomain' => false];
        }
        View::assign('scopes', $scopes);
        View::assign('presets', ApiKeyHelper::$presets);
        View::assign('ipSources', ApiKeyHelper::$ipSources);
        View::assign('users', Db::name('user')->field('id,username,level')->where('status', 1)->order('id', 'asc')->select()->toArray());
        View::assign('domains', Db::name('domain')->order('name', 'asc')->column('name'));
        View::assign('myip', ApiKeyHelper::clientIp());
        View::assign('siteurl', rtrim($this->request->root(true), '/'));
        return view();
    }

    public function doc()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $siteurl = rtrim($this->request->root(true), '/');
        $chip = fn($scope) => $scope == 'cert:download' ? 'is-danger' : (str_ends_with((string)$scope, ':write') ? 'is-write' : '');
        $groups = ApiDoc::groups();
        $total = 0;
        foreach ($groups as &$group) {
            foreach ($group['items'] as &$item) {
                $path = $item[0];
                $query = $item[6] ?? '';
                $curl = 'curl -X POST "' . $siteurl . str_replace('{id}', '1', $path) . $query . '" \\' . "\n" . '  -d "keyid=$KEYID" -d "timestamp=$TS" -d "sign=$SIGN"';
                foreach ($item[3] as $p) {
                    if ($p[4] === '' && !$p[2]) continue;
                    $curl .= " \\\n  --data-urlencode " . escapeshellarg($p[0] . '=' . $p[4]);
                }
                $item = [
                    'path' => $path,
                    'query' => $query,
                    'scope' => $item[1],
                    'scope_name' => ApiKeyHelper::$scopes[$item[1]]['name'] ?? $item[1],
                    'chip' => $chip($item[1]),
                    'summary' => $item[2],
                    'params' => $item[3],
                    'returns' => $item[4] ?? '',
                    'origin' => ($item[5] ?? null) === 'origin',
                    'curl' => $curl,
                    'anchor' => 'api-' . (++$total),
                ];
            }
        }
        $scopes = [];
        foreach (ApiKeyHelper::$scopes as $k => $v) {
            $scopes[] = $v + ['scope' => $k, 'chip' => $chip($k), 'nodomain' => !empty($v['nodomain'])];
        }
        View::assign('groups', $groups);
        View::assign('scopes', $scopes);
        View::assign('total', $total);
        View::assign('siteurl', $siteurl);
        return view();
    }

    public function data()
    {
        if (!checkPermission(2)) return json(['total' => 0, 'rows' => []]);
        $rows = Db::name('apikey')->alias('A')->leftJoin('user B', 'A.uid = B.id')
            ->fieldRaw('A.id,A.uid,A.name,A.keyid,A.scopes,A.domains,A.ipwhitelist,A.ratelimit,A.expiretime,A.status,A.remark,A.addtime,A.lasttime,A.lastip,A.calls,B.username,B.level')
            ->order('A.id', 'desc')->select()->toArray();
        foreach ($rows as &$row) {
            $row['scopes'] = $row['scopes'] ? explode(',', $row['scopes']) : [];
            $row['domains'] = $row['domains'] ? explode("\n", $row['domains']) : [];
            $row['ipwhitelist'] = $row['ipwhitelist'] ? explode("\n", $row['ipwhitelist']) : [];
            $row['expired'] = $row['expiretime'] && strtotime($row['expiretime']) < time();
        }
        return json(['total' => count($rows), 'rows' => $rows]);
    }

    public function op()
    {
        if (!checkPermission(2)) return json(['code' => -1, 'msg' => '无权限']);
        $action = input('param.action');
        if ($action == 'add' || $action == 'edit') {
            $data = $this->formData();
            if (is_string($data)) return json(['code' => -1, 'msg' => $data]);
            if ($action == 'add') {
                if (Db::name('apikey')->count() >= 200) return json(['code' => -1, 'msg' => 'API密钥数量已达上限']);
                [$keyid, $secret, $enc] = ApiKeyHelper::generate();
                $data += ['keyid' => $keyid, 'secret' => $enc, 'status' => 1, 'calls' => 0, 'addtime' => date('Y-m-d H:i:s')];
                Db::name('apikey')->insert($data);
                $this->log('创建API密钥', $data['name'] . ' (' . $keyid . ')');
                return json(['code' => 0, 'msg' => '创建成功', 'keyid' => $keyid, 'secret' => $secret]);
            }
            $id = input('post.id/d');
            $row = Db::name('apikey')->where('id', $id)->find();
            if (!$row) return json(['code' => -1, 'msg' => 'API密钥不存在']);
            Db::name('apikey')->where('id', $id)->update($data);
            $this->log('修改API密钥', $data['name'] . ' (' . $row['keyid'] . ')');
            return json(['code' => 0, 'msg' => '修改成功']);
        } elseif ($action == 'reset') {
            $id = input('post.id/d');
            $row = Db::name('apikey')->where('id', $id)->find();
            if (!$row) return json(['code' => -1, 'msg' => 'API密钥不存在']);
            [$keyid, $secret, $enc] = ApiKeyHelper::generate();
            Db::name('apikey')->where('id', $id)->update(['secret' => $enc]);
            $this->log('重置API密钥', $row['name'] . ' (' . $row['keyid'] . ')');
            return json(['code' => 0, 'msg' => '重置成功，旧的Secret已立即失效', 'keyid' => $row['keyid'], 'secret' => $secret]);
        } elseif ($action == 'status') {
            $id = input('post.id/d');
            $status = input('post.status/d') == 1 ? 1 : 0;
            $row = Db::name('apikey')->where('id', $id)->find();
            if (!$row) return json(['code' => -1, 'msg' => 'API密钥不存在']);
            Db::name('apikey')->where('id', $id)->update(['status' => $status]);
            $this->log($status ? '启用API密钥' : '禁用API密钥', $row['name']);
            return json(['code' => 0, 'msg' => $status ? '已启用' : '已禁用']);
        } elseif ($action == 'del') {
            $id = input('post.id/d');
            $row = Db::name('apikey')->where('id', $id)->find();
            if (!$row) return json(['code' => -1, 'msg' => 'API密钥不存在']);
            Db::name('apikey')->where('id', $id)->delete();
            $this->log('删除API密钥', $row['name'] . ' (' . $row['keyid'] . ')');
            return json(['code' => 0, 'msg' => '删除成功']);
        }
        return json(['code' => -3, 'msg' => '参数错误']);
    }

    /**
     * 校验表单
     * @return array|string
     */
    private function formData()
    {
        $name = trim((string)input('post.name', ''));
        if ($name === '' || mb_strlen($name) > 64) return '名称不能为空且不超过64个字符';
        $uid = input('post.uid/d');
        $user = Db::name('user')->where('id', $uid)->find();
        if (!$user || $user['status'] != 1) return '所属用户不存在或已封禁';
        $isAdmin = $user['level'] == 2;
        $domains = ApiKeyHelper::normalizeDomains((string)input('post.domains', ''));
        if (!empty($domains)) {
            $exists = Db::name('domain')->where('name', 'in', $domains)->column('name');
            $missing = array_diff($domains, $exists);
            if (!empty($missing)) return '以下域名未在系统中添加：' . implode('、', $missing) . '（域名范围请填写系统中已添加的主域名）';
            if (!$isAdmin) {
                $perm = Db::name('permission')->where('uid', $uid)->column('domain');
                $denied = array_diff($domains, $perm);
                if (!empty($denied)) return '所属用户没有以下域名的权限：' . implode('、', $denied);
            }
        }
        $wanted = array_values(array_filter((array)input('post.scopes/a', []), 'is_string'));
        $scopes = ApiKeyHelper::normalizeScopes($wanted, $isAdmin, !empty($domains));
        $dropped = array_diff($wanted, $scopes);
        if (!empty($dropped)) {
            foreach ($dropped as $d) {
                if (!isset(ApiKeyHelper::$scopes[$d])) return '未知权限：' . $d;
                if (ApiKeyHelper::$scopes[$d]['admin'] && !$isAdmin) return '普通用户的密钥不能拥有“' . ApiKeyHelper::$scopes[$d]['name'] . '”权限';
                return '设置了域名范围的密钥不能拥有“' . ApiKeyHelper::$scopes[$d]['name'] . '”权限（该功能无法按域名隔离）';
            }
        }
        if (empty($scopes)) return '请至少选择一项权限';
        $ips = ApiKeyHelper::normalizeIpList((string)input('post.ipwhitelist', ''));
        if (is_string($ips)) return $ips;
        $ratelimit = input('post.ratelimit/d', 120);
        if ($ratelimit < 0 || $ratelimit > 100000) return '频率限制范围 0-100000';
        $expire = trim((string)input('post.expiretime', ''));
        if ($expire !== '') {
            $t = strtotime($expire);
            if ($t === false) return '过期时间格式不正确';
            $expire = date('Y-m-d H:i:s', $t);
        }
        return [
            'uid' => $uid,
            'name' => $name,
            'scopes' => implode(',', $scopes),
            'domains' => $domains ? implode("\n", $domains) : null,
            'ipwhitelist' => $ips ? implode("\n", $ips) : null,
            'ratelimit' => $ratelimit,
            'expiretime' => $expire ?: null,
            'remark' => mb_substr(trim((string)input('post.remark', '')), 0, 255) ?: null,
        ];
    }

    private function log($action, $data)
    {
        Db::name('log')->insert(['uid' => $this->request->user['id'], 'action' => $action, 'domain' => '', 'data' => mb_substr($data . ' IP:' . $this->clientip, 0, 500), 'addtime' => date('Y-m-d H:i:s')]);
    }
}
