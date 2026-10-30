<?php
require_once __DIR__.'/../app/lib/ApiSignature.php';
require_once __DIR__.'/../app/lib/DeploymentTargets.php';
require_once __DIR__.'/../app/lib/SensitiveLog.php';
require_once __DIR__.'/../app/lib/DeployInterface.php';
require_once __DIR__.'/../app/lib/deploy/lucky.php';
require_once __DIR__.'/../app/service/HealthPolicy.php';
require_once __DIR__.'/../app/service/OperationsService.php';
require_once __DIR__.'/../app/service/CertVerificationService.php';
$checks=0;
function policyCheck($ok,$message){$GLOBALS['checks']++;if(!$ok)throw new RuntimeException($message);}
function policyFails($fn){try{$fn();}catch(Throwable $e){$GLOBALS['checks']++;return;}throw new RuntimeException('Expected failure');}
class SignedRequest {
 public array $headers=[];public string $body='name=www&value=1.1.1.1';public string $verb='POST';public string $uri='/api/record/add/1';public array $apiV2=[];
 public function header($k,$default=''){return $this->headers[$k]??$default;}public function getInput(){return $this->body;}public function method(){return $this->verb;}public function url(){return $this->uri;}
}
$r=new SignedRequest();$r->headers=['x-dnsmgr-timestamp'=>(string)time(),'x-dnsmgr-nonce'=>str_repeat('a',32),'idempotency-key'=>'fixture-idempotency'];
$canonical=app\lib\ApiSignature::canonical('keyid:test',$r->method(),$r->url(),$r->getInput(),$r->headers['x-dnsmgr-timestamp'],$r->headers['x-dnsmgr-nonce'],$r->headers['idempotency-key']);
$r->headers['x-dnsmgr-signature']=hash_hmac('sha256',$canonical,'fixture-secret');
policyCheck(app\lib\ApiSignature::verify($r,'keyid:test','fixture-secret'),'Valid v2 signature');
foreach(['body','verb','uri'] as $field){$old=$r->$field;$r->$field.='changed';policyCheck(!app\lib\ApiSignature::verify($r,'keyid:test','fixture-secret'),'Signature binds '.$field);$r->$field=$old;}
foreach(['x-dnsmgr-timestamp','x-dnsmgr-nonce','idempotency-key'] as $field){$old=$r->headers[$field];$r->headers[$field].='changed';policyCheck(!app\lib\ApiSignature::verify($r,'keyid:test','fixture-secret'),'Signature binds '.$field);$r->headers[$field]=$old;}
policyCheck(!app\lib\ApiSignature::verify($r,'keyid:other','fixture-secret'),'Signature binds identity');
policyCheck(!app\lib\ApiSignature::verify($r,'keyid:test','rotated-secret'),'Rotation invalidates old signatures');
function http_request($url,$body=null,$a=null,$b=null,$headers=null,$proxy=false,$method=null){
 if($body===null)return ['code'=>200,'body'=>json_encode(['ret'=>0,'list'=>[['Key'=>'good','Remark'=>'','CertsInfo'=>['Domains'=>['example.com']]],['Key'=>'bad','Remark'=>'','CertsInfo'=>['Domains'=>['example.com']]]]])];
 $key=json_decode($body,true)['Key'];$GLOBALS['deploy_attempts'][]=$key;return ['code'=>200,'body'=>json_encode($key==='bad'&&$GLOBALS['deploy_fail']?['ret'=>1,'msg'=>'provider rejected']:['ret'=>0])];
}
$GLOBALS['deploy_attempts']=[];$GLOBALS['deploy_fail']=true;$client=new app\lib\deploy\lucky(['url'=>'https://fixture.invalid','opentoken'=>'fixture','proxy'=>0]);$info=[];$checkpoints=0;$config=['domainList'=>['example.com'],'_checkpoint'=>function()use(&$checkpoints){$checkpoints++;}];
policyFails(function()use($client,&$info,$config){$client->deploy('cert-one','key',$config,$info);});
policyCheck(count(array_filter($info['_targets'],fn($r)=>$r['status']==='success'))===1&&$checkpoints===2,'Partial deployment persists both receipts');
$GLOBALS['deploy_fail']=false;$client->deploy('cert-one','key',$config,$info);
policyCheck($GLOBALS['deploy_attempts']===['good','bad','bad'],'Retry skips successful targets');
$client->deploy('cert-two','key',$config,$info);policyCheck($GLOBALS['deploy_attempts']===['good','bad','bad','good','bad'],'Renewed certificate invalidates receipts');
$policy=app\service\HealthPolicy::normalize(['statuses'=>'200,204','contains'=>'ok','probes'=>"https://one.example.com\nhttps://two.example.com",'quorum'=>2]);
policyCheck($policy['verify_tls']&&$policy['statuses']===[200,204],'Secure TLS and explicit HTTP statuses');
foreach([['quorum'=>2],['statuses'=>'200,999'],['probes'=>['file:///etc/passwd']],['contains'=>str_repeat('a',1025)]] as $bad)policyFails(fn()=>app\service\HealthPolicy::normalize($bad));
$probeCalls=0;$result=app\service\HealthPolicy::check(['health_options'=>$policy,'timeout'=>5,'checkurl'=>'https://main.example.com','main_value'=>'1.1.1.1','proxy'=>0],function($url,$timeout,$ip)use(&$probeCalls){$probeCalls++;if($probeCalls>1)policyCheck($ip===null,'Independent health endpoints use their own DNS');return ['status'=>$probeCalls!==1,'errmsg'=>null];});
policyCheck($result['status']&&$probeCalls===3,'Quorum accepts two of three health endpoints');
$result=app\service\HealthPolicy::check(['health_options'=>$policy,'timeout'=>5,'checkurl'=>'https://main.example.com','main_value'=>'1.1.1.1'],function($url){if(str_contains($url,'main.'))throw new RuntimeException('transport failed');return ['status'=>true];});
policyCheck($result['status']&&count($result['probes'])===3,'One transport exception does not bypass the remaining quorum probes');
$base=['did'=>1,'rr'=>'www','active'=>1];$conflicts=app\service\OperationsService::conflicts([$base+['id'=>1,'kind'=>'monitor','recordid'=>'r1'],$base+['id'=>2,'kind'=>'schedule','recordid'=>'r1']]);policyCheck(count($conflicts)===1,'Cross-task record conflict detected');
policyCheck(app\service\OperationsService::conflicts([$base+['id'=>1,'kind'=>'monitor','recordid'=>'r1'],$base+['id'=>2,'kind'=>'monitor','recordid'=>'r2']])===[],'Independent records do not conflict');
policyCheck(count(app\service\OperationsService::conflicts([$base+['id'=>1,'kind'=>'monitor','recordid'=>'r1'],$base+['id'=>2,'kind'=>'optimize','recordid'=>'*']]))===1,'RRset-wide optimization conflicts with individual record writers');
$config=app\service\CertVerificationService::normalizeConfig(['enabled'=>true,'targets'=>"example.com:443\nexample.com:443@8.8.8.8",'timeout'=>10],['example.com']);
policyCheck(count($config['targets'])===2&&$config['mode']==='all','Multiple TLS targets and pinned public origin');
foreach(['example.com:443@127.0.0.1','outside.example.net','example.com:22'," \n "] as $bad)policyFails(fn()=>app\service\CertVerificationService::normalizeConfig(['enabled'=>true,'targets'=>$bad],['example.com']));
class MatrixFixture extends app\service\CertVerificationService {
 public string $pem;public bool $bad=true;
 protected function resolvePublicAddresses(string $host,float $deadline):array{return ['1.1.1.1','8.8.8.8'];}
 protected function readCertificate(array $config,string $address,float $deadline):string{if($address==='8.8.8.8'&&$this->bad)throw new RuntimeException('stale or unreachable origin');return $this->pem;}
}
$matrix=new MatrixFixture();$matrix->pem=require __DIR__.'/fixtures/tls-example.php';$expected=app\service\CertVerificationService::fingerprint($matrix->pem);
$result=$matrix->verify($config,$expected);policyCheck($result['status']==='failed'&&count($result['targets'])===3,'One passing IP cannot mask failing targets');
$matrix->bad=false;$result=$matrix->verify($config,$expected);policyCheck($result['status']==='passed'&&$result['targets'][0]['expires_at']!==null,'Matrix reports all fingerprints and certificate expiry');
echo "Operational policies: $checks checks passed\n";
