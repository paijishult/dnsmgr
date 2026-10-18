<?php

declare (strict_types=1);

namespace app\middleware;

use app\lib\ApiKeyHelper;
use app\lib\AuditHelper;
use Exception;
use think\facade\Cache;
use think\facade\Db;

/**
 * API鉴权
 *
 * 方式一（用户API）：uid + timestamp + sign，sign = md5(uid + timestamp + 用户API密钥)，拥有该用户的全部权限
 * 方式二（API密钥）：keyid + timestamp + sign，sign = md5(keyid + timestamp + secret)，按后台设置的权限范围控制
 */
class AuthApi
{
    const FAIL_LIMIT = 20;     // 同一IP认证失败次数上限
    const FAIL_WINDOW = 900;   // 失败统计与封禁时长（秒）

    public function handle($request, \Closure $next)
    {
        $ip = ApiKeyHelper::clientIp();
        $failKey = 'apifail_' . md5((string)$ip);
        $fail = Cache::get($failKey);
        if (is_array($fail) && $fail['n'] >= self::FAIL_LIMIT && $fail['t'] > time() - self::FAIL_WINDOW) {
            $request->auditApiDenied = true;
            return json(['code' => -1, 'msg' => '认证失败次数过多，请' . ceil(self::FAIL_WINDOW / 60) . '分钟后再试'])->code(429);
        }

        $timestamp = input('post.timestamp');
        $sign = input('post.sign');
        $keyid = input('post.keyid');
        $keyid = is_string($keyid) ? trim($keyid) : '';
        $uid = input('post.uid/d');
        if ((!$uid && $keyid === '') || empty($timestamp) || empty($sign) || !is_numeric($timestamp)) {
            $request->auditApiDenied = true;
            return json(['code' => -1, 'msg' => '认证参数不能为空'])->code(403);
        }
        if ($timestamp < time() - 300 || $timestamp > time() + 300) {
            $request->auditApiDenied = true;
            return json(['code' => -1, 'msg' => '时间戳不合法'])->code(403);
        }

        if ($keyid !== '') {
            $result = $this->authByKey($request, $keyid, $timestamp, $sign, $ip);
        } else {
            $result = $this->authByUser($request, $uid, $timestamp, $sign);
        }
        if ($result !== true) {
            [$status, $msg, $countFail] = $result;
            if ($countFail) $this->recordFail($failKey, $fail);
            if ($status != 404) $request->auditApiDenied = true;
            return json(['code' => -1, 'msg' => $msg])->code($status);
        }

        // 原有接口中部分功能只响应AJAX请求，API调用统一视为AJAX请求
        $request->withServer(array_merge($request->server(), ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']));

        // 列表接口的分页参数：后台表格总会传递，API调用时可省略（默认 offset=0, limit=20，单次最多1000条）
        $post = $request->post();
        $request->auditOriginalPost = $post;
        $offset = $post['offset'] ?? null;
        $limit = $post['limit'] ?? null;
        $post['offset'] = is_numeric($offset) && $offset >= 0 ? (string)intval($offset) : '0';
        $post['limit'] = is_numeric($limit) && $limit > 0 ? (string)min(1000, intval($limit)) : '20';
        // 批量操作的 ids 统一转换为整数数组（兼容 "1,2,3" 写法），保证权限检查与实际操作使用同一组ID
        if (isset($post['ids'])) {
            $ids = is_array($post['ids']) ? $post['ids'] : explode(',', (string)$post['ids']);
            $post['ids'] = array_values(array_filter(array_map(fn($v) => is_scalar($v) ? intval($v) : 0, $ids), fn($v) => $v > 0));
        }
        $request->withPost($post);

        $response = $next($request);

        // 安全兜底：API返回内容中如果出现系统里保存的任何密钥值（账户密钥、用户API密钥、系统密钥等），一律替换为 ******
        try {
            $content = $response->getContent();
            if (is_string($content) && $content !== '') {
                $scrubbed = AuditHelper::scrub($content, AuditHelper::apiScrubValues());
                if ($scrubbed !== $content) $response->content($scrubbed);
            }
        } catch (\Throwable $e) {
        }
        return $response->header(empty($request->apiKey) ? [] : ($request->apiRateHeaders ?? []));
    }

    /**
     * 用户API（原有方式）
     */
    private function authByUser($request, $uid, $timestamp, $sign)
    {
        $user = Db::name('user')->where('id', $uid)->find();
        if (!$user) {
            return [403, '用户不存在', true];
        }
        if ($user['status'] == 0) {
            return [403, '该用户已被封禁', false];
        }
        if ($user['is_api'] == 0 || empty($user['apikey'])) {
            return [403, '该用户未开启API权限', true];
        }
        if (!is_string($sign) || !hash_equals(md5($uid . $timestamp . $user['apikey']), strtolower($sign))) {
            return [403, '签名错误', true];
        }

        $user['type'] = 'user';
        $user['permission'] = [];
        if ($user['level'] == 1) {
            $user['permission'] = Db::name('permission')->where('uid', $uid)->column('domain');
        }
        unset($user['password'], $user['totp_secret']);

        $request->islogin = true;
        $request->isApi = true;
        $request->user = $user;
        return true;
    }

    /**
     * 后台创建的API密钥
     */
    private function authByKey($request, $keyid, $timestamp, $sign, $ip)
    {
        if (config_get('api_key_open', '1') != '1') {
            return [403, 'API密钥调用已在后台关闭', false];
        }
        if (!preg_match('/^[0-9a-f]{16}$/', $keyid)) {
            return [403, 'API密钥不存在', true];
        }
        try {
            $row = Db::name('apikey')->where('keyid', $keyid)->find();
        } catch (Exception $e) {
            return [503, 'API密钥功能尚未初始化，请先登录后台完成数据库升级', false];
        }
        if (!$row) {
            return [403, 'API密钥不存在', true];
        }
        if (!ApiKeyHelper::checkSign($keyid, $timestamp, $sign, $row['secret'])) {
            return [403, '签名错误', true];
        }
        if ($row['status'] != 1) {
            return [403, '该API密钥已被禁用', false];
        }
        if (!empty($row['expiretime']) && strtotime($row['expiretime']) < time()) {
            return [403, '该API密钥已过期', false];
        }
        $ipList = $row['ipwhitelist'] ? array_filter(explode("\n", $row['ipwhitelist'])) : [];
        if (!ApiKeyHelper::ipAllowed($ip, $ipList)) {
            return [403, '当前IP（' . $ip . '）不在该API密钥的白名单中', false];
        }

        $user = Db::name('user')->where('id', $row['uid'])->find();
        if (!$user || $user['status'] != 1) {
            return [403, 'API密钥所属用户不存在或已被封禁', false];
        }
        $domains = $row['domains'] ? array_values(array_filter(explode("\n", $row['domains']))) : [];
        $scopes = ApiKeyHelper::normalizeScopes($row['scopes'], $user['level'] == 2, !empty($domains));

        // 检查接口所需权限
        $need = $this->requiredScope($request);
        if ($need === null) {
            return [404, '接口不存在，API接口均为POST请求', false];
        }
        if (!in_array($need, $scopes, true)) {
            $name = ApiKeyHelper::$scopes[$need]['name'] ?? $need;
            return [403, '该API密钥没有“' . $name . '”权限（' . $need . '）', false];
        }

        // 频率限制（每分钟）
        $limit = intval($row['ratelimit']);
        if ($limit > 0) {
            $window = intdiv(time(), 60);
            $rlKey = 'apirl_' . $row['id'];
            $rl = Cache::get($rlKey);
            if (!is_array($rl) || $rl['w'] != $window) {
                $rl = ['w' => $window, 'n' => 0];
            }
            $rl['n']++;
            Cache::set($rlKey, $rl, 120);
            $request->apiRateHeaders = [
                'X-RateLimit-Limit' => (string)$limit,
                'X-RateLimit-Remaining' => (string)max(0, $limit - $rl['n']),
            ];
            if ($rl['n'] > $limit) {
                return [429, '请求过于频繁，该API密钥每分钟最多调用' . $limit . '次', false];
            }
        }

        $user['type'] = 'user';
        $user['permission'] = [];
        if ($user['level'] == 1) {
            $user['permission'] = Db::name('permission')->where('uid', $user['id'])->column('domain');
        }
        if (!empty($domains)) {
            // 限定了域名范围：仅允许操作这些域名（普通用户还需在其授权范围内）
            $user['api_domains'] = $user['level'] == 1 ? array_values(array_intersect($domains, $user['permission'])) : $domains;
            if (empty($user['api_domains'])) $user['api_domains'] = ['-'];
        }
        unset($user['password'], $user['totp_secret'], $user['apikey']);

        $request->islogin = true;
        $request->isApi = true;
        $request->user = $user;
        $request->apiKey = ['id' => $row['id'], 'name' => $row['name'], 'keyid' => $keyid, 'scopes' => $scopes];

        try {
            Db::name('apikey')->where('id', $row['id'])->inc('calls')->update(['lasttime' => date('Y-m-d H:i:s'), 'lastip' => $ip]);
        } catch (Exception $e) {
        }
        return true;
    }

    /**
     * 读取路由上定义的所需权限，支持按 action / act / 参数 区分
     */
    private function requiredScope($request)
    {
        $rule = $request->rule();
        $scope = $rule ? $rule->getOption('scope') : null;
        if (is_string($scope) || $scope === null) return $scope;
        $action = (string)($request->route('action') ?? input('post.act', ''));
        foreach ($scope as $key => $value) {
            if ($key === '*') continue;
            if (str_contains($key, '=')) {
                [$k, $v] = explode('=', $key, 2);
                // 与控制器读取方式保持一致（如 input('get.deploy/d')）
                if ((string)intval(input('get.' . $k)) === $v) return $value;
            } elseif ($key === $action) {
                return $value;
            }
        }
        return $scope['*'] ?? null;
    }

    private function recordFail($failKey, $fail)
    {
        if (!is_array($fail) || $fail['t'] <= time() - self::FAIL_WINDOW) {
            $fail = ['n' => 0, 't' => time()];
        }
        $fail['n']++;
        $fail['t'] = time();
        Cache::set($failKey, $fail, self::FAIL_WINDOW);
    }
}
