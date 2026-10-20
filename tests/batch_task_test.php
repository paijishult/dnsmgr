<?php
/** Durable batch integration regressions with the real reviewed-plan executor. */
namespace think\facade {
    class Db {
        public static array $tables = [];
        public static function name($table) { return new \BatchQuery($table); }
        public static function connect(...$args) { return new \BatchConnection(); }
    }
    class Cache {
        public static array $data = [];
        public static function set($key, $value, $ttl = null) { self::$data[$key] = $value; }
        public static function get($key) { return self::$data[$key] ?? null; }
        public static function delete($key) { unset(self::$data[$key]); }
    }
}
namespace app { class BaseController { protected $request; public function __construct() { $this->request = \request(); } } }
namespace app\lib {
    class DnsHelper {
        public static function getModel(...$args) {
            $GLOBALS['model_calls']++;
            if ($hook = $GLOBALS['model_hook'] ?? null) { $GLOBALS['model_hook'] = null; $hook(); }
            return $GLOBALS['batch_dns'];
        }
    }
    class AuditHelper { public static function scrub($text) { return str_replace('sensitive-token', '[redacted]', $text); } }
}
namespace {
    require_once __DIR__ . '/../app/lib/ApiKeyHelper.php';
    require_once __DIR__ . '/../app/lib/RecordGuard.php';
    require_once __DIR__ . '/../app/lib/AutomaticDnsTarget.php';
    require_once __DIR__ . '/../app/service/TaskLockService.php';
    require_once __DIR__ . '/../app/service/DnsChangeService.php';
    require_once __DIR__ . '/../app/service/BatchTaskService.php';
    require_once __DIR__ . '/../app/controller/Batch.php';
    use think\facade\Db;
    use think\facade\Cache;
    use app\service\BatchTaskService;
    use app\service\DnsChangeService;

