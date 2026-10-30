<?php
namespace app\service;

use think\facade\Db;

class OperationsService
{
    public static function execute(): void
    {
        foreach (Db::name('dns_watch')->where('enabled',1)->where('nexttime','<=',time())->order('nexttime')->limit(2)->select() as $watch) {
            $domain=Db::name('domain')->where('id',$watch['did'])->find();
            if ($domain) {
                try { DriftService::check($domain); }
                catch (\Throwable $e) {
                    Db::name('dns_watch')->where('did',$watch['did'])->update(['nexttime'=>time()+300,'error'=>mb_substr(\app\lib\SensitiveLog::filter($e->getMessage()),0,500)]);
                }
            }
            else Db::name('dns_watch')->where('did',$watch['did'])->update(['enabled'=>0,'error'=>'域名已删除']);
        }
        foreach (Db::name('credential_health')->where('enabled',1)->where('nexttime','<=',time())->order('nexttime')->limit(2)->select() as $health) {
            try { self::checkCredential($health['kind'],(int)$health['aid']); }
            catch (\Throwable $e) {
                if ($e->getCode()!==102) {
                    $error=\app\lib\SensitiveLog::filter($e->getMessage());
                    Db::name('credential_health')->where(['kind'=>$health['kind'],'aid'=>$health['aid']])->update(['status'=>'failed','error'=>mb_substr($error,0,500),'nexttime'=>time()+300]);
                    error_log('Credential health: '.$error);
                }
            }
        }
        Db::name('api_nonce')->where('expires','<',time())->limit(1000)->delete();
        Db::name('api_receipt')->where('expires','<',time())->limit(500)->delete();
    }

    /**
     * Alert once per outage when monitoring tasks are enabled but the dmtask worker has
     * stopped sending its heartbeat (it updates run_time every 3 seconds while running).
     */
    public static function monitorWatchdog(): void
    {
        if (!Db::name('dmtask')->where('active',1)->count()) return;
        $runTime=config_get('run_time',null,true);
        $last=$runTime ? strtotime($runTime) : false;
        if ($last && time()-$last<300) return;
        $error=(string)config_get('run_error','',true);
        $content='尊敬的用户，您好：<br/>有已启用的容灾监控任务，但容灾监控进程已停止运行，健康检查和自动切换都不会执行。<br/>最后运行时间：'.htmlspecialchars($runTime ?: '无',ENT_QUOTES,'UTF-8');
        if ($error!=='') $content.='<br/>错误信息：'.htmlspecialchars(\app\lib\SensitiveLog::filter($error),ENT_QUOTES,'UTF-8');
        $content.='<br/>请检查 dmtask 进程（Docker 镜像中由 Supervisor 守护，可查看容器日志）。';
        \app\utils\MsgNotice::queue('monitor-worker-down:'.($runTime ?: 'never'),'容灾监控进程未运行',$content,'notice_',false);
    }

    public static function credential(string $kind,int $id): array
    {
        $table=match($kind){'dns'=>'account','cert','deploy'=>'cert_account','api'=>'apikey',default=>throw new \InvalidArgumentException('账户类型无效')};
        $row=Db::name($table)->where('id',$id)->find();
        if (!$row || ($kind==='deploy' && !$row['deploy']) || ($kind==='cert' && $row['deploy'])) throw new \RuntimeException('账户不存在',404);
        return $row;
    }

    public static function credentialVersion(array $row): string
    {
        return hash_hmac('sha256',json_encode([$row['type'] ?? '',$row['config'] ?? '',$row['secret'] ?? '',$row['ext'] ?? '']), (string)config_get('sys_key'));
    }

