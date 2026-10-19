<?php
namespace app\service;

use think\facade\Cache;

/** Only an administrator-issued, single-use challenge may change the recipient. */
class QqBotBindingService
{
    public static function saveSettings(array $params): void
    {
        self::locked(function ($db) use ($params) {
            $old = $db->name('config')->whereIn('key', ['qqbot_appid','qqbot_appsecret'])->column('value','key');
            foreach (['qqbot_appid','qqbot_appsecret'] as $key) {
                if (array_key_exists($key, $params) && (string)$params[$key] !== (string)($old[$key] ?? '')) {
                    $params['qqbot_openid'] = ''; $params['qqbot_bind_hash'] = ''; $params['qqbot_bind_until'] = '0';
                }
            }
            $db->transaction(function () use ($db, $params) {
                foreach ($params as $key => $value) if ($key !== '') $db->name('config')->replace()->insert(['key'=>$key,'value'=>$value]);
            });
            Cache::delete('configs');
        });
    }

    private static function locked(callable $callback)
    {
        $db = TaskLockService::connection();
        $lock = new TaskLockService($db, 'qqbot-binding');
        try { $lock->acquire(); return $callback($db); }
        finally { try { $lock->release(); } finally { $db->close(); } }
    }

    public static function start(): array
    {
        return self::locked(function ($db) {
            $code = bin2hex(random_bytes(12));
            $until = time() + 300;
            $secret = (string)$db->name('config')->where('key', 'sys_key')->value('value');
            if ($secret === '') throw new \RuntimeException('系统密钥未配置');
            $hash = hash_hmac('sha256', $code, $secret);
            $db->transaction(function () use ($db, $hash, $until) {
                foreach (['qqbot_bind_hash'=>$hash, 'qqbot_bind_until'=>(string)$until] as $key=>$value) {
                    $db->name('config')->replace()->insert(['key'=>$key,'value'=>$value]);
                }
            });
            Cache::delete('configs');
            return ['message'=>'绑定 ' . $code, 'expires'=>$until];
        });
    }

    public static function consume(string $openid, $content, string $appId, string $appSecret): bool
    {
        if ($openid === '' || strlen($openid) > 256 || !is_string($content)
            || !preg_match('/\A绑定\s+([a-f0-9]{24})\z/uD', trim($content), $match)) return false;
        return self::locked(function ($db) use ($openid, $match, $appId, $appSecret) {
            $values = $db->name('config')->whereIn('key', ['qqbot_bind_hash','qqbot_bind_until','qqbot_appid','qqbot_appsecret','sys_key'])->column('value','key');
            if (!hash_equals(trim((string)($values['qqbot_appid'] ?? '')), $appId) || !hash_equals(trim((string)($values['qqbot_appsecret'] ?? '')), $appSecret)) return false;
            $expected = (string)($values['qqbot_bind_hash'] ?? '');
            if ((int)($values['qqbot_bind_until'] ?? 0) <= time() || $expected === ''
                || !hash_equals($expected, hash_hmac('sha256', $match[1], (string)($values['sys_key'] ?? '')))) return false;
            $db->transaction(function () use ($db, $openid) {
                foreach (['qqbot_openid'=>$openid, 'qqbot_bind_hash'=>'', 'qqbot_bind_until'=>'0'] as $key=>$value) {
                    $db->name('config')->replace()->insert(['key'=>$key,'value'=>$value]);
                }
            });
            Cache::delete('configs');
            return true;
        });
    }
}