    if (!function_exists('mb_substr')) { function mb_substr($s, $start, $length = null) { return substr($s, $start, $length); } }
    if (!function_exists('mb_strlen')) { function mb_strlen($s) { return strlen($s); } }
    function request() { return $GLOBALS['batch_request']; }
    function runtime_path() { return sys_get_temp_dir() . '/dnsmgr-batch-regression-' . getmypid() . '/'; }
    function config($key, $default = null) { return $key === 'database.connections.mysql' ? ['database' => 'test', 'prefix' => 'test_'] : $default; }
    function config_get($key, $default = null) { return $GLOBALS['batch_config'][$key] ?? $default; }
    function config_set($key, $value) { $GLOBALS['batch_config'][$key] = $value; }
    function api_domains() { return request()->user['api_domains'] ?? null; }
    function checkPermission($type, $domain = null) {
        $user = request()->user;
        if ($domain !== null && api_domains() !== null && !in_array($domain, api_domains(), true)) return false;
        if (($user['level'] ?? -1) === 2) return true;
        return ($user['level'] ?? -1) >= $type && ($domain === null || in_array($domain, $user['permission'] ?? [], true));
    }
    function api_has_scope($scope) { return !request()->isApi || request()->apiKey === null || in_array($scope, request()->apiKey['scopes'] ?? [], true); }
    function input($key, $default = null) { return $GLOBALS['batch_input'][preg_replace('~/[a-z]+$~', '', $key)] ?? $default; }
    function json($data) { return $data; }
    function api_write_log(...$args) {}
    class BatchConnection {
        public static array $locks = [];
        private static int $next = 0;
        private int $id;
        public function __construct() { $this->id = ++self::$next; }
        public function name($table) { return new BatchQuery($table); }
        public function query($sql, $bind, $master) {
            if (!$master) throw new RuntimeException('Lock must use primary session');
            $key = $bind['task_lock'];
            if (str_contains($sql, 'GET_LOCK')) {
                if (isset(self::$locks[$key]) && self::$locks[$key] !== $this->id) return [['acquired' => 0]];
                self::$locks[$key] = $this->id; return [['acquired' => 1]];
            }
            if ((self::$locks[$key] ?? null) === $this->id) unset(self::$locks[$key]);
            return [['released' => 1]];
        }
        public function close() { foreach (self::$locks as $key => $owner) if ($owner === $this->id) unset(self::$locks[$key]); }
    }
    class BatchQuery {
        private array $filters = [], $increments = [];
        private int $offset = 0, $length = PHP_INT_MAX;
        private ?string $fields = null;
        public function __construct(private string $table) {}
        public function where($key, $value, $third = null) { $this->filters[] = [$key, func_num_args() === 3 ? $value : '=', func_num_args() === 3 ? $third : $value]; return $this; }
        public function whereIn($key, $values) { $this->filters[] = [$key, 'in', $values]; return $this; }
        public function field($fields) { $this->fields = $fields; return $this; }
        public function order(...$args) { return $this; }
        public function limit($offset, $length = null) { $this->offset = $length === null ? 0 : $offset; $this->length = $length ?? $offset; return $this; }
        public function inc($field, $amount = 1) { $this->increments[$field] = $amount; return $this; }
        private function matches($row) {
            foreach ($this->filters as [$key, $op, $value]) {
                $actual = $row[$key] ?? null;
                if ($op === 'in' ? !in_array($actual, $value) : ($op === '<' ? !($actual < $value) : $actual != $value)) return false;
            }
            return true;
        }
        private function rows() { return array_slice(array_values(array_filter(Db::$tables[$this->table] ?? [], fn($row) => $this->matches($row))), $this->offset, $this->length); }
        public function find() {
            $result = $this->rows()[0] ?? null;
            if ($this->table === 'batch_task' && ($hook = $GLOBALS['task_find_hook'] ?? null)) { $GLOBALS['task_find_hook'] = null; $hook(); }
            return $result;
        }
        public function count() {
            $count = count($this->rows());
            if ($this->table === 'batch_task' && ($hook = $GLOBALS['task_count_hook'] ?? null)) { $GLOBALS['task_count_hook'] = null; $hook(); }
            return $count;
        }
        public function value($key) { return $this->find()[$key] ?? null; }
        public function column($key) { return array_column($this->rows(), $key); }
        public function select() {
            $rows = $this->rows();
            if ($this->fields) $rows = array_map(fn($row) => array_intersect_key($row, array_flip(explode(',', $this->fields))), $rows);
            return new class($rows) { public function __construct(private array $rows) {} public function toArray() { return $this->rows; } };
        }
        public function insertGetId($row) { $row['id'] = count(Db::$tables[$this->table] ?? []) + 1; Db::$tables[$this->table][] = $row; return $row['id']; }
        public function update($data) {
            if ($this->table === 'batch_task' && isset($data['completed']) && !empty($GLOBALS['fail_checkpoint'])) throw new RuntimeException('simulated checkpoint failure');
            $count = 0;
            foreach (Db::$tables[$this->table] as &$row) if ($this->matches($row)) {
                $row = array_merge($row, $data); foreach ($this->increments as $key => $amount) $row[$key] += $amount; $count++;
            }
            return $count;
        }
        public function delete() { Db::$tables[$this->table] = array_values(array_filter(Db::$tables[$this->table] ?? [], fn($row) => !$this->matches($row))); }
    }
    class BatchDns {
        public array $records = [];
        public int $writes = 0, $failAt = 0;
        public bool $mutateOnFailure = false;
        public $afterWrite = null;
        public function getDomainRecords($page = 1, $size = 100) { return ['total' => count($this->records), 'list' => array_slice($this->records, ($page - 1) * $size, $size)]; }
        public function getError() { return 'provider rejected sensitive-token'; }
        public function addDomainRecord($name, $type, $value, $line, $ttl, $mx, $weight, $remark) {
            $this->writes++;
            $fail = $this->writes === $this->failAt;
            if (!$fail || $this->mutateOnFailure) $this->records[] = ['RecordId' => 'r' . $this->writes, 'Domain' => 'example.com', 'Name' => $name, 'Type' => $type, 'Value' => $value, 'Line' => $line, 'TTL' => $ttl, 'MX' => $type === 'MX' ? $mx : null, 'Status' => '1', 'Weight' => $weight, 'Remark' => $remark];
            if ($this->afterWrite) ($this->afterWrite)();
            return $fail ? false : 'r' . $this->writes;
        }
    }
    $checks = 0;
    function batchCheck($condition, $message) { $GLOBALS['checks']++; if (!$condition) throw new RuntimeException($message); }
    function batchReject(callable $call, $message) { try { $call(); } catch (Throwable $e) { batchCheck(true, $message); return $e; } throw new RuntimeException($message); }
    function batchTask() { return Db::$tables['batch_task'][0]; }
    function batchRun($deadline = null) { (new ReflectionMethod(BatchTaskService::class, 'process'))->invoke(new BatchTaskService(), 1, $deadline ?? microtime(true) + 60); }
    function setupBatch(int $count = 2, string $kind = 'web'): void {
        $GLOBALS['batch_config'] = ['sys_key' => 'regression-fixture-key'];
        $user = ['id' => 7, 'status' => 1, 'level' => 1, 'password' => 'fixture-password-hash', 'apikey' => 'legacy-fixture-key', 'is_api' => 1, 'totp_secret' => 'hidden'];
        $key = ['id' => 9, 'uid' => 7, 'keyid' => 'fixture-keyid', 'name' => 'fixture-key', 'status' => 1, 'secret' => 'encrypted-fixture-secret', 'expiretime' => null, 'domains' => 'example.com', 'ipwhitelist' => '203.0.113.10', 'scopes' => 'record:read,record:write'];
        Db::$tables = ['user' => [$user], 'permission' => [['uid' => 7, 'domain' => 'example.com']], 'apikey' => [$key], 'domain' => [['id' => 1, 'aid' => 2, 'thirdid' => 'zone1', 'name' => 'example.com']], 'batch_task' => [], 'dns_snapshot' => []];
        $GLOBALS['batch_request'] = (object)['user' => $user + ['type' => 'user', 'permission' => ['example.com']], 'isApi' => $kind !== 'web', 'apiKey' => $kind === 'key' ? ['id' => 9, 'scopes' => ['record:read', 'record:write']] : null];
        $GLOBALS['batch_dns'] = new BatchDns(); $GLOBALS['model_calls'] = 0;
        $GLOBALS['task_find_hook'] = $GLOBALS['model_hook'] = null; $GLOBALS['fail_checkpoint'] = false;
        $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
        $ops = [];
        for ($i = 0; $i < $count; $i++) $ops[] = ['action' => 'add', 'name' => 'host' . $i, 'type' => 'A', 'value' => '192.0.2.' . ($i + 1)];
        $preview = (new DnsChangeService())->preview(Db::$tables['domain'][0], $GLOBALS['batch_dns'], $ops, DnsChangeService::actor());
        $GLOBALS['preview_token'] = $preview['token'];
        (new BatchTaskService())->enqueue(Db::$tables['domain'][0], $preview['token'], 'fixture batch');
    }

