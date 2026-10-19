<?php
namespace app\lib;

/** Scrub at persistence and again on read, including logs written by old versions. */
class SensitiveLog
{
    public static function filter(string $text): string
    {
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
}
