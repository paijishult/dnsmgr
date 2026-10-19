<?php
namespace app\lib;

class SshSecurity
{
    public static function connect(array $config)
    {
        $expected = preg_replace('/[:\s]/', '', preg_replace('/\ASHA1:/i', '', trim((string)($config['host_fingerprint'] ?? ''))));
        if (!preg_match('/\A[a-f0-9]{40}\z/iD', $expected)) throw new \RuntimeException('请先填写通过可信渠道核实的SSH主机SHA-1指纹（40位十六进制）');
        $connection = ssh2_connect($config['host'], (int)$config['port']);
        if (!$connection) throw new \RuntimeException('SSH连接失败');
        $actual = ssh2_fingerprint($connection, SSH2_FINGERPRINT_SHA1 | SSH2_FINGERPRINT_HEX);
        if (!is_string($actual) || !hash_equals(strtolower($expected), strtolower($actual))) throw new \RuntimeException('SSH主机指纹不匹配，已在认证前停止连接');
        return $connection;
    }

    public static function upload($sftp, string $path, string $contents, int $mode = 0600): void
    {
        if (!str_starts_with($path, '/') || str_contains($path, "\0") || preg_match('/[\r\n]/', $path)) throw new \RuntimeException('SSH文件路径必须为绝对路径');
        $temporary = $path . '.dnsmgr-' . bin2hex(random_bytes(16));
        $stream = fopen('ssh2.sftp://' . intval($sftp) . $temporary, 'w');
        if (!$stream) throw new \RuntimeException('无法创建SSH临时文件');
        try {
            // The file is still empty when its permission is restricted.
            if (!ssh2_sftp_chmod($sftp, $temporary, $mode)) throw new \RuntimeException('无法设置SSH私有文件权限');
            $offset = 0;
            while ($offset < strlen($contents)) {
                $written = fwrite($stream, substr($contents, $offset));
                if (!$written) throw new \RuntimeException('SSH文件写入不完整');
                $offset += $written;
            }
            if (!fflush($stream)) throw new \RuntimeException('无法刷新SSH文件');
            fclose($stream); $stream = null;
            if (!ssh2_sftp_rename($sftp, $temporary, $path)) throw new \RuntimeException('无法替换SSH目标文件');
        } finally {
            if (is_resource($stream)) fclose($stream);
            @ssh2_sftp_unlink($sftp, $temporary);
        }
    }
}