    setupBatch();
    batchCheck(batchTask()['total'] === 2 && batchTask()['status'] === 0, 'Reviewed plan is enqueued');
    batchCheck(!str_contains(batchTask()['identity'], 'fixture-password-hash') && !str_contains(batchTask()['identity'], 'encrypted-fixture-secret'), 'Persisted identity has no plaintext credentials');
    batchReject(fn() => (new BatchTaskService())->enqueue(Db::$tables['domain'][0], $GLOBALS['preview_token'], 'duplicate'), 'Preview token is single use');
    $previous = clone request(); batchRun();
    batchCheck(batchTask()['status'] === 2 && batchTask()['completed'] === 2 && $GLOBALS['batch_dns']->writes === 2, 'All confirmed writes complete');
    batchCheck(json_decode(batchTask()['results'], true) === [['index' => 0, 'success' => true], ['index' => 1, 'success' => true]], 'Successful results retain stable original indices');
    batchCheck(request() == $previous && BatchConnection::$locks === [], 'Original principal and locks restored after success');

    setupBatch(); $GLOBALS['batch_dns']->failAt = 2; batchRun();
    $results = json_decode(batchTask()['results'], true);
    batchCheck(batchTask()['status'] === 3 && batchTask()['completed'] === 1 && count(json_decode(batchTask()['plan'], true)['changes']) === 1, 'Partial failure retains only unconfirmed operations');
    batchCheck($results[1]['index'] === 1 && !$results[1]['success'] && str_contains($results[1]['message'], '[redacted]') && !str_contains(batchTask()['results'], 'sensitive-token'), 'Failure result is indexed and scrubbed');
    $GLOBALS['batch_input'] = ['post.id' => 1, 'param.action' => 'retry'];
    batchCheck((new \app\controller\Batch())->operation()['code'] === 0 && batchTask()['status'] === 0, 'Retry queues existing remaining plan');
    batchRun();
    $results = json_decode(batchTask()['results'], true);
    batchCheck(batchTask()['status'] === 2 && batchTask()['completed'] === 2 && $GLOBALS['batch_dns']->writes === 3, 'Retry never repeats a completed operation');
    batchCheck(count($results) === 2 && $results[1] === ['index' => 1, 'success' => true], 'Successful retry replaces old failure without duplicate results');

