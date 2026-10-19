<?php
namespace app\lib;

class SecureFiles
{
    public static function temporaryDirectory(): string
    {
        $path = rtrim(sys_get_temp_dir(), '/\\') . '/dnsmgr-' . bin2hex(random_bytes(16));
        if (!mkdir($path, 0700)) throw new \RuntimeException('无法创建私有临时目录');
        return $path;
    }

    public static function write(string $path, string $contents, int $mode = 0600): void
    {
        if ($path === '' || str_contains($path, "\0") || is_link($path) || !is_dir(dirname($path))) throw new \RuntimeException('证书保存路径无效或是符号链接');
        $temporary = tempnam(dirname($path), '.dnsmgr-');
        if ($temporary === false) throw new \RuntimeException('无法创建证书临时文件');
        try {
            if (!chmod($temporary, $mode) || file_put_contents($temporary, $contents) !== strlen($contents)) throw new \RuntimeException('无法安全保存证书文件');
            if (!rename($temporary, $path)) throw new \RuntimeException('无法替换证书文件');
        } finally { if (is_file($temporary)) unlink($temporary); }
    }

    public static function removeDirectory(string $path): void
    {
        if (!is_dir($path) || is_link($path)) return;
        foreach (new \FilesystemIterator($path) as $entry) {
            if ($entry->isDir() && !$entry->isLink()) self::removeDirectory($entry->getPathname());
            else unlink($entry->getPathname());
        }
        rmdir($path);
    }
}
