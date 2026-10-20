<?php
namespace app\service;

use app\lib\DnsHelper;
use RuntimeException;
use think\facade\Cache;
use think\facade\Config;

/** Restartable legacy DDL: skip only schema elements which actually exist. */
class LegacyMigrationService
{
    public static function ensure(): void
    {
        $target = (int)config('app.dbversion');
        $current = (int)config_get('version');
        if ($target && $current > $target) throw new RuntimeException('数据库版本高于当前程序，不支持直接降级');
        if (!$target || $current === $target) return;
        $db = TaskLockService::connection();
        $lock = new TaskLockService($db, 'legacy-schema-migration');
        try {
            $lock->acquire();
            $prefix = (string)config('database.connections.mysql.prefix', env('database.prefix', 'dnsmgr_'));
            if (!preg_match('/\A[a-zA-Z0-9_]{0,64}\z/D', $prefix)) throw new RuntimeException('数据库表前缀不合法');
            $source = file_get_contents(app()->getAppPath() . 'sql/update.sql');
            if ($source === false) throw new RuntimeException('数据库升级脚本不存在');
            self::apply($db, str_replace('dnsmgr_', $prefix, $source));
            $old = (int)$db->name('config')->where('key', 'version')->value('value');
            self::convertAccounts($db);
            if ($old > 0 && $old < 1050 && config_get('admin_skin')) {
                $db->name('config')->replace()->insert(['key' => 'admin_skin', 'value' => 'skin-modern']);
                Config::set(['admin_skin' => 'skin-modern'], 'sys');
            }
            // Only commit the version marker after every statement, schema check and data conversion succeeds.
            $db->name('config')->replace()->insert(['key' => 'version', 'value' => (string)$target]);
            Config::set(['version' => (string)$target], 'sys');
            Cache::delete('configs');
        } finally { try { $lock->release(); } finally { $db->close(); } }
    }

    public static function apply($db, string $source): void
    {
        foreach (explode(';', $source) as $statement) {
            $statement = trim($statement);
            if ($statement === '') continue;
            if (preg_match('/\ACREATE TABLE IF NOT EXISTS `([a-zA-Z0-9_]+)`/i', $statement, $match)) {
                $db->execute($statement);
                $columns = self::columns($db, $match[1]);
                preg_match_all('/^\s*`([^`]+)`\s+/m', $statement, $required);
                foreach ($required[1] as $name) if (!isset($columns[$name])) throw new RuntimeException('数据库升级未完成：' . $match[1] . '.' . $name . ' 缺失');
            } elseif (preg_match('/\AALTER TABLE `([a-zA-Z0-9_]+)`\s+(.+)\z/is', $statement, $match)) {
                $table = $match[1];
                foreach (preg_split('/,\s*(?=(?:ADD|CHANGE)\s)/i', $match[2]) as $clause) {
                    $columns = self::columns($db, $table);
                    if (preg_match('/\AADD COLUMN `([^`]+)`/i', trim($clause), $field)) {
                        if (!isset($columns[$field[1]])) $db->execute('ALTER TABLE `' . $table . '` ' . $clause);
                        if (!isset(self::columns($db, $table)[$field[1]])) throw new RuntimeException('数据库列升级未完成');
                    } elseif (preg_match('/\ACHANGE COLUMN `([^`]+)` `([^`]+)`/i', trim($clause), $field)) {
                        if (!isset($columns[$field[2]])) {
                            if (!isset($columns[$field[1]])) throw new RuntimeException('数据库旧字段缺失：' . $table . '.' . $field[1]);
                            $db->execute('ALTER TABLE `' . $table . '` ' . $clause);
                        }
                        if (!isset(self::columns($db, $table)[$field[2]])) throw new RuntimeException('数据库列重命名未完成');
                    } elseif (preg_match('/\AADD KEY `([^`]+)`/i', trim($clause), $field)) {
                        $keys = array_column($db->query('SHOW INDEX FROM `' . $table . '`'), 'Key_name');
                        if (!in_array($field[1], $keys, true)) $db->execute('ALTER TABLE `' . $table . '` ' . $clause);
                        if (!in_array($field[1], array_column($db->query('SHOW INDEX FROM `' . $table . '`'), 'Key_name'), true)) throw new RuntimeException('数据库索引升级未完成');
                    } else { throw new RuntimeException('无法识别的数据库升级操作'); }
                }
            } else { throw new RuntimeException('无法识别的数据库升级语句'); }
            // Refresh both this connection and ThinkORM's shared field cache.
            // A new connection alone can still reload the pre-upgrade schema.
            $db->getSchemaInfo($match[1], true);
        }
    }

    private static function columns($db, string $table): array
    {
        return array_column($db->query('SHOW COLUMNS FROM `' . $table . '`'), null, 'Field');
    }

    private static function convertAccounts($db): void
    {
        foreach ($db->name('account')->select() as $account) {
            if (!empty($account['config']) || !isset(DnsHelper::$dns_config[$account['type']])) continue;
            $config = []; $index = 0;
            foreach (DnsHelper::$dns_config[$account['type']]['config'] as $field => $item) {
                if ($field === 'proxy') { $config[$field] = $account['proxy']; break; }
                if ($index >= 3) break;
                $config[$field] = $account[['name','sk','ext'][$index++]] ?? '';
            }
            $db->name('account')->where('id', $account['id'])->update(['config' => json_encode($config, JSON_THROW_ON_ERROR)]);
        }
    }
}
