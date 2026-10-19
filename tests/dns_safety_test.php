<?php
/** Native standalone regression suite: no cloud calls, database or framework bootstrap. */
namespace think\facade {
    class Cache {
        public static array $data = [];
        public static bool $failSet = false;
        public static bool $failDelete = false;
        public static function set($key, $value, $ttl = null) { if (self::$failSet) return false; self::$data[$key] = $value; return true; }
        public static function get($key) { return self::$data[$key] ?? null; }
        public static function delete($key) { if (self::$failDelete) return false; unset(self::$data[$key]); return true; }
    }
    class Db {
        public static array $rows = [];
        public static function name($table) { return new \DnsFakeQuery($table); }
    }
}
namespace app { class BaseController { protected $request; public function __construct() { $this->request=\request(); } protected function alert($type,$msg='') { return ['code'=>-1,'msg'=>$msg]; } } }
namespace app\lib { class DnsHelper { public static $line_name=['aliyun'=>['DEF'=>'default']]; public static function getModel(...$args) { return $GLOBALS['dns_controller_model']; } } }
namespace {
    require_once __DIR__ . '/../app/lib/RecordGuard.php';
    require_once __DIR__ . '/../app/lib/GuardedDns.php';
    require_once __DIR__ . '/../app/service/DnsChangeService.php';
    use app\lib\RecordGuard;
    use app\lib\GuardedDns;
    use app\service\DnsChangeService;
    use think\facade\Cache;
    use think\facade\Db;

