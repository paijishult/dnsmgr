<?php
namespace app\controller;

use app\BaseController;
use app\service\OperationsService;
use app\service\DriftService;
use app\service\TaskLockService;
use think\facade\Db;

/** Administrator-only operational views. No account configuration or certificate keys leave this controller. */
class Operations extends BaseController
{
    private function authorize(): void
    {
        if (!checkPermission(2) || !empty($this->request->isApi)) throw new \RuntimeException('仅管理员后台可访问');
    }

    public function index()
    {
        $this->authorize();
        return view('operations/index', ['domains'=>Db::name('domain')->field('id,name')->order('name')->select()]);
    }

    public function data()
    {
        try {
            $this->authorize();
            $rows=match(input('post.panel','tasks')) {
                'tasks'=>$this->tasks(), 'conflicts'=>$this->conflicts(), 'matrix'=>$this->matrix(),
                'drift'=>$this->drift(), 'credentials'=>$this->credentials(), 'notifications'=>$this->notifications(),
                default=>throw new \InvalidArgumentException('未知视图'),
            };
            return json(['code'=>0,'rows'=>$rows,'heartbeat'=>config_get('maintenance_time','尚未运行')]);
        } catch (\Throwable $e) { return json(['code'=>-1,'msg'=>\app\lib\SensitiveLog::filter($e->getMessage())]); }
    }

    private function tasks(): array
    {
        $sources=[
            'batch'=>['batch_task','批量解析','/batch','id,name,total,completed,status,cancel_requested,error,updatetime', [0=>'等待',1=>'执行中',2=>'完成',3=>'失败',4=>'取消']],
            'monitor'=>['dmtask','容灾监控','/dmonitor/task','id,rr,status,active,checktime,checknexttime', [0=>'正常',1=>'已切换']],
            'schedule'=>['sctask','定时切换','/schedule/stask','id,rr,active,updatetime,nexttime', []],
            'optimize'=>['optimizeip','优选IP','/optimizeip/opiplist','id,rr,active,status,errmsg,updatetime',[0=>'等待',1=>'成功',2=>'失败']],
            'order'=>['cert_order','证书签发','/cert/certorder','id,status,error,retry,retrytime,updatetime', [0=>'等待',1=>'验证中',2=>'签发中',3=>'已签发',4=>'已吊销']],
            'deploy'=>['cert_deploy','证书部署','/cert/deploytask','id,status,active,error,retry,retrytime,lasttime',[-1=>'失败或部分成功',0=>'待部署',1=>'成功',2=>'待验收']],
        ];
        $kind=input('post.kind','');$out=[];
        if ($kind!=='' && !isset($sources[$kind])) throw new \InvalidArgumentException('任务类型无效');
        foreach ($sources as $key=>[$table,$label,$url,$fields,$states]) {
            if ($kind!=='' && $kind!==$key) continue;
            foreach (Db::name($table)->field($fields)->order('id','desc')->limit(100)->select() as $row) {
                $state=array_key_exists('active',$row) && !$row['active'] ? '停用' : ($states[(int)($row['status'] ?? 0)] ?? (isset($row['status']) && $row['status']<0 ? '失败' : '等待'));
                if ($key==='schedule' && $row['active'] && !$row['nexttime']) $state='完成';
                if ($key==='batch' && $row['cancel_requested'] && $row['status']==1) $state='取消中';
                $out[]=['kind'=>$key,'kind_name'=>$label,'id'=>$row['id'],'name'=>$row['name'] ?? $row['rr'] ?? ($label.' #'.$row['id']), 'status'=>$state, 'progress'=>isset($row['total']) ? $row['completed'].'/'.$row['total'] : null,'error'=>\app\lib\SensitiveLog::filter($row['error'] ?? $row['errmsg'] ?? ''),'updated_at'=>self::time($row['updatetime'] ?? $row['lasttime'] ?? $row['checktime'] ?? null),'next_at'=>self::time($row['retrytime'] ?? $row['nexttime'] ?? $row['checknexttime'] ?? null),'url'=>$url];
            }
        }
        usort($out,fn($a,$b)=>strcmp($b['updated_at'],$a['updated_at']));
        return $out;
    }

    private static function time($value): string { return !$value ? '—' : (is_numeric($value) ? date('Y-m-d H:i:s',(int)$value) : (string)$value); }

    private function conflicts(): array
    {
        $tasks=[];
        foreach (['monitor'=>'dmtask','schedule'=>'sctask','optimize'=>'optimizeip'] as $kind=>$table) {
            $query=Db::name($table)->where('active',1);
            if ($kind==='schedule') $query->where('nexttime','>',0);
            foreach ($query->field('id,did,rr,active'.($kind==='optimize' ? '' : ',recordid'))->limit(5000)->select() as $row) $tasks[]=$row+['kind'=>$kind,'recordid'=>'*'];
        }
        $domains=Db::name('domain')->column('name','id');
        return array_map(fn($r)=>$r+['domain'=>$domains[$r['did']] ?? '域名已删除'],OperationsService::conflicts($tasks));
    }