    setupBatch(); batchRun(microtime(true) - 1);
    batchCheck(batchTask()['status'] === 0 && batchTask()['completed'] === 1, 'Cooperative time slice saves progress before yielding');
    batchCheck(json_decode(batchTask()['results'], true) === [['index' => 0, 'success' => true]], 'Yield exception never overwrites confirmed success');
    batchRun(); batchCheck(batchTask()['status'] === 2 && $GLOBALS['batch_dns']->writes === 2, 'Yielded plan resumes safely');

    setupBatch(); $GLOBALS['batch_dns']->failAt = 1; $GLOBALS['batch_dns']->mutateOnFailure = true; batchRun();
    batchCheck(batchTask()['status'] === 3 && batchTask()['completed'] === 0, 'Uncertain provider outcome is not checkpointed');
    Db::$tables['batch_task'][0]['status'] = 0;
    batchReject(fn() => batchRun(), 'Uncertain remote mutation invalidates old fingerprint');
    batchCheck($GLOBALS['batch_dns']->writes === 1 && batchTask()['status'] === 3, 'Uncertain outcome cannot be blindly replayed');
    setupBatch(); batchRun(microtime(true) - 1); $GLOBALS['batch_dns']->records[0]['Value'] = '192.0.2.200';
    batchReject(fn() => batchRun(), 'External drift stops resumed plan');
    batchCheck($GLOBALS['batch_dns']->writes === 1, 'Resume drift causes no additional writes');

    setupBatch(); $GLOBALS['batch_input'] = ['post.id' => 1, 'param.action' => 'cancel'];
    $GLOBALS['batch_dns']->afterWrite = function () { (new \app\controller\Batch())->operation(); };
    batchRun();
    batchCheck(batchTask()['status'] === 4 && batchTask()['completed'] === 1 && $GLOBALS['batch_dns']->writes === 1, 'Cancellation stops at a confirmed operation boundary');
    batchCheck(json_decode(batchTask()['results'], true)[0]['success'], 'Cancellation preserves completed result');
    setupBatch(); $GLOBALS['batch_input'] = ['post.id' => 1, 'param.action' => 'cancel'];
    $GLOBALS['task_find_hook'] = function () { Db::$tables['batch_task'][0]['status'] = 1; };
    (new \app\controller\Batch())->operation();
    batchCheck(batchTask()['status'] === 1 && batchTask()['cancel_requested'] === 1, 'Stale queued read cannot mark a newly active request cancelled');
    setupBatch(); $GLOBALS['model_hook'] = function () { Db::$tables['batch_task'][0]['status'] = 4; Db::$tables['batch_task'][0]['cancel_requested'] = 1; };
    batchRun();
    batchCheck(batchTask()['status'] === 4 && $GLOBALS['batch_dns']->writes === 0, 'Cancellation racing worker claim cannot revive or execute cancelled task');

    setupBatch(1); batchRun(); Db::$tables['batch_task'][0]['status'] = 1; $calls = $GLOBALS['model_calls']; batchRun();
    batchCheck(batchTask()['status'] === 2 && $GLOBALS['batch_dns']->writes === 1 && $GLOBALS['model_calls'] === $calls, 'Completed checkpoint recovers without replaying or contacting provider');
    Db::$tables['batch_task'][0]['status'] = 1; Db::$tables['batch_task'][0]['expiretime'] = date('Y-m-d H:i:s', time() - 1); batchRun();
    batchCheck(batchTask()['status'] === 2 && $GLOBALS['batch_dns']->writes === 1, 'An expired execution window cannot invalidate an already-confirmed final checkpoint');
    Db::$tables['batch_task'][0]['status'] = 1; Db::$tables['domain'][0]['aid'] = 99;
    batchReject(fn() => batchRun(), 'Completed recovery checks current domain identity');
    batchCheck(batchTask()['status'] === 3, 'Rebound domain cannot consume old completed plan');
    setupBatch(); Db::$tables['batch_task'][0]['completed'] = 1;
    batchReject(fn() => batchRun(), 'Inconsistent persisted progress fails closed');

