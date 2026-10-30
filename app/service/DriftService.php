<?php
namespace app\service;

use app\lib\DnsHelper;
use app\lib\RecordGuard;
use think\facade\Db;

class DriftService
{
    public static function identity(array $domain): string
    {
        return hash('sha256', json_encode([(string)$domain['id'],(string)$domain['aid'],$domain['name'],(string)$domain['thirdid']]));
    }

    public static function difference(array $before, array $after): array
    {
        $map = static function ($records) {
            $out = [];
            foreach ($records as $record) {
                $record = RecordGuard::canonical($record);
                $key = hash('sha256', json_encode($record));
                $out[$key][] = $record;
            }
            return $out;
        };
        $old=$map($before);$new=$map($after);$removed=[];$added=[];
        foreach ($old as $key=>$records) foreach (array_slice($records,count($new[$key] ?? [])) as $record) $removed[]=$record;
        foreach ($new as $key=>$records) foreach (array_slice($records,count($old[$key] ?? [])) as $record) $added[]=$record;
        return ['added'=>array_slice($added,0,100),'removed'=>array_slice($removed,0,100),'added_count'=>count($added),'removed_count'=>count($removed)];
    }

    public static function capture(array $domain, int $interval = 3600): void
    {
        if ($interval<300 || $interval>86400) throw new \InvalidArgumentException('检查间隔应为300到86400秒');
        $lock=DnsChangeService::lock($domain);
        try {
            $dns=DnsHelper::getModel($domain['aid'],$domain['name'],$domain['thirdid']);
            if (!$dns) throw new \RuntimeException('DNS模块不存在');
            $records=RecordGuard::all($dns,$domain['name']);
            $json=json_encode($records,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            if (strlen($json)>DnsChangeService::MAX_SNAPSHOT_BYTES) throw new \RuntimeException('漂移基线超过8MB');
            Db::name('dns_watch')->replace()->insert(['did'=>$domain['id'],'enabled'=>1,'identity'=>self::identity($domain),'checksum'=>RecordGuard::fingerprint($records),'baseline'=>$json,'observed'=>RecordGuard::fingerprint($records),'diff'=>'{}','error'=>null,'interval_seconds'=>$interval,'checked_at'=>time(),'nexttime'=>time()+$interval,'alert_signature'=>null]);
            config_set('dns_watch_enabled', '1');
        } finally { flock($lock,LOCK_UN);fclose($lock); }
    }

    public static function check(array $domain): array
    {
        $lock=DnsChangeService::lock($domain);
        try {
            $watch=Db::name('dns_watch')->where('did',$domain['id'])->find();
            if (!$watch || !$watch['enabled']) throw new \RuntimeException('尚未启用漂移检测');
            try {
                if (!hash_equals($watch['identity'],self::identity($domain))) throw new \RuntimeException('域名账户绑定已改变，请重新建立基线');
                $dns=DnsHelper::getModel($domain['aid'],$domain['name'],$domain['thirdid']);
                if (!$dns) throw new \RuntimeException('DNS模块不存在');
                $records=RecordGuard::all($dns,$domain['name']);
                $fingerprint=RecordGuard::fingerprint($records);
                $diff=self::difference(json_decode($watch['baseline'],true,512,JSON_THROW_ON_ERROR),$records);
                $error=null;
            } catch (\Throwable $e) { $fingerprint=null;$diff=[];$error=mb_substr(\app\lib\SensitiveLog::filter($e->getMessage()),0,500); }
            $alert=hash('sha256',($fingerprint ?? '') . ':' . ($error ?? ''));
            if (($error || $fingerprint!==$watch['checksum']) && $alert!==$watch['alert_signature']) {
                \app\utils\MsgNotice::queue('dns-drift:'.$domain['id'].':'.$watch['checked_at'].':'.$alert, 'DNS漂移告警', htmlspecialchars($domain['name'],ENT_QUOTES,'UTF-8').'：'.htmlspecialchars($error ?? ('新增'.$diff['added_count'].'项，移除'.$diff['removed_count'].'项'),ENT_QUOTES,'UTF-8'));
            }
            Db::name('dns_watch')->where('did',$domain['id'])->update(['observed'=>$fingerprint,'diff'=>json_encode($diff),'error'=>$error,'checked_at'=>time(),'nexttime'=>time()+$watch['interval_seconds'],'alert_signature'=>$alert]);
            return ['diff'=>$diff,'error'=>$error,'drifted'=>$fingerprint!==$watch['checksum']];
        } finally { flock($lock,LOCK_UN);fclose($lock); }
    }

    /** Called while the writer holds the zone lock. Never absorb pre-existing external drift. */
    public static function managed(array $domain, array $before, array $after): void
    {
        if (!function_exists('config_get') || config_get('dns_watch_enabled','0') !== '1') return;
        $old=RecordGuard::fingerprint($before);$new=RecordGuard::fingerprint($after);
        $json=json_encode($after,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if (strlen($json)>DnsChangeService::MAX_SNAPSHOT_BYTES) return;
        try {
            Db::name('dns_watch')->where('did',$domain['id'])->where('identity',self::identity($domain))->where('checksum',$old)->update(['checksum'=>$new,'baseline'=>$json,'observed'=>$new,'diff'=>'{}','error'=>null]);
        } catch (\Throwable $e) {
            // A monitoring failure cannot turn an already verified DNS write into
            // an uncertain operation or encourage replay. The old baseline remains.
            error_log('DNS drift baseline persistence failed for domain #' . (int)$domain['id']);
        }
    }
}
