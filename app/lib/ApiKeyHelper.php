<?php

namespace app\lib;

use think\facade\Db;

/**
 * API密钥工具类
 *
 * 后台可创建多个API密钥，每个密钥有独立的权限范围、域名范围、IP白名单、频率限制和过期时间。
 * 调用方式与系统原有API一致：POST 传 keyid、timestamp、sign，其中 sign = md5(keyid + timestamp + secret)。
 * secret 使用系统密钥派生的 AES-256-GCM 加密后存储，只在创建/重置时展示一次。
 */
class ApiKeyHelper
{
    /**
     * 权限范围定义
     * admin=true 表示只有管理员（level=2）用户的密钥才能拥有该权限
     * nodomain=true 表示设置了域名范围的密钥不能拥有该权限（这些功能无法按域名隔离）
     */
    public static $scopes = [
        'domain:read' => ['name' => '查看域名', 'group' => '域名与解析', 'admin' => false, 'desc' => '查看域名列表、域名详情和解析线路、域名分类、DNS账户列表（不含密钥）'],
        'domain:write' => ['name' => '管理域名', 'group' => '域名与解析', 'admin' => true, 'desc' => '添加/删除域名、修改域名设置和分类、刷新到期时间'],
        'record:read' => ['name' => '查看解析', 'group' => '域名与解析', 'admin' => false, 'desc' => '查看解析记录、解析日志、检测解析是否生效'],
        'record:write' => ['name' => '修改解析', 'group' => '域名与解析', 'admin' => false, 'desc' => '添加/修改/删除/暂停解析记录，批量操作'],
        'cert:read' => ['name' => '查看证书', 'group' => 'SSL证书', 'admin' => true, 'desc' => '查看证书订单、证书账户（不含密钥）、CNAME代理、申请日志'],
        'cert:write' => ['name' => '申请证书', 'group' => 'SSL证书', 'admin' => true, 'desc' => '创建证书订单、申请/续签/重置/吊销/删除证书，管理CNAME代理'],
        'cert:download' => ['name' => '下载证书私钥', 'group' => 'SSL证书', 'admin' => true, 'desc' => '获取证书和私钥内容（高敏感，仅在确实需要时开启）'],
        'deploy:read' => ['name' => '查看部署', 'group' => '证书推送', 'admin' => true, 'desc' => '查看自动部署任务、部署账户（不含密钥）、部署日志'],
        'deploy:write' => ['name' => '推送证书', 'group' => '证书推送', 'admin' => true, 'desc' => '执行证书部署（推送），创建/修改/删除/启停部署任务（SSH、FTP、复制到本机、S3存储等类型除外）'],
        'monitor:read' => ['name' => '查看容灾', 'group' => '容灾与定时', 'admin' => true, 'nodomain' => true, 'desc' => '查看容灾切换策略和切换日志'],
        'monitor:write' => ['name' => '管理容灾', 'group' => '容灾与定时', 'admin' => true, 'nodomain' => true, 'desc' => '创建/修改/删除/启停容灾切换策略'],
        'schedule:read' => ['name' => '查看定时切换', 'group' => '容灾与定时', 'admin' => true, 'nodomain' => true, 'desc' => '查看定时切换任务'],
        'schedule:write' => ['name' => '管理定时切换', 'group' => '容灾与定时', 'admin' => true, 'nodomain' => true, 'desc' => '创建/修改/删除/启停定时切换任务'],
        'optimize:read' => ['name' => '查看优选IP', 'group' => 'CF优选IP', 'admin' => true, 'nodomain' => true, 'desc' => '查看CF优选IP任务'],
        'optimize:write' => ['name' => '管理优选IP', 'group' => 'CF优选IP', 'admin' => true, 'nodomain' => true, 'desc' => '创建/修改/删除/立即执行CF优选IP任务'],
        'cloudflare:read' => ['name' => '查看CF增强', 'group' => 'Cloudflare增强', 'admin' => true, 'nodomain' => true, 'desc' => '查看 Cloudflare SaaS 自定义主机名、回退源、Tunnel 及其路由（不含 Tunnel 令牌）'],
        'cloudflare:write' => ['name' => '管理CF增强', 'group' => 'Cloudflare增强', 'admin' => true, 'nodomain' => true, 'desc' => '管理 Cloudflare SaaS 自定义主机名、回退源、Tunnel 及其公共主机名/CIDR/主机名路由'],
        'system:read' => ['name' => '查看系统概览', 'group' => '其他', 'admin' => true, 'nodomain' => true, 'desc' => '查看后台首页的统计数据（域名、证书、部署、容灾、优选IP数量和状态）'],
        'log:read' => ['name' => '查看操作日志', 'group' => '其他', 'admin' => false, 'desc' => '查看操作日志（普通用户只能看到自己的日志）'],
    ];

