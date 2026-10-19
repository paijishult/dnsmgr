<?php
namespace think\facade {
    class Db { public static array $tables=[];public static function name($name){return new \ApiQuery($name);} }
}
namespace app\lib {
    class AuditHelper { public static function scrub($value,...$args){return $value;}public static function apiScrubValues(){return [];} }
}
namespace {
    require __DIR__.'/../app/lib/ApiKeyHelper.php';
    require __DIR__.'/../app/lib/AttemptLimiter.php';
    require __DIR__.'/../app/middleware/AuthApi.php';
    use app\lib\ApiKeyHelper;
    use app\lib\AttemptLimiter;
    use think\facade\Db;
    class ApiQuery {
        private array $where=[];private array $increments=[];
        public function __construct(private string $table){}
        public function where($key,$value){$this->where[$key]=$value;return $this;}
        private function matches($row){foreach($this->where as $key=>$value)if(($row[$key]??null)!=$value)return false;return true;}
        public function find(){foreach(Db::$tables[$this->table]??[] as $row)if($this->matches($row))return $row;return null;}
        public function column($field){$rows=[];foreach(Db::$tables[$this->table]??[] as $row)if($this->matches($row))$rows[]=$row[$field];return $rows;}
        public function inc($key){$this->increments[]=$key;return $this;}
        public function update($data){foreach(Db::$tables[$this->table] as &$row)if($this->matches($row)){$row=array_merge($row,$data);foreach($this->increments as $key)$row[$key]=($row[$key]??0)+1;}return 1;}
    }
    class ApiResponse {
        public int $status=200;public array $headers=[];
        public function __construct(public array $data){}public function code($code){$this->status=$code;return $this;}public function header($headers){$this->headers=array_merge($this->headers,$headers);return $this;}
        public function getContent(){return json_encode($this->data);}public function content($value){$this->data=json_decode($value,true);return $this;}
    }
    #[AllowDynamicProperties]
    class ApiRequest {
        public array $parameters=[];public array $serverData=[];public string $scope='record:write';
        public function post($key=null,$default=null){return $key===null?$this->parameters:($this->parameters[$key]??$default);}public function withPost($data){$this->parameters=$data;}
        public function server(){return $this->serverData;}public function withServer($data){$this->serverData=$data;}public function route($key){return null;}
        public function rule(){return new class($this->scope){public function __construct(private string $scope){}public function getOption($key){return $this->scope;}};}
    }
    $directory=sys_get_temp_dir().'/dnsmgr-api-tests-'.bin2hex(random_bytes(6)).'/';
    function app(){return new class {public function getRuntimePath(){return $GLOBALS['directory'];}};}
    function config_get($key,$default=null){return ($GLOBALS['api_config']??['sys_key'=>'isolated-fixture-key','api_ip_source'=>'remote'])[$key]??$default;}
    function input($key,$default=null){$key=preg_replace('~/[a-z]+$~','',$key);return request()->post(substr($key,5),$default);}
    function request(){return $GLOBALS['api_request'];}function json($data){return new ApiResponse($data);}
    $_SERVER['REMOTE_ADDR']='192.0.2.20';$GLOBALS['api_request']=new ApiRequest();$checks=0;
    function acheck($ok,$message){$GLOBALS['checks']++;if(!$ok)throw new RuntimeException($message);}
    $secret='isolated-fixture-secret';$keyid='0123456789abcdef';
    Db::$tables=['apikey'=>[['id'=>1,'uid'=>1000,'keyid'=>$keyid,'secret'=>ApiKeyHelper::encrypt($secret),'status'=>1,'expiretime'=>null,'ipwhitelist'=>'','domains'=>'example.com','scopes'=>'record:write','ratelimit'=>3,'name'=>'fixture','calls'=>0]],'user'=>[['id'=>1000,'status'=>1,'level'=>2,'password'=>'hidden','totp_secret'=>'hidden','apikey'=>'hidden']]];
    function credentials($valid=true){$timestamp=time();request()->parameters=['keyid'=>'0123456789abcdef','timestamp'=>$timestamp,'sign'=>$valid?md5('0123456789abcdef'.$timestamp.'isolated-fixture-secret'):str_repeat('0',32)];}
    $middleware=new \app\middleware\AuthApi();$calls=0;$next=function($request)use(&$calls){$calls++;return json(['code'=>0]);};
    for($i=0;$i<3;$i++){credentials();$response=$middleware->handle(request(),$next);acheck($response->status===200&&$response->headers['X-RateLimit-Remaining']===(string)(2-$i),'accepted API calls atomically consume exactly one quota');}
    credentials();$response=$middleware->handle(request(),$next);acheck($response->status===429&&$calls===3&&$response->headers['X-RateLimit-Remaining']==='0'&&(int)$response->headers['Retry-After']>0,'exhausted API quota stops before controller with retry header');
    acheck(request()->user['api_domains']===['example.com']&&!isset(request()->user['password'],request()->user['totp_secret'],request()->user['apikey']),'authenticated key retains domain scope without secret principal fields');
    AttemptLimiter::clear('api-rate:1');Db::$tables['apikey'][0]['scopes']='record:read';credentials();$response=$middleware->handle(request(),$next);acheck($response->status===403&&$calls===3,'missing route scope cannot reach controller');Db::$tables['apikey'][0]['scopes']='record:write';
    for($i=0;$i<20;$i++){credentials(false);$response=$middleware->handle(request(),$next);acheck($response->status===403,'bad API signature rejected');}
    credentials();$response=$middleware->handle(request(),$next);acheck($response->status===429&&$calls===3,'failed-signature cap applies before a subsequent signed request');
    AttemptLimiter::clear('api-fail:192.0.2.20');credentials();$response=$middleware->handle(request(),$next);acheck($response->status===200&&$calls===4,'expired or cleared failure window allows valid authentication');
    // Native Linux CI exercises actual competing workers against the same inode.
    if(function_exists('pcntl_fork')){
        $pids=[];
        for($worker=0;$worker<8;$worker++){
            $pid=pcntl_fork();if($pid===-1)throw new RuntimeException('fork failed');
            if($pid===0){$allowed=0;for($i=0;$i<25;$i++)if(AttemptLimiter::hit('concurrent',17,60)['allowed'])$allowed++;file_put_contents($directory.'worker-'.$worker,(string)$allowed);exit(0);}$pids[]=$pid;
        }
        foreach($pids as $pid){pcntl_waitpid($pid,$status);acheck(pcntl_wifexited($status)&&pcntl_wexitstatus($status)===0,'concurrent limiter worker finished');}
        $allowed=0;for($worker=0;$worker<8;$worker++)$allowed+=(int)file_get_contents($directory.'worker-'.$worker);
        acheck($allowed===17&&!AttemptLimiter::check('concurrent',17)['allowed'],'200 competing requests grant exactly17 permits');
    }else echo "Native limiter concurrency requires pcntl (covered by Linux CI)\n";
    $GLOBALS['api_config']=['sys_key'=>'isolated-fixture-key','api_ip_source'=>'auto'];
    $_SERVER['HTTP_X_FORWARDED_FOR']='198.51.100.10, 203.0.113.99';
    $_SERVER['HTTP_X_REAL_IP']='203.0.113.98';$_SERVER['HTTP_CF_CONNECTING_IP']='203.0.113.97';
    foreach(['10.1.2.3','127.0.0.1','192.168.65.1','2001:db8::1','192.0.2.20'] as $remote){
        $_SERVER['REMOTE_ADDR']=$remote;
        acheck(ApiKeyHelper::clientIp()===$remote,'Safe automatic mode never treats arbitrary private or loopback clients as a trusted proxy');
    }
    foreach(['xff'=>'203.0.113.99','x-real-ip'=>'203.0.113.98','cf'=>'203.0.113.97'] as $source=>$expected){
        $GLOBALS['api_config']['api_ip_source']=$source;
        acheck(ApiKeyHelper::clientIp()===$expected,'Explicit administrator-selected trusted proxy mode remains available');
    }
    echo 'API security: '.$checks." checks passed\n";
}
