<?php

namespace app\utils;

class CheckUtils
{
    public static function curl($url, $timeout, $ip = null, $proxy = false, array $policy = [])
    {
        $status = true;
        $errmsg = null;
        $start = microtime(true);

        $urlarr = parse_url($url);
        if (!$urlarr || empty($urlarr['host'])) {
            return ['status' => false, 'errmsg' => 'Invalid URL', 'usetime' => 0];
        }
        if (str_starts_with($urlarr['host'], '[') && str_ends_with($urlarr['host'], ']')) {
            $urlarr['host'] = substr($urlarr['host'], 1, -1);
        }
        if (!empty($ip) && !filter_var($urlarr['host'], FILTER_VALIDATE_IP)) {
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                $ip = gethostbyname($ip);
            }
            if (!filter_var($ip, FILTER_VALIDATE_IP)) return ['status'=>false,'errmsg'=>'主记录目标DNS解析失败','usetime'=>0];
            if (!empty($ip) && filter_var($ip, FILTER_VALIDATE_IP)) {
                $port = $urlarr['port'] ?? (strtolower($urlarr['scheme'] ?? '') === 'https' ? 443 : 80);
                $resolve = $urlarr['host'] . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip);
                // CONNECT_TO also pins HTTP/SOCKS proxy tunnels and a redirect
                // to another port on the same hostname, while preserving TLS SNI.
                $connectTo = $urlarr['host'] . '::' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip) . ':';
            }
        }

        // Use cURL directly: Guzzle's streaming handler can ignore CURLOPT_RESOLVE,
        // which would probe public DNS instead of the configured primary server.
        $body = '';
        $bounded = false;
        $handle = curl_init($url);
        $milliseconds = max(1, (int)ceil((float)$timeout * 1000));
        $options = [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT_MS => $milliseconds,
            CURLOPT_TIMEOUT_MS => $milliseconds,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_SSL_VERIFYPEER => $policy['verify_tls'] ?? true,
            CURLOPT_SSL_VERIFYHOST => ($policy['verify_tls'] ?? true) ? 2 : 0,
            CURLOPT_PROXY => '',
            CURLOPT_USERAGENT => 'DNSMgr health check',
            CURLOPT_WRITEFUNCTION => static function ($ch, $chunk) use (&$body, &$bounded) {
                $remaining = 1048576 - strlen($body);
                $body .= substr($chunk, 0, $remaining);
                if (strlen($chunk) > $remaining) { $bounded = true; return 0; }
                return strlen($chunk);
            },
        ];
        if (!empty($resolve)) {
            $options[CURLOPT_RESOLVE] = [$resolve];
            $options[CURLOPT_CONNECT_TO] = [$connectTo];
        }
        if ($proxy) {
            $proxyServer = config_get('proxy_server');
            $proxyPort = (int)config_get('proxy_port');
            if ($proxyServer && $proxyPort) {
                $scheme = match (config_get('proxy_type')) {
                    'https' => 'https', 'sock4' => 'socks4',
                    'sock5' => 'socks5', 'sock5h' => 'socks5h', default => 'http',
                };
                $options[CURLOPT_PROXY] = $scheme . '://' . $proxyServer . ':' . $proxyPort;
                $options[CURLOPT_PROXYUSERPWD] = config_get('proxy_user') . ':' . config_get('proxy_pwd');
            }
        }
        try {
            curl_setopt_array($handle, $options);
            $ok = curl_exec($handle);
            $httpcode = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if ($ok === false && !($bounded && curl_errno($handle) === CURLE_WRITE_ERROR)) {
                $status = false;
                $errmsg = 'HTTP检测失败：' . curl_error($handle);
            } elseif (!empty($policy['statuses']) ? !in_array($httpcode, $policy['statuses'], true) : ($httpcode < 200 || $httpcode >= 400)) {
                $status = false;
                $errmsg = 'http_code=' . $httpcode;
            } elseif (($policy['contains'] ?? '') !== '' && !str_contains($body, $policy['contains'])) {
                $status = false;
                $errmsg = '响应内容不符合断言（检查前1MB）';
            }
        } catch (\Throwable $e) {
            $status = false;
            $errmsg = 'HTTP检测失败：' . $e->getMessage();
        } finally { curl_close($handle); }

        $usetime = round((microtime(true) - $start) * 1000);
        return ['status' => $status, 'errmsg' => $errmsg, 'usetime' => $usetime];
    }

    public static function tcp($target, $ip, $port, $timeout)
    {
        if (!empty($ip) && filter_var($ip, FILTER_VALIDATE_IP)) $target = $ip;
        if (str_ends_with($target, '.')) $target = substr($target, 0, -1);
        if (!filter_var($target, FILTER_VALIDATE_IP) && checkDomain($target)) {
            $target = gethostbyname($target);
            if (!$target) return ['status' => false, 'errmsg' => 'DNS resolve failed', 'usetime' => 0];
        }
        if (filter_var($target, FILTER_VALIDATE_IP) && str_contains($target, ':')) {
            $target = '['.$target.']';
        }
        $starttime = getMillisecond();
        $fp = @fsockopen($target, $port, $errCode, $errStr, $timeout);
        if ($fp) {
            $status = true;
            fclose($fp);
        } else {
            $status = false;
        }
        $endtime = getMillisecond();
        $usetime = $endtime - $starttime;
        return ['status' => $status, 'errmsg' => $errStr, 'usetime' => $usetime];
    }

    public static function ping($target, $ip)
    {
        if (!function_exists('exec')) return ['status' => false, 'errmsg' => 'exec函数不可用', 'usetime' => 0];
        if (!empty($ip) && filter_var($ip, FILTER_VALIDATE_IP)) $target = $ip;
        if (str_ends_with($target, '.')) $target = substr($target, 0, -1);
        if (!filter_var($target, FILTER_VALIDATE_IP) && checkDomain($target)) {
            $target = gethostbyname($target);
            if (!$target) return ['status' => false, 'errmsg' => 'DNS resolve failed', 'usetime' => 0];
        }
        if (!filter_var($target, FILTER_VALIDATE_IP)) {
            return ['status' => false, 'errmsg' => 'Invalid IP address', 'usetime' => 0];
        }
        $timeout = 1;
        if (PHP_OS_FAMILY === 'Windows') {
            $waitMs = $timeout * 1000;
            if (str_contains($target, ':')) {
                exec('ping -6 -n 1 -w '.$waitMs.' '.$target, $output, $return_var);
            } else {
                exec('ping -n 1 -w '.$waitMs.' '.$target, $output, $return_var);
            }
        } else {
            if (str_contains($target, ':')) {
                exec('ping -6 -c 1 -w '.$timeout.' '.$target, $output, $return_var);
            } else {
                exec('ping -c 1 -w '.$timeout.' '.$target, $output, $return_var);
            }
        }
        if (!empty($output[1])) {
            if (strpos($output[1], '毫秒') !== false) {
                $usetime = getSubstr($output[1], '时间=', ' 毫秒');
            } else {
                $usetime = getSubstr($output[1], 'time=', ' ms');
            }
        }
        $usetime = !empty($usetime) ? round(trim($usetime)) : 0;
        $errmsg = null;
        if ($return_var !== 0) {
            $usetime = $usetime == 0 ? $timeout * 1000 : $usetime;
            $errmsg = 'ping timeout';
        }
        return ['status' => $return_var === 0, 'errmsg' => $errmsg, 'usetime' => $usetime];
    }
}
