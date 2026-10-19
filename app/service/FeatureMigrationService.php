<?php
namespace app\service;

use think\facade\Cache;
use think\facade\Config;
use think\facade\Db;

/** Idempotent additive migration. Never mark a partially-created schema ready. */
class FeatureMigrationService
{
    public const VERSION = '20261020.1';

    public static function ensure(): void
    {
        LegacyMigrationService::ensure();
        if (config_get('native_features') === self::VERSION) return;
        $connection = TaskLockService::connection();
        $lock = new TaskLockService($connection, 'native-features-migration');
        try {
            $lock->acquire();
            if ($connection->name('config')->where('key', 'native_features')->value('value') === self::VERSION) return;
            $prefix = (string)config('database.connections.mysql.prefix', env('database.prefix', 'dnsmgr_'));
            if (!preg_match('/\A[a-zA-Z0-9_]{0,64}\z/D', $prefix)) throw new \RuntimeException('数据库表前缀不合法');
            $source = file_get_contents(app()->getAppPath() . 'sql/features.sql');
            if ($source === false) throw new \RuntimeException('增量数据库脚本不存在');
            foreach (explode(';', $source) as $statement) {
                if (trim($statement) !== '') $connection->execute(str_replace('dnsmgr_', $prefix, $statement));
            }
            $connection->name('dns_snapshot')->field('id,did,uid,domain,aid,thirdid,label,record_count,records,checksum,addtime')->where('id', 0)->select();
            $connection->name('batch_task')->field('id,uid,kid,did,actor,name,identity,plan,results,total,completed,status,cancel_requested,attempts,error,snapshot_id,addtime,updatetime,expiretime')->where('id', 0)->select();
            $connection->name('cert_dns_lease')->field('oid,claim,did,aid,thirdid,domain,rr,type,value,managed')->where('oid', 0)->select();
            $connection->name('config')->replace()->insert(['key' => 'native_features', 'value' => self::VERSION]);
            Config::set(['native_features' => self::VERSION], 'sys');
            Cache::delete('configs');
        } finally {
            $lock->release();
            $connection->close();
        }
    }
}