    private function matrix(): array
    {
        $out=[];
        foreach (Db::name('cert_deploy')->field('id,oid,status,remark,info,lasttime')->order('id','desc')->limit(100)->select() as $row) {
            $info=json_decode($row['info'] ?? '{}',true) ?: [];
            $order=Db::name('cert_order')->field('fullchain,expiretime')->where('id',$row['oid'])->find();
            try { $expected=$order && $order['fullchain'] ? \app\service\CertVerificationService::fingerprint($order['fullchain']) : null; } catch (\Throwable $e) { $expected=null; }
            $verification=$info['_tls_verification'] ?? [];
            $targets=[];
            foreach ($info['_targets'] ?? [] as $target) $targets[]=array_intersect_key($target,array_flip(['target','status','error','updated_at']));
            $probes=[];
            foreach ($verification['targets'] ?? [] as $target) $probes[]=array_intersect_key($target,array_flip(['host','port','address','status','expected_sha256','observed_sha256','expires_at','error']));
            if (!$probes && isset($verification['status'])) $probes[]=array_intersect_key($verification,array_flip(['host','port','status','expected_sha256','observed_sha256','error']));
            $out[]=['id'=>$row['id'],'oid'=>$row['oid'],'name'=>$row['remark'] ?: '部署 #'.$row['id'],'status'=>$row['status'],'expected_sha256'=>$expected,'deployed_sha256'=>$info['_certificate_sha256'] ?? null,'expires_at'=>$order['expiretime'] ?? null,'targets'=>$targets,'verification'=>$probes,'lasttime'=>$row['lasttime']];
        }
        return $out;
    }

    private function drift(): array
    {
        $rows=Db::name('dns_watch')->alias('W')->leftJoin('domain D','W.did=D.id')->field('W.did,D.name,W.enabled,W.interval_seconds,W.checked_at,W.error,W.checksum,W.observed,W.diff')->limit(1000)->select()->toArray();
        foreach ($rows as &$row) { $row['drifted']=$row['checksum']!==$row['observed'];$row['diff']=json_decode($row['diff'] ?? '{}',true) ?: [];unset($row['checksum'],$row['observed']); } unset($row);
        return $rows;
    }

    private function credentials(): array
    {
        $health=[];foreach(Db::name('credential_health')->select() as $r)$health[$r['kind'].':'.$r['aid']]=$r;
        $out=[];
        foreach (['dns'=>'account','cert'=>'cert_account','api'=>'apikey'] as $kind=>$table) {
            $fields=$kind==='api' ? 'id,name,expiretime,status' : 'id,type,remark'.($kind==='cert' ? ',deploy' : '');
            foreach (Db::name($table)->field($fields)->limit(1000)->select() as $row) {
                $actual=$kind==='cert' && $row['deploy'] ? 'deploy' : $kind;
                $state=$health[$actual.':'.$row['id']] ?? ['enabled'=>0,'status'=>'unknown','checked_at'=>0,'rotation_days'=>90,'expires_at'=>null,'rotated_at'=>null,'error'=>null];
                $out[]=array_intersect_key($state,array_flip(['enabled','status','checked_at','rotation_days','expires_at','rotated_at','error']))+['kind'=>$actual,'aid'=>$row['id'],'name'=>$row['remark'] ?? $row['name'] ?? '', 'provider'=>$row['type'] ?? 'API','api_expires_at'=>$row['expiretime'] ?? null];
            }
        }
        return $out;
    }

    private function notifications(): array
    {
        return Db::name('notification_job')->field('id,channel,status,attempts,nexttime,addtime,error')->order('id','desc')->limit(200)->select()->toArray();
    }

    public function operation()
    {
        try {
            $this->authorize();$action=input('post.action');$id=input('post.id/d');
            if ($id<1) throw new \InvalidArgumentException('ID无效');
            if (in_array($action,['watch','baseline','check_drift','disable_watch'],true)) {
                $domain=Db::name('domain')->where('id',$id)->find();if(!$domain)throw new \RuntimeException('域名不存在');
                if ($action==='check_drift') DriftService::check($domain);
                elseif ($action==='disable_watch') { $lock=\app\service\DnsChangeService::lock($domain);try{Db::name('dns_watch')->where('did',$id)->update(['enabled'=>0]);}finally{flock($lock,LOCK_UN);fclose($lock);} }
                else DriftService::capture($domain,input('post.interval/d',3600));
            } elseif ($action==='credential_save' || $action==='credential_check') {
                $kind=input('post.kind');$account=OperationsService::credential($kind,$id);
                if ($action==='credential_check') OperationsService::checkCredential($kind,$id);
                else {
                    $days=input('post.rotation_days/d',90);if($days<7 || $days>730)throw new \InvalidArgumentException('轮换周期应为7到730天');
                    $expires=input('post.expires_at','');$rotated=input('post.rotated_at','');
                    foreach([$expires,$rotated] as $date)if($date!=='' && (!is_string($date) || !($parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$date)) || $parsed->format('Y-m-d')!==$date))throw new \InvalidArgumentException('日期格式应为YYYY-MM-DD');
                    $db=TaskLockService::connection();$lock=new TaskLockService($db,'credential:'.$kind.':'.$id);
                    try {
                        $lock->acquire();$where=['kind'=>$kind,'aid'=>$id];
                        $update=['enabled'=>input('post.enabled/d',0)===1 ? 1 : 0,'expires_at'=>$expires ? $expires.' 23:59:59' : null,'rotated_at'=>$rotated ? $rotated.' 00:00:00' : null,'rotation_days'=>$days,'nexttime'=>time()];
                        if($db->name('credential_health')->where($where)->find())$db->name('credential_health')->where($where)->update($update);
                        else $db->name('credential_health')->insert($where+$update+['version'=>OperationsService::credentialVersion($account)]);
                    } finally {try{$lock->release();}finally{$db->close();}}
                }
            } elseif ($action==='notification_retry') Db::name('notification_job')->where('id',$id)->where('status',-1)->update(['status'=>0,'attempts'=>0,'nexttime'=>time(),'error'=>null]);
            else throw new \InvalidArgumentException('操作无效');
            api_write_log('运行中心:'.$action,'ID:'.$id);
            return json(['code'=>0,'msg'=>'操作成功']);
        } catch (\Throwable $e) {return json(['code'=>-1,'msg'=>\app\lib\SensitiveLog::filter($e->getMessage())]);}
    }
}
