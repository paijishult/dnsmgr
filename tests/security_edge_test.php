<?php
/** Regression coverage for provider zone binding and native Cloudflare batch API inputs. */
namespace think\facade {
    class Db { public static array $tables = []; public static function name($name) { return new \EdgeQuery($name); } }
}
namespace app { class BaseController { protected $request; public function __construct() { $this->request = \request(); } protected function alert($type, $msg) { return \json(['code' => -1, 'msg' => $msg]); } } }
namespace app\lib { class DnsHelper { public static function getModel(...$args) { return $GLOBALS['edge_provider']; } } }
namespace app\service {
    class CloudflareEnhanceService {
        public static array $updates = [];
        public function __construct($config) {}
        public function getCustomHostname($zone, $id) { return ['hostname' => 'www.example.com', 'ssl' => ['method' => 'txt', 'type' => 'dv', 'settings' => ['min_tls_version' => '1.2']]]; }
        public function updateCustomHostname($zone, $id, $payload) { self::$updates[] = compact('zone', 'id', 'payload'); return []; }
    }
}
namespace {
    require_once __DIR__ . '/../app/common.php';
    require_once __DIR__ . '/../app/lib/RecordGuard.php';
    require_once __DIR__ . '/../app/lib/ApiKeyHelper.php';
    require_once __DIR__ . '/../app/controller/Domain.php';
    require_once __DIR__ . '/../app/controller/Cloudflare.php';
    use app\lib\RecordGuard;
    use app\lib\ApiKeyHelper;
    use app\service\CloudflareEnhanceService;
    use think\facade\Db;

    if (!function_exists('mb_substr')) { function mb_substr($s, $start, $length = null) { return substr($s, $start, $length); } }
    if (!function_exists('mb_strcut')) { function mb_strcut($s, $start, $length = null) { return substr($s, $start, $length); } }
    function request() { return $GLOBALS['edge_request']; }
    function input($key, $default = null, $filter = null) {
        if (str_starts_with($key, '?')) return array_key_exists(substr($key, 1), $GLOBALS['edge_input']);
        $key = preg_replace('~/[a-z]+$~', '', $key);
        return $GLOBALS['edge_input'][$key] ?? $default;
    }
    function json($value) { return $value; }
    function config($key, $default = null) { return $default; }
    class EdgeQuery {
        private array $conditions = [];
        public function __construct(private string $table) {}
        public function alias(...$args) { return $this; }
        public function join(...$args) { return $this; }
        public function field(...$args) { return $this; }
        public function where($key, $value, $third = null) { $this->conditions[] = [preg_replace('/^[A-Z]\./', '', $key), func_num_args() === 3 ? $value : '=', func_num_args() === 3 ? $third : $value]; return $this; }
        private function matches($row) {
            foreach ($this->conditions as [$key, $op, $value]) if ($op === 'in' ? !in_array($row[$key] ?? null, $value) : ($row[$key] ?? null) != $value) return false;
            return true;
        }
        public function find() { foreach (Db::$tables[$this->table] ?? [] as $row) if ($this->matches($row)) return $row; return null; }
        public function column($key) { return array_column(array_values(array_filter(Db::$tables[$this->table] ?? [], fn($row) => $this->matches($row))), $key); }
        public function insert($row) { Db::$tables[$this->table][] = $row; return 1; }
        public function insertAll($rows) { foreach ($rows as $row) $this->insert($row); return count($rows); }
    }
    class EdgeProvider {
        public int $calls = 0;
        public array $keywords = [];
        public bool $repeat = false;
        public function __construct(public array $zones) {}
        public function getDomainList($keyword = null, $page = 1, $size = 100) {
            $this->calls++; $this->keywords[] = $keyword;
            return ['total' => count($this->zones), 'list' => array_slice($this->zones, ($this->repeat ? 0 : $page - 1) * $size, $size)];
        }
    }
    $GLOBALS['edge_request'] = (object)['user' => ['id' => 1, 'type' => 'user', 'level' => 2], 'isApi' => true, 'apiKey' => ['id' => 1, 'name' => 'test', 'scopes' => ['domain:write', 'record:write', 'cloudflare:write']]];
    $GLOBALS['edge_input'] = [];
    $checks = 0;
    function edgeCheck($condition, $message) { $GLOBALS['checks']++; if (!$condition) throw new RuntimeException($message); }
    function edgeReject(callable $call, $message) { try { $call(); } catch (Throwable $e) { edgeCheck(true, $message); return; } throw new RuntimeException($message); }

    $provider = new EdgeProvider([['Domain' => 'allowed.example.com', 'DomainId' => 'correct-id'], ['Domain' => 'other.example.com', 'DomainId' => 'other-id']]);
    RecordGuard::assertDomainBindings($provider, [['name' => 'ALLOWED.EXAMPLE.COM.', 'id' => 'correct-id']]);
    edgeCheck($provider->calls === 1 && $provider->keywords === ['allowed.example.com'], 'Single import checks exact canonical zone using a filtered provider query');
    edgeReject(fn() => RecordGuard::assertDomainBindings($provider, [['name' => 'allowed.example.com', 'id' => 'other-id']]), 'Allowed name cannot be bound to another provider zone ID');
    $zones = [];
    for ($i = 0; $i < 201; $i++) $zones[] = ['Domain' => 'host' . $i . '.example.com', 'DomainId' => (string)$i];
    $provider = new EdgeProvider($zones);
    RecordGuard::assertDomainBindings($provider, [['name' => 'host0.example.com', 'id' => '0'], ['name' => 'host200.example.com', 'id' => '200']]);
    edgeCheck($provider->calls === 3 && $provider->keywords === [null, null, null], 'Batch import shares one bounded paged zone listing');
    $provider = new EdgeProvider($zones); $provider->repeat = true;
    edgeReject(fn() => RecordGuard::assertDomainBindings($provider, [['name' => 'host200.example.com', 'id' => '200']]), 'Repeated provider pages fail closed');
    $provider = new EdgeProvider([]);
    edgeReject(fn() => RecordGuard::assertDomainBindings($provider, [['name' => 'host0.example.com', 'id' => '0']]), 'Missing provider domain is rejected');
    edgeReject(fn() => RecordGuard::assertDomainBindings($provider, [['name' => 'allowed.example.com', 'id' => ['not-scalar']]]), 'Provider zone ID must be scalar');

