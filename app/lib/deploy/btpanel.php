<?php

namespace app\lib\deploy;

use app\lib\DeployInterface;
use app\lib\CertHelper;
use Exception;

class btpanel implements DeployInterface
{
    private $logger;
    private $url;
    private $key;
    private $proxy;

    public function __construct($config)
    {
        $this->url = rtrim($config['url'], '/');
        $this->key = $config['key'];
        $this->proxy = $config['proxy'] == 1;
    }

    public function check()
    {
        if (empty($this->url) || empty($this->key)) throw new Exception('请填写面板地址和接口密钥');

        $path = '/config?action=get_config';
        $response = $this->request($path, []);
        $result = json_decode($response, true);
        if (isset($result['status']) && ($result['status'] == 1 || isset($result['sites_path']))) {
            return true;
        } else {
            throw new Exception(isset($result['msg']) ? $result['msg'] : '面板地址无法连接');
        }
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        if ($config['type'] == '1') {
            $this->deployPanel($fullchain, $privatekey);
            $this->log("面板证书部署成功");
            return;
        }

        $targets = [];
        foreach (explode("\n", $config['sites']) as $site) if (trim($site) !== '') $targets[$config['type'] . ':' . trim($site)] = trim($site);
        \app\lib\DeploymentTargets::run($fullchain, $targets, $info, function ($site) use ($config, $fullchain, $privatekey) {
            $method = match ((string)$config['type']) { '4'=>'deployProxy', '3'=>'deployDocker', '2'=>'deployMailSys', default=>'deploySite' };
            $this->$method($site, $fullchain, $privatekey);
            $this->log("网站 {$site} 证书部署成功");
        }, $config['_checkpoint'] ?? null, $config['_redact_error'] ?? null);
    }

    private function deployPanel($fullchain, $privatekey)
    {
        $path = '/config?action=SavePanelSSL';
        $data = [
            'privateKey' => $privatekey,
            'certPem' => $fullchain,
        ];
        $response = $this->request($path, $data);
        $result = json_decode($response, true);
        if (isset($result['status']) && $result['status']) {
            return true;
        } elseif (isset($result['msg'])) {
            throw new Exception($result['msg']);
        } else {
            throw new Exception($response ? $response : '返回数据解析失败');
        }
    }

    private function deploySite($siteName, $fullchain, $privatekey)
    {
        $path = '/site?action=SetSSL';
        $data = [
            'type' => '0',
            'siteName' => $siteName,
            'key' => $privatekey,
            'csr' => $fullchain,
        ];
        $response = $this->request($path, $data);
        $result = json_decode($response, true);
        if (isset($result['status']) && $result['status']) {
            return true;
        } elseif (isset($result['msg'])) {
            throw new Exception($result['msg']);
        } else {
            throw new Exception($response ? $response : '返回数据解析失败');
        }
    }

    private function deployMailSys($domain, $fullchain, $privatekey)
    {
        $path = '/plugin?action=a&name=mail_sys&s=set_mail_certificate_multiple';
        $data = [
            'domain' => $domain,
            'key' => $privatekey,
            'csr' => $fullchain,
            'act' => 'add',
        ];
        $response = $this->request($path, $data);
        $result = json_decode($response, true);
        if (isset($result['status']) && $result['status']) {
            return true;
        } elseif (isset($result['msg'])) {
            throw new Exception($result['msg']);
        } else {
            throw new Exception($response ? $response : '返回数据解析失败');
        }
    }

    private function deployDocker($domain, $fullchain, $privatekey)
    {
        $path = '/mod/docker/com/set_ssl';
        $data = [
            'site_name' => $domain,
            'key' => $privatekey,
            'csr' => $fullchain,
        ];
        $response = $this->request($path, $data);
        $result = json_decode($response, true);
        if (isset($result['status']) && $result['status']) {
            return true;
        } elseif (isset($result['msg'])) {
            throw new Exception($result['msg']);
        } else {
            throw new Exception($response ? $response : '返回数据解析失败');
        }
    }

    private function deployProxy($domain, $fullchain, $privatekey)
    {
        $path = '/mod/proxy/com/set_ssl';
        $data = [
            'site_name' => $domain,
            'key' => $privatekey,
            'csr' => $fullchain,
        ];
        $response = $this->request($path, $data);
        $result = json_decode($response, true);
        if (isset($result['status']) && $result['status']) {
            return true;
        } elseif (isset($result['msg'])) {
            throw new Exception($result['msg']);
        } else {
            throw new Exception($response ? $response : '返回数据解析失败');
        }
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

    private function request($path, $params, $file = false)
    {
        $url = $this->url . $path;

        $now_time = (string) time();
        $headers = [];
        if ($file) {
            $post_data = [
                ['name' => 'request_token', 'contents' => md5($now_time . md5($this->key))],
                ['name' => 'request_time', 'contents' => $now_time],
            ];
            $post_data = array_merge($post_data, $params);
            $headers['Content-Type'] = 'multipart/form-data';
        } else {
            $post_data = [
                'request_token' => md5($now_time . md5($this->key)),
                'request_time' => $now_time
            ];
            $post_data = array_merge($post_data, $params);
        }
        $response = http_request($url, $post_data, null, null, $headers, $this->proxy);
        return $response['body'];
    }
}
