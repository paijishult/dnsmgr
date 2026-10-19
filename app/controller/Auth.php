<?php

namespace app\controller;

use app\BaseController;
use app\lib\ApiKeyHelper;
use app\lib\AttemptLimiter;
use app\lib\CsrfToken;
use Exception;
use think\facade\Db;

class Auth extends BaseController
{

    public function login()
    {
        if ($this->request->islogin) return redirect('/');
        if (!$this->request->isAjax()) return view();
        if (!$this->request->isPost() || !CsrfToken::sameOrigin($this->request)) {
            return json(['code' => -1, 'msg' => '请求来源不合法'])->code(403);
        }
        $username = input('post.username');
        $password = input('post.password');
        $code = input('post.code');
        if (!is_string($username) || !is_string($password) || trim($username) === '' || $password === '' || strlen($username) > 256 || strlen($password) > 1024) {
            return json(['code' => -1, 'msg' => '用户名或密码不能为空或过长']);
        }
        $username = trim($username);
        $ip = ApiKeyHelper::clientIp();
        $ipKey = 'login-ip:' . $ip;
        $ipLimit = AttemptLimiter::hit($ipKey, 30, 900);
        if (!$ipLimit['allowed']) return json(['code' => -1, 'msg' => '登录尝试过于频繁，请稍后再试', 'vcode' => 1])->code(429);
        if (config_get('vcode', '1') == '1' && (!is_string($code) || !captcha_check($code))) {
            return json(['code' => -1, 'msg' => '验证码错误', 'vcode' => 1]);
        }
        $this->clearPendingLogin();
        $user = Db::name('user')->where('username', $username)->find();
        $userKey = $user ? 'login-user:' . $user['id'] . ':' . $ip : null;
        if ($userKey && !AttemptLimiter::hit($userKey, 5, 900)['allowed']) {
            return json(['code' => -1, 'msg' => '用户名或密码错误，或登录尝试过于频繁，请稍后再试', 'vcode' => 1])->code(429);
        }
        if (!$user || !password_verify($password, $user['password']) || $user['status'] != 1) {
            if ($user) Db::name('log')->insert(['uid' => $user['id'], 'action' => '登录失败', 'data' => 'IP:' . $ip, 'addtime' => date('Y-m-d H:i:s')]);
            return json(['code' => -1, 'msg' => '用户名或密码错误', 'vcode' => 1]);
        }
        AttemptLimiter::clear($userKey);
        AttemptLimiter::clear($ipKey);
        \think\facade\Session::regenerate();
        if (!empty($user['totp_open']) && !empty($user['totp_secret'])) {
            session('pre_login_user', (int)$user['id']);
            session('pre_login_until', time() + 300);
            session('pre_login_fingerprint', $this->loginFingerprint($user));
            return json(['code' => -1, 'msg' => '需要验证动态口令', 'vcode' => 2]);
        }
        $this->loginUser($user);
        return json(['code' => 0]);
    }

    public function totp()
    {
        if (!$this->request->isPost() || !CsrfToken::sameOrigin($this->request)) {
            return json(['code' => -1, 'msg' => '请求来源不合法'])->code(403);
        }
        $uid = (int)session('pre_login_user');
        if (!$uid || (int)session('pre_login_until') < time()) {
            $this->clearPendingLogin();
            return json(['code' => -1, 'msg' => '登录验证已过期，请重新登录']);
        }
        $user = Db::name('user')->where('id', $uid)->find();
        $fingerprint = session('pre_login_fingerprint');
        if (!$user || $user['status'] != 1 || empty($user['totp_open']) || empty($user['totp_secret'])
            || !is_string($fingerprint) || !hash_equals($this->loginFingerprint($user), $fingerprint)) {
            $this->clearPendingLogin();
            return json(['code' => -1, 'msg' => '登录状态已变更，请重新登录']);
        }
        $limitKey = 'login-totp:' . $uid . ':' . ApiKeyHelper::clientIp();
        $limit = AttemptLimiter::hit($limitKey, 5, 300);
        if (!$limit['allowed']) {
            $this->clearPendingLogin();
            return json(['code' => -1, 'msg' => '动态口令尝试次数过多，请稍后重新登录'])->code(429);
        }
        $code = input('post.code');
        $valid = false;
        try {
            $valid = is_string($code) && preg_match('/^[0-9]{6}$/D', $code)
                && \app\lib\TOTP::create($user['totp_secret'])->verify($code, null, 1);
        } catch (\Throwable $e) {
            $valid = false;
        }
        if (!$valid) {
            if ($limit['remaining'] === 0) $this->clearPendingLogin();
            return json(['code' => -1, 'msg' => $limit['remaining'] ? '动态口令错误' : '动态口令尝试次数过多，请稍后重新登录']);
        }
        AttemptLimiter::clear($limitKey);
        $this->loginUser($user);
        $this->clearPendingLogin();
        return json(['code' => 0]);
    }

