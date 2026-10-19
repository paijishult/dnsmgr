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
        return preg_replace('/("?(?:password|passwd|secret|token|apikey|api_key|accesskey|access_key|secretkey|secret_key|signature|privatekey|private_key|keydata|keybase64|ssl_key)"?\s*[:=]\s*"?)[^"\s,&}]+/i', '$1******', $text);
    }
}
