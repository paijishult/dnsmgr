<?php
namespace app\service;

/** Optional, read-only acceptance check of the public HTTPS endpoint. */
class CertVerificationService
{
    public static function normalizeConfig($value, array $domains): array
    {
        if (!is_array($value) || !in_array($value['enabled'] ?? false, [true, 1, '1', 'true', 'on'], true)) return ['enabled' => false];
        if (!empty($value['targets'])) {
            $rows = $value['targets'];
            if (is_string($rows)) $rows = array_values(array_filter(array_map('trim', explode("\n", $rows)), 'strlen'));
            if (!is_array($rows) || !$rows || count($rows)>20) throw new \InvalidArgumentException('请配置1到20个验收目标');
            $targets=[];
            foreach ($rows as $row) {
                if (is_string($row)) {
                    if (!preg_match('/\A([^:@\s]+)(?::(443|8443))?(?:@([^\s]+))?\z/D', $row, $m)) throw new \InvalidArgumentException('目标格式应为域名:端口@源站IP');
                    $row=['host'=>$m[1],'port'=>($m[2] ?? '') ?: 443,'address'=>$m[3] ?? ''];
                }
                if (!is_array($row)) throw new \InvalidArgumentException('验收目标无效');
                $target=self::normalizeConfig(['enabled'=>true,'host'=>$row['host'] ?? '', 'port'=>$row['port'] ?? 443,'timeout'=>min(10,(int)($value['timeout'] ?? 5))],$domains);
                $address=trim((string)($row['address'] ?? ''));
                if ($address!=='' && !self::isPublicAddress($address)) throw new \InvalidArgumentException('源站验收仅支持公网IP');
                $target['address']=$address;
                $targets[$target['host'].':'.$target['port'].'@'.$address]=$target;
            }
            $targets=array_values($targets); $first=$targets[0];
            $timeout=filter_var($value['timeout'] ?? 10,FILTER_VALIDATE_INT);
            if ($timeout===false || $timeout<1 || $timeout>60) throw new \InvalidArgumentException('多目标验收总超时应为1到60秒');
            return ['enabled'=>true,'host'=>$first['host'],'port'=>$first['port'],'timeout'=>$timeout,'targets'=>$targets,'mode'=>'all'];
        }
        $host = strtolower(rtrim(trim((string)($value['host'] ?? '')), '.'));
        if (function_exists('idn_to_ascii')) $host = idn_to_ascii($host, 0, INTL_IDNA_VARIANT_UTS46) ?: $host;
        if (strlen($host) > 253 || !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $host) || filter_var($host, FILTER_VALIDATE_IP)) throw new \InvalidArgumentException('验收目标必须是证书域名，不能包含协议、路径或IP地址');
        $allowed = false;
        foreach ($domains as $domain) {
            $domain = strtolower(rtrim((string)$domain, '.'));
            if (function_exists('idn_to_ascii')) $domain = idn_to_ascii($domain, 0, INTL_IDNA_VARIANT_UTS46) ?: $domain;
            if ($domain === $host || (strncmp($domain, '*.', 2) === 0 && substr($host, strpos($host, '.') + 1) === substr($domain, 2))) { $allowed = true; break; }
        }
        if (!$allowed) throw new \InvalidArgumentException('验收目标不在该证书的域名范围内');
        $port = filter_var($value['port'] ?? 443, FILTER_VALIDATE_INT);
        $timeout = filter_var($value['timeout'] ?? 5, FILTER_VALIDATE_INT);
        if (!in_array($port, [443, 8443], true)) throw new \InvalidArgumentException('验收端口仅支持443或8443');
        if ($timeout === false || $timeout < 1 || $timeout > 10) throw new \InvalidArgumentException('验收超时应为1到10秒');
        return ['enabled' => true, 'host' => $host, 'port' => $port, 'timeout' => $timeout];
    }

    public static function fingerprint(string $pem): string
    {
        $certificate = openssl_x509_read($pem);
        if (!$certificate) throw new \RuntimeException('无法读取待部署证书');
        $fingerprint = openssl_x509_fingerprint($certificate, 'sha256');
        if (!$fingerprint) throw new \RuntimeException('无法计算证书指纹');
        return strtolower(str_replace(':', '', $fingerprint));
    }

    public static function isPublicAddress(string $address): bool
    {
        if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
        // PHP's reserved-range filter does not cover all special-use blocks.
        $packed = @inet_pton($address);
        if (strlen($packed) === 4) {
            $first = ord($packed[0]); $second = ord($packed[1]);
            return $first !== 0 && $first < 224 && !($first === 100 && $second >= 64 && $second <= 127)
                && !($first === 192 && $second === 0) && !($first === 198 && ($second === 18 || $second === 19));
        }
        // Accept native global-unicast IPv6 only; reject transition/mapped addresses.
        return strlen($packed) === 16 && (ord($packed[0]) & 0xe0) === 0x20
            && !(substr($packed, 0, 2) === "\x20\x01" && ord($packed[2]) < 2)
            && substr($packed, 0, 2) !== "\x20\x02"
            && substr($packed, 0, 2) !== "\x3f\xff"
            && substr($packed, 0, 4) !== "\x20\x01\x0d\xb8";
    }

    public function verify(array $config, string $expectedFingerprint): array
    {
        if (!empty($config['targets'])) return $this->verifyMatrix($config, $expectedFingerprint);
        $result = ['status' => 'failed', 'host' => $config['host'], 'port' => $config['port'], 'expected_sha256' => $expectedFingerprint, 'observed_sha256' => null, 'checked_at' => date('Y-m-d H:i:s'), 'error' => null];
        $deadline = microtime(true) + $config['timeout'];
        try {
            $addresses = $this->resolvePublicAddresses($config['host'], $deadline);
            $lastError = null;
            foreach ($addresses as $address) {
                try {
                    $observed = $this->readCertificate($config, $address, $deadline);
                    $result['observed_sha256'] = self::fingerprint($observed);
                    if (!hash_equals($expectedFingerprint, $result['observed_sha256'])) throw new \RuntimeException('线上证书指纹与本次部署证书不一致');
                    $result['status'] = 'passed';
                    return $result;
                } catch (\Throwable $e) { $lastError = $e; }
            }
            throw $lastError ?: new \RuntimeException('验收目标无可用的公网地址');
        } catch (\Throwable $e) {
            $result['error'] = mb_strcut(str_replace(["\r", "\n"], '', $e->getMessage()), 0, 300);
            return $result;
        }
    }

    private function verifyMatrix(array $config, string $expected): array
    {
        $result=['status'=>'failed','host'=>$config['host'],'port'=>$config['port'],'expected_sha256'=>$expected,'observed_sha256'=>null,'checked_at'=>date('Y-m-d H:i:s'),'error'=>null,'targets'=>[]];
        $deadline=microtime(true)+$config['timeout'];
        foreach ($config['targets'] as $target) {
            try { $addresses=$target['address']!=='' ? [$target['address']] : $this->resolvePublicAddresses($target['host'],$deadline); }
            catch (\Throwable $e) { $result['targets'][]=['host'=>$target['host'],'port'=>$target['port'],'address'=>null,'status'=>'failed','error'=>$e->getMessage()]; continue; }
            foreach ($addresses as $address) {
                $row=['host'=>$target['host'],'port'=>$target['port'],'address'=>$address,'status'=>'failed','expected_sha256'=>$expected,'observed_sha256'=>null,'expires_at'=>null,'error'=>null];
                try {
                    $pem=$this->readCertificate($target,$address,$deadline);
                    $row['observed_sha256']=self::fingerprint($pem);
                    $parsed=openssl_x509_parse($pem);
                    $row['expires_at']=isset($parsed['validTo_time_t']) ? date('Y-m-d H:i:s',$parsed['validTo_time_t']) : null;
                    if (!hash_equals($expected,$row['observed_sha256'])) throw new \RuntimeException('线上证书指纹不一致');
                    $row['status']='passed';
                } catch (\Throwable $e) { $row['error']=mb_substr($e->getMessage(),0,300); }
                $result['targets'][]=$row;
            }
        }
        $failed=array_filter($result['targets'],fn($r)=>$r['status']!=='passed');
        if (!$failed && $result['targets']) { $result['status']='passed';$result['observed_sha256']=$expected; }
        else $result['error']='验收未通过：' . count($failed) . '/' . count($result['targets']) . ' 个目标';
        return $result;
    }

    protected function resolvePublicAddresses(string $host, float $deadline): array
    {
        $addresses = [];
        foreach ([1, 28] as $type) {
            // Use a pinned public resolver: both DNS and TLS share one deadline.
            $handle = curl_init('https://dns.alidns.com/resolve?name=' . rawurlencode($host) . '&type=' . $type);
            $body = '';
            $options = $this->options($deadline);
            $options[CURLOPT_RESOLVE] = ['dns.alidns.com:443:223.5.5.5'];
            $options[CURLOPT_WRITEFUNCTION] = static function ($ch, $chunk) use (&$body) {
                if (strlen($body) + strlen($chunk) > 65536) return 0;
                $body .= $chunk; return strlen($chunk);
            };
            curl_setopt_array($handle, $options);
            try {
                if (!curl_exec($handle) || curl_getinfo($handle, CURLINFO_RESPONSE_CODE) !== 200) throw new \RuntimeException('验收DNS查询失败');
            } finally { curl_close($handle); }
            $data = json_decode($body, true);
            if (!is_array($data) || (int)($data['Status'] ?? -1) !== 0) throw new \RuntimeException('验收域名解析失败');
            foreach (($data['Answer'] ?? []) as $answer) {
                if (!in_array((int)($answer['type'] ?? 0), [1, 28], true)) continue;
                $address = (string)($answer['data'] ?? '');
                if (!self::isPublicAddress($address)) throw new \RuntimeException('验收目标解析到了非公网地址');
                $addresses[] = $address;
            }
        }
        if (!$addresses) throw new \RuntimeException('验收目标没有公网A或AAAA记录');
        $addresses = array_values(array_unique($addresses));
        if (count($addresses)>16) throw new \RuntimeException('验收地址超过16个，请配置明确的源站IP');
        return $addresses;
    }

    protected function readCertificate(array $config, string $address, float $deadline): string
    {
        $handle = curl_init('https://' . $config['host'] . ':' . $config['port'] . '/');
        $options = $this->options($deadline);
        $options[CURLOPT_RESOLVE] = [$config['host'] . ':' . $config['port'] . ':' . (strpos($address, ':') !== false ? '[' . $address . ']' : $address)];
        $options[CURLOPT_NOBODY] = true;
        $options[CURLOPT_CERTINFO] = true;
        curl_setopt_array($handle, $options);
        try {
            if (!curl_exec($handle)) throw new \RuntimeException('TLS验收连接失败: ' . curl_error($handle));
            $certificates = curl_getinfo($handle, CURLINFO_CERTINFO);
            if (empty($certificates[0]['Cert'])) throw new \RuntimeException('TLS连接未返回证书');
            return $certificates[0]['Cert'];
        } finally { curl_close($handle); }
    }

    private function options(float $deadline): array
    {
        $remaining = (int)floor(($deadline - microtime(true)) * 1000);
        if ($remaining <= 0) throw new \RuntimeException('证书验收超时');
        return [CURLOPT_RETURNTRANSFER => false, CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROXY => '', CURLOPT_NOSIGNAL => true, CURLOPT_CONNECTTIMEOUT_MS => $remaining, CURLOPT_TIMEOUT_MS => $remaining];
    }
}