    /**
     * 常用权限组合
     */
    public static $presets = [
        'dns' => ['name' => '解析管理', 'scopes' => ['domain:read', 'record:read', 'record:write']],
        'cert' => ['name' => '申请+推送证书', 'scopes' => ['cert:read', 'cert:write', 'deploy:read', 'deploy:write']],
        'readonly' => ['name' => '只读', 'scopes' => ['domain:read', 'record:read', 'cert:read', 'deploy:read', 'monitor:read', 'schedule:read', 'optimize:read', 'cloudflare:read', 'log:read', 'system:read']],
        'full' => ['name' => '全部（不含下载私钥）', 'scopes' => ['domain:read', 'domain:write', 'record:read', 'record:write', 'cert:read', 'cert:write', 'deploy:read', 'deploy:write', 'monitor:read', 'monitor:write', 'schedule:read', 'schedule:write', 'optimize:read', 'optimize:write', 'cloudflare:read', 'cloudflare:write', 'log:read', 'system:read']],
    ];

    /**
     * 生成新密钥
     * @return array [keyid, secret, 加密后的secret]
     */
    public static function generate()
    {
        do {
            $keyid = bin2hex(random_bytes(8));
        } while (Db::name('apikey')->where('keyid', $keyid)->find());
        $secret = bin2hex(random_bytes(16));
        return [$keyid, $secret, self::encrypt($secret)];
    }

    private static function cryptKey()
    {
        $sysKey = (string)config_get('sys_key');
        if ($sysKey === '') throw new \Exception('系统密钥未初始化');
        return hash('sha256', 'dnsmgr-apikey|' . $sysKey, true);
    }

