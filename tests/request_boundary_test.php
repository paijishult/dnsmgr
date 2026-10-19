<?php
/** Real controllers with isolated transports: no email, webhook or cloud call is sent. */
namespace think\facade {
    class Db {
        public static array $tables = ['cert_deploy'=>[['id'=>1,'oid'=>1]], 'optimizeip'=>[['id'=>1]]];
        public static function name($table) { return new \BoundaryQuery($table); }
    }
}
namespace app {
    class BaseController {
        protected $request;
        public function __construct() { $this->request = \request(); }
        protected function alert($type, $message) { return \json(['code'=>-1,'msg'=>$message]); }
    }
}
namespace app\utils {
    class MsgNotice {
        public static array $sent = [];
        public static function __callStatic($method, $args) { self::$sent[] = $method; return true; }
    }
}
namespace app\lib { class AuditHelper { public static function scrub($text) { return str_replace('fixture-secret-value', '[redacted]', $text); } } }
namespace app\service {
    class CertOrderService {
        public function __construct(...$args) {}
        public function reset() {}
        public function process(...$args) { throw new \RuntimeException('provider rejected fixture-secret-value'); }
    }
    class CertDeployService extends CertOrderService {}
    class OptimizeService { public function execute_one(...$args) { throw new \RuntimeException('provider rejected fixture-secret-value'); } }
}
namespace {
    require __DIR__.'/../app/controller/System.php';
    require __DIR__.'/../app/controller/Cert.php';
    require __DIR__.'/../app/controller/Optimizeip.php';
    class BoundaryQuery {
        public function __construct(private string $table) {}
        public function where(...$args) { return $this; }
        public function find() { return \think\facade\Db::$tables[$this->table][0] ?? null; }
        public function value($field) { return $this->find()[$field] ?? null; }
        public function update($data) { \think\facade\Db::$tables[$this->table][0] = $data + $this->find(); return 1; }
    }
    class BoundaryResponse {
        public int $status = 200;
        public array $headers = [];
        public function __construct(public array $data) {}
        public function code($status) { $this->status = $status; return $this; }
        public function header($headers) { $this->headers = $headers + $this->headers; return $this; }
    }
    class BoundaryRequest {
        public bool $isApi = false;
        public string $verb = 'GET';
        public function isPost() { return $this->verb === 'POST'; }
        public function root(...$args) { return 'https://dnsmgr.fixture'; }
    }
    $GLOBALS['boundary_request'] = new BoundaryRequest();
    $GLOBALS['boundary_input'] = ['post.id'=>1, 'param.action'=>'run'];
    $GLOBALS['boundary_admin'] = true;
    function request() { return $GLOBALS['boundary_request']; }
    function input($key, $default = null) { return $GLOBALS['boundary_input'][preg_replace('~/[a-z]+$~','',$key)] ?? $default; }
    function json($data) { return new BoundaryResponse($data); }
    function config_get($key, $default = null) {
        return ['mail_recv'=>'fixture@example.com','tgbot_token'=>'fixture-only','tgbot_chatid'=>'fixture-only','webhook_url'=>'https://example.com/fixture','custom_webhook_url'=>'https://example.com/fixture'][$key] ?? $default;
    }
    function checkPermission(...$args) { return $GLOBALS['boundary_admin']; }
    function api_domains() { return null; }
    function api_write_log(...$args) {}
    $checks = 0;
    function boundaryCheck($ok, $message) { $GLOBALS['checks']++; if (!$ok) throw new RuntimeException($message); }
    $system = new \app\controller\System();
    foreach (['mailtest','tgbottest','webhooktest','customwebhooktest'] as $method) {
        request()->verb = 'GET'; $before = count(\app\utils\MsgNotice::$sent);
        $response = $system->$method();
        boundaryCheck($response->status === 405 && count(\app\utils\MsgNotice::$sent) === $before, 'GET cannot trigger an outbound notification');
        request()->verb = 'POST'; $response = $system->$method();
        boundaryCheck($response->data['code'] === 0 && count(\app\utils\MsgNotice::$sent) === $before + 1, 'Authorized POST keeps notification testing usable');
        $GLOBALS['boundary_admin'] = false; $response = $system->$method();
        boundaryCheck($response->data['code'] === -1 && count(\app\utils\MsgNotice::$sent) === $before + 1, 'Notification testing still requires administrator permission');
        $GLOBALS['boundary_admin'] = true;
    }
    foreach ([false,true] as $api) {
        request()->isApi = $api;
        foreach (['order_process','deploy_process'] as $method) {
            $response = (new \app\controller\Cert())->$method();
            boundaryCheck($response->data['code'] === -1 && !array_key_exists('trace',$response->data) && !array_key_exists('stack',$response->data), 'Certificate failure returns no call stack in browser or API');
            boundaryCheck(!str_contains(json_encode($response->data), 'fixture-secret-value') && str_contains($response->data['msg'], 'provider rejected'), 'Certificate failure scrubs credentials while retaining the provider reason');
        }
        $response = (new \app\controller\Optimizeip())->opipform();
        boundaryCheck($response->data['code'] === -1 && !array_key_exists('stack',$response->data) && !array_key_exists('trace',$response->data), 'IP optimization failure returns no call stack');
        boundaryCheck(!str_contains(json_encode($response->data), 'fixture-secret-value') && !str_contains(\think\facade\Db::$tables['optimizeip'][0]['errmsg'], 'fixture-secret-value'), 'IP optimization failure and persisted status scrub credentials');
    }
    echo 'Request boundaries: ' . $checks . " checks passed\n";
}