    setupBatch(); Db::$tables['batch_task'][0]['expiretime'] = date('Y-m-d H:i:s', time() - 1);
    batchReject(fn() => batchRun(), 'Expired task cannot execute');
    batchCheck($GLOBALS['batch_dns']->writes === 0, 'Expired task caused no cloud writes');
    foreach (['password', 'status', 'permission'] as $change) {
        setupBatch();
        if ($change === 'password') Db::$tables['user'][0]['password'] = 'new-password-hash';
        elseif ($change === 'status') Db::$tables['user'][0]['status'] = 0;
        else Db::$tables['permission'] = [];
        batchReject(fn() => batchRun(), 'Revoked user credential or domain authority stops task');
        batchCheck($GLOBALS['batch_dns']->writes === 0 && request()->user['password'] === 'fixture-password-hash', 'Revocation caused no write and restored caller');
    }
    foreach (['secret', 'scopes', 'ipwhitelist', 'domains', 'status', 'expiry', 'removed'] as $change) {
        setupBatch(2, 'key');
        if ($change === 'expiry') Db::$tables['apikey'][0]['expiretime'] = date('Y-m-d H:i:s', time() - 10);
        elseif ($change === 'removed') Db::$tables['apikey'] = [];
        else Db::$tables['apikey'][0][$change] = match ($change) { 'secret' => 'rotated', 'scopes' => 'record:read', 'ipwhitelist' => '203.0.113.99', 'domains' => 'other.com', 'status' => 0 };
        batchReject(fn() => batchRun(), 'Current API key limits are rechecked');
        batchCheck($GLOBALS['batch_dns']->writes === 0, 'Revoked API key caused no writes');
    }
    setupBatch(2, 'user_api'); Db::$tables['user'][0]['is_api'] = 0;
    batchReject(fn() => batchRun(), 'Disabled legacy API stops its queued jobs');
    setupBatch(); $GLOBALS['batch_dns']->afterWrite = function () { Db::$tables['permission'] = []; }; batchRun();
    batchCheck(batchTask()['completed'] === 1 && batchTask()['status'] === 3 && $GLOBALS['batch_dns']->writes === 1, 'Domain authority is checked between operations');
    batchCheck(json_decode(batchTask()['results'], true)[0]['success'], 'Permission revocation after checkpoint preserves successful item');

    setupBatch(1); $GLOBALS['fail_checkpoint'] = true;
    batchReject(fn() => batchRun(), 'Durable checkpoint failure propagates');
    batchCheck(batchTask()['status'] === 3 && batchTask()['completed'] === 0 && $GLOBALS['batch_dns']->writes === 1, 'Failed checkpoint cannot falsely mark last operation complete');
    $GLOBALS['fail_checkpoint'] = false; Db::$tables['batch_task'][0]['status'] = 0;
    batchReject(fn() => batchRun(), 'Retry after failed checkpoint detects remote drift');
    batchCheck($GLOBALS['batch_dns']->writes === 1 && BatchConnection::$locks === [], 'Failed checkpoint never causes duplicate cloud request and releases locks');

    setupBatch(); $second = batchTask(); $second['id'] = 2; Db::$tables['batch_task'][] = $second;
    $badIdentity = json_decode(Db::$tables['batch_task'][0]['identity'], true); $badIdentity['version'] = str_repeat('0', 64);
    Db::$tables['batch_task'][0]['identity'] = json_encode($badIdentity);
    request()->user = null; request()->isApi = false; request()->apiKey = null;
    ob_start(); (new BatchTaskService())->execute(); ob_end_clean();
    batchCheck(Db::$tables['batch_task'][0]['status'] === 3 && Db::$tables['batch_task'][1]['status'] === 2 && $GLOBALS['batch_dns']->writes === 2, 'Minute worker continues other jobs after one creator fails validation');
    batchCheck(request()->user === null && request()->isApi === false && request()->apiKey === null && !empty($GLOBALS['batch_config']['batchtask_time']), 'Cron entry point restores its empty principal and records heartbeat');