    public static function encrypt($plain)
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::cryptKey(), OPENSSL_RAW_DATA, $iv, $tag);
        return base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt($data)
    {
        $raw = base64_decode((string)$data, true);
        if ($raw === false || strlen($raw) < 29) return false;
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::cryptKey(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $plain === false ? false : $plain;
    }

    /**
     * 校验签名：sign = md5(keyid + timestamp + secret)
     */
    public static function checkSign($keyid, $timestamp, $sign, $encSecret)
    {
        $secret = self::decrypt($encSecret);
        if ($secret === false || !is_string($sign)) return false;
        return hash_equals(md5($keyid . $timestamp . $secret), strtolower($sign));
    }

    /**
     * 规范化权限列表，过滤非法值，非管理员去掉管理员专属权限
     */
    public static function normalizeScopes($scopes, $isAdmin, $hasDomains = false)
    {
        if (is_string($scopes)) {
            $scopes = explode(',', $scopes);
        }
        if (!is_array($scopes)) return [];
        $result = [];
        foreach ($scopes as $scope) {
            $scope = trim((string)$scope);
            if (!isset(self::$scopes[$scope])) continue;
            if (self::$scopes[$scope]['admin'] && !$isAdmin) continue;
            if (!empty(self::$scopes[$scope]['nodomain']) && $hasDomains) continue;
            $result[] = $scope;
        }
        return array_values(array_unique($result));
    }

    /**
     * 规范化域名限制列表（每行一个，支持根域名或子域名，自动转为小写/去掉通配符前缀）
     */
    public static function normalizeDomains($domains)
    {
        if (is_string($domains)) {
            $domains = preg_split('/[\s,;]+/', $domains);
        }
        if (!is_array($domains) || count($domains) > 10000) throw new \InvalidArgumentException('域名范围应为文本或不超过10000项的数组');
        $result = [];
        foreach ($domains as $domain) {
            if (!is_string($domain)) throw new \InvalidArgumentException('域名范围中包含无效值');
            $domain = strtolower(trim((string)$domain));
            if ($domain === '') continue;
            $domain = rtrim($domain, '.');
            if (str_starts_with($domain, '*.')) $domain = substr($domain, 2);
            if ($domain === '') throw new \InvalidArgumentException('域名范围中包含无效域名');
            $ascii = convertDomainToAscii($domain);
            if (!$ascii || !checkDomain($ascii)) throw new \InvalidArgumentException('域名范围中包含无效域名：' . $domain);
            \app\lib\RecordGuard::domainName($ascii);
            $result[] = $domain;
        }
        return array_values(array_unique($result));
    }

    /**
     * 规范化IP白名单（每行一个，支持IPv4/IPv6和CIDR）
     * @return array|string 成功返回数组，失败返回错误信息
     */
    public static function normalizeIpList($list)
    {
        if (is_string($list)) {
            $list = preg_split('/[\s,;]+/', $list);
        }
        if (!is_array($list)) return [];
        $result = [];
        foreach ($list as $item) {
            $item = trim((string)$item);
            if ($item === '') continue;
            if (strpos($item, '/') !== false) {
                [$ip, $bits] = explode('/', $item, 2);
                if (!filter_var($ip, FILTER_VALIDATE_IP) || !ctype_digit($bits)) return '无效的IP段：' . $item;
                $max = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 128 : 32;
                if ((int)$bits < 0 || (int)$bits > $max) return '无效的IP段：' . $item;
            } elseif (!filter_var($item, FILTER_VALIDATE_IP)) {
                return '无效的IP地址：' . $item;
            }
            $result[] = $item;
        }
        return array_values(array_unique($result));
    }

    /**
     * 判断IP是否在白名单中
     */
    public static function ipAllowed($ip, array $list)
    {
        if (empty($list)) return true;
        $ipBin = @inet_pton($ip);
        if ($ipBin === false) return false;
        foreach ($list as $item) {
            if (strpos($item, '/') === false) {
                if (@inet_pton($item) === $ipBin) return true;
                continue;
            }
            [$net, $bits] = explode('/', $item, 2);
            $netBin = @inet_pton($net);
            if ($netBin === false || strlen($netBin) !== strlen($ipBin)) continue;
            $bits = (int)$bits;
            $bytes = intdiv($bits, 8);
            $rem = $bits % 8;
            if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) continue;
            if ($rem > 0) {
                $mask = (0xff << (8 - $rem)) & 0xff;
                if ((ord($ipBin[$bytes]) & $mask) !== (ord($netBin[$bytes]) & $mask)) continue;
            }
            return true;
        }
        return false;
    }

    /**
     * 对标识类字段（如AccessKeyId、主机地址）做脱敏展示
     */
    public static function maskText($text)
    {
        $text = (string)$text;
        $len = mb_strlen($text);
        if ($len == 0) return '';
        if ($len <= 4) return str_repeat('*', $len);
        if ($len <= 8) return mb_substr($text, 0, 2) . '****';
        return mb_substr($text, 0, 4) . '****' . mb_substr($text, -4);
    }

    /**
     * 判断字段名是否属于敏感字段（密码、密钥、令牌等），用于API输出脱敏
     */
    public static function isSecretField($field)
    {
        return (bool)preg_match('/(pass|pwd|secret|token|private|apiid$|accesskey|access_key|credential|cookie|kubeconfig|hmac|signature|proxy_url|webhook_url|webhook_headers|^auth_|key$|^sk$|^ak$|^kid$)/i', (string)$field);
    }

    /**
     * 递归脱敏数组中的敏感字段
     */
    public static function redact($data)
    {
        if (!is_array($data)) return $data;
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = self::redact($v);
            } elseif (self::isSecretField($k) && !isNullOrEmpty($v)) {
                $data[$k] = '******';
            }
        }
        return $data;
    }

    public static $ipSources = [
        'auto' => '安全默认（推荐）：仅取连接IP；可信反代请明确选择请求头模式',
        'remote' => '仅取连接IP（REMOTE_ADDR），程序直接对外时最安全',
        'x-real-ip' => '取 X-Real-IP 请求头（前面有可信反向代理时使用）',
        'xff' => '取 X-Forwarded-For 最后一个IP（前面有一层可信反向代理时使用）',
        'cf' => '取 CF-Connecting-IP（套了 Cloudflare 且源站只允许 CF 访问时使用）',
    ];

    /**
     * 获取API客户端真实IP
     * 私网/本机连接不等于可信代理。默认只取连接IP；头部模式须由管理员
     * 显式配置，并确保源站只接受可信代理且代理覆盖相应头部。
     */
    public static function clientIp()
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        $source = config_get('api_ip_source', 'auto');
        $realIp = self::headerIp('HTTP_X_REAL_IP');
        $xffIp = self::lastForwardedIp();
        $cfIp = self::headerIp('HTTP_CF_CONNECTING_IP');
        switch ($source) {
            case 'remote':
                return $remote;
            case 'x-real-ip':
                return $realIp ?: $remote;
            case 'xff':
                return $xffIp ?: $remote;
            case 'cf':
                return $cfIp ?: $remote;
            default:
                return $remote;
        }
    }

    private static function headerIp($name)
    {
        $value = isset($_SERVER[$name]) ? trim($_SERVER[$name]) : '';
        return filter_var($value, FILTER_VALIDATE_IP) ? $value : null;
    }

    private static function lastForwardedIp()
    {
        if (empty($_SERVER['HTTP_X_FORWARDED_FOR'])) return null;
        // 只取最后一跳（由最近的反向代理追加），前面的部分可能是客户端伪造的
        $parts = explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']);
        $last = trim(end($parts));
        return filter_var($last, FILTER_VALIDATE_IP) ? $last : null;
    }
}
