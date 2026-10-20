<?php
namespace think\facade {
    class Db {
        public static array $tables = [];
        public static function name($name) { return new \AutoQuery($name); }
        public static function connect(...$args) { return new \AutoConnection(); }
    }
}
namespace app\lib {
    class DnsHelper {
        public static $line_name = ['fixture'=>['DEF'=>'default'], 'huawei'=>['DEF'=>'default']];
        public static function getModel(...$args) { return $GLOBALS['auto_dns']; }
    }
}
namespace {
    require __DIR__.'/../app/lib/RecordGuard.php';
    require __DIR__.'/../app/lib/AutomaticDnsTarget.php';
    require __DIR__.'/../app/lib/GuardedDns.php';
    require __DIR__.'/../app/service/DnsChangeService.php';
    require __DIR__.'/../app/service/TaskLockService.php';
    require __DIR__.'/../app/service/CertDnsService.php';
    require __DIR__.'/../app/service/OptimizeService.php';
    use think\facade\Db;
    use app\service\CertDnsService;
    function config($key, $default=null) { return $key === 'database.connections.mysql' ? ['database'=>'test','prefix'=>'test_'] : $default; }
    function config_get($key, $default=null) { return $default; }
    function request() { return (object)['user'=>null]; }
    function runtime_path() { return sys_get_temp_dir().'/dnsmgr-auto-tests-'.getmypid().'/'; }
    if (!function_exists('mb_substr')) { function mb_substr($v,$s,$n) { return substr($v,$s,$n); } }
    class AutoConnection {
        public static array $held = [];
        public function name($name) { return new AutoQuery($name); }
        public function query($sql,$bind,$master) {
            $key = $bind['task_lock'];
            if (str_contains($sql,'GET_LOCK')) { if (isset(self::$held[$key])) return [['acquired'=>0]]; self::$held[$key]=$this; return [['acquired'=>1]]; }
            if ((self::$held[$key]??null)===$this) unset(self::$held[$key]); return [['released'=>1]];
        }
        public function close() { foreach(self::$held as $key=>$owner) if($owner===$this)unset(self::$held[$key]); }
    }
    class AutoQuery {
        private array $filters=[];
        private bool $replace=false;
        public function __construct(private string $table) {}
        public function alias(...$args) { return $this; } public function join(...$args) { return $this; }
        public function field(...$args) { return $this; } public function order(...$args) { return $this; }
        public function limit(...$args) { return $this; }
        public function replace() { $this->replace=true; return $this; }
        public function where($key,$op,$value=null) { $this->filters[]=[preg_replace('/^\w+\./','',$key),func_num_args()===3?$op:'=',func_num_args()===3?$value:$op]; return $this; }
        private function matches($row) { foreach($this->filters as [$key,$op,$value]) if($op==='<>' ? ($row[$key]??null)==$value : ($row[$key]??null)!=$value)return false; return true; }
        public function select() { return array_values(array_filter(Db::$tables[$this->table]??[],fn($r)=>$this->matches($r))); }
        public function find() { return $this->select()[0]??null; }
        public function count() { return count($this->select()); }
        public function column($key) { return []; } // No expired snapshots in these scenarios.
        public function insert($row) {
            if($this->replace) Db::$tables[$this->table]=array_values(array_filter(Db::$tables[$this->table]??[],fn($r)=>!($r['oid']===$row['oid']&&$r['claim']===$row['claim'])));
            Db::$tables[$this->table][]=$row; return 1;
        }
        public function insertGetId($row) { $row['id']=count(Db::$tables[$this->table]??[])+1; $this->insert($row); return $row['id']; }
        public function update($values) { foreach(Db::$tables[$this->table] as &$row) if($this->matches($row))$row=array_merge($row,$values); return 1; }
        public function delete() { Db::$tables[$this->table]=array_values(array_filter(Db::$tables[$this->table]??[],fn($r)=>!$this->matches($r))); }
    }
    class AutoDns {
        public array $records=[]; public int $writes=0; public bool $fail=false; public $afterWrite=null;
        public function getDomainRecords($page=1,$size=100) { return ['total'=>count($this->records),'list'=>array_slice($this->records,($page-1)*$size,$size)]; }
        public function getError() { return 'provider failure'; }
        public function addDomainRecord($name,$type,$value,$line='default',$ttl=600) { if($this->fail)return false; $this->records[]=autoRecord('new-'.++$this->writes,$name,$value,$type); if($this->afterWrite)($this->afterWrite)(); return 'new-'.$this->writes; }
        public function deleteDomainRecord($id) { if($this->fail)return false; $this->writes++; $this->records=array_values(array_filter($this->records,fn($r)=>$r['RecordId']!==$id)); if($this->afterWrite)($this->afterWrite)(); return true; }
        public function updateDomainRecord($id,$name,$type,$value,...$args) { if($this->fail)return false; $this->writes++; foreach($this->records as &$r)if($r['RecordId']===$id){$r['Name']=$name;$r['Type']=$type;$r['Value']=$value;} if($this->afterWrite)($this->afterWrite)(); return true; }
    }
    class AutoOptimize extends app\service\OptimizeService { public array $ips=['DEF'=>[['ip'=>'1.1.1.1'],['ip'=>'8.8.8.8']]]; public function get_ip_address2($cdn=1,$type='v4') { return $this->ips; } }
    function autoRecord($id,$name,$value,$type='TXT') { return ['RecordId'=>$id,'Name'=>$name,'Value'=>$value,'Type'=>$type,'Domain'=>'example.com','Line'=>'default','TTL'=>600,'Status'=>'1']; }
    function autoSetup() {
        Db::$tables=['domain'=>[['id'=>1,'aid'=>1,'thirdid'=>'zone','name'=>'example.com','type'=>'fixture']],'cert_dns_lease'=>[],'dns_snapshot'=>[], 'optimizeip'=>[['id'=>1,'did'=>1,'rr'=>'www','ip_type'=>'v4','type'=>0,'cdn_type'=>1,'recordnum'=>1,'ttl'=>600,'active'=>1]]];
        $GLOBALS['auto_dns']=new AutoDns();
    }
    $checks=0; function autoCheck($ok,$message) { $GLOBALS['checks']++; if(!$ok)throw new RuntimeException($message); }
    function autoReject($callback,$message) { try{$callback();}catch(Throwable $e){autoCheck(true,$message);return;}throw new RuntimeException($message); }
    $log=static function($line){};
    $rows=static fn($value)=>['example.com'=>[['name'=>'_acme-challenge','type'=>'TXT','value'=>$value]]];
    autoSetup(); $dns=$GLOBALS['auto_dns']; $dns->records=[autoRecord('other','_acme-challenge','other-order')];
    CertDnsService::add($rows('order-one'),$log,false,1); CertDnsService::add($rows('order-two'),$log,false,2);
    autoCheck(count($dns->records)===3,'Concurrent order TXT values coexist');
    CertDnsService::remove(1,$log); autoCheck(array_column($dns->records,'Value')===['other-order','order-two'],'Cleanup removes only the order-owned TXT value');
    CertDnsService::remove(2,$log); autoCheck(array_column($dns->records,'Value')===['other-order'],'Pre-existing TXT survives all cleanup');
    autoCheck(count(Db::$tables['dns_snapshot'])===4,'ACME add/delete use guarded snapshots');
    autoSetup(); $dns=$GLOBALS['auto_dns']; $dns->records=[autoRecord('preexisting','_acme-challenge','same')];
    CertDnsService::add($rows('same'),$log,false,1); CertDnsService::remove(1,$log);
    autoCheck($dns->writes===0 && count($dns->records)===1,'An exact pre-existing value is never claimed as created');
    autoSetup(); $dns=$GLOBALS['auto_dns'];
    CertDnsService::add($rows('shared'),$log,false,1); CertDnsService::add($rows('shared'),$log,false,2);
    CertDnsService::remove(1,$log); autoCheck(count($dns->records)===1,'A second order retains its shared token');
    CertDnsService::remove(2,$log); autoCheck(count($dns->records)===0,'The last lease cleans up a system-created shared token');
    autoSetup(); $dns=$GLOBALS['auto_dns']; Db::$tables['domain'][0]['type']='huawei'; $dns->records=[autoRecord('rrset','_acme-challenge',['"other-order"'])];
    CertDnsService::add($rows('new-order'),$log,false,1); autoCheck($dns->records[0]['Value']===['"other-order"','"new-order"'],'Huawei merges TXT values without replacing siblings');
    CertDnsService::remove(1,$log); autoCheck($dns->records[0]['Value']===['"other-order"'],'Huawei cleanup preserves unrelated RRset values');
    autoSetup(); $dns=$GLOBALS['auto_dns']; for($i=0;$i<105;$i++)$dns->records[]=autoRecord('pad-'.$i,'pad-'.$i,'value'); $dns->records[]=autoRecord('last','_acme-challenge','paged');
    CertDnsService::add($rows('paged'),$log,false,1); autoCheck($dns->writes===0,'ACME reads beyond the first 100 records');
    autoSetup(); $dns=$GLOBALS['auto_dns']; $dns->fail=true;
    autoReject(fn()=>CertDnsService::add($rows('failed'),$log,false,1),'Provider failure must not report DNS success');
    autoCheck(Db::$tables['cert_dns_lease'][0]['managed']===0,'Unconfirmed writes never acquire cleanup ownership');
    autoSetup(); $dns=$GLOBALS['auto_dns']; CertDnsService::add($rows('bound'),$log,false,1); Db::$tables['domain'][0]['aid']=99;
    autoReject(fn()=>CertDnsService::remove(1,$log),'Rebinding a zone stops cleanup'); autoCheck(count($dns->records)===1,'Rebound zone is not changed');
    autoSetup(); $dns=$GLOBALS['auto_dns']; $dns->records=[autoRecord('one','www','1.1.1.1','A'),autoRecord('two','www','8.8.8.8','A'),autoRecord('mail','mail','8.8.4.4','A')];
    (new AutoOptimize())->execute_one(['id'=>1]); autoCheck(array_column($dns->records,'RecordId')===['one','mail'],'Reducing recordnum removes surplus valid IPs and preserves another host');
    autoSetup(); $dns=$GLOBALS['auto_dns']; $dns->records=[autoRecord('conflict','www','old.example.com','CNAME')]; $dns->fail=true;
    autoReject(fn()=>(new AutoOptimize())->execute_one(['id'=>1]),'Failed CNAME deletion stops optimization');
    autoSetup(); $service=new AutoOptimize(); $service->ips=['DEF'=>[['ip'=>'127.0.0.1']]];
    autoReject(fn()=>$service->execute_one(['id'=>1]),'Invalid upstream IP cannot overwrite DNS'); autoCheck($GLOBALS['auto_dns']->writes===0,'IP validation happens before writes');
    autoSetup(); $dns=$GLOBALS['auto_dns']; $dns->records=[autoRecord('one','www','9.9.9.9','A'),autoRecord('two','www','8.8.8.8','A')]; $dns->afterWrite=static function(){Db::$tables['domain']=[];};
    autoReject(fn()=>(new AutoOptimize())->execute_one(['id'=>1]),'Deleting a domain stops subsequent optimization writes'); autoCheck($dns->writes===1,'Domain authority is rechecked between writes');
    autoCheck(AutoConnection::$held===[],'Automatic DNS paths always release task locks');
    echo "Automatic DNS security: $checks checks passed\n";
}
