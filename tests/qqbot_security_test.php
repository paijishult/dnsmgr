<?php
namespace think\facade {
    class Cache { public static function delete($key) {} public static function get($key) {return null;} public static function set(...$args) {} }
    class Log { public static function error($text) {} }
    class Db { public static array $values=[]; public static function connect(...$args) {return new \QqConnection();} }
}
namespace app { class BaseController {protected $request;public function __construct(){$this->request=\request();}} }
namespace {
    if (!function_exists('sodium_crypto_sign_seed_keypair')) {echo "QQ Ed25519 regression requires native sodium (covered by CI)\n";return;}
    require __DIR__.'/../app/lib/QqBot.php'; require __DIR__.'/../app/service/TaskLockService.php'; require __DIR__.'/../app/service/QqBotBindingService.php'; require __DIR__.'/../app/controller/Qqbot.php';
    use think\facade\Db; use app\service\QqBotBindingService;
    function config($key,$default=null){return $key==='database.connections.mysql'?['database'=>'test','prefix'=>'test_']:$default;}
    function config_get($key,$default=null){return $GLOBALS['qq_config'][$key]??$default;}
    function request(){return $GLOBALS['qq_request'];}
    class QqResponse {public function __construct(public $body,public $status=200){}}
    function response($body,$status){return new QqResponse($body,$status);} function json($value){return new QqResponse($value);}
    function get_curl($url,...$args){$GLOBALS['qq_http_calls']++;return str_contains($url,'getAppAccessToken')?'["invalid fixture"]':'{}';}
    class QqConnection {
        public static $held=null;public function name($table){return new QqQuery();}public function close(){if(self::$held===$this)self::$held=null;}
        public function transaction($fn){return $fn();}
        public function query($sql,$bind,$master){if(str_contains($sql,'GET_LOCK')){if(self::$held!==null)return [['acquired'=>0]];self::$held=$this;return [['acquired'=>1]];}self::$held=null;return [['released'=>1]];}
    }
    class QqQuery {
        private array $keys=[];public function whereIn($key,$values){$this->keys=$values;return $this;}public function where($key,$value){$this->keys=[$value];return $this;}
        public function column($value,$key){return array_intersect_key(Db::$values,array_flip($this->keys));}
        public function value($key){return Db::$values[$this->keys[0]]??null;}
        public function replace(){return $this;}public function insert($row){Db::$values[$row['key']]=$row['value'];return 1;}
    }
    class QqRequest {public string $body='';public array $headers=[];public bool $post=true;public function isPost(){return $this->post;}public function getContent(){return $this->body;}public function header($key,$default=''){return $this->headers[$key]??$default;}}
    $secret='fixture-'.bin2hex(random_bytes(16));$seed=substr(str_repeat($secret,4),0,32);$pair=sodium_crypto_sign_seed_keypair($seed);
    $GLOBALS['qq_config']=Db::$values=['qqbot_appid'=>'fixture-app','qqbot_appsecret'=>$secret,'sys_key'=>'binding-fixture','qqbot_openid'=>'existing-owner'];$GLOBALS['qq_request']=new QqRequest();$GLOBALS['qq_http_calls']=0;
    $checks=0;function qqCheck($ok,$message){$GLOBALS['checks']++;if(!$ok)throw new RuntimeException($message);}
    function callback($payload,bool $signed=true,?int $timestamp=null){global $pair;$r=request();$r->body=json_encode($payload);$ts=(string)($timestamp??time());$r->headers=$signed?['x-signature-timestamp'=>$ts,'x-signature-ed25519'=>bin2hex(sodium_crypto_sign_detached($ts.$r->body,sodium_crypto_sign_secretkey($pair)))]:[];return (new app\controller\Qqbot())->webhook();}
    $event=static fn($user,$message)=>['op'=>0,'t'=>'C2C_MESSAGE_CREATE','d'=>['id'=>'event-fixture','author'=>['user_openid'=>$user],'content'=>$message]];
    qqCheck(callback(['op'=>13,'d'=>['event_ts'=>'123','plain_token'=>'oracle'] ],false)->status===401,'Unauthenticated op13 must not sign attacker input');
    $result=callback(['op'=>13,'d'=>['event_ts'=>'123','plain_token'=>'verified']]);qqCheck($result->status===200&&isset($result->body['signature']),'Authenticated QQ URL validation remains supported');
    qqCheck(callback($event('attacker','hello'))->status===200&&Db::$values['qqbot_openid']==='existing-owner','Valid ordinary messages cannot replace the recipient');
    $challenge=QqBotBindingService::start();qqCheck(!str_contains(Db::$values['qqbot_bind_hash'],$challenge['message']),'Binding stores a digest instead of the challenge');
    callback($event('new-owner',$challenge['message']));qqCheck(Db::$values['qqbot_openid']==='new-owner','Administrator-issued binding code can bind');
    $calls=$GLOBALS['qq_http_calls'];callback($event('replay-user',$challenge['message']));qqCheck(Db::$values['qqbot_openid']==='new-owner'&&$GLOBALS['qq_http_calls']===$calls,'Binding code is single use and replay sends no reply');
    qqCheck(callback($event('attacker','hello'),true,time()-301)->status===401,'Expired signed requests are rejected');
    qqCheck(callback($event('attacker','hello'),true,time()+301)->status===401,'Future signed requests outside the clock window are rejected');
    $challenge=QqBotBindingService::start();Db::$values['qqbot_bind_until']=time();callback($event('late-user',$challenge['message']));qqCheck(Db::$values['qqbot_openid']==='new-owner','Expiry is enforced at the exact deadline');
    $challenge=QqBotBindingService::start();QqBotBindingService::saveSettings(['qqbot_appsecret'=>'rotated-fixture']);callback($event('old-app-user',$challenge['message']));
    qqCheck(Db::$values['qqbot_openid']===''&&Db::$values['qqbot_bind_hash']==='','Credential rotation invalidates binding and cached-old-app callbacks');
    request()->post=false;qqCheck((new app\controller\Qqbot())->webhook()->status===405,'Webhook is POST only');request()->post=true;request()->body=str_repeat('x',65537);qqCheck((new app\controller\Qqbot())->webhook()->status===413,'Webhook body is bounded');
    qqCheck(QqConnection::$held===null,'Binding session locks are always released');
    echo "QQ bot security: $checks checks passed\n";
}
