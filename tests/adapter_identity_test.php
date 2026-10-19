<?php
require __DIR__.'/../app/lib/DnsInterface.php';require __DIR__.'/../app/lib/dns/powerdns.php';require __DIR__.'/../app/lib/dns/technitium.php';require __DIR__.'/../app/lib/AutomaticDnsTarget.php';require __DIR__.'/../app/lib/RecordGuard.php';
function isNullOrEmpty($v){return $v===null||$v==='';}
function getDnsType($value){return 'A';}
function cache($key,$value=null,...$args){if(func_num_args()>1)$GLOBALS['adapter_cache'][$key]=$value;return $GLOBALS['adapter_cache'][$key]??null;}
function http_request($url,$body=null,...$args){
    $isPower=str_contains($url,'/servers/');$host=parse_url($url,PHP_URL_HOST);$state=&$GLOBALS['adapter_state'][$host];
    if($isPower){
        if($body===null)return ['code'=>200,'body'=>json_encode(['rrsets'=>$state])];
        $payload=json_decode($body,true);$GLOBALS['adapter_writes'][]=[$host,$payload];
        foreach($payload['rrsets'] as $change){foreach($state as $i=>$rrset)if($rrset['name']===$change['name']&&$rrset['type']===$change['type']){if($change['changetype']==='DELETE')unset($state[$i]);else $state[$i]=$change;}}
        $state=array_values($state);return ['code'=>204,'body'=>''];
    }
    if($body===null)return ['code'=>200,'body'=>json_encode(['status'=>'ok','response'=>['records'=>$state]])];
    parse_str($body,$payload);$GLOBALS['adapter_writes'][]=[$host,$payload];return ['code'=>200,'body'=>'{"status":"ok"}'];
}
function identityCheck($ok,$message){$GLOBALS['identity_checks']++;if(!$ok)throw new RuntimeException($message);}
$GLOBALS['identity_checks']=0;$GLOBALS['adapter_writes']=[];
function powerRow($name,$ip){return ['name'=>$name.'.example.com.','type'=>'A','ttl'=>600,'records'=>[['content'=>$ip,'disabled'=>false]],'comments'=>[]];}
$GLOBALS['adapter_state']=['192.0.2.1'=>[powerRow('www','192.0.2.11'),powerRow('mail','192.0.2.12')],'192.0.2.2'=>[powerRow('www','192.0.2.22')]];
$a=new app\lib\dns\powerdns(['ip'=>'192.0.2.1','port'=>8081,'apikey'=>'fixture-a','domain'=>'example.com','domainid'=>'example.com.']);
$b=new app\lib\dns\powerdns(['ip'=>'192.0.2.2','port'=>8081,'apikey'=>'fixture-b','domain'=>'example.com','domainid'=>'example.com.']);
$first=$a->getDomainRecords()['list'];$target=$first[0]['RecordId'];$b->getDomainRecords();
identityCheck($a->addDomainRecord('www','A','192.0.2.33')!==false,'PowerDNS add succeeds with instance-local state');
$write=$GLOBALS['adapter_writes'][0][1]['rrsets'][0]['records'];identityCheck(array_column($write,'content')===['192.0.2.11','192.0.2.33'],'A second account cannot contaminate the first account’s RRset');
identityCheck(!isset($write[0]['id']),'Internal stable IDs are not sent to PowerDNS');
$GLOBALS['adapter_state']['192.0.2.1']=array_reverse($GLOBALS['adapter_state']['192.0.2.1']);$fresh=$a->getDomainRecords()['list'];
identityCheck($fresh[1]['RecordId']===$target,'PowerDNS IDs survive RRset reorder');
identityCheck($a->setDomainRecordStatus($target,'0')!==false,'A stable ID can target the original record after reorder');
$change=end($GLOBALS['adapter_writes'])[1]['rrsets'][0];identityCheck($change['name']==='www.example.com.'&&$change['records'][0]['disabled']===true,'The original host is paused, not the row now occupying its old index');
identityCheck($a->updateDomainRecord($target,'www','A','192.0.2.44')!==false,'PowerDNS value switching succeeds');
$newId=app\lib\AutomaticDnsTarget::afterUpdate($a,['type'=>'powerdns','name'=>'example.com'],'www','192.0.2.44','default');
identityCheck($newId!==$target,'A successful value change resolves its new stable record ID');
class IdentityConnection {
    public array $rows=['dmtask'=>[],'sctask'=>[]];public function startTrans(){}public function commit(){}public function rollback(){}
    public function name($table){return new IdentityQuery($this,$table);}
}
class IdentityQuery {
    private array $where=[];public function __construct(private IdentityConnection $db,private string $table){}
    public function where($key,$value){$this->where[$key]=$value;return $this;}
    public function update($data){foreach($this->db->rows[$this->table] as &$row){foreach($this->where as $key=>$value)if($row[$key]!==$value)continue 2;$row=array_merge($row,$data);}return 1;}
}
$connection=new IdentityConnection();$connection->rows=['dmtask'=>[['did'=>1,'recordid'=>$target]],'sctask'=>[['did'=>1,'recordid'=>$target],['did'=>1,'recordid'=>$target],['did'=>2,'recordid'=>$target]]];
app\lib\AutomaticDnsTarget::retarget($connection,1,$target,$newId);
identityCheck(array_column($connection->rows['sctask'],'recordid')===[$newId,$newId,$target]&&$connection->rows['dmtask'][0]['recordid']===$newId,'Paired schedules and monitors follow the changed record without crossing domain boundaries');
foreach(['powerdns'=>'0_0','technitium'=>'0'] as $type=>$id){$threw=false;try{app\lib\AutomaticDnsTarget::validate(['recordid'=>$id],['type'=>$type]);}catch(RuntimeException $e){$threw=true;}identityCheck($threw,'Legacy positional automatic task IDs fail closed');}
$row=static fn($ip)=>['name'=>'www.example.com','type'=>'A','ttl'=>600,'disabled'=>false,'rData'=>['ipAddress'=>$ip,'fixtureOption'=>'stable']];
$GLOBALS['adapter_state']['192.0.2.3']=[$row('192.0.2.31'),['name'=>'mail.example.com','type'=>'A','ttl'=>600,'disabled'=>false,'rData'=>['ipAddress'=>'192.0.2.32']]];
$GLOBALS['adapter_state']['192.0.2.4']=[$row('192.0.2.41')];
$ta=new app\lib\dns\technitium(['url'=>'http://192.0.2.3:5380','token'=>'fixture-a','domain'=>'example.com','domainid'=>'example.com']);$tb=new app\lib\dns\technitium(['url'=>'http://192.0.2.4:5380','token'=>'fixture-b','domain'=>'example.com','domainid'=>'example.com']);
$target=$ta->getDomainRecords()['list'][0]['RecordId'];$tb->getDomainRecords();$GLOBALS['adapter_state']['192.0.2.3']=array_reverse($GLOBALS['adapter_state']['192.0.2.3']);$GLOBALS['adapter_state']['192.0.2.3'][1]['rData']=array_reverse($GLOBALS['adapter_state']['192.0.2.3'][1]['rData'],true);
$fresh=$ta->getDomainRecords()['list'];identityCheck($fresh[1]['RecordId']===$target,'Technitium IDs survive record and JSON property reorder');
identityCheck($ta->setDomainRecordStatus($target,'0'),'Technitium stable target can be paused');$change=end($GLOBALS['adapter_writes'])[1];identityCheck($change['domain']==='www.example.com'&&$change['ipAddress']==='192.0.2.31','Technitium targets the same host and original value');
echo 'Adapter identity isolation: '.$GLOBALS['identity_checks']." checks passed\n";
