<?php
/** Security regressions exercise real controllers, CSRF checks and the atomic limiter. */
namespace think\facade {
    class Db { public static array $tables=[]; public static function name($name){return new \AuthQuery($name);} }
    class Session { public static int $regenerated=0;public static function regenerate(){self::$regenerated++;} }
    class View { public static function assign(...$args){} }
    class Cache {}
}
namespace app {
    class BaseController { protected $request;protected $clientip='192.0.2.10';public function __construct(){$this->request=\request();}protected function alert($type,$msg=''){return \json(['code'=>-1,'msg'=>$msg]);} }
}
namespace app\lib { class ApiKeyHelper { public static function clientIp(){return '192.0.2.10';} } }
namespace {
    require __DIR__.'/../app/lib/AttemptLimiter.php';
    require __DIR__.'/../app/lib/CsrfToken.php';
    require __DIR__.'/../app/lib/TOTP.php';
    require __DIR__.'/../app/controller/Auth.php';
    require __DIR__.'/../app/controller/Index.php';
    require __DIR__.'/../app/middleware/CheckLogin.php';
    use app\lib\AttemptLimiter;
    use app\lib\CsrfToken;
    use app\lib\TOTP;
    use think\facade\Db;
    class AuthResponse { public int $status=200;public function __construct(public array $data){}public function code($status){$this->status=$status;return $this;} }
    class AuthRequest {
        public bool $islogin=false;public bool $isApi=false;public array $user=[];
        public array $headers=[];public string $verb='POST';public string $hostname='example.com';public bool $ssl=true;public bool $ajax=true;
        public function header($name){return $this->headers[strtolower($name)]??null;}
        public function post($key,$default=null){return $GLOBALS['auth_input']['post.'.$key]??$default;}
        public function host(){return $this->hostname;}public function isSsl(){return $this->ssl;}public function isPost(){return $this->verb==='POST';}public function isGet(){return $this->verb==='GET';}public function method(){return $this->verb;}public function isAjax(){return $this->ajax;}
    }
    class AuthQuery {
        private array $conditions=[];
        public function __construct(private string $table){}
        public function where($key,$value){$this->conditions[$key]=$value;return $this;}
        private function matches($row){foreach($this->conditions as $key=>$value)if(($row[$key]??null)!=$value)return false;return true;}
        public function find(){foreach(Db::$tables[$this->table]??[] as $row)if($this->matches($row))return $row;return null;}
        public function insert($row){Db::$tables[$this->table][]=$row;return true;}
        public function update($data){$count=0;foreach(Db::$tables[$this->table] as &$row)if($this->matches($row)){$row=array_merge($row,$data);$count++;}return $count;}
    }
    function request(){return $GLOBALS['auth_request'];}
    function input($key,$default=null,...$args){return $GLOBALS['auth_input'][$key]??$default;}
    function json($data){return new AuthResponse($data);}
    function redirect($url){return new AuthResponse(['redirect'=>$url]);}
    function view(){return 'login view';}
    function url($url){return $url;}
    function config_get($key,$default=null){return ['sys_key'=>'test-only-installation-secret','vcode'=>'0'][$key]??$default;}
    function captcha_check($code){return $code==='test';}
    function checkPermission(...$args){return true;}
    function session($key,$value='__GET__'){if($value==='__GET__')return $GLOBALS['auth_session'][$key]??null;if($value===null)unset($GLOBALS['auth_session'][$key]);else $GLOBALS['auth_session'][$key]=$value;}
    function cookie($key,$value='__GET__',$options=[]){if($value==='__GET__')return $GLOBALS['auth_cookies'][$key]['value']??null;if($value===null)unset($GLOBALS['auth_cookies'][$key]);else $GLOBALS['auth_cookies'][$key]=['value'=>$value,'options'=>$options];}
    function authcode($data,...$args){return base64_encode($data);}
    function app(){return $GLOBALS['auth_app'];}
    class AuthApp { public function __construct(public string $directory){}public function getRuntimePath(){return $this->directory;} }
    $GLOBALS['auth_app']=new AuthApp(sys_get_temp_dir().'/dnsmgr-auth-tests-'.bin2hex(random_bytes(6)).'/');
    $GLOBALS['auth_request']=new AuthRequest();$GLOBALS['auth_session']=[];$GLOBALS['auth_input']=[];$GLOBALS['auth_cookies']=[];
    $checks=0;
    function verify($condition,$message){$GLOBALS['checks']++;if(!$condition)throw new RuntimeException($message);}
    function user(){return ['id'=>1000,'username'=>'tester','password'=>password_hash('correct-password',PASSWORD_DEFAULT),'status'=>1,'totp_open'=>1,'totp_secret'=>'JBSWY3DPEHPK3PXP','level'=>2,'type'=>'user'];}
    function otp($secret){$method=new ReflectionMethod(TOTP::class,'at');return $method->invoke(TOTP::create($secret),time());}
    function startLogin(){request()->verb='POST';request()->ajax=true;request()->islogin=false;request()->headers=['origin'=>'https://example.com'];$GLOBALS['auth_input']=['post.username'=>'tester','post.password'=>'correct-password'];return (new \app\controller\Auth())->login();}
    Db::$tables['user']=[user()];
    // Atomic limiter grants exactly N attempts, keeps a stable lock inode across clear.
    $directory=app()->getRuntimePath().'unit-limit';
    for($i=0;$i<5;$i++)verify(AttemptLimiter::hit('test',5,60,$directory)['allowed'],'first five allowed');
    $blocked=AttemptLimiter::hit('test',5,60,$directory);verify(!$blocked['allowed']&&$blocked['remaining']===0&&$blocked['retry_after']>0,'sixth rate limited');
    AttemptLimiter::clear('test',$directory);verify(AttemptLimiter::hit('test',5,60,$directory)['allowed'],'clear resets count');
    $filename=$directory.'/'.hash('sha256','test').'.json';file_put_contents($filename,json_encode(['count'=>99,'expires'=>time()-1]));verify(AttemptLimiter::hit('test',5,60,$directory)['allowed'],'expired window resets count');
    // Bind CSRF token to the encrypted login cookie, validate header and form transports.
    cookie('user_token','signed-login-cookie');$token=CsrfToken::token();request()->headers=['origin'=>'https://example.com','x-csrf-token'=>$token];
    verify(strlen($token)===64&&CsrfToken::valid(request()),'valid same-origin CSRF header accepted');
    request()->headers=['referer'=>'https://example.com/account'];$GLOBALS['auth_input']=['post.__token__'=>$token];verify(CsrfToken::valid(request()),'same-origin form CSRF accepted');
    request()->headers=['origin'=>'https://attacker.test','x-csrf-token'=>$token];verify(!CsrfToken::valid(request()),'cross-origin token rejected');
    request()->headers=['origin'=>'https://example.com:444','x-csrf-token'=>$token];verify(!CsrfToken::valid(request()),'different origin port rejected');
    request()->headers=['origin'=>'null','x-csrf-token'=>$token];verify(!CsrfToken::valid(request()),'opaque origin rejected');
    request()->headers=['origin'=>'http://example.com','x-csrf-token'=>$token];verify(!CsrfToken::valid(request()),'origin scheme mismatch rejected');
    request()->headers=['origin'=>'https://example.com','x-csrf-token'=>str_repeat('0',64)];$GLOBALS['auth_input']=[];verify(!CsrfToken::valid(request()),'forged CSRF token rejected');
    request()->headers=['x-csrf-token'=>$token];verify(CsrfToken::valid(request()),'non-browser request with valid token accepted');
    cookie('user_token','another-login-cookie');verify(!CsrfToken::valid(request()),'CSRF token invalid after login cookie changes');cookie('user_token',null);
    // A valid password creates only a pending second-factor phase.
    $response=startLogin();verify($response->data['vcode']===2&&!cookie('user_token')&&session('pre_login_user')===1000,'password phase cannot create authenticated cookie');
    verify(\think\facade\Session::$regenerated===1,'session regenerated before challenge');
    $GLOBALS['auth_input']=['post.code'=>otp(Db::$tables['user'][0]['totp_secret'])];$response=(new \app\controller\Auth())->totp();
    verify($response->data['code']===0&&cookie('user_token')!==null,'correct OTP completes login');
    verify(!session('pre_login_user')&&!session('pre_login_fingerprint'),'successful login clears pending phase');
    $options=$GLOBALS['auth_cookies']['user_token']['options'];verify($options['httponly']&&$options['secure']&&$options['samesite']==='lax','login cookie security flags retained');
    cookie('user_token',null);startLogin();session('pre_login_until',time()-1);$GLOBALS['auth_input']=['post.code'=>otp(Db::$tables['user'][0]['totp_secret'])];$response=(new \app\controller\Auth())->totp();
    verify($response->data['code']===-1&&!session('pre_login_user')&&!cookie('user_token'),'expired OTP phase rejected and cleared');
    startLogin();Db::$tables['user'][0]['password']=password_hash('changed-password',PASSWORD_DEFAULT);$GLOBALS['auth_input']=['post.code'=>otp(Db::$tables['user'][0]['totp_secret'])];$response=(new \app\controller\Auth())->totp();
    verify($response->data['code']===-1&&!session('pre_login_user')&&!cookie('user_token'),'password change invalidates pending phase fingerprint');
    Db::$tables['user'][0]=user();startLogin();Db::$tables['user'][0]['totp_secret']='GEZDGNBVGY3TQOJQ';$GLOBALS['auth_input']=['post.code'=>otp(Db::$tables['user'][0]['totp_secret'])];$response=(new \app\controller\Auth())->totp();
    verify($response->data['code']===-1&&!cookie('user_token'),'secret change invalidates pending fingerprint');
    Db::$tables['user'][0]=user();startLogin();Db::$tables['user'][0]['status']=0;$GLOBALS['auth_input']=['post.code'=>otp(Db::$tables['user'][0]['totp_secret'])];$response=(new \app\controller\Auth())->totp();
    verify($response->data['code']===-1&&!cookie('user_token'),'disabled user cannot complete pending login');
    Db::$tables['user'][0]=user();AttemptLimiter::clear('login-totp:1000:192.0.2.10');startLogin();
    for($i=0;$i<5;$i++){$GLOBALS['auth_input']=['post.code'=>'bad-code'];$response=(new \app\controller\Auth())->totp();verify($response->data['code']===-1&&!cookie('user_token'),'invalid OTP rejected');}
    verify(!session('pre_login_user'),'fifth failure clears pending phase');
    startLogin();$GLOBALS['auth_input']=['post.code'=>otp(Db::$tables['user'][0]['totp_secret'])];$response=(new \app\controller\Auth())->totp();
    verify($response->status===429&&!cookie('user_token'),'new password phase cannot reset OTP attempt cap');
    AttemptLimiter::clear('login-totp:1000:192.0.2.10');startLogin();request()->headers=['origin'=>'https://attacker.test'];$GLOBALS['auth_input']=['post.code'=>otp(Db::$tables['user'][0]['totp_secret'])];$response=(new \app\controller\Auth())->totp();
    verify($response->status===403&&!cookie('user_token'),'cross-origin OTP rejected');
    request()->headers=['origin'=>'https://attacker.test'];$GLOBALS['auth_input']=['post.username'=>'tester','post.password'=>'correct-password'];$response=(new \app\controller\Auth())->login();verify($response->status===403,'cross-origin login rejected');
    request()->headers=['origin'=>'https://example.com'];request()->verb='GET';$response=(new \app\controller\Auth())->totp();verify($response->status===403,'OTP endpoint refuses GET');
    // Disable requires POST, current password and current OTP; route CSRF guard is tested below.
    request()->user=Db::$tables['user'][0];request()->islogin=true;$GLOBALS['auth_input']=['param.action'=>'close'];$response=(new \app\controller\Index())->totp();verify($response->status===405&&Db::$tables['user'][0]['totp_open']===1,'GET cannot disable two-factor authentication');
    request()->verb='POST';$GLOBALS['auth_input']=['param.action'=>'close','post.password'=>'wrong','post.code'=>otp(request()->user['totp_secret'])];$response=(new \app\controller\Index())->totp();verify($response->data['code']===-1&&Db::$tables['user'][0]['totp_open']===1,'wrong password cannot disable TOTP');
    $GLOBALS['auth_input']=['param.action'=>'close','post.password'=>'correct-password','post.code'=>'bad-code'];$response=(new \app\controller\Index())->totp();verify($response->data['code']===-1&&Db::$tables['user'][0]['totp_open']===1,'wrong OTP cannot disable TOTP');
    $GLOBALS['auth_input']=['param.action'=>'close','post.password'=>'correct-password','post.code'=>otp(request()->user['totp_secret'])];$response=(new \app\controller\Index())->totp();verify($response->data['code']===0&&Db::$tables['user'][0]['totp_open']===0&&Db::$tables['user'][0]['totp_secret']===null,'verified user can disable TOTP');
    // CheckLogin rejects mutations before controllers, preserves valid writes and normal reads.
    cookie('user_token','middleware-login-cookie');$validToken=CsrfToken::token();$guard=new \app\middleware\CheckLogin();$calls=0;$next=function()use(&$calls){$calls++;return new AuthResponse(['code'=>0]);};
    request()->headers=['origin'=>'https://example.com'];$GLOBALS['auth_input']=[];$response=$guard->handle(request(),$next);verify($response->status===403&&$calls===0,'mutation without CSRF blocked before controller');
    request()->headers=['origin'=>'https://example.com','x-csrf-token'=>$validToken];$response=$guard->handle(request(),$next);verify($response->status===200&&$calls===1,'valid mutation reaches controller');
    request()->headers=['origin'=>'https://attacker.test','x-csrf-token'=>$validToken];$response=$guard->handle(request(),$next);verify($response->status===403&&$calls===1,'cross-origin mutation blocked even with token');
    request()->verb='GET';request()->headers=[];$response=$guard->handle(request(),$next);verify($response->status===200&&$calls===2,'normal read remains usable');
    request()->islogin=false;request()->verb='POST';$response=$guard->handle(request(),$next);verify($response->status===401&&$calls===2,'unauthenticated mutation blocked');
    // Logout must clear the unfinished second-factor phase too.
    request()->islogin=true;request()->verb='GET';request()->headers=[];$response=(new \app\controller\Auth())->logout();verify($response->status===403&&cookie('user_token')!==null,'logout refuses GET');
    request()->verb='POST';request()->headers=['origin'=>'https://example.com'];$response=(new \app\controller\Auth())->logout();verify($response->status===403&&cookie('user_token')!==null,'authenticated logout requires CSRF');
    request()->headers['x-csrf-token']=$validToken;session('pre_login_user',1000);session('pre_login_until',time()+300);session('pre_login_fingerprint','test');
    $response=(new \app\controller\Auth())->logout();verify($response->data['code']===0&&!cookie('user_token')&&!session('pre_login_user')&&!session('pre_login_until')&&!session('pre_login_fingerprint'),'logout clears cookie and pending OTP state');
    echo 'Authentication security: '.$checks." checks passed\n";
}