    Db::$tables = ['account' => [['id' => 2]], 'domain' => [], 'log' => []];
    request()->user['api_domains'] = ['allowed.example.com'];
    $GLOBALS['edge_provider'] = new EdgeProvider([['Domain' => 'allowed.example.com', 'DomainId' => 'correct-id'], ['Domain' => 'other.example.com', 'DomainId' => 'other-id']]);
    $GLOBALS['edge_input'] = ['param.act' => 'add', 'post.aid' => 2, 'post.method' => 0, 'post.name' => 'allowed.example.com', 'post.thirdid' => 'other-id'];
    $response = (new \app\controller\Domain())->domain_op();
    edgeCheck($response['code'] === -1 && Db::$tables['domain'] === [], 'Domain controller rejects mismatched imported zone before insert');
    $GLOBALS['edge_input'] = ['param.act' => 'batchadd', 'post.aid' => 2, 'post.domains' => [['name' => 'allowed.example.com', 'id' => 'other-id']]];
    $response = (new \app\controller\Domain())->domain_op();
    edgeCheck($response['code'] === -1 && Db::$tables['domain'] === [], 'Batch import rejects domain scope bypass before any insert');
    $GLOBALS['edge_input'] = ['param.act' => 'add', 'post.aid' => 2, 'post.method' => 0, 'post.name' => 'allowed.example.com', 'post.thirdid' => 'correct-id'];
    $response = (new \app\controller\Domain())->domain_op();
    edgeCheck($response['code'] === 0 && Db::$tables['domain'][0]['thirdid'] === 'correct-id', 'Verified domain import remains compatible');

    edgeCheck(ApiKeyHelper::normalizeDomains("EXAMPLE.COM.\n*.sub.example.com\nexample.com") === ['example.com', 'sub.example.com'], 'Domain scope normalization preserves explicit restrictions and removes duplicates');
    foreach (['<img>.com', 'a..com', '-bad.com', 'example.com/path', ['example.com', []], true] as $invalid) edgeReject(fn() => ApiKeyHelper::normalizeDomains($invalid), 'Malformed scope cannot silently become unrestricted');

    unset(request()->user['api_domains']);
    Db::$tables['domain'] = [['id' => 10, 'aid' => 2, 'name' => 'example.com', 'type' => 'cloudflare', 'thirdid' => 'zone-id', 'account_config' => '{}']];
    $first = '0123456789abcdef0123456789abcdef'; $second = 'abcdef0123456789abcdef0123456789';
    $baseInput = ['param.id' => 10, 'post.custom_origin_server' => 'origin.example.com', 'post.ssl_method' => 'http', 'post.min_tls_version' => '1.3'];
    $GLOBALS['edge_input'] = $baseInput + ['post.hostname_ids' => [$first, $second, $first]];
    CloudflareEnhanceService::$updates = [];
    $response = (new \app\controller\Cloudflare())->hostnames_batch_update();
    edgeCheck($response['code'] === 0 && count(CloudflareEnhanceService::$updates) === 2, 'Cloudflare accepts documented array of IDs and deduplicates writes');
    $update = CloudflareEnhanceService::$updates[0];
    edgeCheck($update['zone'] === 'zone-id' && $update['payload'] === ['custom_origin_server' => 'origin.example.com', 'ssl' => ['method' => 'http', 'type' => 'dv', 'settings' => ['min_tls_version' => '1.3']]], 'Batch update preserves exact native payload and zone');
    $GLOBALS['edge_input'] = $baseInput + ['post.hostname_ids' => $first . ',' . $second];
    CloudflareEnhanceService::$updates = [];
    edgeCheck((new \app\controller\Cloudflare())->hostnames_batch_update()['code'] === 0 && count(CloudflareEnhanceService::$updates) === 2, 'Legacy comma-separated IDs still work');
    $GLOBALS['edge_input'] = ['param.id' => 10, 'post.hostname_ids' => [$first]];
    CloudflareEnhanceService::$updates = [];
    (new \app\controller\Cloudflare())->hostnames_batch_update();
    edgeCheck(CloudflareEnhanceService::$updates[0]['payload'] === ['custom_origin_server' => null], 'Blank source clears source without modifying SSL settings');
    foreach ([[[$first]], [], [true], ['../../zone/escape'], array_fill(0, 501, $first)] as $invalid) {
        $GLOBALS['edge_input'] = $baseInput + ['post.hostname_ids' => $invalid]; CloudflareEnhanceService::$updates = [];
        edgeCheck((new \app\controller\Cloudflare())->hostnames_batch_update()['code'] === -1 && CloudflareEnhanceService::$updates === [], 'Invalid hostname IDs are rejected before cloud writes');
    }
    $GLOBALS['edge_input'] = $baseInput + ['post.hostname_ids' => [$first]]; request()->user['level'] = 1; CloudflareEnhanceService::$updates = [];
    edgeCheck((new \app\controller\Cloudflare())->hostnames_batch_update()['code'] === -1 && CloudflareEnhanceService::$updates === [], 'Cloudflare batch writes still require administrator authority');
    echo 'Security edges: ' . $checks . " checks passed\n";
}