    if (!function_exists('mb_substr')) { function mb_substr($s, $start, $length = null) { return substr($s, $start, $length); } }
    function runtime_path() { return sys_get_temp_dir() . '/dnsmgr-dns-regression-' . getmypid() . '/'; }
    function request() { return $GLOBALS['dns_test_request']; }
    function input($key,$default=null,$filter=null) { $key=preg_replace('~/[a-z]+$~','',$key); return $GLOBALS['dns_input'][$key]??$default; }
    function json($data) { return $data; }
    function checkPermission($type,$domain=null) { return $domain===null || $domain==='example.com'; }
    function api_domains() { return null; }
    function api_domain_allowed($name) { return $name==='example.com'; }
    function config_get($key,$default=null) { return $default; }
    class DnsRequest { public $user=['id'=>7,'type'=>'user'];public $apiKey=['id'=>1,'name'=>'test-key'];public $isApi=true;public function isPost(){return true;} public function isAjax(){return false;} }
    $GLOBALS['dns_test_request'] = new DnsRequest();
    class DnsFakeQuery {
        private array $where = [];
        public function __construct(private string $table) {}
        public function where($key, $value) { $this->where[$key] = $value; return $this; }
        public function order(...$args) { return $this; }
        public function limit(...$args) { return $this; }
        public function column($key) { return []; }
        public function insert($row) { return $this->insertGetId($row); }
        public function insertGetId($row) { $row['id'] = count(Db::$rows[$this->table] ?? []) + 1; Db::$rows[$this->table][] = $row; return $row['id']; }
        public function find() { foreach (Db::$rows[$this->table] ?? [] as $row) { foreach ($this->where as $key => $value) if (($row[$key] ?? null) != $value) continue 2; return $row; } return null; }
        public function value($key) { return $this->find()[$key] ?? null; }
    }
    class DnsFake {
        public int $writes = 0;
        public bool $fail = false;
        public bool $positional = false;
        public bool $mutateSibling = false;
        public bool $fullContext = true;
        public function __construct(public array $records) {}
        public function getDomainRecords($page = 1, $size = 100) {
            $this->fullContext = $this->fullContext && RecordGuard::isFullZoneRead();
            if ($this->positional) foreach ($this->records as $i => &$r) $r['RecordId'] = $i;
            return ['total' => count($this->records), 'list' => array_slice($this->records, ($page - 1) * $size, $size)];
        }
        public function getError() { return 'mock failure'; }
        public function addDomainRecord($name, $type, $value, $line, $ttl, $mx, $weight, $remark) { $this->writes++; if ($this->fail) return false; $this->records[] = row('new' . $this->writes, $name, $type, $value) + []; $i = array_key_last($this->records); foreach (['Line' => $line, 'TTL' => $ttl, 'MX' => $type === 'MX' ? $mx : null, 'Weight' => $weight, 'Remark' => $remark] as $k => $v) $this->records[$i][$k] = $v; return 'new' . $this->writes; }
        public function deleteDomainRecord($id) { $this->writes++; if ($this->fail) return false; foreach ($this->records as $i => $r) if ((string)$r['RecordId'] === (string)$id) { array_splice($this->records, $i, 1); return true; } return false; }
        public function updateDomainRecord($id, $name, $type, $value, $line, $ttl, $mx, $weight, $remark) { $this->writes++; if ($this->fail) return false; foreach ($this->records as &$r) if ((string)$r['RecordId'] === (string)$id) { foreach (['Name' => $name, 'Type' => $type, 'Value' => $value, 'Line' => $line, 'TTL' => $ttl, 'MX' => $type === 'MX' ? $mx : null, 'Weight' => $weight, 'Remark' => $remark] as $k => $v) $r[$k] = $v; if ($this->mutateSibling && isset($this->records[1])) $this->records[1]['TTL'] = 999; return true; } return false; }
        public function setDomainRecordStatus($id, $status) { $this->writes++; if ($this->fail) return false; foreach ($this->records as &$r) if ((string)$r['RecordId'] === (string)$id) { $r['Status'] = $status; return true; } return false; }
        public function updateDomainRecordRemark($id, $remark) { $this->writes++; if ($this->fail) return false; foreach ($this->records as &$r) if ((string)$r['RecordId'] === (string)$id) { $r['Remark'] = $remark; return true; } return false; }
    }
    function row($id = 'a', $name = 'www', $type = 'A', $value = '192.0.2.1') { return ['RecordId' => $id, 'Domain' => 'example.com', 'Name' => $name, 'Type' => $type, 'Value' => $value, 'Line' => 'default', 'TTL' => 600, 'MX' => null, 'Status' => '1', 'Weight' => null, 'Remark' => null]; }
    $checks = 0;
    function expect($ok, $message) { $GLOBALS['checks']++; if (!$ok) throw new RuntimeException($message); }
    function rejects(callable $call, $message) { try { $call(); } catch (Throwable $e) { expect(true, $message); return; } expect(false, $message); }
    $domain = ['id' => 1, 'name' => 'example.com', 'aid' => 1, 'thirdid' => 'zone1'];
    Db::$rows['account'] = [['id' => 1, 'type' => 'aliyun']];
    expect(RecordGuard::domainName(' EXAMPLE.com. ') === 'example.com', 'normalize domain');
    foreach (['<svg/onload=alert(1)>.com', 'example.com/path', '-bad.com', 'a..com', 'a_' . '.com', str_repeat('a', 64) . '.com'] as $invalid) rejects(fn() => RecordGuard::domainName($invalid), 'reject invalid domain');
    RecordGuard::validateRecord(['name' => '_acme-challenge.www', 'type' => 'TXT', 'value' => '0', 'ttl' => 1]);
    rejects(fn() => RecordGuard::validateRecord(['name' => '<img>', 'type' => 'A', 'value' => '1']), 'record host HTML rejected');
    $records = []; for ($i = 0; $i < 201; $i++) $records[] = row((string)$i, 'host' . $i);
    $dns = new DnsFake($records);
    expect(count(RecordGuard::all($dns, 'example.com')) === 201, 'all pages retrieved');
    expect($dns->fullContext && !RecordGuard::isFullZoneRead(), 'full-zone flag scoped to reads');
    expect(RecordGuard::owned($dns, 'example.com', [0])[0]['Name'] === 'host0', 'numeric record ID0 accepted');
    rejects(fn() => RecordGuard::owned($dns, 'example.com', ['foreign']), 'foreign ID rejected');
    rejects(fn() => RecordGuard::owned($dns, 'example.com', ['1', '1']), 'duplicate IDs rejected');
    $broken = new class([row()]) extends DnsFake { public function getDomainRecords($p = 1, $s = 100) { return ['total' => 2, 'list' => $this->records]; } };
    rejects(fn() => RecordGuard::all($broken, 'example.com'), 'repeated pagination fails closed');
    expect(!RecordGuard::isFullZoneRead(), 'full-zone flag reset on exception');
    $bad = row(); $bad['Domain'] = 'other.example.net';
    rejects(fn() => RecordGuard::all(new DnsFake([$bad]), 'example.com'), 'foreign response domain rejected');
    $mixed = [row('a'), row('t', 'www', 'TXT', 'token'), row('m', 'www', 'MX', 'mail.example.com')];
    expect(count(RecordGuard::selectForBatchEdit($mixed, 'www', 'A', 'default')) === 1, 'type filter survives preferred-line filter');
    $v6 = row('b', 'www', 'AAAA', '2001:db8::1'); $mixed[] = $v6;
    expect(count(RecordGuard::selectForBatchEdit($mixed, 'www', 'CNAME', 'default')) === 2, 'address-type fallback excludes TXT/MX');
    $qing = new class([]) extends DnsFake { public function getDomainRecords($p=1,$s=100) { $r=row('www.example.com','www'); $r['Type']=null;$r['Count']=1;return ['total'=>1,'list'=>[$r]]; } public function getSubDomainRecords(...$a) { return ['total'=>1,'list'=>[row('parent_value')]]; } };
    expect(RecordGuard::owned($qing,'example.com',['parent_value'])[0]['RecordId']==='parent_value','QingCloud host records expanded');
    $service = new DnsChangeService(); $dns = new DnsFake([row()]);
    $leaky = row(); $leaky['AccessKeySecret'] = 'must-not-persist'; $dns->records = [$leaky];
    $snapshot = $service->snapshot($domain, $dns);
    expect(!str_contains(Db::$rows['dns_snapshot'][0]['records'], 'must-not-persist'), 'snapshot whitelists fields');
    $dns->records = [row()];
    $op = ['action' => 'update', 'recordid' => 'a', 'name' => 'www', 'type' => 'A', 'value' => '192.0.2.2'];
    $preview = $service->preview($domain, $dns, [$op], 'actor1');
    rejects(fn() => $service->apply($domain,$dns,$preview['token'],'actor2'),'actor binding');
    $otherDomain=$domain; $otherDomain['id']=2;
    rejects(fn() => $service->apply($otherDomain,$dns,$preview['token'],'actor1'),'domain binding');
    $dns->records[0]['Value']='192.0.2.9';
    rejects(fn() => $service->apply($domain,$dns,$preview['token'],'actor1'),'drift rejects reviewed plan');
    expect($dns->writes===0,'drift caused no writes');
    $dns->records=[row()]; $preview=$service->preview($domain,$dns,[$op],'actor1');
    $applied=$service->apply($domain,$dns,$preview['token'],'actor1');
    expect($applied['complete'] && $dns->records[0]['Value']==='192.0.2.2','reviewed update applied');
    rejects(fn() => $service->apply($domain,$dns,$preview['token'],'actor1'),'single-use token');
    $preview=$service->preview($domain,$dns,[['action'=>'delete','recordid'=>'a']],'actor1');
    Cache::$data['dns_preview_'.$preview['token']]['expires']=time()-1;
    rejects(fn() => $service->consumePlan($domain,$preview['token'],'actor1'),'expired token rejected');
    $ns=row('ns','@','NS','ns1.example.com');$dns->records=[row(),$ns];
    rejects(fn() => $service->preview($domain,$dns,[['action'=>'delete','recordid'=>'ns']],'actor1'),'apex NS protected');
    $dns->records=[row()]; $guard=new GuardedDns($dns,$domain);
    expect($guard->deleteDomainRecord('foreign')===false && $dns->writes===1,'foreign record never reaches adapter');
    rejects(fn() => $guard->owned(['a','foreign']), 'whole batch rejected before mutation');
    unset($guard);
    $dns=new DnsFake([row('0','a'),row('1','b')]);$dns->positional=true;$guard=new GuardedDns($dns,$domain);
    expect($guard->deleteDomainRecord('0')===true,'first positional record deleted');
    expect($guard->deleteDomainRecord('1')===true && !$dns->records,'second positional ID resolved by original content');unset($guard);
    $dns=new DnsFake([row(),row('b','other')]);$dns->mutateSibling=true;
    $preview=$service->preview($domain,$dns,[$op],'actor1');$result=$service->apply($domain,$dns,$preview['token'],'actor1');
    expect(!$result['complete'] && !$result['results'][0]['success'],'unexpected sibling mutation stops reviewed execution');
    $dns=new DnsFake([row()]);$dns->fail=true;$preview=$service->preview($domain,$dns,[$op],'actor1');
    $result=$service->apply($domain,$dns,$preview['token'],'actor1');
    expect(!$result['complete'] && count($result['remaining_changes'])===1,'failed item retained for safe retry');
    $dns=new DnsFake([row()]);$preview=$service->preview($domain,$dns,[$op],'actor1');$plan=$service->consumePlan($domain,$preview['token'],'actor1');$checkpoints=[];
    $result=$service->applyPlan($domain,$dns,$plan,'actor1',function($state)use(&$checkpoints){$checkpoints[]=$state;});
    expect($result['complete'] && count($checkpoints)===1 && !$checkpoints[0]['remaining_changes'],'queue checkpoint persisted after confirmed change');
    $dns=new DnsFake([row('renumbered')]);$rollback=$service->rollbackPreview($domain,$dns,$snapshot,'actor1');
    expect(!$rollback['changes'],'rollback ignores ID renumbering');
    $dns->records[0]['Value']='192.0.2.2';$rollback=$service->rollbackPreview($domain,$dns,$snapshot,'actor1');
    expect(count($rollback['changes'])===1 && $rollback['changes'][0]['operation']['action']==='update','rollback updates existing RR without duplicate CNAME creates');
    $dns->records[]=$ns;$rollback=$service->rollbackPreview($domain,$dns,$snapshot,'actor1');
    expect(count($rollback['changes'])===1,'rollback preserves current apex NS');
    require_once __DIR__ . '/../app/controller/Domain.php';
    Db::$rows['domain']=[$domain];
    $GLOBALS['dns_controller_model']=new DnsFake([row()]);$dns=$GLOBALS['dns_controller_model'];
    $controller=new \app\controller\Domain();
    $GLOBALS['dns_input']=['param.id'=>1,'post.recordid'=>'foreign','post.recordinfo'=>json_encode(row('foreign'))];
    $response=$controller->record_delete();
    expect($response['code']===-1 && $dns->writes===0,'controller rejects foreign delete before adapter write');
    $GLOBALS['dns_input']=['param.id'=>1,'post.action'=>'delete','post.recordinfo'=>json_encode([row(),row('foreign')])];
    $response=$controller->record_batch();
    expect($response['code']===-1 && $dns->writes===0,'controller authenticates entire batch before first mutation');
    $forged=row();$forged['Name']='attacker';$forged['Line']='forged';
    $GLOBALS['dns_input']=['param.id'=>1,'post.action'=>'value','post.recordinfo'=>json_encode([$forged]),'post.type'=>'A','post.value'=>'192.0.2.8'];
    $response=$controller->record_batch_edit();
    expect($response['code']===0 && $dns->records[0]['Name']==='www' && $dns->records[0]['Line']==='default','controller ignores client metadata in batch updates');
    $dns->records=[row(),row('t','www','TXT','token'),row('m','www','MX','mail.example.com')];
    $GLOBALS['dns_input']=['post.id'=>1,'post.name'=>'www','post.type'=>'A','post.value'=>'192.0.2.7'];
    $response=$controller->record_batch_edit2();
    expect($response['code']===0 && $dns->records[0]['Value']==='192.0.2.7' && $dns->records[1]['Type']==='TXT' && $dns->records[2]['Type']==='MX','plain POST named batch edit preserves unrelated record types');
    $actor1=DnsChangeService::actor();request()->apiKey['id']=2;
    expect($actor1!==DnsChangeService::actor(),'API key identity included in actor binding');
    $capDns=new DnsFake([row()]);$capped=[];
    for($i=0;$i<5;$i++)$capped[]=$service->preview($domain,$capDns,[['action'=>'delete','recordid'=>'a']],'cap-actor')['token'];
    expect(!isset(Cache::$data['dns_preview_'.$capped[0]],Cache::$data['dns_preview_'.$capped[1]]),'abandoned preview files evicted after three retained plans');
    foreach(array_slice($capped,2) as $token)expect(isset(Cache::$data['dns_preview_'.$token]),'three newest preview plans remain available');
    $another=$service->preview($domain,$capDns,[['action'=>'delete','recordid'=>'a']],'other-key-actor');
    expect(!isset(Cache::$data['dns_preview_'.$capped[2]]),'different API keys share the user preview storage cap');
    $previousUser=request()->user;request()->user['id']=9999;
    $service->preview($domain,$capDns,[['action'=>'delete','recordid'=>'a']],'another-user');
    expect(isset(Cache::$data['dns_preview_'.$another['token']]),'preview storage caps isolate different users');request()->user=$previousUser;
    $largeRecords=[];$largeOps=[];
    for($i=0;$i<60;$i++){$largeRecords[]=row('large'.$i,'host'.$i,'TXT',str_repeat('v',40000));$largeOps[]=['action'=>'delete','recordid'=>'large'.$i];}
    $cacheCount=count(Cache::$data);
    rejects(fn()=>$service->preview($domain,new DnsFake($largeRecords),$largeOps,'large-actor'),'oversized before-record plan rejected before cache write');
    expect(count(Cache::$data)===$cacheCount,'rejected large preview does not allocate token cache files');
    Cache::$failSet=true;
    rejects(fn()=>$service->preview($domain,$capDns,[['action'=>'delete','recordid'=>'a']],'storage-failure'),'failed cache write does not issue a usable preview');
    Cache::$failSet=false;
    $preview=$service->preview($domain,$capDns,[['action'=>'delete','recordid'=>'a']],'consume-failure');
    Cache::$failDelete=true;
    rejects(fn()=>$service->apply($domain,$capDns,$preview['token'],'consume-failure'),'failed token consumption rejects execution');
    expect($capDns->writes===0,'failed token consumption cannot trigger DNS writes');
    rejects(fn()=>$service->preview($domain,$capDns,[['action'=>'delete','recordid'=>'a']],'eviction-failure'),'failed eviction cannot create an unbounded preview file');
    Cache::$failDelete=false;
    echo 'DNS safety: ' . $checks . " checks passed\n";
}
