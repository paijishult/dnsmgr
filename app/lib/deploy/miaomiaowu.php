<?php

namespace app\lib\deploy;

use app\lib\DeployInterface;
use app\lib\SensitiveLog;
use Exception;

class miaomiaowu implements DeployInterface
{
    private $logger;
    private $url;
    private $token;
    private $proxy;

    public function __construct($config)
    {
        $this->url = rtrim(trim($config['url'] ?? ''), '/');
        $this->token = trim($config['token'] ?? '');
        $this->proxy = ($config['proxy'] ?? 0) == 1;
    }

    public function check()
    {
        // The admin certificate list can require the browser's encrypted channel.
        // Probe the external upload endpoint without supplying certificate data.
        $path = '/api/admin/certificates/upload';
        $response = $this->send($path, [], 10);
        $result = json_decode($response['body'], true);
        if ((int)$response['code'] === 400 && is_array($result) && $this->errorMessage($result) !== '') {
            // Some servers validate the body before authenticating it. A missing
            // field error alone must never make an invalid token look usable.
            $control = clone $this;
            $control->token = 'mmwx_' . bin2hex(random_bytes(24));
            $rejected = $control->send($path, [], 10);
            $rejectedResult = json_decode($rejected['body'], true);
            if (in_array((int)$rejected['code'], [401, 403], true) && is_array($rejectedResult) && $this->errorMessage($rejectedResult) !== '') {
                return true;
            }
            throw new Exception('妙妙屋上传接口未能区分有效与无效Token，无法安全确认账户可用');
        }
        $this->parseResponse($response, []);
        throw new Exception('妙妙屋上传接口未返回预期的参数校验结果，无法安全确认账户可用');
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        $domain = $config['domain'] ?? '';
        if (!is_string($domain)) throw new Exception('证书域名格式不正确');
        $domain = trim($domain);
        if ($domain === '') $domain = $config['domainList'][0] ?? '';
        if (!is_string($domain) || $domain === '') throw new Exception('没有设置要部署的域名');
        $hostname = str_starts_with($domain, '*.') ? substr($domain, 2) : $domain;
        if (!filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) throw new Exception('证书域名格式不正确');
        if (empty($fullchain) || empty($privatekey)) throw new Exception('SSL证书或私钥内容不存在');

        $this->request('/api/admin/certificates/upload', [
            'domain' => $domain,
            'cert_pem' => base64_encode($fullchain),
            'key_pem' => base64_encode($privatekey),
        ]);
        $this->log('域名 ' . $domain . ' 证书推送成功');
    }

    public function setLogger($func)
    {
        $this->logger = $func;
    }

    private function log($txt)
    {
        if ($this->logger) {
            call_user_func($this->logger, $txt);
        }
    }

    private function request($path, $params = null)
    {
        return $this->parseResponse($this->send($path, $params), $params);
    }

    private function send($path, $params = null, $timeout = null)
    {
        if ($this->url === '' || $this->token === '') throw new Exception('请填写面板地址和API Token');
        $url = parse_url($this->url);
        if (!$url || !in_array(strtolower($url['scheme'] ?? ''), ['http', 'https'], true) || empty($url['host'])
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])) {
            throw new Exception('面板地址格式不正确，请填写完整的HTTP或HTTPS地址');
        }
        if (preg_match('/[\r\n]/', $this->token)) throw new Exception('API Token格式不正确');

        $headers = [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $this->token,
            'MM-Authorization' => $this->token,
        ];
        $body = null;
        if ($params !== null) {
            $headers['Content-Type'] = 'application/json';
            $body = json_encode((object)$params, JSON_THROW_ON_ERROR);
        }
        return http_request($this->url . $path, $body, null, null, $headers, $this->proxy, $params === null ? 'GET' : 'POST', $timeout ?? ($params === null ? 10 : 60));
    }

    private function errorMessage(array $result)
    {
        foreach (['error', 'message', 'msg'] as $field) {
            if (isset($result[$field]) && is_string($result[$field]) && trim($result[$field]) !== '') return $result[$field];
        }
        return '';
    }

    private function parseResponse($response, $params)
    {
        $code = (int)$response['code'];
        if ($code === 204 && trim($response['body']) === '') return [];

        $result = json_decode($response['body'], true);
        $failed = !empty($result['error']) || ($result['success'] ?? null) === false
            || (isset($result['code']) && !in_array($result['code'], [0, '0', 200, '200'], true));
        if ($code < 200 || $code >= 300 || $failed) {
            $message = is_array($result) ? $this->errorMessage($result) : '';
            if ($message === '') $message = '请求失败';
            if (($result['code'] ?? '') === 'SECURE_CHANNEL_REQUIRED' || $message === 'secure channel required') {
                $message .= '（妙妙屋要求加密安全通道，请核对管理员API Token及当前版本的证书上传Webhook支持）';
            }
            $secrets = [$this->token];
            foreach (['cert_pem', 'key_pem'] as $key) {
                if (isset($params[$key])) {
                    $secrets[] = $params[$key];
                    $secrets[] = base64_decode($params[$key]);
                }
            }
            throw new Exception('妙妙屋请求失败(httpCode=' . $code . ')：' . SensitiveLog::filter($message, $secrets));
        }
        if (!is_array($result)) throw new Exception('妙妙屋返回数据解析失败(httpCode=' . $code . ')');
        return $result;
    }
}
