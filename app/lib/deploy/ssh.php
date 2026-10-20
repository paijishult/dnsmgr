<?php

namespace app\lib\deploy;

use app\lib\CertHelper;
use app\lib\DeployInterface;
use Exception;

class ssh implements DeployInterface
{
    private $logger;
    private $config;
    private array $secrets;

    public function __construct($config)
    {
        $this->config = $config;
        $this->secrets = \app\lib\SensitiveLog::secrets($config);
    }

    public function check()
    {
        $this->connect();
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        $this->secrets = array_merge($this->secrets, \app\lib\SensitiveLog::secrets($config));
        $connection = $this->connect();
        if (isset($config['cmd_pre']) && !empty($config['cmd_pre'])) {
            $cmds = explode("\n", $config['cmd_pre']);
            foreach ($cmds as $cmd) {
                $cmd = trim($cmd);
                if (empty($cmd)) continue;
                $this->exec($connection, $cmd, '部署前命令');
            }
        }
        $sftp = ssh2_sftp($connection);
        if ($config['format'] == 'pem') {
            \app\lib\SshSecurity::upload($sftp, $config['pem_cert_file'], $fullchain, 0644);
            $this->log('证书已保存到：' . $config['pem_cert_file']);

            \app\lib\SshSecurity::upload($sftp, $config['pem_key_file'], $privatekey, 0600);
            $this->log('私钥已保存到：' . $config['pem_key_file']);
        } elseif ($config['format'] == 'pfx') {
            $pfx_pass = $config['pfx_pass'] ?? null;
            $pfx = CertHelper::getPfx($fullchain, $privatekey, $pfx_pass);

            \app\lib\SshSecurity::upload($sftp, $config['pfx_file'], $pfx, 0600);
            $this->log('PFX证书已保存到：' . $config['pfx_file']);

            if ($config['uptype'] == '1' && !empty($config['iis_domain'])) {
                $cert_hash = openssl_x509_fingerprint($fullchain, 'sha1');
                $this->deploy_iis($connection, $config['iis_domain'], $config['pfx_file'], $config['pfx_pass'] ?? '', $cert_hash);
                $config['cmd'] = null;
            }
        }
        if (!empty($config['cmd'])) {
            $cmds = explode("\n", $config['cmd']);
            foreach ($cmds as $cmd) {
                $cmd = trim($cmd);
                if (empty($cmd)) continue;
                $this->exec($connection, $cmd, '部署后命令');
            }
        }
    }

    private function deploy_iis($connection, $domain, $pfx_file, $pfx_pass, $cert_hash)
    {
        if (($this->config['windows'] ?? '0') != '1') throw new Exception('IIS部署需要启用Windows模式');
        if (!is_string($cert_hash) || !preg_match('/\A[a-f0-9]{40}\z/iD', $cert_hash)) throw new Exception('无法读取证书指纹');
        $quote = static fn($value) => "'" . str_replace("'", "''", (string)$value) . "'";
        $this->secrets[] = (string)$pfx_pass;
        if (!strpos($domain, ':')) {
            $domain .= ':443';
        }
        $binding = $quote('hostnameport=' . $domain);
        $ret = $this->exec($connection, '& netsh.exe http show sslcert ' . $binding, '查询IIS绑定', true, [0,1]);
        $exists = preg_match('/:\s+([a-f0-9]{40})/i', $ret, $match);
        if ($exists) {
            if ($match[1] == $cert_hash) {
                $this->log('IIS域名 ' . $domain . ' 证书已存在，无需更新');
                return;
            }
        }
        if (substr($pfx_file, 0, 1) == '/') $pfx_file = substr($pfx_file, 1);
        $this->exec($connection, '& certutil.exe -p ' . $quote($pfx_pass) . ' -importPFX ' . $quote($pfx_file), '导入IIS证书', true);
        if ($exists) $this->exec($connection, '& netsh.exe http delete sslcert ' . $binding, '删除旧IIS绑定', true);
        $this->exec($connection, '& netsh.exe http add sslcert ' . $binding . ' ' . $quote('certhash=' . $cert_hash) . ' certstorename=MY ' . $quote('appid={' . $this->uuid() . '}'), '更新IIS绑定', true);
        $this->log('IIS域名 ' . $domain . ' 证书已更新');
    }

    private function uuid()
    {
        $guid = md5(uniqid(mt_rand(), true));
        return substr($guid, 0, 8) . '-' . substr($guid, 8, 4) . '-4' . substr($guid, 12, 3) . '-' . substr($guid, 16, 4) . '-' . substr($guid, 20, 12);
    }