    private function loginFingerprint(array $user): string
    {
        return hash_hmac('sha256', $user['password'] . '|' . ($user['totp_secret'] ?? ''), (string)config_get('sys_key'));
    }

    private function clearPendingLogin(): void
    {
        foreach (['pre_login_user', 'pre_login_until', 'pre_login_fingerprint'] as $key) session($key, null);
    }

    public function logout()
    {
        if (!$this->request->isPost() || !CsrfToken::sameOrigin($this->request)
            || ($this->request->islogin && !CsrfToken::valid($this->request))) {
            return json(['code' => -1, 'msg' => '页面验证已失效，请刷新后重试'])->code(403);
        }
        $this->clearPendingLogin();
        \think\facade\Session::regenerate();
        cookie('user_token', null);
        return json(['code' => 0]);
    }

    public function quicklogin()
    {
        $domain = input('get.domain', null, 'trim');
        $timestamp = input('get.timestamp', null, 'trim');
        $token = input('get.token', null, 'trim');
        $sign = input('get.sign', null, 'trim');
        if (empty($domain) || empty($timestamp) || empty($token) || empty($sign)) {
            return $this->alert('error', '参数错误');
        }
        if ($timestamp < time() - 300 || $timestamp > time() + 300) {
            return $this->alert('error', '时间戳无效');
        }
        if (md5(config_get('sys_key') . $domain . $timestamp . $token . config_get('sys_key')) !== $sign) {
            return $this->alert('error', '签名错误');
        }
        if ($token != cache('quicklogin_' . $domain)) {
            return $this->alert('error', 'Token无效');
        }
        $row = Db::name('domain')->where('name', $domain)->find();
        if (!$row) {
            return $this->alert('error', '该域名不存在');
        }
        if (!$row['is_sso']) {
            return $this->alert('error', '该域名不支持快捷登录');
        }

        $this->loginDomain($row);
        return redirect('/record/' . $row['id']);
    }

    private function loginUser($user)
    {
        Db::name('log')->insert(['uid' => $user['id'], 'action' => '登录后台', 'data' => 'IP:' . $this->clientip, 'addtime' => date("Y-m-d H:i:s")]);
        DB::name('user')->where('id', $user['id'])->update(['lasttime' => date("Y-m-d H:i:s")]);
        $session = md5($user['id'] . $user['password']);
        $expiretime = time() + 2562000;
        $token = authcode("user\t{$user['id']}\t{$session}\t{$expiretime}", 'ENCODE', config_get('sys_key'));
        cookie('user_token', $token, ['expire' => $expiretime, 'httponly' => true, 'secure' => $this->request->isSsl(), 'samesite' => 'lax']);
    }

    private function loginDomain($row)
    {
        Db::name('log')->insert(['uid' => 0, 'action' => '域名快捷登录', 'data' => 'IP:' . $this->clientip, 'addtime' => date("Y-m-d H:i:s"), 'domain' => $row['name']]);
        $session = md5($row['id'] . $row['name']);
        $expiretime = time() + 2562000;
        $token = authcode("domain\t{$row['id']}\t{$session}\t{$expiretime}", 'ENCODE', config_get('sys_key'));
        cookie('user_token', $token, ['expire' => $expiretime, 'httponly' => true, 'secure' => $this->request->isSsl(), 'samesite' => 'lax']);
    }

    public function verifycode()
    {
        return captcha();
    }
}
