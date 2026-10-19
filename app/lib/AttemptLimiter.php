<?php

namespace app\lib;

/**
 * Atomic, bounded-window counters shared by the native PHP workers.
 * The default runtime directory is already shared by the Docker installation.
 */
class AttemptLimiter
{
    public static function check(string $key, int $limit, ?string $directory = null): array
    {
        return self::change($key, $directory, function (array $state) use ($limit): array {
            if (($state['expires'] ?? 0) <= time()) $state = [];
            return [$state, ['allowed' => ($state['count'] ?? 0) < $limit, 'remaining' => max(0, $limit - ($state['count'] ?? 0)), 'retry_after' => max(1, ($state['expires'] ?? 0) - time())]];
        });
    }

    public static function hit(string $key, int $limit, int $seconds, ?string $directory = null): array
    {
        return self::change($key, $directory, function (array $state) use ($limit, $seconds): array {
            $now = time();
            if (($state['expires'] ?? 0) <= $now) $state = ['count' => 0, 'expires' => $now + $seconds];
            $allowed = $state['count'] < $limit;
            if ($allowed) $state['count']++;
            return [$state, ['allowed' => $allowed, 'remaining' => max(0, $limit - $state['count']), 'retry_after' => max(1, $state['expires'] - $now)]];
        });
    }

    public static function clear(string $key, ?string $directory = null): void
    {
        // Keep the lock inode stable: unlinking a locked file would split workers.
        self::change($key, $directory, fn(array $state) => [[], null]);
    }

    private static function change(string $key, ?string $directory, callable $callback)
    {
        $directory = $directory ?? app()->getRuntimePath() . 'limits';
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('无法创建安全计数目录，请检查 runtime 权限');
        }
        $file = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
        $handle = @fopen($file, 'c+');
        if (!$handle) throw new \RuntimeException('无法读取安全计数，请检查 runtime 权限');
        @chmod($file, 0600);
        try {
            if (!flock($handle, LOCK_EX)) throw new \RuntimeException('无法锁定安全计数');
            $raw = stream_get_contents($handle, 4096);
            $state = json_decode($raw ?: '{}', true);
            [$state, $result] = $callback(is_array($state) ? $state : []);
            $json = json_encode($state, JSON_THROW_ON_ERROR);
            rewind($handle);
            if (!ftruncate($handle, 0) || fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
                throw new \RuntimeException('无法保存安全计数');
            }
            return $result;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