    public static function checkCredential(string $kind,int $id): array
    {
        $db=TaskLockService::connection();$lock=new TaskLockService($db,'credential:'.$kind.':'.$id);
        try {
            $lock->acquire();
            $health=$db->name('credential_health')->where(['kind'=>$kind,'aid'=>$id])->find();
            if (!$health) throw new \RuntimeException('请先保存账户检查配置');
            try { $row=self::credential($kind,$id); }
            catch (\RuntimeException $e) {
                if ($e->getCode()!==404) throw $e;
                $update=['enabled'=>0,'status'=>'failed','error'=>'账户已删除或类型已改变','checked_at'=>time(),'nexttime'=>time()+86400];
                $db->name('credential_health')->where(['kind'=>$kind,'aid'=>$id])->update($update);
                return $update;
            }
            $version=self::credentialVersion($row);
            $status='healthy';$error=null;
            try {
                if ($kind==='dns') {
                    $client=\app\lib\DnsHelper::getModel($id);
                    if (!$client || $client->getDomainList(null,1,1)===false) throw new \RuntimeException($client ? $client->getError() : 'DNS模块不存在');
                } elseif ($kind==='deploy' && in_array($row['type'],['btpanel','lucky','ratpanel'],true)) {
                    \app\lib\DeployHelper::getModel($id)->check();
                } elseif ($kind==='api') {
                    if (!$row['status']) throw new \RuntimeException('API密钥已停用');
                } else { $status='manual';$error='此平台尚未提供已验证的只读探测，请在平台核对权限；到期与轮换提醒仍有效'; }
                $expiry=$kind==='api' ? ($row['expiretime'] ?? null) : $health['expires_at'];
                $changed=$health['version']!=='' && !hash_equals($health['version'],$version);
                $rotated=$changed ? date('Y-m-d H:i:s') : ($health['rotated_at'] ?: ($row['addtime'] ?? null));
                if ($expiry && strtotime($expiry)<time()+7*86400) { $status='warning';$error='凭据已到期或将在7天内到期'; }
                elseif ($rotated && strtotime($rotated)+(int)$health['rotation_days']*86400<=time()) { $status='warning';$error='凭据已达到轮换周期'; }
            } catch (\Throwable $e) { $status='failed';$error=\app\lib\SensitiveLog::filter($e->getMessage(),\app\lib\SensitiveLog::secrets(json_decode($row['config'] ?? '{}',true) ?: [])); }
            if (!hash_equals($version,self::credentialVersion(self::credential($kind,$id)))) {
                $db->name('credential_health')->where(['kind'=>$kind,'aid'=>$id])->update(['status'=>'unknown','error'=>'检查期间凭据已变更，将重新检查','nexttime'=>time()+300]);
                throw new \RuntimeException('检查期间凭据已变更，请重新检查');
            }
            $alert=hash('sha256',$version.':'.$status.':'.($error ?? ''));
            if (in_array($status,['failed','warning'],true) && $alert!==$health['alert_signature']) \app\utils\MsgNotice::queue('credential:'.$kind.':'.$id.':'.$health['checked_at'].':'.$alert,'凭据健康提醒',htmlspecialchars($kind.' #'.$id.'：'.$error,ENT_QUOTES,'UTF-8'));
            $update=['version'=>$version,'status'=>$status,'error'=>$error ? mb_substr($error,0,500) : null,'checked_at'=>time(),'nexttime'=>time()+86400,'alert_signature'=>$alert];
            if (isset($rotated)) $update['rotated_at']=$rotated;
            $db->name('credential_health')->where(['kind'=>$kind,'aid'=>$id])->update($update);
            return $update;
        } finally { try{$lock->release();}finally{$db->close();} }
    }

    public static function conflicts(array $tasks): array
    {
        $groups=[];
        foreach ($tasks as $task) {
            if (empty($task['active'])) continue;
            $key=(int)$task['did'].':'.strtolower(rtrim($task['rr'],'.'));
            $groups[$key][]=$task;
        }
        $out=[];
        foreach ($groups as $items) {
            if (count($items)<2) continue;
            $ids=array_unique(array_column($items,'recordid'));
            $optimize=count(array_filter($items,fn($t)=>$t['kind']==='optimize'))>0;
            if (count($ids)===count($items) && !$optimize) continue;
            $out[]=['did'=>$items[0]['did'],'rr'=>$items[0]['rr'],'tasks'=>$items,'message'=>$optimize ? '优选IP会修改同主机记录集，可能覆盖其他任务的结果' : '多个任务写入同一记录，请核对执行时间、主备值与优先级'];
        }
        return $out;
    }
}
