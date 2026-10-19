<?php
namespace app\lib;

use RuntimeException;
use ZipArchive;

/** Read bounded entries without extracting provider-controlled paths to the filesystem. */
class CertificateArchive
{
    public static function read(string $bytes, array $domains, ?string $folder = null): array
    {
        if ($bytes === '' || strlen($bytes) > 16777216) throw new RuntimeException('证书下载为空或超过16MB');
        $directory = SecureFiles::temporaryDirectory();
        $zip = new ZipArchive(); $opened = false;
        try {
            $path = $directory . '/certificate.zip';
            SecureFiles::write($path, $bytes);
            if ($zip->open($path) !== true) throw new RuntimeException('无法读取证书压缩包');
            $opened = true;
            if ($zip->numFiles > 200) throw new RuntimeException('证书压缩包文件过多');
            $keys = []; $certificates = []; $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i);
                if (!$entry) throw new RuntimeException('证书压缩包目录损坏');
                $name = str_replace('\\', '/', $entry['name']);
                if (str_starts_with($name, '/') || preg_match('~(?:\A|/)\.\.(?:/|\z)|\A[A-Za-z]:|[\x00-\x1f]~', $name)) throw new RuntimeException('证书压缩包包含不安全路径');
                $total += $entry['size'];
                if ($entry['size'] > 1048576 || $total > 8388608) throw new RuntimeException('证书压缩包解压数据过大');
                if ($folder !== null && !preg_match('~(?:\A|/)' . preg_quote($folder, '~') . '/~i', $name)) continue;
                if (!preg_match('/\.(key|crt|pem)\z/i', $name, $match)) continue;
                $contents = $zip->getFromIndex($i, 1048577);
                if (!is_string($contents) || strlen($contents) !== $entry['size']) throw new RuntimeException('证书压缩包文件读取不完整');
                if (strtolower($match[1]) === 'key') $keys[] = $contents;
                else $certificates[] = $contents;
            }
            $matches = [];
            foreach ($keys as $key) foreach ($certificates as $chain) {
                if (!@openssl_x509_check_private_key($chain, $key)) continue;
                $info = @openssl_x509_parse($chain);
                if (!$info || !self::covers($info, $domains)) continue;
                $fingerprint = openssl_x509_fingerprint($chain, 'sha256');
                if (!isset($matches[$fingerprint]) || strlen($chain) > strlen($matches[$fingerprint]['fullchain'])) $matches[$fingerprint] = ['private_key' => $key, 'fullchain' => $chain];
            }
            if (count($matches) !== 1) throw new RuntimeException('证书与私钥不匹配、域名不匹配或无法唯一选择');
            return array_values($matches)[0];
        } finally {
            if ($opened) $zip->close();
            SecureFiles::removeDirectory($directory);
        }
    }

    private static function covers(array $info, array $domains): bool
    {
        $names = [];
        foreach (explode(',', $info['extensions']['subjectAltName'] ?? '') as $name) {
            if (str_starts_with(trim($name), 'DNS:')) $names[] = strtolower(rtrim(substr(trim($name), 4), '.'));
        }
        if (!$names) return false;
        foreach ($domains as $domain) {
            $domain = strtolower(rtrim($domain, '.'));
            if (function_exists('idn_to_ascii')) $domain = idn_to_ascii($domain, 0, INTL_IDNA_VARIANT_UTS46) ?: $domain;
            $found = false;
            foreach ($names as $name) {
                if ($domain === $name || (!str_starts_with($domain, '*.') && str_starts_with($name, '*.') && substr($domain, strpos($domain, '.') + 1) === substr($name, 2))) { $found = true; break; }
            }
            if (!$found) return false;
        }
        return (bool)$domains;
    }
}
