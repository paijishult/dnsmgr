<?php

namespace app\lib\deploy;

use app\lib\DeployInterface;
use Exception;

class local implements DeployInterface
{
    private $logger;

    public function __construct($config) {}

    public function check() {}

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        if (!empty($config['cmd']) && !function_exists('exec')) {
            throw new Exception('exec函数被禁用');
        }
        if ($config['format'] == 'pem') {
            $dir = dirname($config['pem_cert_file']);
            if (!is_dir($dir)) throw new Exception($dir . ' 目录不存在');
            if (!is_writable($dir)) throw new Exception($dir . ' 目录不可写');

            \app\lib\SecureFiles::write($config['pem_cert_file'], $fullchain, 0644);
            $this->log('证书已保存到：' . $config['pem_cert_file']);
            \app\lib\SecureFiles::write($config['pem_key_file'], $privatekey);
            $this->log('私钥已保存到：' . $config['pem_key_file']);
        } elseif ($config['format'] == 'pfx') {
            $dir = dirname($config['pfx_file']);
            if (!is_dir($dir)) throw new Exception($dir . ' 目录不存在');
            if (!is_writable($dir)) throw new Exception($dir . ' 目录不可写');

            $pfx = \app\lib\CertHelper::getPfx($fullchain, $privatekey, $config['pfx_pass'] ? $config['pfx_pass'] : null);
            \app\lib\SecureFiles::write($config['pfx_file'], $pfx);
            $this->log('PFX证书已保存到：' . $config['pfx_file']);
        }
        if (!empty($config['cmd'])) {
            $cmds = explode("\n", $config['cmd']);
            foreach ($cmds as $cmd) {
                $cmd = trim($cmd);
                if (empty($cmd)) continue;
                $this->log('执行命令：' . $cmd);
                $output = [];
                $ret = 0;
                exec($cmd, $output, $ret);
                if ($ret == 0) {
                    $this->log('执行命令成功：' . implode("\n", $output));
                } else {
                    throw new Exception('执行命令失败：' . implode("\n", $output));
                }
            }
        }
    }

    private function log($txt)
    {
        if ($this->logger) {
            call_user_func($this->logger, $txt);
        }
    }

    public function setLogger($logger)
    {
        $this->logger = $logger;
    }
}
