<?php
namespace think\facade {
    class Db {
        public static array $tables=[];
        public static function name($name){return new \ControllerQuery($name);}
        public static function connect(...$args){return new \ControllerConnection();}
        public static function transaction($fn){return $fn();}
    }
    class View {} class Cache {}
}
namespace app {
    class BaseController { protected $request;public function __construct(){$this->request=\request();}protected function alert($type,$msg){return \json(['code'=>-1,'msg'=>$msg]);} }
}
namespace {
    require __DIR__.'/../app/lib/SensitiveLog.php';
    require __DIR__.'/../app/lib/RecordGuard.php';
    require __DIR__.'/../app/lib/ApiKeyHelper.php';
    require __DIR__.'/../app/lib/DeployHelper.php';
    require __DIR__.'/../app/service/TaskLockService.php';
    require __DIR__.'/../app/service/CertVerificationService.php';
    require __DIR__.'/../app/controller/Cert.php';
    use think\facade\Db;
    class ControllerConnection {
        public static array $locks=[];
        public function query($sql,$bind,$master){$key=$bind['task_lock'];if(str_contains($sql,'GET_LOCK')){if(isset(self::$locks[$key])&&self::$locks[$key]!==$this)return [['acquired'=>0]];self::$locks[$key]=$this;return [['acquired'=>1]];}if((self::$locks[$key]??null)===$this)unset(self::$locks[$key]);return [['released'=>1]];}
        public function close(){foreach(self::$locks as $key=>$owner)if($owner===$this)unset(self::$locks[$key]);}
    }
    class ControllerQuery {
        private array $where=[];
        public function __construct(private string $table){}
        public function where($key,$value){$this->where[$key]=$value;return $this;}
        private function matches($row){foreach($this->where as $key=>$value)if(($row[$key]??null)!=$value)return false;return true;}
        public function find(){foreach(Db::$tables[$this->table]??[] as $row)if($this->matches($row))return $row;return null;}
        public function value($field){return $this->find()[$field]??null;}
        public function column($field){$result=[];foreach(Db::$tables[$this->table]??[] as $row)if($this->matches($row))$result[]=$row[$field];return $result;}
        public function count(){return count($this->column('id'));}
        public function update($data){$n=0;foreach(Db::$tables[$this->table] as &$row)if($this->matches($row)){$row=array_merge($row,$data);$n++;}return $n;}
        public function delete(){$old=count(Db::$tables[$this->table]??[]);Db::$tables[$this->table]=array_values(array_filter(Db::$tables[$this->table]??[],fn($row)=>!$this->matches($row)));return $old-count(Db::$tables[$this->table]);}
    }
    function config($key,$default=null){return $key==='database.connections.mysql'?['database'=>'fixture','prefix'=>'fixture_']:$default;}
    function config_get($key,$default=null){return $default;}
    function request(){return $GLOBALS['controller_request'];}
    function input($key,$default=null,...$args){return $GLOBALS['controller_input'][$key]??$default;}
    function json($value){return $value;}
    function checkPermission(...$args){return true;}
    function api_domains(){return null;}
    function api_domain_allowed($domain){return true;}
    function convertDomainToAscii($domain){return $domain;}
    function checkDomain($domain){return (bool)preg_match('/^[a-z0-9.-]+\.[a-z]+$/D',$domain);}
    $GLOBALS['controller_request']=(object)['isApi'=>false];$GLOBALS['controller_input']=[];$checks=0;
    function ccheck($ok,$message){$GLOBALS['checks']++;if(!$ok)throw new RuntimeException($message);}
    function rejects($fn){try{$fn();}catch(Throwable $e){$GLOBALS['checks']++;return;}throw new RuntimeException('Expected invalid input to fail closed');}
    // Invalid domain restrictions must never silently turn into unrestricted keys.
    ccheck(\app\lib\ApiKeyHelper::normalizeDomains(' Example.COM,*.example.com;www.example.com. ' )===['example.com','www.example.com'],'domain restrictions normalized and deduplicated');
    ccheck(\app\lib\ApiKeyHelper::normalizeDomains('')===[],'explicit empty restriction stays unrestricted');
    foreach([123,null,[[]],['<img src=x onerror=alert(1)>'],['example.com','bad/host'],['-bad.example.com'],'.','...'] as $invalid)rejects(fn()=>\app\lib\ApiKeyHelper::normalizeDomains($invalid));
    ccheck(\app\lib\ApiKeyHelper::normalizeScopes(['record:read','domain:write'],false)===['record:read'],'normal key cannot gain admin scope');
    Db::$tables=['cert_account'=>[['id'=>1,'deploy'=>1,'type'=>'btpanel']], 'cert_order'=>[['id'=>10,'aid'=>0,'isauto'=>0]], 'cert_domain'=>[['id'=>1,'oid'=>10,'domain'=>'example.com']], 'cert_deploy'=>[['id'=>20,'aid'=>1,'oid'=>10,'active'=>1,'config'=>'{}']]];
    $controller=new \app\controller\Cert();$normalize=new ReflectionMethod($controller,'normalizeDeployConfig');
    $value=json_decode($normalize->invoke($controller,'{}',1,10),true);ccheck($value===['_verify_tls'=>['enabled'=>false]],'empty native object config remains supported');
    rejects(fn()=>$normalize->invoke($controller,'[]',1,10));rejects(fn()=>$normalize->invoke($controller,'null',1,10));rejects(fn()=>$normalize->invoke($controller,'{}',99,10));rejects(fn()=>$normalize->invoke($controller,'{}',1,99));
    $GLOBALS['controller_request']->isApi=true;
    rejects(fn()=>$normalize->invoke($controller,'{"unrecognized":"value"}',1,10));
    rejects(fn()=>$normalize->invoke($controller,'{"_verify_tls":{"enabled":true,"host":"attacker.example.net"}}',1,10));
    $value=json_decode($normalize->invoke($controller,'{"_verify_tls":{"enabled":true,"host":"example.com"}}',1,10),true);ccheck($value['_verify_tls']['host']==='example.com'&&$value['_verify_tls']['port']===443,'verification target stays in order scope');
    $GLOBALS['controller_request']->isApi=false;
    // A live worker prevents controller writes/deletes under the identical mutex.
    $lock=new \app\service\TaskLockService(new ControllerConnection(),'cert-deploy:20');$lock->acquire();
    $GLOBALS['controller_input']=['param.action'=>'del','post.id/d'=>20];$response=$controller->deploy_op();ccheck($response['code']===-1&&count(Db::$tables['cert_deploy'])===1,'cannot delete a deployment while its worker is live');
    $GLOBALS['controller_input']=['param.action'=>'setactive','post.id/d'=>20,'post.active/d'=>0];$response=$controller->deploy_op();ccheck($response['code']===-1&&Db::$tables['cert_deploy'][0]['active']===1,'cannot edit live deployment');
    $lock->release();$response=$controller->deploy_op();ccheck($response['code']===0&&Db::$tables['cert_deploy'][0]['active']===0,'deployment editable after worker releases');
    $lock=new \app\service\TaskLockService(new ControllerConnection(),'cert-order:10');$lock->acquire();$GLOBALS['controller_input']=['param.action'=>'setauto','post.id/d'=>10,'post.isauto/d'=>1];$response=$controller->order_op();ccheck($response['code']===-1&&Db::$tables['cert_order'][0]['isauto']===0,'cannot mutate live certificate order');$lock->release();
    $response=$controller->order_op();ccheck($response['code']===0&&Db::$tables['cert_order'][0]['isauto']===1,'order editable after worker releases');
    $pem=require __DIR__.'/fixtures/tls-example.php';Db::$tables['cert_order'][0]['fullchain']=$pem;Db::$tables['cert_order'][]=['id'=>11,'aid'=>0,'fullchain'=>$pem];Db::$tables['cert_domain'][]=['id'=>2,'oid'=>11,'domain'=>'example.com'];
    Db::$tables['cert_deploy'][0]+= ['status'=>2,'info'=>json_encode(['_tls_verification'=>['expected_sha256'=>'old-receipt']]),'retry'=>5];
    $GLOBALS['controller_input']=['param.action'=>'edit','post.id/d'=>20,'post.aid/d'=>1,'post.oid/d'=>11,'post.config'=>'{}','post.remark'=>'changed order'];$response=$controller->deploy_op();
    ccheck($response['code']===0&&Db::$tables['cert_deploy'][0]['oid']===11&&Db::$tables['cert_deploy'][0]['status']===0&&Db::$tables['cert_deploy'][0]['info']===null&&Db::$tables['cert_deploy'][0]['retry']===0,'changing an awaiting-verification order clears stale receipt and queues deployment');
    Db::$tables['cert_deploy'][0]['status']=1;$GLOBALS['controller_input']['post.config']='{"_verify_tls":{"enabled":true,"host":"example.com"}}';$response=$controller->deploy_op();
    $receipt=json_decode(Db::$tables['cert_deploy'][0]['info'],true);
    ccheck($response['code']===0&&Db::$tables['cert_deploy'][0]['status']===2&&$receipt['_tls_verification']['expected_sha256']===\app\service\CertVerificationService::fingerprint($pem),'enabling verification queues only certificate acceptance');
    $GLOBALS['controller_input']['post.config']='{}';$response=$controller->deploy_op();ccheck($response['code']===0&&Db::$tables['cert_deploy'][0]['status']===1,'disabling pending optional verification retains completed deployment');
    Db::$tables['cert_deploy'][0]['oid']=10;Db::$tables['cert_order']=array_slice(Db::$tables['cert_order'],0,1);Db::$tables['cert_domain']=array_slice(Db::$tables['cert_domain'],0,1);
    $GLOBALS['controller_input']=['param.action'=>'del','post.id/d'=>10];$response=$controller->order_op();ccheck($response['code']===-1&&count(Db::$tables['cert_order'])===1,'referenced imported certificate cannot be deleted');
    Db::$tables['cert_deploy']=[];$response=$controller->order_op();ccheck($response['code']===0&&Db::$tables['cert_order']===[]&&Db::$tables['cert_domain']===[],'unreferenced imported certificate and domains deleted together');
    ccheck(ControllerConnection::$locks===[],'all controller mutexes released including failures');
    echo 'Controller security: '.$checks." checks passed\n";
}
