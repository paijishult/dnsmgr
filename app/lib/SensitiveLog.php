<?php
namespace app\lib;

/** Scrub at persistence and again on read, including logs written by old versions. */
class SensitiveLog
{
    public static function filter(string $text, array $secrets = []): string
    {
        $secrets = array_values(array_filter($secrets, static fn($v) => is_string($v) && $v !== ''));
        usort($secrets, static fn($a, $b) => strlen($b) <=> strlen($a));
        foreach ($secrets as $secret) $text = str_replace([$secret, substr(json_encode($secret, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), 1, -1)], '******', $text);
        // Old SSH logs contain positional passwords, without a password= label.
        $text = preg_replace('/\bcertutil(?:\.exe)?\s+[^\r\n]*/i', 'certutil [参数已隐藏]', $text);
        $text = preg_replace('/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s', '[PRIVATE KEY]', $text);
        // Download URLs are bearer credentials even when the path is opaque
        // and the query parameter is named signature, rather than token.
        $text = preg_replace('~https?:(?://|\\\\/\\\\/)[^\s<>"\x27]+~i', '[URL]', $text);
        $text = preg_replace('/(\bAuthorization\s*[:=]\s*)(?:Bearer|Basic|QQBot)\s+[^\s,;]+/i', '$1******', $text);
        $pattern = <<<'REGEX'
~("?(?:[a-z0-9_]*(?:password|passwd|secret|token|authorization|apikey|api_key|accesskey|access_key|privatekey|private_key)|signature|keydata|keybase64|ssl_key|pfx_pass|passphrase)"?\s*[:=]\s*)("(?:\\.|[^"\\])*"|[^\s,&}]+)~i
REGEX;
        return preg_replace($pattern, '$1"******"', $text);
    }

    public static function secrets(array $config): array
    {
        $values = [];
        foreach ($config as $key => $value) {
            if (is_array($value)) $values = array_merge($values, self::secrets($value));
            elseif (is_string($value) && preg_match('/(?:password|passwd|secret|token|authorization|apikey|api_key|accesskey|access_key|privatekey|private_key|signature|keydata|keybase64|ssl_key|pfx_pass|passphrase)$/i', (string)$key)) $values[] = $value;
        }
        return $values;
    }
}
