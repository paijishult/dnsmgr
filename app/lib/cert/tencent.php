<?php

namespace app\lib\cert;

use app\lib\CertInterface;
use app\lib\client\TencentCloud;
use Exception;

class tencent implements CertInterface
{
    private $SecretId;
    private $SecretKey;
    private $email;
    private $endpoint = "ssl.tencentcloudapi.com";
    private $service = "ssl";
    private $version = "2019-12-05";
    private $logger;
    private $proxy;
    private TencentCloud $client;

    public function __construct($config, $ext = null)
    {
        $this->SecretId = $config['SecretId'];
        $this->SecretKey = $config['SecretKey'];
        $this->proxy = isset($config['proxy']) ? $config['proxy'] == 1 : false;
        $this->client = new TencentCloud($this->SecretId, $this->SecretKey, $this->endpoint, $this->service, $this->version, null, $this->proxy);
        $this->email = $config['email'];
    }

    /**
     * 测试账户是否可用（只调用查询类接口）
     */
    public function check()
    {
        return $this->register();
    }

    public function register()
    {
        if (empty($this->SecretId) || empty($this->SecretKey) || empty($this->email)) throw new Exception('必填参数不能为空');
        $this->request('DescribeCertificates', []);
        return true;
    }

    public function buyCert($domainList, &$order) {}

    public function createOrder($domainList, &$order, $keytype, $keysize)
    {
        if (empty($domainList)) throw new Exception('域名列表不能为空');
        $domain = $domainList[0];
        $param = [
            'DvAuthMethod' => 'DNS',
            'DomainName' => $domain,
            'ContactEmail' => $this->email,
            'CsrEncryptAlgo' => $keytype,
            'CsrKeyParameter' => $keytype == 'ECC' ? 'prime256v1' : '2048',
        ];
        $data = $this->request('ApplyCertificate', $param);
        if (empty($data['CertificateId'])) throw new Exception('证书申请失败，CertificateId为空');
        $order['CertificateId'] = $data['CertificateId'];

        $param = [
            'CertificateId' => $order['CertificateId'],
        ];
        $data = $this->request('DescribeCertificate', $param);
        $order['OrderId'] = $data['OrderId'];

        $dnsList = [];
        if (!empty($data['DvAuthDetail']['DvAuths'])) {
            foreach ($data['DvAuthDetail']['DvAuths'] as $opts) {
                $mainDomain = getMainDomain($opts['DvAuthKey']);
                $name = substr($opts['DvAuthKey'], 0, -(strlen($mainDomain) + 1));
                $dnsList[$mainDomain][] = ['name' => $name, 'type' => $opts['DvAuthVerifyType'] ?? 'CNAME', 'value' => $opts['DvAuthValue']];
            }
        }

        return $dnsList;
    }

    public function authOrder($domainList, $order)
    {
        $param = [
            'CertificateId' => $order['CertificateId'],
        ];
        $data = $this->request('DescribeCertificate', $param);
        if ($data['Status'] == 0 || $data['Status'] == 4) {
            $this->request('CompleteCertificate', $param);
            sleep(3);
        }
    }

    public function getAuthStatus($domainList, $order)
    {
        $param = [
            'CertificateId' => $order['CertificateId'],
        ];
        $data = $this->request('DescribeCertificate', $param);
        if ($data['Status'] == 1) {
            return true;
        } elseif ($data['Status'] == 2) {
            throw new Exception('证书审核失败' . (empty($data['StatusMsg'] ? '' : ':' . $data['StatusMsg'])));
        } else {
            return false;
        }
    }

    public function finalizeOrder($domainList, $order, $keytype, $keysize)
    {
        $param = [
            'CertificateIds' => [$order['CertificateId']],
            'SwitchStatus' => 1,
        ];
        $this->request('ModifyCertificatesExpiringNotificationSwitch', $param);

        $param = [
            'CertificateId' => $order['CertificateId'],
            'ServiceType' => 'nginx',
        ];
        $data = $this->request('DescribeDownloadCertificateUrl', $param);
        $file_data = http_request($data['DownloadCertificateUrl'], null, null, null, null, $this->proxy);
        $file_data = $file_data['body'] ?? null;
        if (empty($file_data)) throw new Exception('下载证书失败');
        $files = \app\lib\CertificateArchive::read($file_data, $domainList);
        $private_key = $files['private_key'];
        $fullchain = $files['fullchain'];

        $certInfo = openssl_x509_parse($fullchain, true);
        if (!$certInfo) throw new Exception('证书解析失败');
        return ['private_key' => $private_key, 'fullchain' => $fullchain, 'issuer' => $certInfo['issuer']['CN'], 'subject' => $certInfo['subject']['CN'], 'validFrom' => $certInfo['validFrom_time_t'], 'validTo' => $certInfo['validTo_time_t']];
    }

    public function revoke($order, $pem)
    {
        $param = [
            'CertificateId' => $order['CertificateId'],
        ];
        $action = 'RevokeCertificate';
        $data = $this->request($action, $param);

        if (!empty($data['RevokeDomainValidateAuths'])) {
            $dnsList = [];
            foreach ($data['RevokeDomainValidateAuths'] as $opts) {
                $mainDomain = getMainDomain($opts['DomainValidateAuthKey']);
                $name = substr($opts['DomainValidateAuthKey'], 0, -(strlen($mainDomain) + 1));
                $dnsList[$mainDomain][] = ['name' => $name, 'type' => 'TXT', 'value' => $opts['DomainValidateAuthValue']];
            }
            \app\utils\CertDnsUtils::addDns($dnsList, function ($txt) {
                $this->log($txt);
            });
        }
    }

    public function cancel($order)
    {
        $param = [
            'CertificateId' => $order['CertificateId'],
        ];
        $action = 'CancelAuditCertificate';
        $this->request($action, $param);
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

    private function request($action, $param)
    {
        $this->log('Action:' . $action . PHP_EOL . 'Request:' . json_encode($param, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $result = $this->client->request($action, $param);
        $this->log('Response:' . json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $result;
    }
}
