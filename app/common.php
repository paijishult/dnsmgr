<?php
// 应用公共文件
use think\facade\Db;
use think\facade\Request;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

function get_curl($url, $post = 0, $referer = 0, $cookie = 0, $ua = 0, $nobody = 0, $addheader = [])
{
    $options = [
        'timeout' => 10,
        'verify' => true,
        'headers' => [
            'User-Agent' => $ua ?: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36'
        ],
        'http_errors' => false // 不抛出异常
    ];

    $options['headers'] = array_merge($options['headers'], $addheader);
    if ($referer) {
        $options['headers']['Referer'] = $referer;
    }
    if ($cookie) {
        $options['headers']['Cookie'] = $cookie;
    }

    $method = 'GET';
    if ($post) {
        $method = 'POST';
        if (!isset($options['headers']['Content-Type'])) {
            $options['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
        }
        if (is_array($post)) {
            $options['form_params'] = $post;
        } else {
            $options['body'] = $post;
        }
    }
    // 规范化头部
    $options['headers'] = normalize_http_headers($options['headers']);

    try {
        $client = new Client();
        $response = $client->request($method, $url, $options);

        if ($nobody) {
            return '';
        }

        return $response->getBody()->getContents();
    } catch (GuzzleException $e) {
        return '';
    }
}

function real_ip($type = 0)
{
    $ip = $_SERVER['REMOTE_ADDR'];
    if ($type <= 0 && isset($_SERVER['HTTP_X_FORWARDED_FOR']) && preg_match_all('#\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}#s', $_SERVER['HTTP_X_FORWARDED_FOR'], $matches)) {
        foreach ($matches[0] as $xip) {
            if (filter_var($xip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                $ip = $xip;
                break;
            }
        }
    } elseif ($type <= 0 && isset($_SERVER['HTTP_CLIENT_IP']) && filter_var($_SERVER['HTTP_CLIENT_IP'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif ($type <= 1 && isset($_SERVER['HTTP_CF_CONNECTING_IP']) && filter_var($_SERVER['HTTP_CF_CONNECTING_IP'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
    } elseif ($type <= 1 && isset($_SERVER['HTTP_X_REAL_IP']) && filter_var($_SERVER['HTTP_X_REAL_IP'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        $ip = $_SERVER['HTTP_X_REAL_IP'];
    }
    return $ip;
}

function checkmobile()
{
    $useragent = strtolower(request()->header('user-agent', ''));
    $ualist = array('android', 'midp', 'nokia', 'mobile', 'iphone', 'ipod', 'blackberry', 'windows phone');
    foreach ($ualist as $ua) {
        if (str_contains($useragent, $ua)) return true;
    }
    if (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], "VND.WAP") || isset($_SERVER['HTTP_VIA']) && str_contains($_SERVER['HTTP_VIA'], "wap")) return true;
    return false;
}

function authcode($string, $operation = 'DECODE', $key = '', $expiry = 0)
{
    $ckey_length = 4;
    $key = md5($key);
    $keya = md5(substr($key, 0, 16));
    $keyb = md5(substr($key, 16, 16));
    $keyc = $ckey_length ? ($operation == 'DECODE' ? substr($string, 0, $ckey_length) : substr(md5(microtime()), -$ckey_length)) : '';
    $cryptkey = $keya.md5($keya.$keyc);
    $key_length = strlen($cryptkey);
    $string = $operation == 'DECODE' ? base64_decode(substr($string, $ckey_length)) : sprintf('%010d', $expiry ? $expiry + time() : 0).substr(md5($string.$keyb), 0, 16).$string;
    $string_length = strlen($string);
    $result = '';
    $box = range(0, 255);
    $rndkey = array();
    for ($i = 0; $i <= 255; $i++) {
        $rndkey[$i] = ord($cryptkey[$i % $key_length]);
    }
    for ($j = $i = 0; $i < 256; $i++) {
        $j = ($j + $box[$i] + $rndkey[$i]) % 256;
        $tmp = $box[$i];
        $box[$i] = $box[$j];
        $box[$j] = $tmp;
    }
    for ($a = $j = $i = 0; $i < $string_length; $i++) {
        $a = ($a + 1) % 256;
        $j = ($j + $box[$a]) % 256;
        $tmp = $box[$a];
        $box[$a] = $box[$j];
        $box[$j] = $tmp;
        $result .= chr(ord($string[$i]) ^ ($box[($box[$a] + $box[$j]) % 256]));
    }
    if ($operation == 'DECODE') {
        if (((int)substr($result, 0, 10) == 0 || (int)substr($result, 0, 10) - time() > 0) && substr($result, 10, 16) == substr(md5(substr($result, 26).$keyb), 0, 16)) {
            return substr($result, 26);
        } else {
            return '';
        }
    } else {
        return $keyc.base64_encode($result);
    }
}

function random($length, $numeric = 0)
{
    $seed = base_convert(bin2hex(random_bytes(16)), 16, $numeric ? 10 : 35);
    $seed = $numeric ? (str_replace('0', '', $seed).'012340567890') : ($seed.'zZ'.strtoupper($seed));
    $hash = '';
    $max = strlen($seed) - 1;
    for ($i = 0; $i < $length; $i++) {
        $hash .= $seed[random_int(0, $max)];
    }
    return $hash;
}

function checkDomain($domain)
{
    if (empty($domain) || !preg_match('/^[-$a-z0-9_*.]{2,512}$/i', $domain) || (stripos($domain, '.') === false) || substr($domain, -1) == '.' || substr($domain, 0, 1) == '.' || substr($domain, 0, 1) == '*' && substr($domain, 1, 1) != '.' || substr_count($domain, '*') > 1 || strpos($domain, '*') > 0 || strlen($domain) < 4) return false;
    return true;
}

function getSubstr($str, $leftStr, $rightStr)
{
    $left = strpos($str, $leftStr);
    if ($left === false) return '';
    $start = $left + strlen($leftStr);
    $right = strpos($str, $rightStr, $start);
    if ($right !== false) {
        return substr($str, $start, $right - $start);
    } else {
        return substr($str, $start);
    }
}

function arrays_are_equal($array1, $array2)
{
    return empty(array_diff($array1, $array2)) && empty(array_diff($array2, $array1));
}

function checkRefererHost()
{
    if (!Request::header('referer')) {
        return false;
    }
    $url_arr = parse_url(Request::header('referer'));
    if (!is_array($url_arr) || empty($url_arr['host'])) {
        return false;
    }
    $http_host = Request::header('host');
    if (strpos($http_host, ':')) {
        $http_host = substr($http_host, 0, strpos($http_host, ':'));
    }
    return $url_arr['host'] === $http_host;
}

function checkIfActive($string)
{
    $array = explode(',', $string);
    $action = Request::action();
    if (in_array($action, $array)) {
        return 'active';
    } else {
        return null;
    }
}

function getSid()
{
    return md5(uniqid(mt_rand(), true) . microtime());
}

function isNullOrEmpty($str)
{
    return $str === null || $str === '';
}

function checkPermission($type, $domain = null)
{
    $user = Request()->user;
    if (empty($user)) {
        return false;
    }
    // API密钥限定了域名范围时，指定域名的操作必须在范围内
    if ($domain !== null && !api_domain_allowed($domain)) {
        return false;
    }
    if ($user['level'] == 2) {
        return true;
    }
    if ($type == 1 && $user['level'] == 1 || $type == 0 && $user['level'] >= 0) {
        if ($domain == null) {
            return true;
        }
        if (in_array($domain, $user['permission'])) {
            return true;
        }
    }
    return false;
}

function getAdminSkin()
{
    $skin = cookie('admin_skin');
    if (empty($skin)) {
        $skin = config_get('admin_skin');
    }
    if (empty($skin) || !preg_match('/^skin-[a-z\-]+$/', $skin) || !file_exists(public_path() . 'static/css/skins/' . $skin . '.css')) {
        $skin = 'skin-modern';
    }
    return $skin;
}

function config_get($key, $default = null, $force = false)
{
    if ($force) {
        $value = Db::name('config')->where('key', $key)->value('value');
    } else {
        $value = config('sys.' . $key);
    }
    return isNullOrEmpty($value) ? $default : $value;
}

function config_set($key, $value)
{
    $res = Db::name('config')->replace()->insert(['key' => $key, 'value' => $value]);
    return $res !== false;
}

function getMillisecond()
{
    list($s1, $s2) = explode(' ', microtime());
    return (int)sprintf('%.0f', (floatval($s1) + floatval($s2)) * 1000);
}

function getDnsType($value)
{
    if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return 'A';
    } elseif (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return 'AAAA';
    } else {
        return 'CNAME';
    }
}

function convert_second($s)
{
    $m = floor($s / 60);
    if ($m == 0) {
        return $s.'秒';
    } else {
        $s = $s % 60;
        $h = floor($m / 60);
        if ($h == 0) {
            return $m.'分钟'.$s.'秒';
        } else {
            $m = $m % 60;
            return $h.'小时'.$m.'分钟'.$s.'秒';
        }
    }
}

function getMainDomain($host)
{
    if (filter_var($host, FILTER_VALIDATE_IP)) return $host;
    $domains = config('temp.domains');
    if (!$domains) {
        $domains = Db::name('domain')->column('name');
        $domains_alias = Db::name('domain_alias')->column('name');
        $domains = array_merge($domains, $domains_alias);
        usort($domains, fn($a, $b) => strlen($b) <=> strlen($a));
        config(['domains'=>$domains], 'temp');
    }
    foreach ($domains as $domain) {
        if ($host === $domain || str_ends_with($host, '.' . $domain)) {
            return $domain;
        }
    }
    $domain_root = file_get_contents(app()->getBasePath() . 'data' . DIRECTORY_SEPARATOR . 'domain_root.txt');
    $domain_root = explode("\n", $domain_root);
    $data = explode('.', $host);
    $co_ta = count($data);
    if ($co_ta <= 2) return $host;
    $domain_name = $data[$co_ta - 2] . '.' . $data[$co_ta - 1];
    if (in_array($domain_name, $domain_root) && $co_ta > 2) {
        $domain_name = $data[$co_ta - 3] . '.' . $domain_name;
    }
    return $domain_name;
}

function check_proxy($url, $proxy_server, $proxy_port, $type, $proxy_user, $proxy_pwd)
{
    match ($type) {
        'https' => $proxy_string = 'https://',
        'sock4' => $proxy_string = 'socks4://',
        'sock5' => $proxy_string = 'socks5://',
        'sock5h' => $proxy_string = 'socks5h://',
        default => $proxy_string = 'http://',
    };

    if (!empty($proxy_user) && !empty($proxy_pwd)) {
        $proxy_string .= $proxy_user . ':' . $proxy_pwd . '@';
    }

    $proxy_string .= $proxy_server . ':' . intval($proxy_port);
    $options = [
        'proxy' => $proxy_string,
        'timeout' => 3,
        'connect_timeout' => 3,
        'verify' => true,
        'headers' => [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36'
        ]
    ];

    try {
        $client = new Client();
        $response = $client->request('GET', $url, $options);
        $httpCode = $response->getStatusCode();

        if ($httpCode >= 200 && $httpCode < 400) {
            return true;
        } else {
            throw new Exception('HTTP状态码异常：' . $httpCode);
        }
    } catch (GuzzleException $e) {
        throw new Exception(guzzle_error($e));
    }
}

function clearDirectory($dir): bool
{
    // 确保路径是目录
    if (!is_dir($dir)) {
        return false;
    }

    // 打开目录
    $items = scandir($dir);
    foreach ($items as $item) {
        // 跳过 '.' 和 '..'
        if ($item == '.' || $item == '..') {
            continue;
        }

        // 完整路径
        $path = $dir . DIRECTORY_SEPARATOR . $item;

        // 如果是目录，递归删除其内容
        if (is_dir($path)) {
            clearDirectory($path);
            // 删除空目录
            rmdir($path);
        } else {
            // 删除文件
            unlink($path);
        }
    }
    return true;
}

/**
 * 规范化请求头，将标量值转为字符串，剔除 null 等非法值，避免 GuzzleHttp 类型校验异常
 *
 * @param array $headers 原始请求头
 * @return array 规范化后的请求头
 */
function normalize_http_headers($headers)
{
    $result = [];
    foreach ((array)$headers as $name => $value) {
        if (is_array($value)) {
            $items = [];
            foreach ($value as $item) {
                if (is_string($item)) {
                    $items[] = $item;
                } elseif (is_scalar($item)) {
                    $items[] = (string)$item;
                }
            }
            if ($items) {
                $result[$name] = $items;
            }
        } elseif (is_string($value)) {
            $result[$name] = $value;
        } elseif (is_scalar($value)) {
            // 转为字符串
            $result[$name] = (string)$value;
        }
        // 忽略 null 等非法值
    }
    return $result;
}

/**
 * 发送 HTTP 请求
 *
 * @param string $url 请求URL
 * @param mixed $data 请求数据，可以是字符串或数组，数组将自动根据请求方法及传入的 Content-Type 头序列化
 * @param string|null $referer 请求的 Referer 头
 * @param array|null $cookie 请求的 Cookie 头
 * @param array|null $headers 其他自定义请求头
 * @param bool $proxy 是否使用代理
 * @param string|null $method 请求方法，默认为 GET 或 POST（如果存在请求数据）
 * @param int $timeout 请求超时时间，默认为 10 秒
 * @return array 包含 HTTP 状态码、重定向 URL、响应头和响应体的数组
 * @throws Exception
 */
function http_request($url, $data = null, $referer = null, $cookie = null, $headers = null, $proxy = false, $method = null, $timeout = 10): array
{
    $options = [
        'timeout' => $timeout,
        'connect_timeout' => $timeout,
        'allow_redirects' => false,
        'verify' => true,
        'headers' => [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36',
        ],
        'http_errors' => false // 不抛出异常
    ];

    // 默认请求方法
    if (!$method) {
        $method = $data ? 'POST' : 'GET';
    }
    // 处理头部
    if (is_array($headers)) {
        $options['headers'] = array_merge($options['headers'], $headers);
    }
    // 处理Cookie
    if ($cookie) {
        $options['headers']['Cookie'] = $cookie;
    }
    // 处理Referer
    if ($referer) {
        $options['headers']['Referer'] = $referer;
    }
    // 处理数据
    if ($data) {
        if ($method !== 'GET') {
            if (is_string($data)) {
                $options['body'] = $data;
                if (!isset($options['headers']['Content-Type'])) {
                    if (json_validate($data)) {
                        // json
                        $options['headers']['Content-Type'] = 'application/json';
                    } elseif (str_contains($data, '=') || str_contains($data, '&')) {
                        // 表单
                        $options['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
                    }
                }
            } else if (is_array($data) || is_object($data)) {
                if (!isset($options['headers']['Content-Type'])) {
                    // 默认为表单
                    $options['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
                }
                if ($options['headers']['Content-Type'] == 'application/x-www-form-urlencoded') {
                    // 表单
                    $options['form_params'] = $data;
                } else if ($options['headers']['Content-Type'] == 'multipart/form-data') {
                    // 表单文件
                    $options['multipart'] = $data;
                    unset($options['headers']['Content-Type']); // 由GuzzleHttp重新生成Content-Type头部
                } else if ($options['headers']['Content-Type'] == 'application/json') {
                    // json
                    $options['json'] = $data;
                } else {
                    // 其他
                    $options['body'] = http_build_query($data);
                }
            } else {
                $options['body'] = $data;
            }
        } else {
            // 兼容已经存在查询字符串的情况
            if (!str_contains($url, '?')) {
                $options['query'] = $data;
            }
        }
    }
    // 处理代理
    if ($proxy) {
        $proxy_server = config_get('proxy_server');
        $proxy_port = intval(config_get('proxy_port'));
        $proxy_userpwd = config_get('proxy_user').':'.config_get('proxy_pwd');
        $proxy_type = config_get('proxy_type');

        if (empty($proxy_server) || empty($proxy_port)) {
            throw new Exception('代理服务器或端口未配置');
        }

        match ($proxy_type) {
            'https' => $proxy_string = 'https://',
            'sock4' => $proxy_string = 'socks4://',
            'sock5' => $proxy_string = 'socks5://',
            'sock5h' => $proxy_string = 'socks5h://',
            default => $proxy_string = 'http://',
        };

        if ($proxy_userpwd != ':') {
            $proxy_string .= $proxy_userpwd . '@';
        }

        $proxy_string .= $proxy_server . ':' . $proxy_port;
        $options['proxy'] = $proxy_string;
    }
    // 规范化头部
    $options['headers'] = normalize_http_headers($options['headers']);

    try {
        $client = new Client();
        $response = $client->request($method, $url, $options);
        $code = $response->getStatusCode();

        // 取重定向URL
        $redirect_url = '';
        if ($code >= 300 && $code < 400) {
            $redirect_url = $response->getHeaderLine('Location');
        }

        return [
            'code' => $code,
            'redirect_url' => $redirect_url,
            'headers' => $response->getHeaders(),
            'body' => $response->getBody()->getContents()
        ];
    } catch (GuzzleException $e) {
        throw new Exception('请求失败: ' . guzzle_error($e));
    }
}

function guzzle_error($e)
{
    $errmsg = $e->getMessage();
    if (preg_match('/^cURL error \d+: /', $errmsg)) {
        $errmsg = preg_replace('/^cURL error \d+: /', '', $errmsg);
    }
    $pos = strpos($errmsg, ' (see https://curl.haxx.se/libcurl/c/libcurl-errors.html)');
    if ($pos !== false) {
        $errmsg = substr($errmsg, 0, $pos);
    }
    if (strlen($errmsg) > 100) {
        $errmsg = substr($errmsg, 0, 97) . '...';
    }
    return $errmsg;
}

function curl_set_proxy(&$ch)
{
    $proxy_server = config_get('proxy_server');
    $proxy_port = intval(config_get('proxy_port'));
    $proxy_userpwd = config_get('proxy_user') . ':' . config_get('proxy_pwd');
    $proxy_type = config_get('proxy_type');
    if (empty($proxy_server) || empty($proxy_port)) {
        return;
    }
    if ($proxy_type == 'https') {
        $proxy_type = CURLPROXY_HTTPS;
    } elseif ($proxy_type == 'sock4') {
        $proxy_type = CURLPROXY_SOCKS4;
    } elseif ($proxy_type == 'sock5') {
        $proxy_type = CURLPROXY_SOCKS5;
    } elseif ($proxy_type == 'sock5h') {
        $proxy_type = CURLPROXY_SOCKS5_HOSTNAME;
    } else {
        $proxy_type = CURLPROXY_HTTP;
    }
    curl_setopt($ch, CURLOPT_PROXYAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_PROXY, $proxy_server);
    curl_setopt($ch, CURLOPT_PROXYPORT, $proxy_port);
    if ($proxy_userpwd != ':') {
        curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy_userpwd);
    }
    curl_setopt($ch, CURLOPT_PROXYTYPE, $proxy_type);
}

function convertDomainToAscii($domain) {
    if (preg_match('/[\x{4e00}-\x{9fa5}]/u', $domain)) {
        return idn_to_ascii($domain);
    } else {
        return $domain;
    }
}
function convertDomainToUtf8($domain) {
    if (preg_match('/^xn--/', $domain)) {
        return idn_to_utf8($domain);
    } else {
        return $domain;
    }
}

/**
 * 查询域名注册时间和到期时间
 * 依次尝试：WHOIS（内置服务器表）→ RDAP（IANA 引导表）→ WHOIS（IANA 登记的服务器）
 * 注册局完全不提供公开查询的后缀（如 .al）会给出明确提示，可在域名设置里手动填写到期时间
 * @return array [注册时间|null, 到期时间]
 */
function getDomainDate($domain)
{
    $domain = strtolower(trim(convertDomainToAscii(trim($domain)), " .\t\r\n"));
    $tld = substr($domain, strrpos($domain, '.') + 1);
    $errors = [];

    try {
        $whois = \Iodev\Whois\Factory::get()->createWhois();
        $info = $whois->loadDomainInfo($domain);
        if ($info && $info->expirationDate > 0) {
            return [$info->creationDate > 0 ? date('Y-m-d H:i:s', $info->creationDate) : null, date('Y-m-d H:i:s', $info->expirationDate)];
        }
        $errors[] = 'WHOIS: ' . ($info ? '未返回到期时间' : '域名信息未找到');
    } catch (\Throwable $e) {
        $errors[] = 'WHOIS: ' . $e->getMessage();
    }

    $rdapServers = [];
    $rdapKnown = false;
    try {
        $rdapServers = rdapServersForTld($tld);
        $rdapKnown = true;
        if (!empty($rdapServers)) {
            $res = getDomainDateByRdap($domain, $rdapServers);
            if ($res) return $res;
            $errors[] = 'RDAP: 未返回到期时间';
        }
    } catch (\Throwable $e) {
        $errors[] = 'RDAP: ' . $e->getMessage();
    }

    $whoisServer = null;
    try {
        $whoisServer = whoisServerForTld($tld);
        if ($whoisServer) {
            $text = whoisRawQuery($whoisServer, $domain);
            $res = parseWhoisDate($text);
            if ($res) return $res;
            $errors[] = 'WHOIS(' . $whoisServer . '): 未解析到到期时间';
        }
    } catch (\Throwable $e) {
        $errors[] = 'WHOIS(' . ($whoisServer ?: 'IANA') . '): ' . $e->getMessage();
    }

    if ($rdapKnown && empty($rdapServers) && $whoisServer === '') {
        throw new Exception('.' . $tld . ' 的注册局没有提供公开的 WHOIS/RDAP 查询，无法自动获取到期时间，请在域名“修改”里手动填写到期时间');
    }
    throw new Exception('查询域名到期时间失败（' . implode('；', array_unique($errors)) . '）');
}

/**
 * 从 IANA RDAP 引导表获取后缀对应的 RDAP 服务地址（缓存7天）
 */
function rdapServersForTld($tld)
{
    $map = cache('rdap_bootstrap_dns');
    if (!is_array($map)) {
        $res = http_request('https://data.iana.org/rdap/dns.json', null, null, null, ['Accept' => 'application/json'], false, 'GET', 15);
        $json = $res['code'] == 200 ? json_decode($res['body'], true) : null;
        if (!is_array($json) || empty($json['services'])) {
            throw new Exception('获取RDAP引导表失败');
        }
        $map = [];
        foreach ($json['services'] as $service) {
            foreach ($service[0] as $t) {
                $map[strtolower($t)] = $service[1];
            }
        }
        cache('rdap_bootstrap_dns', $map, 86400 * 7);
    }
    return $map[strtolower($tld)] ?? [];
}

function getDomainDateByRdap($domain, array $servers)
{
    $lastError = null;
    foreach ($servers as $base) {
        $url = rtrim($base, '/') . '/domain/' . $domain;
        try {
            for ($i = 0; $i < 3; $i++) {
                $res = http_request($url, null, null, null, ['Accept' => 'application/rdap+json, application/json'], false, 'GET', 15);
                if ($res['code'] >= 300 && $res['code'] < 400 && $res['redirect_url']) {
                    $url = $res['redirect_url'];
                    continue;
                }
                break;
            }
            if ($res['code'] == 404) throw new Exception('域名不存在');
            if ($res['code'] != 200) throw new Exception('HTTP ' . $res['code']);
            $json = json_decode($res['body'], true);
            if (!is_array($json)) throw new Exception('返回格式错误');
            $reg = $exp = null;
            foreach ($json['events'] ?? [] as $event) {
                $action = strtolower($event['eventAction'] ?? '');
                $date = isset($event['eventDate']) ? strtotime($event['eventDate']) : false;
                if (!$date) continue;
                if ($action == 'expiration') $exp = $date;
                elseif ($action == 'registration') $reg = $date;
            }
            if ($exp) {
                return [$reg ? date('Y-m-d H:i:s', $reg) : null, date('Y-m-d H:i:s', $exp)];
            }
            return null;
        } catch (\Throwable $e) {
            $lastError = $e;
        }
    }
    if ($lastError) throw $lastError;
    return null;
}

/**
 * 通过 whois.iana.org 获取后缀登记的 WHOIS 服务器（缓存7天）；返回空字符串表示 IANA 确认该后缀没有 WHOIS 服务器
 */
function whoisServerForTld($tld)
{
    $key = 'whois_server_' . strtolower($tld);
    $server = cache($key);
    if ($server === null || $server === false) {
        $text = whoisRawQuery('whois.iana.org', $tld);
        if (!preg_match('/^status:\s*ACTIVE/mi', $text) && !preg_match('/^domain:\s*\S+/mi', $text)) {
            throw new Exception('IANA未返回该后缀信息');
        }
        $server = preg_match('/^whois:[ \t]*(\S+)/mi', $text, $m) ? strtolower($m[1]) : '';
        cache($key, $server, 86400 * 7);
    }
    return $server;
}

function whoisRawQuery($server, $query, $timeout = 10)
{
    $fp = @fsockopen($server, 43, $errno, $errstr, $timeout);
    if (!$fp) throw new Exception('连接' . $server . '失败: ' . $errstr);
    stream_set_timeout($fp, $timeout);
    fwrite($fp, $query . "\r\n");
    $text = '';
    while (!feof($fp)) {
        $buf = fread($fp, 8192);
        if ($buf === false || $buf === '') {
            $meta = stream_get_meta_data($fp);
            if ($meta['timed_out']) break;
            if ($buf === false) break;
        }
        $text .= $buf;
        if (strlen($text) > 1048576) break;
    }
    fclose($fp);
    if (trim($text) === '') throw new Exception($server . '无返回内容');
    return $text;
}

function parseWhoisDate($text)
{
    $find = function (array $labels) use ($text) {
        foreach ($labels as $label) {
            if (preg_match('/^[ \t]*' . $label . '[ \t]*[:\]][ \t]*(.+)$/mi', $text, $m)) {
                $v = trim(preg_replace('/\s*\(.*\)\s*$/', '', trim($m[1])));
                $v = preg_replace('/^(\d{1,2})[.\/](\d{1,2})[.\/](\d{4})/', '$3-$2-$1', $v);
                $t = strtotime($v);
                if ($t && $t > 0) return $t;
            }
        }
        return null;
    };
    $exp = $find(['Registry Expiry Date', 'Registrar Registration Expiration Date', 'Expiration Date', 'Expiry Date', 'Expiration Time', 'Expire Date', 'Expires On', 'Expires', 'Expiry', 'paid-till', 'Valid Until', 'renewal date', 'Renewal Date', 'expire']);
    if (!$exp) return null;
    $reg = $find(['Creation Date', 'Registration Time', 'Registered On', 'Registered', 'Created On', 'created', 'Domain Registration Date', 'registration date']);
    return [$reg ? date('Y-m-d H:i:s', $reg) : null, date('Y-m-d H:i:s', $exp)];
}

function checkTableExists($table)
{
    $prefix = env('database.prefix', 'dnsmgr_');
    $res = Db::query("SHOW TABLES LIKE '" . $prefix . $table . "'");
    return !empty($res);
}

/**
 * 数据库版本升级检查（访问后台首页或开放API管理页时执行）
 */
function db_update_check()
{
    if (!config('app.dbversion') || config_get('version') == config('app.dbversion')) return;
    $oldVersion = intval(config_get('version', 0));
    $sqls = file_get_contents(app()->getAppPath() . 'sql/update.sql');
    $mysql_prefix = env('database.prefix', 'dnsmgr_');
    $sqls = explode(';', $sqls);
    foreach ($sqls as $value) {
        $value = trim($value);
        if (empty($value)) continue;
        $value = str_replace('dnsmgr_', $mysql_prefix, $value);
        try {
            Db::execute($value);
        } catch (Exception $e) {
        }
    }
    config_set('version', config('app.dbversion'));
    if ($oldVersion > 0 && $oldVersion < 1050 && config_get('admin_skin')) {
        // 升级到新版界面时切换为新的默认主题（之后仍可在右上角自行更换）
        config_set('admin_skin', 'skin-modern');
        \think\facade\Config::set(['admin_skin' => 'skin-modern'], 'sys');
    }
    \think\facade\Cache::clear();
    if(Db::name('account')->count() > 0 && Db::name('account')->whereNotNull('config')->count() == 0) {
        $accounts = Db::name('account')->select();
        foreach ($accounts as $account) {
            if (!empty($account['config']) || !isset(\app\lib\DnsHelper::$dns_config[$account['type']])) continue;
            $config = [];
            $account_fields = ['name', 'sk', 'ext'];
            $i = 0;
            foreach(\app\lib\DnsHelper::$dns_config[$account['type']]['config'] as $field => $item) {
                if ($field == 'proxy') {
                    $config[$field] = $account['proxy'];
                    break;
                }
                if ($i >= 3) break;
                $account_field = $account_fields[$i++];
                $config[$field] = isset($account[$account_field]) ? $account[$account_field] : '';
            }
            Db::name('account')->where('id', $account['id'])->update(['config' => json_encode($config)]);
        }
    }
}

/**
 * 当前API密钥限定的域名范围（未限定返回null）
 */
function api_domains()
{
    $user = request()->user;
    return !empty($user['api_domains']) ? $user['api_domains'] : null;
}

/**
 * 域名（含子域名、通配符）是否在当前API密钥的域名范围内
 */
function api_domain_allowed($name)
{
    $domains = api_domains();
    if ($domains === null) return true;
    $name = strtolower(trim((string)$name));
    if (str_starts_with($name, '*.')) $name = substr($name, 2);
    $variants = array_unique(array_filter([$name, convertDomainToAscii($name), convertDomainToUtf8($name)]));
    foreach ($domains as $zone) {
        $zone = strtolower($zone);
        foreach ($variants as $v) {
            if ($v === $zone || str_ends_with($v, '.' . $zone)) return true;
        }
    }
    return false;
}

/**
 * 当前API密钥是否拥有某个权限（非API密钥调用返回true）
 */
function api_has_scope($scope)
{
    $key = request()->apiKey ?? null;
    if (empty($key)) return true;
    return in_array($scope, $key['scopes'], true);
}

/**
 * 记录通过API执行的修改操作（可在后台「API密钥」页面关闭）
 */
function api_write_log($action, $data, $domain = '')
{
    $request = request();
    if (empty($request->isApi)) return;
    if (!empty($request->apiKey)) {
        if (config_get('api_log', '1') != '1') return;
        $data .= ' [API:' . $request->apiKey['name'] . ']';
    } else {
        $data .= ' [API]';
    }
    Db::name('log')->insert([
        'uid' => $request->user['id'] ?? 0,
        'action' => mb_substr((string)$action, 0, 40),
        'domain' => (string)$domain,
        'data' => mb_strcut((string)$data, 0, 500),
        'addtime' => date('Y-m-d H:i:s'),
    ]);
}
