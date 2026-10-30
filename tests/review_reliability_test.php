<?php
namespace app\lib {
    class CertHelper { public static function getModel2(...$args){return new class{public function finalizeOrder(...$args){return ['fullchain'=>'NEW-CERT','private_key'=>'NEW-KEY','issuer'=>'fixture','validFrom'=>time(),'validTo'=>time()+86400];}};} }
}
namespace app\utils {
    class MsgNotice {
        public static array $calls=[]; public static bool $fail=true;
        public static function deliver($channel,...$args){self::$calls[]=$channel;return $channel==='mail' && self::$fail ? 'simulated mail failure' : true;}
    }
}
namespace app\lib\deploy {
    function http_request($url,$body=null,...$args) {
        if ($body===null) return ['code'=>200,'body'=>json_encode(['ret'=>0,'list'=>[['Key'=>'good','CertsInfo'=>['Domains'=>['example.com']]],['Key'=>'bad','CertsInfo'=>['Domains'=>['example.com']]]]])];
        $key=json_decode($body,true)['Key'];$GLOBALS['receipt_calls'][]=$key;
        return ['code'=>200,'body'=>json_encode($key==='bad'&&!empty($GLOBALS['receipt_failure']) ? ['ret'=>1,'msg'=>'rejected '.$GLOBALS['receipt_secret']] : ['ret'=>0])];
    }
}
namespace {
    $root=dirname(__DIR__);$vendor=getenv('DNSMGR_COMPOSER_VENDOR')?:$root.'/vendor';
    if(!is_file($vendor.'/autoload.php')){echo "Review reliability requires Composer/SQLite (covered by CI)\n";return;}
    require $vendor.'/autoload.php';$_SERVER['REMOTE_ADDR']='127.0.0.1';
    $app=new think\App($root.'/');$app->setRuntimePath(sys_get_temp_dir().'/dnsmgr-review-tests-'.bin2hex(random_bytes(6)).'/');$app->initialize();
    set_exception_handler(static function(Throwable $e){echo (string)$e;exit(1);});
    class ReviewSqlite extends think\db\connector\Sqlite {
        public static array $locks=[];
        public function query(string $sql,array $bind=[],bool $master=false):array {
            if(str_contains($sql,'GET_LOCK')){$key=$bind['task_lock'];if(isset(self::$locks[$key]))return [['acquired'=>0]];self::$locks[$key]=$this;return [['acquired'=>1]];}
            if(str_contains($sql,'RELEASE_LOCK')){$key=$bind['task_lock'];if((self::$locks[$key]??null)===$this)unset(self::$locks[$key]);return [['released'=>1]];}
            return parent::query($sql,$bind,$master);
        }
    }
    mkdir($app->getRuntimePath(),0700,true);
    $app->config->set(['default'=>'mysql','connections'=>['mysql'=>['type'=>'\\'.ReviewSqlite::class,'builder'=>think\db\builder\Sqlite::class,'database'=>$app->getRuntimePath().'review.sqlite','prefix'=>'r_','fields_cache'=>false,'params'=>[PDO::ATTR_TIMEOUT=>1]]]],'database');
    $app->config->set(['dns_watch_enabled'=>'1','sys_key'=>'test-only'],'sys');
    use think\facade\Db;
    Db::execute('PRAGMA journal_mode=WAL');
    foreach([
        'cert_account(id INTEGER PRIMARY KEY,type TEXT,config TEXT,ext TEXT)',
        'cert_order(id INTEGER PRIMARY KEY,aid INTEGER,status INTEGER,retry INTEGER,retrytime TEXT,issend INTEGER,fullchain TEXT,privatekey TEXT,issuer TEXT,issuetime TEXT,expiretime TEXT,info TEXT,dns TEXT,error TEXT,keytype TEXT,keysize INTEGER)',
        'cert_domain(oid INTEGER,domain TEXT,sort INTEGER)',
        'cert_deploy(id INTEGER PRIMARY KEY,oid INTEGER,status INTEGER,retry INTEGER,retrytime TEXT,issend INTEGER)',
        'dmtask(id INTEGER PRIMARY KEY,active INTEGER)',
        'notification_job(id INTEGER PRIMARY KEY AUTOINCREMENT,dedup TEXT UNIQUE,event TEXT,channel TEXT,payload TEXT,status INTEGER,attempts INTEGER,nexttime INTEGER,addtime INTEGER,error TEXT)',
        'api_nonce(id TEXT PRIMARY KEY,expires INTEGER)',
        'api_receipt(id TEXT PRIMARY KEY,digest TEXT,authorization TEXT,status INTEGER,body TEXT,http_code INTEGER,expires INTEGER)',
        'dns_watch(did INTEGER PRIMARY KEY,identity TEXT,checksum TEXT,baseline TEXT,observed TEXT,diff TEXT,error TEXT)',
        'apikey(id INTEGER PRIMARY KEY,status INTEGER,secret TEXT,expiretime TEXT)',
        'credential_health(kind TEXT,aid INTEGER,enabled INTEGER,version TEXT,status TEXT,error TEXT,checked_at INTEGER,nexttime INTEGER,alert_signature TEXT,rotation_days INTEGER,rotated_at TEXT,expires_at TEXT,PRIMARY KEY(kind,aid))',
        'user(id INTEGER PRIMARY KEY,status INTEGER,level INTEGER,is_api INTEGER,apikey TEXT,password TEXT,totp_secret TEXT)',
    ] as $schema)Db::execute('CREATE TABLE r_'.$schema);
    $checks=0;function reviewCheck($ok,$message){$GLOBALS['checks']++;if(!$ok)throw new RuntimeException($message);}
    function reviewFails($call){try{$call();}catch(Throwable $e){$GLOBALS['checks']++;return $e;}throw new RuntimeException('Expected failure');}
    Db::name('cert_account')->insert(['id'=>1,'type'=>'fixture','config'=>'{}']);
    Db::name('cert_order')->insert(['id'=>1,'aid'=>1,'status'=>2,'fullchain'=>'OLD-CERT','privatekey'=>'OLD-KEY','keytype'=>'RSA','keysize'=>2048]);
    Db::name('cert_domain')->insert(['oid'=>1,'domain'=>'example.com','sort'=>1]);
    Db::name('cert_deploy')->insert(['id'=>1,'oid'=>1,'status'=>1,'retry'=>0,'issend'=>0]);
    Db::execute("CREATE TRIGGER reject_deploy BEFORE UPDATE ON r_cert_deploy BEGIN SELECT RAISE(ABORT,'simulated DB failure'); END");
    $order=new app\service\CertOrderService(1);reviewFails(fn()=>$order->finalizeOrder());
    reviewCheck(Db::name('cert_order')->where('id',1)->value('fullchain')==='OLD-CERT' && Db::name('cert_order')->where('id',1)->value('status')==2,'Failed deployment enqueue rolls back certificate and status');
    Db::execute('DROP TRIGGER reject_deploy');$order->finalizeOrder();
    reviewCheck(Db::name('cert_order')->where('id',1)->value('fullchain')==='NEW-CERT' && Db::name('cert_deploy')->where('id',1)->value('status')==0,'Retry atomically stores certificate and schedules deployment');
    Db::name('dmtask')->insert(['id'=>1,'active'=>1]);$conn=app\service\TaskLockService::connection();$lock=new app\service\TaskLockService($conn,'monitor:1');$lock->acquire();
    $mutation=fn()=>Db::name('dmtask')->where('id',1)->update(['active'=>0]);reviewFails(fn()=>app\service\TaskLockService::mutateMany('monitor',[1],$mutation));
    reviewCheck(Db::name('dmtask')->where('id',1)->value('active')==1,'In-flight monitor prevents misleading successful disable');$lock->release();$conn->close();
    app\service\TaskLockService::mutateMany('monitor',[1],$mutation);reviewCheck(Db::name('dmtask')->where('id',1)->value('active')==0,'Disable succeeds after worker releases mutex');
    reviewFails(fn()=>app\service\TaskLockService::mutateMany('monitor',range(1,51),$mutation));
    reviewFails(fn()=>app\service\TaskLockService::mutateMany('monitor',[[]],$mutation));
    $conn=app\service\TaskLockService::connection();$lock=new app\service\TaskLockService($conn,'monitor:2');$lock->acquire();
    reviewFails(fn()=>app\service\TaskLockService::mutateMany('monitor',[1,2],$mutation));$lock->release();$conn->close();
    reviewCheck(ReviewSqlite::$locks===[],'Partial bulk lock acquisition releases earlier locks');
    $ack=['table'=>'cert_deploy','where'=>['id'=>1,'status'=>0],'update'=>['issend'=>1]];
    Db::execute("CREATE TRIGGER reject_channel BEFORE INSERT ON r_notification_job WHEN NEW.channel='tgbot' BEGIN SELECT RAISE(ABORT,'simulated insert failure'); END");
    reviewFails(fn()=>app\service\NotificationService::enqueue('interrupted-queue','title','body',['mail','tgbot'],$ack));
    reviewCheck(Db::name('notification_job')->count()===0,'Channel insertion failure rolls back the whole event');Db::execute('DROP TRIGGER reject_channel');
    app\service\NotificationService::enqueue('fixture','title','body',['mail','tgbot'],$ack);app\service\NotificationService::enqueue('fixture','title','body',['mail','tgbot'],$ack);
    reviewCheck(Db::name('notification_job')->count()===2,'Notification channels deduplicate repeated scheduling');
    app\service\NotificationService::execute();
    reviewCheck(Db::name('cert_deploy')->where('id',1)->value('issend')==0,'Failed channel never acknowledges notification');
    reviewCheck(Db::name('notification_job')->where('channel','mail')->value('status')==0 && Db::name('notification_job')->where('channel','tgbot')->value('status')==1,'Partial delivery saves each channel separately');
    app\utils\MsgNotice::$fail=false;Db::name('notification_job')->where('channel','mail')->update(['nexttime'=>0]);app\service\NotificationService::execute();
    reviewCheck(Db::name('cert_deploy')->where('id',1)->value('issend')==1,'Acknowledgement follows all successful deliveries');
    reviewCheck(app\utils\MsgNotice::$calls===['mail','tgbot','mail'],'Retry does not resend successful channel');
    class ReceiptRequest {public array $apiV2;public array $user=['level'=>2];public array $apiKey=['scopes'=>['record:write']];public function url(){return '/api/record/add/1';}}
    $request=new ReceiptRequest();$request->apiV2=['identity'=>'fixture','nonce'=>'first','idempotency'=>'stable-request-key','digest'=>'body-digest'];$calls=0;
    $execute=function()use(&$calls){$calls++;return json(['code'=>0,'recordid'=>'created']);};
    $response=app\service\ApiRequestService::execute($request,$execute);reviewCheck($response->getCode()===200&&$calls===1,'First idempotent request executes');
    reviewCheck(app\service\ApiRequestService::execute($request,$execute)->getCode()===409&&$calls===1,'Nonce replay never reaches mutation');
    $request->apiV2['nonce']='second';$replay=app\service\ApiRequestService::execute($request,$execute);reviewCheck(json_decode($replay->getContent(),true)['recordid']==='created'&&$calls===1,'Fresh nonce replays stored result without mutation');
    $request->user['api_domains']=['restricted.example'];$request->apiV2['nonce']='new-authorization';reviewCheck(app\service\ApiRequestService::execute($request,$execute)->getCode()===409&&$calls===1,'Changed authorization cannot replay an earlier privileged result');unset($request->user['api_domains']);
    $request->apiV2['nonce']='third';$request->apiV2['digest']='changed';reviewCheck(app\service\ApiRequestService::execute($request,$execute)->getCode()===409&&$calls===1,'Same idempotency key cannot change payload');
    $request->apiV2['nonce']='fourth';$request->apiV2['idempotency']='interrupted-key';reviewFails(fn()=>app\service\ApiRequestService::execute($request,fn()=>throw new RuntimeException('interrupted')));
    $request->apiV2['nonce']='fifth';reviewCheck(app\service\ApiRequestService::execute($request,$execute)->getCode()===409&&$calls===1,'Uncertain execution is not replayed after interruption');
    $domain=['id'=>1,'aid'=>1,'name'=>'example.com','thirdid'=>'zone'];$before=[['RecordId'=>'1','Name'=>'www','Type'=>'A','Value'=>'1.1.1.1','Line'=>'default','TTL'=>600,'Status'=>'1']];$after=$before;$after[0]['Value']='8.8.8.8';
    $fingerprint=app\lib\RecordGuard::fingerprint($before);Db::name('dns_watch')->insert(['did'=>1,'identity'=>app\service\DriftService::identity($domain),'checksum'=>$fingerprint,'baseline'=>json_encode($before)]);
    app\service\DriftService::managed($domain,$before,$after);reviewCheck(Db::name('dns_watch')->where('did',1)->value('checksum')===app\lib\RecordGuard::fingerprint($after),'Managed change advances matching baseline');
    app\service\DriftService::managed($domain,$before,[]);reviewCheck(Db::name('dns_watch')->where('did',1)->value('checksum')===app\lib\RecordGuard::fingerprint($after),'Pre-existing external drift cannot be absorbed by later managed changes');
    Db::execute("CREATE TRIGGER reject_baseline BEFORE UPDATE ON r_dns_watch BEGIN SELECT RAISE(ABORT,'simulated monitoring failure'); END");
    $oldErrorLog=ini_get('error_log');ini_set('error_log',$app->getRuntimePath().'expected-errors.log');
    try {app\service\DriftService::managed($domain,$after,[]);}finally{ini_set('error_log',$oldErrorLog);Db::execute('DROP TRIGGER reject_baseline');}
    reviewCheck(Db::name('dns_watch')->where('did',1)->value('checksum')===app\lib\RecordGuard::fingerprint($after),'Failed baseline persistence retains evidence without failing a confirmed DNS write');
    $diff=app\service\DriftService::difference($before,$after);reviewCheck($diff['added_count']===1&&$diff['removed_count']===1,'Changed value appears on both sides of drift');
    $diff=app\service\DriftService::difference(array_merge($before,$before),$before);reviewCheck($diff['removed_count']===1&&$diff['added_count']===0,'Drift detects removal of one identical record without collapsing duplicates');
    Db::name('credential_health')->insert(['kind'=>'api','aid'=>99,'enabled'=>1,'version'=>'old','status'=>'healthy','nexttime'=>0]);
    $health=app\service\OperationsService::checkCredential('api',99);reviewCheck($health['enabled']===0&&$health['status']==='failed','Deleted credentials leave the active queue and cannot starve later checks');
    // Exercise the real request, authentication middleware, nonce store and response cache together.
    Db::name('user')->insert(['id'=>2,'status'=>1,'level'=>2,'is_api'=>1,'apikey'=>'hmac-fixture-secret']);
    $http=$app->request;$http->setUrl('/api/record/add/1');$http->withServer(['REQUEST_METHOD'=>'POST','REQUEST_URI'=>'/api/record/add/1','REMOTE_ADDR'=>'127.0.0.1']);
    $body='name=www&type=A&value=192.0.2.4';parse_str($body,$params);$http->withPost($params);$http->withInput($body);
    $headers=['x-dnsmgr-version'=>'2','x-dnsmgr-uid'=>'2','x-dnsmgr-timestamp'=>(string)time(),'x-dnsmgr-nonce'=>str_repeat('b',32),'idempotency-key'=>'middleware-operation'];
    $headers['x-dnsmgr-signature']=hash_hmac('sha256',app\lib\ApiSignature::canonical('uid:2','POST',$http->url(),$body,$headers['x-dnsmgr-timestamp'],$headers['x-dnsmgr-nonce'],$headers['idempotency-key']),'hmac-fixture-secret');$http->withHeader($headers);
    $middleware=new app\middleware\AuthApi();$controllerCalls=0;$next=function($req)use(&$controllerCalls){$controllerCalls++;return json(['code'=>0,'name'=>$req->post('name')]);};
    $response=$middleware->handle($http,$next);reviewCheck($response->getCode()===200&&$controllerCalls===1,'Signed v2 request reaches controller through real middleware');
    $response=$middleware->handle($http,$next);reviewCheck($response->getCode()===409&&$controllerCalls===1,'Middleware rejects nonce replay before controller');
    $http->withInput($body.'0');$response=$middleware->handle($http,$next);reviewCheck($response->getCode()===403&&$controllerCalls===1,'Middleware rejects a changed raw body');
    $http->withInput($body);$http->withServer(['REQUEST_METHOD'=>'POST','REQUEST_URI'=>'/api/record/delete/1','HTTP_X_REWRITE_URL'=>'/api/record/add/1','REMOTE_ADDR'=>'127.0.0.1']);
    $response=$middleware->handle($http,$next);reviewCheck($response->getCode()===403&&$controllerCalls===1,'Rewrite headers cannot transplant a signature to a different routed URI');
    // The new operational queries must run against real ORM/SQL, not just browser mocks.
    foreach ([
        'dmtask'=>['did INTEGER','rr TEXT','recordid TEXT','status INTEGER','checktime INTEGER','checknexttime INTEGER'],
        'cert_deploy'=>['aid INTEGER','remark TEXT','active INTEGER','error TEXT','lasttime TEXT','info TEXT','config TEXT','islock INTEGER','locktime TEXT','processid TEXT'],
        'cert_order'=>['updatetime TEXT'],
        'cert_account'=>['name TEXT','remark TEXT','deploy INTEGER'],
        'apikey'=>['name TEXT'],
        'dns_watch'=>['enabled INTEGER','interval_seconds INTEGER','checked_at INTEGER'],
    ] as $table=>$columns) {foreach($columns as $column)Db::execute('ALTER TABLE r_'.$table.' ADD COLUMN '.$column);Db::connect()->getSchemaInfo('r_'.$table,true);}
    foreach ([
        'domain(id INTEGER PRIMARY KEY,name TEXT)',
        'account(id INTEGER PRIMARY KEY,type TEXT,remark TEXT,config TEXT)',
        'batch_task(id INTEGER PRIMARY KEY,name TEXT,total INTEGER,completed INTEGER,status INTEGER,cancel_requested INTEGER,error TEXT,updatetime TEXT)',
        'sctask(id INTEGER PRIMARY KEY,did INTEGER,rr TEXT,recordid TEXT,active INTEGER,updatetime INTEGER,nexttime INTEGER)',
        'optimizeip(id INTEGER PRIMARY KEY,did INTEGER,rr TEXT,active INTEGER,status INTEGER,errmsg TEXT,updatetime TEXT)',
    ] as $schema)Db::execute('CREATE TABLE r_'.$schema);
    Db::name('cert_order')->where('id',1)->update(['fullchain'=>require __DIR__.'/fixtures/tls-example.php']);
    Db::name('cert_deploy')->where('id',1)->update(['info'=>json_encode(['privatekey'=>'must-stay-private','_targets'=>[['target'=>'origin','status'=>'success','privatekey'=>'must-stay-private']]])]);
    $http->isApi=false;$http->user=['id'=>2,'type'=>'user','level'=>2];
    $operations=new app\controller\Operations($app);
    foreach(['tasks','conflicts','matrix','drift','credentials','notifications'] as $panel){$http->withPost(['panel'=>$panel]);$response=$operations->data();$data=json_decode($response->getContent(),true);reviewCheck(($data['code']??-1)===0,'Operational '.$panel.' panel executes against real SQL');reviewCheck(!str_contains($response->getContent(),'must-stay-private'),'Operational '.$panel.' output excludes stored private material');}
    $http->user=['id'=>2,'type'=>'user','level'=>1];reviewCheck(json_decode($operations->data()->getContent(),true)['code']===-1,'Ordinary users cannot read operational views');
    $http->user=['id'=>2,'type'=>'user','level'=>2];$http->isApi=true;reviewCheck(json_decode($operations->data()->getContent(),true)['code']===-1,'Even admin API keys cannot enter operational web views');
    // Real deployment adapter + service + ORM: partial receipts survive retries and rotations.
    $GLOBALS['receipt_calls']=[];$GLOBALS['receipt_failure']=true;$GLOBALS['receipt_secret']=str_repeat('private-fixture-',60);
    $accountConfig=['url'=>'https://deployment.invalid','opentoken'=>$GLOBALS['receipt_secret'],'proxy'=>0];
    Db::name('cert_account')->insert(['id'=>2,'type'=>'lucky','config'=>json_encode($accountConfig),'deploy'=>1]);
    Db::name('cert_deploy')->insert(['id'=>2,'aid'=>2,'oid'=>1,'active'=>1,'status'=>0,'retry'=>0,'config'=>'{}']);
    ob_start();
    try {
        $deployment=new app\service\CertDeployService(2);reviewFails(fn()=>$deployment->process(true));
        $saved=Db::name('cert_deploy')->where('id',2)->find();
        reviewCheck($saved['status']==-1&&!str_contains($saved['info'],'private-fixture-'),'Partial deployment stores failed status and redacts credentials before truncation');
        Db::name('cert_deploy')->where('id',2)->update(['config'=>json_encode(['_verify_tls'=>['enabled'=>false]])]);
        $GLOBALS['receipt_failure']=false;$deployment->process(true);
        reviewCheck($GLOBALS['receipt_calls']===['good','bad','bad']&&Db::name('cert_deploy')->where('id',2)->value('status')==1,'Service retries only the failed remote target');
        $deployment->reset();$deployment->process(true);reviewCheck(count($GLOBALS['receipt_calls'])===5,'Explicit redeploy of a completed certificate resets target receipts');
        $GLOBALS['receipt_failure']=true;$deployment->reset();reviewFails(fn()=>$deployment->process(true));
        $accountConfig['url']='https://rotated-deployment.invalid';Db::name('cert_account')->where('id',2)->update(['config'=>json_encode($accountConfig)]);
        $GLOBALS['receipt_failure']=false;$deployment->process(true);reviewCheck(array_slice($GLOBALS['receipt_calls'],-2)===['good','bad'],'Changed deployment account invalidates all old target receipts');
    } finally {ob_end_clean();}
    reviewCheck(ReviewSqlite::$locks===[],'All failures and successful operations release locks');
    echo "Review transactional reliability: $checks checks passed\n";
}