    setupBatch(); $GLOBALS['batch_dns']->failAt = 1; batchRun();
    Db::$tables['batch_task'][0]['results'] = json_encode([['index' => 0, 'success' => false, 'message' => 'sensitive-token failed', 'identity' => 'must-not-escape'], ['index' => 9000, 'success' => true]]);
    $GLOBALS['batch_input'] = ['post.id' => 1]; $details = (new \app\controller\Batch())->details();
    batchCheck($details['code'] === 0 && count($details['results']) === 1 && $details['results'][0]['message'] === '[redacted] failed', 'Details expose bounded scrubbed results');
    batchCheck(!isset($details['task']['identity'], $details['task']['plan'], $details['task']['actor']) && !isset($details['results'][0]['identity']), 'Details whitelist excludes identity and executable plan');
    request()->user['id'] = 8;
    batchCheck((new \app\controller\Batch())->details()['code'] === -1, 'One user cannot read another creator details');
    request()->user['id'] = 7; request()->user['level'] = 2; request()->isApi = true; request()->apiKey = ['id' => 12];
    batchCheck((new \app\controller\Batch())->details()['code'] === -1, 'Even admin API keys are isolated by creator identity');
    foreach (['deleted','rebound'] as $mutation) {
        setupBatch(2);
        $GLOBALS['batch_dns']->afterWrite = static function () use ($mutation) {
            if ($mutation === 'deleted') Db::$tables['domain'] = [];
            else Db::$tables['domain'][0]['aid'] = 99;
        };
        batchRun();
        batchCheck($GLOBALS['batch_dns']->writes === 1 && batchTask()['completed'] === 1 && batchTask()['status'] === 3, 'Domain deletion or rebinding stops the next queued operation');
    }
    batchCheck(BatchConnection::$locks === [], 'Every execution path releases database advisory locks');
    // Interleave two enqueue requests exactly between quota read and insert.
    setupBatch(1);
    $seed = batchTask();
    for ($i = 2; $i <= 19; $i++) { $seed['id'] = $i; Db::$tables['batch_task'][] = $seed; }
    $domain = Db::$tables['domain'][0];
    $operation = [['action'=>'add','name'=>'quota','type'=>'A','value'=>'192.0.2.10']];
    $first = (new DnsChangeService())->preview($domain, $GLOBALS['batch_dns'], $operation, DnsChangeService::actor());
    $second = (new DnsChangeService())->preview($domain, $GLOBALS['batch_dns'], $operation, DnsChangeService::actor());
    $overlapError = null;
    $GLOBALS['task_count_hook'] = function () use ($domain, $second, &$overlapError) {
        try { (new BatchTaskService())->enqueue($domain, $second['token'], 'overlapping request'); }
        catch (Throwable $error) { $overlapError = $error; }
    };
    (new BatchTaskService())->enqueue($domain, $first['token'], 'last available slot');
    batchCheck(count(Db::$tables['batch_task']) === 20 && $overlapError?->getCode() === 102, 'Overlapping requests cannot exceed the per-user pending task quota');
    batchCheck(isset(Cache::$data['dns_preview_'.$second['token']]) && BatchConnection::$locks === [], 'Rejected overlapping enqueue preserves its preview and releases its own connection');
    Db::$tables['batch_task'][0]['status'] = 2;
    (new BatchTaskService())->enqueue($domain, $second['token'], 'retry after a slot frees');
    batchCheck(count(array_filter(Db::$tables['batch_task'], fn($row)=>in_array($row['status'], [0,1,3], true))) === 20, 'Quota lock is reusable after release');
    $preview = (new DnsChangeService())->preview($domain, $GLOBALS['batch_dns'], $operation, DnsChangeService::actor());
    batchReject(fn()=>(new BatchTaskService())->enqueue($domain, $preview['token'], 'over quota'), 'Full quota rejects enqueue');
    batchCheck(isset(Cache::$data['dns_preview_'.$preview['token']]) && BatchConnection::$locks === [], 'Quota rejection neither consumes a preview nor leaks its mutex');
    Db::$tables['batch_task'][1]['status'] = 2;
    batchReject(fn()=>(new BatchTaskService())->enqueue($domain, $preview['token'], str_repeat('x',121)), 'Overlong task name is rejected');
    batchCheck(isset(Cache::$data['dns_preview_'.$preview['token']]), 'Invalid task name cannot consume a confirmed preview');
    echo 'Batch tasks: ' . $checks . " checks passed\n";
}
