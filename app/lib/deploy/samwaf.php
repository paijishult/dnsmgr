<?php

namespace app\lib\deploy;

use app\lib\DeployInterface;
use app\lib\SensitiveLog;
use Exception;

/** SamWaf OpenAPI certificate-folder deployment, adapted from upstream cf6ad5e. */
class samwaf implements DeployInterface
{
    private $logger;
    private string $url;
    private string $apiKey;
    private bool $proxy;

    public function __construct($config)
    {
        $this->url = rtrim(trim($config['url'] ?? ''), '/');
        $this->apiKey = trim($config['api_key'] ?? '');
        $this->proxy = ($config['proxy'] ?? 0) == 1;
    }

    public function check()
    {
        // Use an authenticated, read-only endpoint. Version endpoints can be public.
        $this->page(1, 1);
        return true;
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        $fingerprint = @openssl_x509_fingerprint($fullchain, 'sha256');
        if (!$fingerprint || !is_string($privatekey) || trim($privatekey) === '') throw new Exception('SSL证书或私钥内容不存在或格式错误');
        $id = $config['id'] ?? '';
        if (!is_string($id) || strlen($id) > 255) throw new Exception('证书ID格式不正确');
        $id = trim($id);
        $params = ['cert_content' => $fullchain, 'key_content' => $privatekey];
        if ($id === '') {
            // Recover an earlier accepted upload when saving its local ID failed.
            $id = $this->findCertId($fingerprint, $privatekey);
            if ($id === null) {
                $this->request('/sslconfig/add', $params);
                $id = $this->findCertId($fingerprint, $privatekey);
                if ($id === null) throw new Exception('证书已提交但未能确认ID，请核对SamWaf证书夹后重试');
            }
            $info['config']['id'] = $id;
            $this->log('SamWaf证书已保存，ID：' . $id);
            return;
        }

        $before = $this->detail($id);
        // SamWaf resets omitted import paths to defaults; preserve existing paths.
        foreach (['cert_path', 'key_path'] as $field) {
            if (!is_string($before[$field] ?? null)) throw new Exception('SamWaf证书路径信息不完整，已停止更新');
            $params[$field] = $before[$field];
        }
        $params['id'] = $id;
        $this->request('/sslconfig/edit', $params);
        $after = $this->detail($id);
        if (!$this->matches($after, $fingerprint) || !is_string($after['key_content'] ?? null) || !hash_equals($privatekey, $after['key_content'])) throw new Exception('SamWaf返回成功，但证书或私钥内容未更新，请核对证书夹');
        $this->log('SamWaf证书更新成功，ID：' . $id);
    }

    private function matches(array $cert, string $fingerprint): bool
    {
        return is_string($cert['cert_content'] ?? null)
            && @openssl_x509_fingerprint($cert['cert_content'], 'sha256') === $fingerprint;
    }

    private function findCertId(string $fingerprint, string $privatekey): ?string
    {
        $seen = [];
        $match = null;
        // No CN filter: certificates may contain only SANs or IP addresses.
        for ($page = 1; $page <= 100; $page++) {
            $data = $this->page($page, 100);
            foreach ($data['list'] as $cert) {
                $id = $cert['id'] ?? null;
                if (!is_string($id) || $id === '' || strlen($id) > 255 || isset($seen[$id])) throw new Exception('SamWaf证书分页重复或ID无效');
                $seen[$id] = true;
                if ($this->matches($cert, $fingerprint)) {
                    if ($match !== null) throw new Exception('存在多个相同证书，请明确填写证书ID');
                    if (!is_string($cert['key_content'] ?? null) || !hash_equals($privatekey, $cert['key_content'])) throw new Exception('SamWaf存在相同证书但私钥缺失或不同，请指定证书ID后更新');
                    $match = $id;
                }
            }
            if (count($seen) >= (int)$data['total']) return $match;
            if (!$data['list']) throw new Exception('SamWaf证书分页不完整');
        }
        throw new Exception('SamWaf证书超过10000条，请明确填写证书ID');
    }

    private function page(int $page, int $size): array
    {
        $data = $this->request('/sslconfig/list', ['pageIndex' => $page, 'pageSize' => $size]);
        if (is_array($data) && ($data['list'] ?? null) === null && (string)($data['total'] ?? '') === '0') $data['list'] = [];
        if (!is_array($data) || !is_array($data['list'] ?? null) || !array_is_list($data['list'])
            || !isset($data['total']) || !ctype_digit((string)$data['total']) || count($data['list']) > $size) throw new Exception('SamWaf证书列表响应无效');
        return $data;
    }

    private function detail(string $id): array
    {
        $data = $this->request('/sslconfig/detail', ['id' => $id], 'GET');
        if (!is_array($data) || ($data['id'] ?? null) !== $id) throw new Exception('SamWaf证书ID不存在或详情不匹配');
        return $data;
    }

    private function request(string $path, ?array $params = null, string $method = 'POST')
    {
        $parts = parse_url($this->url);
        if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || preg_match('/[\x00-\x20]/', $this->url)) throw new Exception('SamWaf控制台地址格式不正确');
        if ($this->apiKey === '' || preg_match('/[\r\n]/', $this->apiKey)) throw new Exception('请填写有效的API Key');
        $url = $this->url . '/api/v1' . $path;
        $headers = ['X-API-Key' => $this->apiKey, 'Accept' => 'application/json'];
        $body = null;
        if ($method === 'GET') $url .= '?' . http_build_query($params ?? []);
        elseif ($params !== null) {
            $headers['Content-Type'] = 'application/json';
            $body = json_encode($params, JSON_THROW_ON_ERROR);
        }
        try {
            $response = http_request($url, $body, null, null, $headers, $this->proxy, $method);
            $result = json_decode($response['body'], true);
            $code = (int)$response['code'];
            if ($code >= 200 && $code < 300 && is_array($result) && in_array($result['code'] ?? null, [0, '0'], true)) return $result['data'] ?? null;
            $message = is_array($result) && is_string($result['msg'] ?? null) ? $result['msg'] : '响应无效';
            throw new Exception('SamWaf请求失败(httpCode=' . $code . ')：' . $message);
        } catch (\Throwable $e) {
            throw new Exception(SensitiveLog::filter($e->getMessage(), [$this->apiKey, $params['key_content'] ?? '', $params['cert_content'] ?? '']));
        }
    }

    public function setLogger($func)
    {
        $this->logger = $func;
    }

    private function log(string $text): void
    {
        if ($this->logger) call_user_func($this->logger, $text);
    }
}