    private function exec($connection, string $cmd, string $name = '远程命令', bool $nativePowerShell = false, array $allowed = [0])
    {
        $this->log('开始执行：' . $name);
        $marker = '__DNSMGR_EXIT_' . bin2hex(random_bytes(12)) . '__';
        if (($this->config['windows'] ?? '0') == '1') {
            $body = $nativePowerShell ? $cmd : '& $env:ComSpec /D /S /C ' . "'" . str_replace("'", "''", $cmd . ' 2>&1') . "'";
            $script = '$ErrorActionPreference=\'Stop\'; $global:LASTEXITCODE=0; try { & { ' . $body . ' }; $dnsmgrCode=[int]$global:LASTEXITCODE } catch { $dnsmgrCode=1 }; [Console]::Out.Write("`n' . $marker . '"+$dnsmgrCode); exit $dnsmgrCode';
            $command = 'powershell.exe -NoLogo -NoProfile -NonInteractive -EncodedCommand ' . base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8')) . ' 2>&1';
        } else {
            $command = 'sh -c ' . escapeshellarg($cmd) . ' 2>&1; dnsmgr_status=$?; printf ' . escapeshellarg("\n" . $marker . '%d') . ' "$dnsmgr_status"';
        }
        $stream = ssh2_exec($connection, $command);
        if (!$stream) throw new Exception($name . '无法启动');
        $errorStream = null;
        try {
            $errorStream = ssh2_fetch_stream($stream, SSH2_STREAM_STDERR);
            if (!$errorStream) throw new Exception($name . '无法读取命令结果');
            foreach ([$stream, $errorStream] as $handle) { stream_set_blocking($handle, true); stream_set_timeout($handle, 60); }
            $output = stream_get_contents($stream, 1048577);
            $errorOutput = stream_get_contents($errorStream, 65537);
            if ((stream_get_meta_data($stream)['timed_out'] ?? false) || (stream_get_meta_data($errorStream)['timed_out'] ?? false)) throw new Exception($name . '执行超时，请核实远端状态');
            if (!is_string($output) || strlen($output) > 1048576 || strlen((string)$errorOutput) > 65536) throw new Exception($name . '输出超过安全上限，请核实远端状态');
            if (!preg_match('/\r?\n' . preg_quote($marker, '/') . '([0-9]+)(?:\r?\n)?\z/D', $output, $match)) throw new Exception($name . '未取得退出状态，请核实远端状态');
            if (!in_array((int)$match[1], $allowed, true)) throw new Exception($name . '执行失败（退出码' . (int)$match[1] . '），请在服务器检查命令');
            $output = substr($output, 0, -strlen($match[0]));
            if (($this->config['windows'] ?? '0') == '1' && $this->containsGBKChinese($output)) $output = mb_convert_encoding($output, 'UTF-8', 'GBK');
            // Output can contain arbitrary credentials echoed by a remote tool.
            $this->log($name . '执行成功');
            return $output;
        } finally {
            if (is_resource($errorStream)) fclose($errorStream);
            fclose($stream);
        }
    }

    private function connect()
    {
        if (!function_exists('ssh2_connect')) {
            throw new Exception('ssh2扩展未安装');
        }
        if (empty($this->config['host']) || empty($this->config['port']) || empty($this->config['username']) || $this->config['auth'] == '0' && empty($this->config['password']) || $this->config['auth'] == '1' && empty($this->config['privatekey'])) {
            throw new Exception('必填参数不能为空');
        }
        if (!filter_var($this->config['host'], FILTER_VALIDATE_IP) && !filter_var($this->config['host'], FILTER_VALIDATE_DOMAIN)) {
            throw new Exception('主机地址不合法');
        }
        if (!is_numeric($this->config['port']) || $this->config['port'] < 1 || $this->config['port'] > 65535) {
            throw new Exception('端口不合法');
        }

        $connection = \app\lib\SshSecurity::connect($this->config);
        if (!$connection) {
            throw new Exception('SSH连接失败');
        }
        if ($this->config['auth'] == '1') {
            $publicKey = $this->getPublicKey($this->config['privatekey']);
            $directory = \app\lib\SecureFiles::temporaryDirectory();
            $publicKeyPath = $directory . '/auth.pub';
            $privateKeyPath = $directory . '/auth.key';
            try {
                \app\lib\SecureFiles::write($privateKeyPath, $this->config['privatekey']);
                \app\lib\SecureFiles::write($publicKeyPath, $publicKey);
                if (!empty($this->config['passphrase'])) {
                    if (!ssh2_auth_pubkey_file($connection, $this->config['username'], $publicKeyPath, $privateKeyPath, $this->config['passphrase'])) {
                        throw new Exception('私钥认证失败');
                    }
                } else {
                    if (!ssh2_auth_pubkey_file($connection, $this->config['username'], $publicKeyPath, $privateKeyPath)) {
                        throw new Exception('私钥认证失败');
                    }
                }
            } finally {
                \app\lib\SecureFiles::removeDirectory($directory);
            }
        } else {
            if (!ssh2_auth_password($connection, $this->config['username'], $this->config['password'])) {
                throw new Exception('用户名或密码错误');
            }
        }
        return $connection;
    }

    private function getPublicKey($privateKey)
    {
        $res = openssl_pkey_get_private($privateKey, (string)($this->config['passphrase'] ?? ''));
        if (!$res) {
            throw new Exception('加载私钥失败');
        }
        $details = openssl_pkey_get_details($res);
        if (!$details || !isset($details['key'])) {
            throw new Exception('从私钥导出公钥失败');
        }
        if (!isset($details['rsa'])) throw new Exception('当前SSH私钥认证仅支持RSA PEM私钥');
        $buffer = pack("N", 7) . "ssh-rsa" .
            $this->sshEncodeBuffer($details['rsa']['e']) .
            $this->sshEncodeBuffer($details['rsa']['n']);
        return "ssh-rsa " . base64_encode($buffer);
    }

    private function sshEncodeBuffer($buffer)
    {
        $len = strlen($buffer);
        if (ord($buffer[0]) & 0x80) {
            $len++;
            $buffer = "\x00" . $buffer;
        }
        return pack("Na*", $len, $buffer);
    }

    private function containsGBKChinese($string)
    {
        return preg_match('/[\x81-\xFE][\x40-\xFE]/', $string) === 1;
    }

    private function log($txt)
    {
        if ($this->logger) {
            call_user_func($this->logger, \app\lib\SensitiveLog::filter((string)$txt, $this->secrets));
        }
    }

    public function setLogger($logger)
    {
        $this->logger = $logger;
    }
}
