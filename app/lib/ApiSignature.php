<?php
namespace app\lib;

class ApiSignature
{
    public static function uri($request): string
    {
        // ThinkPHP url() accepts X-Rewrite-URL, which is not necessarily the
        // routed URI. Never let a caller-controlled header replace the signed path.
        return method_exists($request, 'server') ? (string)$request->server('REQUEST_URI', '') : $request->url();
    }

    public static function canonical(string $identity, string $method, string $uri, string $body, string $timestamp, string $nonce, string $idempotency = ''): string
    {
        return implode("\n", ['DNSMGR-HMAC-SHA256', $identity, strtoupper($method), $uri, hash('sha256', $body), $timestamp, $nonce, $idempotency]);
    }

    public static function verify($request, string $identity, string $secret): bool
    {
        $timestamp = (string)$request->header('x-dnsmgr-timestamp', '');
        $nonce = (string)$request->header('x-dnsmgr-nonce', '');
        $signature = (string)$request->header('x-dnsmgr-signature', '');
        $key = (string)$request->header('idempotency-key', '');
        if (!preg_match('/\A\d{10}\z/D', $timestamp) || abs(time()-(int)$timestamp)>300 || !preg_match('/\A[a-zA-Z0-9_-]{16,128}\z/D', $nonce) || !preg_match('/\A[a-f0-9]{64}\z/D', $signature) || ($key !== '' && !preg_match('/\A[a-zA-Z0-9_-]{16,128}\z/D', $key))) return false;
        $body = $request->getInput();
        if (strlen($body)>2097152) return false;
        $uri = self::uri($request);
        if (!str_starts_with($uri, '/') || strpbrk($uri, "\r\n") !== false) return false;
        $canonical = self::canonical($identity, $request->method(), $uri, $body, $timestamp, $nonce, $key);
        if (!hash_equals(hash_hmac('sha256', $canonical, $secret), $signature)) return false;
        $request->apiV2 = ['identity'=>hash('sha256', $identity . ':' . hash('sha256', $secret)), 'nonce'=>$nonce, 'idempotency'=>$key, 'uri'=>$uri, 'digest'=>hash('sha256', $request->method() . "\n" . $uri . "\n" . $body)];
        return true;
    }
}
