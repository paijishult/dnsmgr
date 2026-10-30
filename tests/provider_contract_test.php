<?php
namespace think\facade {
    class Db { public static array $rows=[]; public static function name($t){return new \ProofQuery($t);} public static function connect(...$args){return new \ProofConnection();} }
    class Cache { public static array $rows=[]; public static function get($k){return self::$rows[$k]??null;} public static function set($k,$v,...$a){self::$rows[$k]=$v;return true;} public static function delete($k){unset(self::$rows[$k]);return true;} }
}
namespace app\lib\client {
    class AWS {
        public function __construct(...$a){}
        public function requestXmlN($method,$path,$params){
            if($method==='GET'){
                $GLOBALS['aws_reads']=($GLOBALS['aws_reads']??0)+1;
                if(isset($GLOBALS['aws_override']))return $GLOBALS['aws_override'];
                if(!empty($GLOBALS['aws_transient'])){$GLOBALS['aws_transient']--;throw new \RuntimeException('Rate exceeded');}
                $sets=$GLOBALS['aws_sets'];
                if(isset($params['name']))$sets=array_values(array_filter($sets,fn($r)=>$r['Name']===$params['name']&&$r['Type']===$params['type']));
                return ['ResourceRecordSets'=>['ResourceRecordSet'=>$sets],'IsTruncated'=>'false'];
            }
            preg_match_all('~<Change>(.*?)</Change>~s',$params,$changes);
            foreach($changes[1] as $candidate) if(isset($GLOBALS['aws_reject']) && str_contains($candidate,'<Name>'.$GLOBALS['aws_reject'].'</Name>'))throw new \RuntimeException('simulated atomic provider rejection');
            foreach($changes[1] as $xml){
                $get=static function($tag)use($xml){preg_match('~<'.$tag.'>(.*?)</'.$tag.'>~s',$xml,$m);return html_entity_decode($m[1]??'',ENT_XML1|ENT_QUOTES);};
                $name=$get('Name');$type=$get('Type');$action=$get('Action');
                if($name===($GLOBALS['aws_reject']??null))throw new \Exception('simulated provider rejection at destination');
                $GLOBALS['aws_writes'][]=$params;
                $GLOBALS['aws_sets']=array_values(array_filter($GLOBALS['aws_sets'],fn($r)=>$r['Name']!==$name||$r['Type']!==$type));
                if($action!=='DELETE'){
                    preg_match_all('~<Value>(.*?)</Value>~s',$xml,$vals);
                    $GLOBALS['aws_sets'][]=['Name'=>$name,'Type'=>$type,'TTL'=>(int)$get('TTL'),'ResourceRecords'=>['ResourceRecord'=>array_map(fn($v)=>['Value'=>html_entity_decode($v,ENT_XML1|ENT_QUOTES)],$vals[1])]];
                }
            }
            return ['ChangeInfo'=>['Status'=>'PENDING']];
        }
    }
}
namespace {
$root=dirname(__DIR__);
foreach(['lib/AtomicDns','lib/ProviderReadRetry','lib/DnsInterface','lib/DnsHelper','lib/RecordGuard','lib/AutomaticDnsTarget','lib/GuardedDns','service/DnsChangeService','lib/dns/powerdns','lib/dns/aws','lib/dns/cloudflare','lib/dns/dnsla'] as $file)require "$root/app/$file.php";
class ProofConnection { public function name($t){return new ProofQuery($t);}public function startTrans(){}public function commit(){}public function rollback(){} }
class ProofQuery {
    private array $conditions=[];
    public function __construct(private string $table){}
    public function where($k,$v){$this->conditions[$k]=$v;return $this;}public function order(...$a){return $this;}public function limit(...$a){return $this;}public function column(...$a){return [];}
    public function insertGetId($r){$r['id']=count(\think\facade\Db::$rows[$this->table]??[])+1;\think\facade\Db::$rows[$this->table][]=$r;return $r['id'];}
    public function value($key){foreach(\think\facade\Db::$rows[$this->table]??[] as $row){foreach($this->conditions as $k=>$value)if(($row[$k]??null)!=$value)continue 2;return $row[$key]??null;}return null;}
    public function update($v){foreach(\think\facade\Db::$rows[$this->table]??[] as $i=>$r){foreach($this->conditions as $k=>$wanted)if(($r[$k]??null)!=$wanted)continue 2;\think\facade\Db::$rows[$this->table][$i]=array_merge($r,$v);}return 1;}
}
function request(){return (object)['user'=>['id'=>1,'type'=>'user'],'isApi'=>false];}
function runtime_path(){return sys_get_temp_dir().'/dnsmgr-provider-contracts/';}
function isNullOrEmpty($v){return $v===null||$v==='';}
if(!function_exists('mb_substr')){function mb_substr($v,$s,$n){return substr($v,$s,$n);}}
if(!function_exists('idn_to_ascii')){define('IDNA_DEFAULT',0);define('INTL_IDNA_VARIANT_UTS46',1);function idn_to_ascii($v,...$a){return $v;}}
function http_request($url,$body=null,$ref=null,$cookie=null,$headers=null,$proxy=false,$method=null){
    if(str_starts_with($url,'https://api.dns.la/')){
        $GLOBALS['dnsla_writes'][]=json_decode($body,true);
        return ['code'=>200,'body'=>json_encode(['code'=>200,'data'=>['id'=>'dnsla-record']])];
    }
    if(str_contains($url,'/servers/')){
        $GLOBALS['power_calls'][$method]=($GLOBALS['power_calls'][$method]??0)+1;
        if($method==='GET'&&!empty($GLOBALS['power_transient'])){$GLOBALS['power_transient']--;return ['code'=>503,'body'=>'{}'];}
        if($method==='PATCH'&&!empty($GLOBALS['power_write_failure']))return ['code'=>503,'body'=>'{"error":"temporary failure"}'];
        if($method!=='GET')foreach(json_decode($body,true)['rrsets'] as $change)if($change['name']===($GLOBALS['power_reject']??null))return ['code'=>422,'body'=>'{"error":"simulated atomic rejection"}'];
        if($method==='GET')return ['code'=>200,'body'=>json_encode(['rrsets'=>$GLOBALS['power_sets']])];
        foreach(json_decode($body,true)['rrsets'] as $change){
            $GLOBALS['power_sets']=array_values(array_filter($GLOBALS['power_sets'],fn($r)=>$r['name']!==$change['name']||$r['type']!==$change['type']));
            if($change['changetype']!=='DELETE')$GLOBALS['power_sets'][]=$change;
        }
        return ['code'=>204,'body'=>''];
    }
    if($method==='GET'){
        $list=str_ends_with(parse_url($url,PHP_URL_PATH),'/dns_records');
        return ['code'=>200,'body'=>json_encode(['success'=>true,'result'=>$list?[$GLOBALS['cf_record']]:$GLOBALS['cf_record'],'result_info'=>['total_count'=>1]])];
    }
    $payload=json_decode($body,true);if(isset($payload['name']))$payload['name']=$payload['name']==='@'?'example.com':$payload['name'].'.example.com';
    $GLOBALS['cf_record']=array_merge($GLOBALS['cf_record'],$payload);
    return ['code'=>200,'body'=>json_encode(['success'=>true,'result'=>$GLOBALS['cf_record']])];
}
$checks=0;
function contractCheck($ok,$message){$GLOBALS['checks']++;if(!$ok)throw new \RuntimeException($message);}
function show($scenario,$data){
    $ok=match($scenario){
        'powerdns_two_adds'=>$data['actual_values']===$data['expected_values'] && $data['success']===[true,true],
        'powerdns_disable_erases_comment'=>($data['comments'][0]['content']??null)==='keep-me',
        'aws_automatic_recovery'=>$data['first_success'] && $data['id_changed'] && $data['task_id_updated'] && $data['recovery_success'],
        'aws_add_over_alias'=>$data['success']===false && $data['alias_survives'],
        'aws_move_rejected'=>!$data['success'] && $data['remaining_records']===1,
        'cloudflare_resume'=>$data['after_resume']==='www.example.com' && $data['listed_status']==='1',
        'cloudflare_apex_preview_pause'=>$data['complete'] && $data['listed_name']==='@',
        'retarget_schema_length'=>$data['recordinfo_length']<=200,
        default=>false,
    };
    contractCheck($ok,$scenario.': '.json_encode($data));
}
function awsModel(){return new \app\lib\dns\aws(['AccessKeyId'=>'fixture','SecretAccessKey'=>'fixture','domain'=>'example.com','domainid'=>'zone']);}
function awsSet($name='www',$value='192.0.2.1'){return ['Name'=>"$name.example.com.",'Type'=>'A','TTL'=>600,'ResourceRecords'=>['ResourceRecord'=>[['Value'=>$value]]]];}
$domain=['id'=>1,'name'=>'example.com','aid'=>1,'thirdid'=>'zone'];
$GLOBALS['power_sets']=[['name'=>'www.example.com.','type'=>'A','ttl'=>600,'records'=>[['content'=>'192.0.2.1','disabled'=>false]],'comments'=>[]]];
$dns=new \app\lib\dns\powerdns(['ip'=>'192.0.2.10','port'=>8081,'apikey'=>'fixture','domain'=>'example.com','domainid'=>'example.com.']);
$guard=new \app\lib\GuardedDns($dns,$domain);
$first=$guard->addDomainRecord('www','A','192.0.2.2');$second=$guard->addDomainRecord('www','A','192.0.2.3');
show('powerdns_two_adds',['success'=>[$first,$second],'actual_values'=>array_column($GLOBALS['power_sets'][0]['records'],'content'),'expected_values'=>['192.0.2.1','192.0.2.2','192.0.2.3']]);unset($guard);
$GLOBALS['power_sets'][0]['comments']=[['content'=>'keep-me','account'=>'owner']];
$guard=new \app\lib\GuardedDns($dns,$domain);$id=$guard->records()[0]['RecordId'];$ok=$guard->setDomainRecordStatus($id,'0');show('powerdns_disable_erases_comment',['success'=>$ok,'comments'=>$GLOBALS['power_sets'][0]['comments']]);unset($guard);
$GLOBALS['aws_sets']=[awsSet()];$dns=awsModel();$old=$dns->getDomainRecords()['list'][0]['RecordId'];
\think\facade\Db::$rows['dmtask']=[['did'=>1,'recordid'=>$old]];
$guard=new \app\lib\GuardedDns($dns,$domain);$ok=$guard->updateDomainRecord($old,'www','A','192.0.2.2');unset($guard);
$new=$dns->getDomainRecords()['list'][0]['RecordId'];$guard=new \app\lib\GuardedDns($dns,$domain);$stored=\think\facade\Db::$rows['dmtask'][0]['recordid'];$recovery=$guard->updateDomainRecord($stored,'www','A','192.0.2.1');
show('aws_automatic_recovery',['first_success'=>$ok,'id_changed'=>$old!==$new,'task_id_updated'=>$stored===$new,'recovery_success'=>$recovery,'error'=>$guard->getError()]);unset($guard);
$GLOBALS['aws_sets']=[['Name'=>'www.example.com.','Type'=>'A','AliasTarget'=>['DNSName'=>'loadbalancer.example.net.','HostedZoneId'=>'alias-zone','EvaluateTargetHealth'=>true]]];$dns=awsModel();$guard=new \app\lib\GuardedDns($dns,$domain);$before=$guard->records();$ok=$guard->addDomainRecord('www','A','192.0.2.50');
show('aws_add_over_alias',['visible_before'=>count($before),'success'=>$ok,'alias_survives'=>isset($GLOBALS['aws_sets'][0]['AliasTarget']),'after'=>$GLOBALS['aws_sets']]);unset($guard);
$GLOBALS['aws_sets']=[awsSet()];$GLOBALS['aws_reject']='bad.example.com.';$dns=awsModel();$guard=new \app\lib\GuardedDns($dns,$domain);$id=$guard->records()[0]['RecordId'];$ok=$guard->updateDomainRecord($id,'bad','A','192.0.2.1');
show('aws_move_rejected',['success'=>$ok,'remaining_records'=>count($GLOBALS['aws_sets']),'error'=>$guard->getError()]);unset($guard);unset($GLOBALS['aws_reject']);
$GLOBALS['cf_record']=['id'=>'cf1','name'=>'www.example.com','type'=>'A','content'=>'192.0.2.1','proxied'=>false,'ttl'=>600,'comment'=>null,'modified_on'=>'now'];
$dns=new \app\lib\dns\cloudflare(['email'=>'fixture','apikey'=>'fixture','auth'=>1,'domain'=>'example.com','domainid'=>'zone']);
$dns->setDomainRecordStatus('cf1','0');$afterPause=$GLOBALS['cf_record']['name'];$dns->setDomainRecordStatus('cf1','1');show('cloudflare_resume',['after_pause'=>$afterPause,'after_resume'=>$GLOBALS['cf_record']['name'],'listed_status'=>$dns->getDomainRecords()['list'][0]['Status']]);
$GLOBALS['cf_record']['name']='example.com';$change=new \app\service\DnsChangeService();$plan=$change->preview($domain,$dns,[['action'=>'status','recordid'=>'cf1','status'=>'0']],'actor');$result=$change->apply($domain,$dns,$plan['token'],'actor');show('cloudflare_apex_preview_pause',['complete'=>$result['complete'],'results'=>$result['results'],'actual_name'=>$GLOBALS['cf_record']['name'],'listed_name'=>$dns->getDomainRecords()['list'][0]['Name']]);
$GLOBALS['power_sets']=[['name'=>'www.example.com.','type'=>'A','ttl'=>600,'records'=>[['content'=>'192.0.2.1','disabled'=>false]],'comments'=>[]]];$dns=new \app\lib\dns\powerdns(['ip'=>'192.0.2.10','port'=>8081,'apikey'=>'fixture','domain'=>'example.com','domainid'=>'example.com.']);
$id=$dns->getDomainRecords()['list'][0]['RecordId'];\think\facade\Db::$rows['dmtask']=[['did'=>1,'recordid'=>$id]];$guard=new \app\lib\GuardedDns($dns,$domain);$guard->updateDomainRecord($id,'www','A','192.0.2.2');unset($guard);$info=\think\facade\Db::$rows['dmtask'][0]['recordinfo'];show('retarget_schema_length',['recordinfo_length'=>strlen($info),'schema_varchar_limit'=>200,'stored_json'=>$info]);

$GLOBALS['power_sets']=[['name'=>'www.example.com.','type'=>'A','ttl'=>600,'records'=>[['content'=>'192.0.2.1','disabled'=>false]],'comments'=>[['content'=>'preserve','account'=>'fixture']]]];
$dns=new \app\lib\dns\powerdns(['ip'=>'192.0.2.10','port'=>8081,'apikey'=>'fixture','domain'=>'example.com','domainid'=>'example.com.']);
$id=$dns->getDomainRecords()['list'][0]['RecordId'];$GLOBALS['power_reject']='bad.example.com.';
contractCheck(!$dns->updateDomainRecord($id,'bad','A','192.0.2.1'),'Rejected PowerDNS move fails');
contractCheck($GLOBALS['power_sets'][0]['name']==='www.example.com.' && count($GLOBALS['power_sets'])===1,'Rejected PowerDNS move retains source');unset($GLOBALS['power_reject']);
$change=new \app\service\DnsChangeService();
$ops=[['action'=>'add','name'=>'new','type'=>'A','value'=>'192.0.2.2'],['action'=>'add','name'=>'new','type'=>'A','value'=>'192.0.2.3']];
$plan=$change->preview($domain,$dns,$ops,'actor');$GLOBALS['power_calls']=[];$result=$change->apply($domain,$dns,$plan['token'],'actor');
contractCheck($result['complete'] && $GLOBALS['power_calls']['PATCH']===1,'PowerDNS reviewed batch commits once');
contractCheck($GLOBALS['power_calls']['GET']<=3,'PowerDNS batch avoids per-record network scans');
$plan=$change->preview($domain,$dns,[['action'=>'add','name'=>'no-write','type'=>'A','value'=>'192.0.2.5'],['action'=>'add','name'=>'new','type'=>'A','value'=>'192.0.2.2']],'actor');$GLOBALS['power_calls']=[];
$result=$change->apply($domain,$dns,$plan['token'],'actor');contractCheck(!$result['complete'] && empty($GLOBALS['power_calls']['PATCH']),'Invalid staged batch never reaches provider');
$GLOBALS['aws_sets']=[awsSet()];$dns=awsModel();$plan=$change->preview($domain,$dns,$ops,'actor');$GLOBALS['aws_writes']=[];$result=$change->apply($domain,$dns,$plan['token'],'actor');
contractCheck($result['complete'] && count($GLOBALS['aws_writes'])===1,'Route53 reviewed batch groups one RRset transaction');
contractCheck(count($GLOBALS['aws_sets'])===2 && count($GLOBALS['aws_sets'][1]['ResourceRecords']['ResourceRecord'])===2,'Route53 batch preserves both new values');
$plan=$change->preview($domain,$dns,[['action'=>'add','name'=>'cancelled','type'=>'A','value'=>'192.0.2.8']],'actor');$trusted=$change->consumePlan($domain,$plan['token'],'actor');$GLOBALS['aws_writes']=[];
$result=$change->applyPlan($domain,$dns,$trusted,'actor',static function($state){contractCheck(!empty($state['atomic_ready'])&&$state['completed']===0,'Cancellation checkpoint runs before atomic provider writes');throw new \RuntimeException('cancelled');});
contractCheck(!$result['complete']&&!$GLOBALS['aws_writes'],'Cancelled atomic batch never mutates provider');
$GLOBALS['aws_transient']=1;$GLOBALS['aws_reads']=0;contractCheck($dns->getDomainRecords()!==false&&$GLOBALS['aws_reads']===2,'Route53 transient read retries without changing records');
$GLOBALS['aws_override']=['ResourceRecordSets'=>[],'IsTruncated'=>'true'];contractCheck($dns->getDomainRecords()===false,'Route53 truncated response requires a next cursor');
$GLOBALS['aws_override']=['ResourceRecordSets'=>[],'IsTruncated'=>'true','NextRecordName'=>'loop.example.com.','NextRecordType'=>'A'];$GLOBALS['aws_reads']=0;contractCheck($dns->getDomainRecords()===false&&$GLOBALS['aws_reads']===2,'Repeated Route53 cursor fails instead of looping');unset($GLOBALS['aws_override']);
$GLOBALS['aws_sets']=[awsSet()+['SetIdentifier'=>'0']];$GLOBALS['aws_writes']=[];contractCheck(!$dns->addDomainRecord('www','A','192.0.2.9')&&!$GLOBALS['aws_writes'],'Routing identifier zero cannot bypass unsupported record protection');
$dns=new \app\lib\dns\powerdns(['ip'=>'192.0.2.10','port'=>8081,'apikey'=>'fixture','domain'=>'example.com','domainid'=>'example.com.']);
$GLOBALS['power_transient']=1;$GLOBALS['power_calls']=[];contractCheck($dns->getDomainRecords()!==false&&$GLOBALS['power_calls']['GET']===2,'PowerDNS transient reads retry');
$GLOBALS['power_transient']=10;$GLOBALS['power_calls']=[];contractCheck($dns->getDomainRecords()===false&&$GLOBALS['power_calls']['GET']===3,'Provider read retries have a fixed bound');unset($GLOBALS['power_transient']);
$GLOBALS['power_write_failure']=true;$GLOBALS['power_calls']=[];contractCheck(!$dns->addDomainRecord('uncertain','A','192.0.2.9')&&$GLOBALS['power_calls']['PATCH']===1,'Uncertain writes are never automatically retried');unset($GLOBALS['power_write_failure']);

\think\facade\Db::$rows['account']=[['id'=>1,'type'=>'powerdns']];
$GLOBALS['power_sets']=[];
$dns=new \app\lib\dns\powerdns(['ip'=>'192.0.2.10','port'=>8081,'apikey'=>'fixture','domain'=>'example.com','domainid'=>'example.com.']);
$plan=$change->preview($domain,$dns,[['action'=>'add','name'=>'@','type'=>'ALIAS','value'=>'Target.example.net']],'actor');
$result=$change->apply($domain,$dns,$plan['token'],'actor');
contractCheck($result['complete']&&$GLOBALS['power_sets'][0]['records'][0]['content']==='Target.example.net.','PowerDNS ALIAS adds an absolute target and preview accepts the trailing dot');
$id=$dns->getDomainRecords()['list'][0]['RecordId'];
\think\facade\Db::$rows['sctask']=[['did'=>1,'recordid'=>$id]];
$guard=new \app\lib\GuardedDns($dns,$domain);
contractCheck($guard->updateDomainRecord($id,'@','ALIAS','new.example.net')===true,'Manual ALIAS changes synchronize content-derived IDs');unset($guard);
$new=$dns->getDomainRecords()['list'][0]['RecordId'];
contractCheck($new!==$id&&\think\facade\Db::$rows['sctask'][0]['recordid']===$new,'ALIAS task links point at the updated record');
$plan=$change->preview($domain,$dns,[['action'=>'update','recordid'=>$new,'name'=>'@','type'=>'ALIAS','value'=>'renewed.example.net']],'actor');
$result=$change->apply($domain,$dns,$plan['token'],'actor');
contractCheck($result['complete']&&\think\facade\Db::$rows['sctask'][0]['recordid']===$dns->getDomainRecords()['list'][0]['RecordId'],'Reviewed ALIAS updates also preserve task links');
$plan=$change->preview($domain,$dns,[['action'=>'add','name'=>'unsupported','type'=>'ALIAS','value'=>'target.example.net']],'actor');
\think\facade\Db::$rows['account'][0]['type']='cloudflare';$GLOBALS['power_calls']=[];
$guard=new \app\lib\GuardedDns($dns,$domain);
contractCheck($guard->addDomainRecord('unsupported','ALIAS','target.example.net')===false&&empty($GLOBALS['power_calls']['PATCH']),'Unsupported ALIAS forms and API writes fail before mutation');unset($guard);
try{$change->preview($domain,$dns,[['action'=>'add','name'=>'unsupported','type'=>'ALIAS','value'=>'target.example.net']],'actor');throw new \LogicException('Unsupported ALIAS preview accepted');}
catch(\RuntimeException $e){contractCheck(str_contains($e->getMessage(),'不支持ALIAS'),'Preview rejects unsupported providers');}
try{$change->apply($domain,$dns,$plan['token'],'actor');throw new \LogicException('Changed provider accepted stale ALIAS plan');}
catch(\RuntimeException $e){contractCheck(str_contains($e->getMessage(),'不支持ALIAS')&&empty($GLOBALS['power_calls']['PATCH']),'Execution rechecks ALIAS capability after provider changes');}
foreach(['aliyun','henet','powerdns','spaceship'] as $provider)contractCheck(\app\lib\DnsHelper::supportsRecordType($provider,'alias'),'Imported ALIAS provider is advertised');
contractCheck(!\app\lib\DnsHelper::supportsRecordType('aws','ALIAS'),'Route53 alias targets are not confused with ALIAS record types');
$dnsla=new \app\lib\dns\dnsla(['apiid'=>'fixture','apisecret'=>'fixture','domain'=>'example.com','domainid'=>'zone']);$GLOBALS['dnsla_writes']=[];
$dnsla->addDomainRecord('www','A','192.0.2.1','0');$dnsla->updateDomainRecord('record','www','A','192.0.2.2',0);$dnsla->addDomainRecord('www','A','192.0.2.3','custom-line');
contractCheck(array_column($GLOBALS['dnsla_writes'],'lineId')===['','','custom-line'],'DNSLA zero default lines become empty API lines while custom lines survive');
echo "Provider HTTP contracts: $checks checks passed\n";
}
