<?php
namespace app\lib {
    class DnsHelper {public static $line_name=['fixture'=>['DEF'=>'default']];public static function getModel(...$args){return $GLOBALS['cleanup_dns'];}}
    class CertHelper {public static function getModel2(...$args){return new class{public function setLogger(...$args){}};}}
}
namespace {
    $root=dirname(__DIR__);$vendor=getenv('DNSMGR_COMPOSER_VENDOR')?:$root.'/vendor';
    if(!is_file($vendor.'/autoload.php')){echo "Certificate cleanup requires Composer/SQLite (covered by CI)\n";return;}
    require $vendor.'/autoload.php';$_SERVER['REMOTE_ADDR']='127.0.0.1';
    $app=new think\App($root.'/');$app->setRuntimePath(sys_get_temp_dir().'/dnsmgr-cleanup-'.bin2hex(random_bytes(6)).'/');$app->initialize();
    set_exception_handler(static function(Throwable $e){echo (string)$e;exit(1);});
    class CleanupSqlite extends think\db\connector\Sqlite {
        public static array $locks=[];
        public function query(string $sql,array $bind=[],bool $master=false):array {
            if(str_contains($sql,'GET_LOCK')){$key=$bind['task_lock'];if(isset(self::$locks[$key]))return [['acquired'=>0]];self::$locks[$key]=$this;return [['acquired'=>1]];}
            if(str_contains($sql,'RELEASE_LOCK')){$key=$bind['task_lock'];if((self::$locks[$key]??null)===$this)unset(self::$locks[$key]);return [['released'=>1]];}
            return parent::query($sql,$bind,$master);
        }
    }
    $config=['type'=>'\\'.CleanupSqlite::class,'builder'=>think\db\builder\Sqlite::class,'database'=>$app->getRuntimePath().'cleanup.sqlite','prefix'=>'proof_','fields_cache'=>false,'params'=>[PDO::ATTR_TIMEOUT=>1]];
    if(!is_dir($app->getRuntimePath()))mkdir($app->getRuntimePath(),0700,true);
    $app->config->set(['default'=>'mysql','connections'=>['mysql'=>$config]],'database');$app->request->user=null;
    think\facade\Db::execute('PRAGMA journal_mode=WAL');
    foreach(['account(id INTEGER PRIMARY KEY,type TEXT)','domain(id INTEGER PRIMARY KEY,aid INTEGER,name TEXT,thirdid TEXT)','cert_account(id INTEGER PRIMARY KEY,type TEXT,config TEXT,ext TEXT)','cert_order(id INTEGER PRIMARY KEY,aid INTEGER,status INTEGER,retry INTEGER,retry2 INTEGER,retrytime TEXT,processid TEXT,islock INTEGER,locktime TEXT,info TEXT,dns TEXT,error TEXT)','cert_domain(oid INTEGER,domain TEXT,sort INTEGER)','cert_deploy(id INTEGER PRIMARY KEY,oid INTEGER)','cert_dns_lease(oid INTEGER,claim TEXT,did INTEGER,aid INTEGER,thirdid TEXT,domain TEXT,rr TEXT,type TEXT,value TEXT,managed INTEGER,PRIMARY KEY(oid,claim))','dns_snapshot(id INTEGER PRIMARY KEY AUTOINCREMENT,did INTEGER,uid INTEGER,domain TEXT,aid INTEGER,thirdid TEXT,label TEXT,record_count INTEGER,records TEXT,checksum TEXT,addtime TEXT)'] as $schema)think\facade\Db::execute('CREATE TABLE proof_'.$schema);
    class CleanupDns {
        public array $records=[];public bool $fail=false;
        public function getDomainRecords(...$args){return ['total'=>count($this->records),'list'=>$this->records];}
        public function getError(){return 'fixture refusal';}
        public function addDomainRecord($name,$type,$value,...$args){$id='fixture-'.count($this->records);$this->records[]=['RecordId'=>$id,'Name'=>$name,'Type'=>$type,'Value'=>$value,'Line'=>'default','TTL'=>600,'Status'=>'1'];return $id;}
        public function deleteDomainRecord($id){if($this->fail)return false;$this->records=array_values(array_filter($this->records,fn($r)=>$r['RecordId']!==$id));return true;}
    }
    $GLOBALS['cleanup_dns']=$dns=new CleanupDns();$log=static function($line){};$checks=0;
    function cleanupCheck($ok,$message){$GLOBALS['checks']++;if(!$ok)throw new RuntimeException($message);}
    function cleanupSeed($id,$status=3){think\facade\Db::name('cert_order')->insert(['id'=>$id,'aid'=>1,'status'=>$status,'retry'=>0,'retry2'=>0,'islock'=>0,'dns'=>'[]']);think\facade\Db::name('cert_domain')->insert(['oid'=>$id,'domain'=>'example.com','sort'=>1]);}
    think\facade\Db::name('account')->insert(['id'=>1,'type'=>'fixture']);think\facade\Db::name('domain')->insert(['id'=>1,'aid'=>1,'name'=>'example.com','thirdid'=>'zone']);think\facade\Db::name('cert_account')->insert(['id'=>1,'type'=>'fixture','config'=>'{}']);
    $rows=['example.com'=>[['name'=>'_acme-challenge','type'=>'TXT','value'=>'shared-fixture']]];
    cleanupSeed(1);app\service\CertDnsService::add($rows,$log,false,1);$service=new app\service\CertOrderService(1);$dns->fail=true;ob_start();$service->delDns();ob_end_clean();$dns->fail=false;ob_start();$service->process(true);ob_end_clean();
    cleanupCheck(!$dns->records&&think\facade\Db::name('cert_dns_lease')->count()===0,'A manual retry of an issued certificate retries cleanup without reissuing');
    app\service\CertDnsService::add($rows,$log,false,1);$dns->fail=true;$blocked=false;ob_start();try{$service->remove();}catch(Throwable $e){$blocked=true;}ob_end_clean();
    cleanupCheck($blocked&&think\facade\Db::name('cert_order')->where('id',1)->find()!==null&&think\facade\Db::name('cert_dns_lease')->count()===1,'Provider cleanup failure preserves both the order and its lease');
    $dns->fail=false;app\service\CertDnsService::retryCleanup($log);
    cleanupCheck(!$dns->records&&think\facade\Db::name('cert_dns_lease')->count()===0,'The normal background cleanup queue retries a transient failure');
    cleanupSeed(2);app\service\CertDnsService::add($rows,$log,false,1);app\service\CertDnsService::add($rows,$log,false,2);think\facade\Db::name('cert_order')->where('id',1)->delete();
    app\service\CertDnsService::retryCleanup($log);
    app\service\CertDnsService::retryCleanup($log); // The rotating cursor wraps on the following bounded pass.
    cleanupCheck(!$dns->records&&think\facade\Db::name('cert_dns_lease')->count()===0,'An orphan from an older release no longer blocks the last shared-value cleanup');
    cleanupSeed(3,1);app\service\CertDnsService::add($rows,$log,false,3);app\service\CertDnsService::retryCleanup($log);
    cleanupCheck(count($dns->records)===1&&think\facade\Db::name('cert_dns_lease')->count()===1,'A live issuance lease is never cleaned by the background queue');
    think\facade\Db::name('cert_order')->where('id',3)->update(['status'=>3]);
    $busyDb=app\service\TaskLockService::connection();$busy=new app\service\TaskLockService($busyDb,'cert-order:3');$busy->acquire();app\service\CertDnsService::retryCleanup($log);
    cleanupCheck(count($dns->records)===1&&count(CleanupSqlite::$locks)===1,'A live worker keeps its lease and its original order lock');$busy->release();$busyDb->close();
    think\facade\Db::name('domain')->where('id',1)->update(['aid'=>99]);app\service\CertDnsService::retryCleanup($log);
    cleanupCheck(count($dns->records)===1&&think\facade\Db::name('cert_dns_lease')->count()===1,'A rebound zone remains untouched and retains its cleanup proof');
    cleanupCheck(CleanupSqlite::$locks===[],'Cleanup success, busy and failure paths release order locks');
    echo "Certificate cleanup lifecycle: $checks checks passed\n";
}
