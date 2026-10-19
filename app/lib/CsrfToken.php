<?php

namespace app\lib;

/** Browser-only request token, bound to the existing encrypted login cookie. */
class CsrfToken
{
    public static function token(): string
    {
        $cookie = cookie('user_token');
        $secret = config_get('sys_key');
        if (!is_string($cookie) || $cookie === '' || !is_string($secret) || $secret === '') return '';
        return hash_hmac('sha256', 'dnsmgr-browser-csrf|' . $cookie, $secret);
    }

    public static function valid($request): bool
    {
        $expected = self::token();
        $provided = $request->header('X-CSRF-Token') ?: $request->post('__token__', '');
        return $expected !== '' && is_string($provided) && strlen($provided) === 64
            && hash_equals($expected, $provided) && self::sameOrigin($request);
    }

    public static function sameOrigin($request): bool
    {
        $origin = $request->header('Origin') ?: $request->header('Referer');
        if (!$origin) return true; // A valid secret token still authorizes non-browser clients.
        if (!is_string($origin)) return false;
        $parts = parse_url($origin);
        $host = parse_url('https://' . $request->host());
        if (!$parts || !$host || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)) return false;
        $scheme = $request->isSsl() ? 'https' : 'http';
        if (($parts['scheme'] ?? '') !== $scheme) return false;
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $expectedPort = $host['port'] ?? ($scheme === 'https' ? 443 : 80);
        return strtolower($parts['host'] ?? '') === strtolower($host['host'] ?? '') && $port === $expectedPort;
    }
}
