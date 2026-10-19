<?php
namespace think\facade {
    class Db { public static function connect(...$args) { return new \TaskFakeConnection(); } }
}
namespace app\lib {
    class NewDb { public static function pool(...$args) { return new \TaskFakeConnection(); } public static function release(...$args) {} }
    class DnsHelper { public static function getModel2(...$args) { return $GLOBALS['fake_dns']; } }
    class GuardedDns {
        public function __construct(private $dns, $domain, $connection = null) { if (!$connection instanceof \TaskFakeConnection) throw new \RuntimeException('Automatic writes must use their dedicated database connection'); }
        public function __call($method, $arguments) { return $this->dns->$method(...$arguments); }
    }
    class DeployHelper { public static function getModel(...$args) { return $GLOBALS['fake_deploy']; } }
    class CertHelper {
        public static $cert_config = ['fake'=>['cname'=>false]];
        public static function getModel2(...$args) { return $GLOBALS['fake_cert']; }
    }
}
namespace app\utils {
    class CheckUtils { public static function ping(...$args) { return ['status'=>$GLOBALS['health'], 'errmsg'=>'offline']; } }
    class MsgNotice { public static function send(...$args) { $GLOBALS['notices'][]=$args; } }
    class CertDnsUtils { public static function verifyDns(...$args) { return true; } }
}
namespace {
    require_once __DIR__.'/../app/lib/AutomaticDnsTarget.php';
    require_once __DIR__.'/../app/lib/SensitiveLog.php';
    require_once __DIR__.'/../app/service/TaskLockService.php';
    require_once __DIR__.'/../app/service/TaskRunner.php';
    require_once __DIR__.'/../app/service/ScheduleService.php';
    require_once __DIR__.'/../app/service/CertVerificationService.php';
    require_once __DIR__.'/../app/service/CertOrderService.php';
    require_once __DIR__.'/../app/service/CertDeployService.php';
    function config($key, $default=null) { return $key==='database.connections.mysql' ? ['database'=>'test','prefix'=>'test_','break_reconnect'=>true] : ($key==='database.connections.mysql.database'?'test':($key==='database.connections.mysql.prefix'?'test_':$default)); }
    function config_get($key,$default=null) { return $default; }
    function getSid() { static $n=0; return 'tasks-regression-'.++$n; }
    function getDnsType($value) { return 'A'; }
    function getMainDomain($value) { return preg_replace('/^\*\./','',$value); }
    function isNullOrEmpty($value) { return $value === null || $value === ''; }
    function app() { return new class { public function getRuntimePath(){return '/tmp/dnsmgr-tests/';} }; }
    class TaskFakeConnection {
        public static $locks=[]; private static $next=0; public $id;
        public function __construct() { $this->id=++self::$next; }
        public function name($table) { return new TaskFakeQuery($table); }
        public function startTrans() {} public function commit() {} public function rollback() {}
        public function close() { foreach(self::$locks as $key=>$owner) if($owner===$this->id)unset(self::$locks[$key]); }
        public function query($sql,$bind,$master) {
            if (!$master) throw new RuntimeException('Lock queried on a read replica');
            $key=$bind['task_lock'];
            if (strpos($sql,'GET_LOCK')!==false) {
                if (isset(self::$locks[$key]) && self::$locks[$key]!==$this->id) return [['acquired'=>0]];
                // Emulate MySQL5.6: a second named lock on one physical
                // session silently releases that session's earlier lock.
                foreach(self::$locks as $existing=>$owner) if($owner===$this->id && $existing!==$key)unset(self::$locks[$existing]);
                self::$locks[$key]=$this->id; return [['acquired'=>1]];
            }
            if ((self::$locks[$key]??null)===$this->id) unset(self::$locks[$key]);
            return [['released'=>1]];
        }
    }
    class TaskFakeQuery {
        private $table; private $where=[]; private $data=[]; private $inc=[];
        public function __construct($table) { $this->table=$table; }
        public function where($key,$value=null,$third=null) { if(is_array($key)) foreach($key as $k=>$v)$this->where[$k]=$v; else $this->where[preg_replace('/^\w+\./','',$key)]=$third??$value; return $this; }
        public function alias(...$args) { return $this; } public function join(...$args) { return $this; } public function field(...$args) { return $this; } public function order(...$args) { return $this; } public function lock(...$args) { return $this; }
        private function matches($row) { foreach($this->where as $k=>$v) if (($row[$k]??null)!=$v) return false; return true; }
        public function find() { foreach($GLOBALS['tables'][$this->table]??[] as $row) if($this->matches($row))return $row; return null; }
        public function column($key) { $a=[];foreach($GLOBALS['tables'][$this->table]??[] as $row)if($this->matches($row))$a[]=$row[$key]; return $a; }
        public function count() { $n=0;foreach($GLOBALS['tables'][$this->table]??[] as $row)if($this->matches($row))$n++;return $n; }
        public function data($data) { $this->data=$data;return $this; } public function inc($field,$amount=1) {$this->inc[$field]=$amount;return $this;}
        public function update($data=[]) { if($this->table==='cert_deploy' && isset($data['config']) && !empty($GLOBALS['fail_provider_config_write']))throw new Error('configuration persistence error'); $n=0;foreach($GLOBALS['tables'][$this->table] as &$row)if($this->matches($row)){$row=array_merge($row,$this->data,$data);foreach($this->inc as $k=>$v)$row[$k]+=$v;$n++;}return $n; }
        public function insert($data) {$GLOBALS['tables'][$this->table][]=$data;return 1;}
    }
    class TaskFakeDns {
        public $ok=false; public $calls=0;
        public function setDomainRecordStatus(...$args) {$this->calls++;return $this->ok;}
        public function updateDomainRecord(...$args) {$this->calls++;return $this->ok;}
        public function getError(){return 'provider refused';}
    }
    class TaskFakeDeploy {
        public $calls=0; public $callback; public $throw=false;
        public function setLogger($logger) {}
        public function deploy($cert,$key,$config,&$info) {$this->calls++;if($this->callback)($this->callback)();if($this->throw)throw new Error('provider error');$info['provider_receipt']='ok';if(!empty($GLOBALS['fail_provider_config_write']))$info['config']=['provider_derived'=>'updated'];}
    }
    class TaskFakeCert { public $buys=0; public function buyCert(...$args){$this->buys++;} public function setLogger(...$args){} }
    class TaskFakeVerification extends \app\service\CertVerificationService {
        public function verify(array $config,string $expected):array { $GLOBALS['verification_calls']++;return ['status'=>$GLOBALS['verification_ok']?'passed':'failed','host'=>$config['host'],'port'=>$config['port'],'expected_sha256'=>$expected,'observed_sha256'=>$expected,'checked_at'=>date('Y-m-d H:i:s'),'error'=>$GLOBALS['verification_ok']?null:'old certificate']; }
    }
    class TestDeployService extends \app\service\CertDeployService { protected function verificationService(): \app\service\CertVerificationService {return new TaskFakeVerification();} }
    $checks=0;
    function taskCheck($value,$message){global $checks;if(!$value)throw new RuntimeException($message);$checks++;}
    function expectTaskFailure(callable $call,$code=null){global $checks;try{$call();}catch(Throwable $e){if($code!==null&&$e->getCode()!==$code)throw $e;$checks++;return;}throw new RuntimeException('Expected task failure');}
    $GLOBALS['fake_dns']=new TaskFakeDns();$GLOBALS['health']=false;$GLOBALS['notices']=[];
    $monitor=['id'=>1,'did'=>1,'rr'=>'www','recordid'=>'record1','type'=>1,'checktype'=>0,'main_value'=>'1.1.1.1','checkurl'=>'','status'=>0,'errcount'=>0,'cycle'=>1,'active'=>1];
    $GLOBALS['tables']=['dmtask'=>[$monitor],'domain'=>[['id'=>1,'name'=>'example.com','type'=>'fake','config'=>'{}']],'log'=>[],'dmlog'=>[],'sctask'=>[]];
    $runner=new \app\service\TaskRunner();
    taskCheck($runner->execute($monitor)===false,'DNS refusal must not report successful switch');
    taskCheck($GLOBALS['tables']['dmtask'][0]['status']===0 && count($GLOBALS['notices'])===0 && count($GLOBALS['tables']['dmlog'])===0,'Failed switch preserves actual state and sends no success notice');
    $GLOBALS['fake_dns']->ok=true;$runner->execute($monitor);
    taskCheck($GLOBALS['tables']['dmtask'][0]['status']===1 && count($GLOBALS['notices'])===1,'Next check retries and notifies only real success');
    $runner->execute($monitor);
    taskCheck($GLOBALS['fake_dns']->calls===2,'Stale monitor snapshot cannot repeat successful switch');
    $GLOBALS['tables']['dmtask'][0]=array_merge($monitor,['cycle'=>2]);$GLOBALS['fake_dns']->calls=0;
    $runner->execute($monitor);taskCheck($GLOBALS['fake_dns']->calls===0,'Cycle2 requires two failures');
    $runner->execute($monitor);taskCheck($GLOBALS['fake_dns']->calls===1,'Cycle2 switches exactly on second failure');
    $once=['id'=>5,'did'=>1,'rr'=>'www','recordid'=>'record1','type'=>0,'cycle'=>0,'switchtype'=>1,'switchdate'=>'','switchtime'=>date('Y-m-d H:i',time()-60),'nexttime'=>time()-60,'active'=>1];
    $GLOBALS['tables']['sctask']=[$once];$GLOBALS['fake_dns']->ok=false;
    expectTaskFailure(fn()=>(new \app\service\ScheduleService())->execute_one($once));
    taskCheck($GLOBALS['tables']['sctask'][0]['nexttime']>time(),'Failed one-shot remains pending for retry');
    $GLOBALS['tables']['sctask'][0]['nexttime']=time()-1;$GLOBALS['fake_dns']->ok=true;$retry=$GLOBALS['tables']['sctask'][0];
    taskCheck((new \app\service\ScheduleService())->execute_one($retry)===true && $GLOBALS['tables']['sctask'][0]['nexttime']===0,'Successful one-shot is completed once');
    taskCheck((new \app\service\ScheduleService())->execute_one($retry)===false,'Completed stale one-shot cannot run twice');
    $lock1=new \app\service\TaskLockService(new TaskFakeConnection(),'test');$lock2=new \app\service\TaskLockService(new TaskFakeConnection(),'test');
    $lock1->acquire();expectTaskFailure(fn()=>$lock2->acquire(),102);$lock1->release();$lock2->acquire();$lock2->release();taskCheck(count(TaskFakeConnection::$locks)===0,'Locks release on original sessions');
    $pem=(require __DIR__.'/fixtures/tls-example.php');
    $order=['id'=>10,'aid'=>1,'status'=>3,'retry'=>0,'retry2'=>0,'retrytime'=>null,'fullchain'=>$pem,'privatekey'=>'testkey','islock'=>0,'locktime'=>null,'processid'=>null,'info'=>null,'dns'=>null,'keytype'=>'RSA','keysize'=>2048,'updatetime'=>date('Y-m-d H:i:s'),'expiretime'=>date('Y-m-d H:i:s',time()+86400*90)];
    $deploy=['id'=>20,'aid'=>1,'oid'=>10,'status'=>0,'active'=>1,'retry'=>0,'error'=>null,'retrytime'=>null,'config'=>json_encode(['_verify_tls'=>['enabled'=>true,'host'=>'example.com','port'=>443,'timeout'=>1]]),'info'=>null,'islock'=>0,'locktime'=>null,'processid'=>null];
    $GLOBALS['tables']['cert_order']=[$order];$GLOBALS['tables']['cert_deploy']=[$deploy];$GLOBALS['tables']['cert_domain']=[['oid'=>10,'domain'=>'example.com','sort'=>1]];$GLOBALS['tables']['cert_account']=[['id'=>1,'type'=>'fake','config'=>'{}','ext'=>null]];
    $GLOBALS['fake_deploy']=new TaskFakeDeploy();$GLOBALS['fake_cert']=new TaskFakeCert();$GLOBALS['verification_ok']=false;$GLOBALS['verification_calls']=0;
    $service=new TestDeployService(20);$staleService=new TestDeployService(20);
    $GLOBALS['fake_deploy']->callback=function()use($staleService){expectTaskFailure(fn()=>$staleService->process(true),102);};
    expectTaskFailure(fn()=>$service->process(true));
    taskCheck($GLOBALS['tables']['cert_deploy'][0]['status']===2 && $GLOBALS['fake_deploy']->calls===1,'Successful deployment with failed acceptance stays status2');
    $staleService->reset();taskCheck($GLOBALS['tables']['cert_deploy'][0]['status']===2,'Manual retry preserves deployed receipt');
    $GLOBALS['verification_ok']=true;$GLOBALS['fake_deploy']->callback=null;$staleService->process(true);
    taskCheck($GLOBALS['tables']['cert_deploy'][0]['status']===1 && $GLOBALS['fake_deploy']->calls===1 && $GLOBALS['verification_calls']===2,'Acceptance retry never redeploys provider');
    $service->process(true);taskCheck($GLOBALS['fake_deploy']->calls===1,'Stale deployment object refreshes completed status');
    $GLOBALS['tables']['cert_deploy'][0]=$deploy;$GLOBALS['fake_deploy']->throw=true;
    expectTaskFailure(fn()=>(new TestDeployService(20))->process(true));
    taskCheck($GLOBALS['tables']['cert_deploy'][0]['status']===-1 && $GLOBALS['tables']['cert_deploy'][0]['islock']===0 && count(TaskFakeConnection::$locks)===0,'Throwable from provider records failure and releases locks');
    $GLOBALS['fake_deploy']->throw=false;
    $GLOBALS['tables']['cert_deploy'][0]=$deploy;$GLOBALS['fail_provider_config_write']=true;
    $callsBefore=$GLOBALS['fake_deploy']->calls;
    expectTaskFailure(fn()=>(new TestDeployService(20))->process(true));
    taskCheck($GLOBALS['tables']['cert_deploy'][0]['status']===2,'Configuration error after remote success must preserve deployment receipt');
    $GLOBALS['fail_provider_config_write']=false;$GLOBALS['verification_ok']=true;
    (new TestDeployService(20))->process(true);
    taskCheck($GLOBALS['tables']['cert_deploy'][0]['status']===1 && $GLOBALS['fake_deploy']->calls===$callsBefore+1,'Post-deploy persistence failure retries acceptance without provider side effects');
    $staleOrder=new \app\service\CertOrderService(10);$GLOBALS['tables']['cert_order'][0]['status']=4;
    expectTaskFailure(fn()=>$staleOrder->process(),102);taskCheck($GLOBALS['fake_cert']->buys===0,'Fresh revoked status prevents stale issuance');
    $GLOBALS['tables']['cert_order'][0]=$order;$renewal=new \app\service\CertOrderService(10);$renewal->reset(true);
    taskCheck($GLOBALS['tables']['cert_order'][0]['status']===3,'Stale automatic renewal cannot reset a fresh unexpired certificate');
    $GLOBALS['tables']['cert_order'][0]['expiretime']=date('Y-m-d H:i:s',time()+86400);$GLOBALS['tables']['cert_order'][0]['isauto']=0;$renewal->reset(true);
    taskCheck($GLOBALS['tables']['cert_order'][0]['status']===3,'Disabled automatic renewal rechecked after locking');
    $GLOBALS['tables']['cert_deploy'][0]=$deploy;$staleActive=new TestDeployService(20);$GLOBALS['tables']['cert_deploy'][0]['active']=0;$callsBefore=$GLOBALS['fake_deploy']->calls;$staleActive->process(false);
    taskCheck($GLOBALS['fake_deploy']->calls===$callsBefore&&$GLOBALS['tables']['cert_deploy'][0]['status']===0,'Automatic deployment refreshes disabled state under lock');
    $GLOBALS['tables']['cert_deploy'][0]=$deploy;
    $scopedDeploy=new TestDeployService(20,function($oid,$domains){if($oid!==10||$domains!==['example.com'])throw new RuntimeException('scope revoked',102);});
    $GLOBALS['tables']['cert_deploy'][0]['oid']=99;expectTaskFailure(fn()=>$scopedDeploy->process(true),102);
    taskCheck($GLOBALS['fake_deploy']->calls===$callsBefore,'Latest deployment order is reauthorized before any cloud operation');
    $scopedOrder=new \app\service\CertOrderService(10,function($oid,$domains){if($domains!==['example.com'])throw new RuntimeException('scope revoked',102);});
    $GLOBALS['tables']['cert_domain'][0]['domain']='unapproved.example.net';expectTaskFailure(fn()=>$scopedOrder->reset(),102);
    taskCheck($GLOBALS['tables']['cert_order'][0]['status']===3,'Latest certificate domains reauthorized before reset');
    taskCheck(count(TaskFakeConnection::$locks)===0,'All success and error paths release advisory locks');
    echo "Task reliability checks passed: $checks\n";
}
