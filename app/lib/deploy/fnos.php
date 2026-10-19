<?php

namespace app\lib\deploy;

use app\lib\DeployInterface;
use Exception;

class fnos implements DeployInterface
{
    private $logger;
    private $config;


    public function __construct($config)
    {
        $this->config = $config;
    }

    public function check()
    {
        $this->connect();
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        $domains = $config['domainList'];
        if (empty($domains)) throw new Exception('没有设置要部署的域名');

        $certInfo = openssl_x509_parse($fullchain, true);
        if (!$certInfo) throw new Exception('证书解析失败');

        $connection = $this->connect();
        $cert_all = $this->exec($connection, '获取证书列表', 'cat /usr/trim/etc/network_cert_all.conf');
        $list = json_decode($cert_all, true);
        if (!$list) throw new Exception('获取证书列表失败');

        $success = 0;
        foreach ($list as $row) {
            if (empty($row['san'])) continue;
            $cert_domains = $row['san'];
            $flag = false;
            foreach ($cert_domains as $domain) {
                if (in_array($domain, $domains)) {
                    $flag = true;
                    break;
                }
            }
            if ($flag) {
                $certPath = $row['certificate'];
                $keyPath = $row['privateKey'];
                $this->writeRootFile($connection, $certPath, $fullchain, 0644);
                $this->writeRootFile($connection, $keyPath, $privatekey, 0600);
                $literal = static fn($value) => "E'" . str_replace(["\\", "'"], ["\\\\", "''"], (string)$value) . "'";
                $sql = 'SET standard_conforming_strings=on; UPDATE cert SET valid_to=' . (int)$certInfo['validTo_time_t'] . '000,valid_from=' . (int)$certInfo['validFrom_time_t'] . '000,issued_by=' . $literal($certInfo['issuer']['CN'] ?? '') . ',updated_time=' . (int)getMillisecond() . ' WHERE private_key=' . $literal($keyPath);
                $this->exec($connection, '更新数据表', 'cd /tmp && sudo -u postgres psql -v ON_ERROR_STOP=1 -d trim_connect -c ' . escapeshellarg($sql));
                $this->log('证书 '.$row['domain'].' 更新成功');
                $success++;
            }
        }
        if ($success == 0) {
            throw new Exception('没有要更新的证书');
        } else {
            $this->exec($connection, '重启webdav', 'sudo systemctl restart webdav.service');
            $this->exec($connection, '重启smbftpd', 'sudo systemctl restart smbftpd.service');
            $this->exec($connection, '重启trim_nginx', 'sudo systemctl restart trim_nginx.service');
        }
    }

    private function writeRootFile($connection, string $path, string $contents, int $mode): void
    {
        if (!str_starts_with($path, '/') || preg_match('/[\x00\r\n]/', $path)) throw new Exception('飞牛证书路径无效');
        $sftp = ssh2_sftp($connection);
        if (!$sftp) throw new Exception('无法建立SFTP连接');
        $directory = '/tmp/dnsmgr-' . bin2hex(random_bytes(16));
        if (!ssh2_sftp_mkdir($sftp, $directory, 0700)) throw new Exception('无法创建私有SFTP目录');
        $source = $directory . '/upload';
        $target = $path . '.dnsmgr-' . bin2hex(random_bytes(16));
        try {
            \app\lib\SshSecurity::upload($sftp, $source, $contents);
            $this->exec($connection, '准备证书文件', 'sudo install -m ' . decoct($mode) . ' -- ' . escapeshellarg($source) . ' ' . escapeshellarg($target));
            $this->exec($connection, '替换证书文件', 'sudo mv -f -- ' . escapeshellarg($target) . ' ' . escapeshellarg($path));
        } finally {
            try { $this->exec($connection, '清理临时文件', 'sudo rm -f -- ' . escapeshellarg($target)); }
            catch (\Throwable $e) { $this->log('临时文件清理失败，请检查远端权限'); }
            finally { @ssh2_sftp_unlink($sftp, $source); @ssh2_sftp_rmdir($sftp, $directory); }
        }
    }

    private function exec($connection, $name, $cmd)
    {
        $marker = '__DNSMGR_EXIT_' . bin2hex(random_bytes(12)) . '__';
        $stream = ssh2_exec($connection, '(' . $cmd . '); printf ' . escapeshellarg("\n" . $marker . '%d') . ' "$?"');
        if (!$stream) {
            throw new Exception($name.'执行命令失败');
        }
        $errorStream = ssh2_fetch_stream($stream, SSH2_STREAM_STDERR);
        if (!$errorStream) { fclose($stream); throw new Exception($name.'无法读取命令结果'); }
        stream_set_blocking($stream, true);
        stream_set_blocking($errorStream, true);
        stream_set_timeout($stream, 30);
        stream_set_timeout($errorStream, 30);
        $output = stream_get_contents($stream);
        $errorOutput = stream_get_contents($errorStream);
        fclose($stream);
        fclose($errorStream);
        if (!preg_match('/\n' . preg_quote($marker, '/') . '([0-9]+)\z/D', $output, $match)) throw new Exception($name.'未取得命令退出状态');
        $output = substr($output, 0, -strlen($match[0]));
        if ((int)$match[1] !== 0) {
            if (strpos($errorOutput, 'a password is required') !== false) {
                throw new Exception('权限不足，请先配置 sudo 免密');
            }
            throw new Exception($name.'失败：' . trim($errorOutput));
        } else {
            if (strlen($output) > 200) {
                return $output;
            }
            $this->log($name.'成功 ' . trim($output));
            return $output;
        }
    }

    private function connect()
    {
        if (!function_exists('ssh2_connect')) {
            throw new Exception('ssh2扩展未安装');
        }
        if (empty($this->config['host']) || empty($this->config['port']) || empty($this->config['username']) || empty($this->config['password'])) {
            throw new Exception('必填参数不能为空');
        }
        if (!filter_var($this->config['host'], FILTER_VALIDATE_IP) && !filter_var($this->config['host'], FILTER_VALIDATE_DOMAIN)) {
            throw new Exception('主机地址不合法');
        }
        if (!is_numeric($this->config['port']) || $this->config['port'] < 1 || $this->config['port'] > 65535) {
            throw new Exception('SSH端口不合法');
        }

        $connection = \app\lib\SshSecurity::connect($this->config);
        if (!$connection) {
            throw new Exception('SSH连接失败');
        }
        if (!ssh2_auth_password($connection, $this->config['username'], $this->config['password'])) {
            throw new Exception('用户名或密码错误');
        }
        return $connection;
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
}
