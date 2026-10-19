<?php
/** Integration against a disposable server; creates and removes uniquely prefixed tables. */
$dsn = getenv('TEST_MYSQL_DSN');
if (!$dsn) { echo "MySQL advisory integration skipped: set TEST_MYSQL_DSN for a disposable server\n"; return; }
if (!extension_loaded('pdo_mysql')) throw new RuntimeException('pdo_mysql is required for this integration probe');
require_once __DIR__.'/../app/service/TaskLockService.php';
function config($key, $default = null) { return $key === 'database.connections.mysql.database' ? 'integration-test' : ($key === 'database.connections.mysql.prefix' ? 'probe_' : $default); }
class TaskMysqlConnection {
    public $pdo;
    public function __construct($dsn) { $this->pdo = new PDO($dsn, getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASSWORD') ?: '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_PERSISTENT=>false]); }
    public function query($sql, $bind, $master) { $query=$this->pdo->prepare($sql);$query->execute($bind);return $query->fetchAll(PDO::FETCH_ASSOC); }
}
$first = new TaskMysqlConnection($dsn); $second = new TaskMysqlConnection($dsn);
$third = new TaskMysqlConnection($dsn);
$schema = new TaskMysqlConnection($dsn);
$prefix = 'dnsmgr_ci_' . bin2hex(random_bytes(6)) . '_';
$tables = [$prefix . 'dns_snapshot', $prefix . 'batch_task', $prefix . 'cert_dns_lease'];
$checks = 0;
function mysqlCheck($condition, $message) { global $checks; if (!$condition) throw new RuntimeException($message); $checks++; }
$resource = 'integration:' . bin2hex(random_bytes(8));
$a = new app\service\TaskLockService($first, $resource); $b = new app\service\TaskLockService($second, $resource);
try {
    $a->acquire();
    $first->pdo->beginTransaction(); $first->pdo->commit();
    $blocked = false;
    try { $b->acquire(); } catch (RuntimeException $e) { $blocked = $e->getCode() === 102; }
    if (!$blocked) throw new RuntimeException('Second live worker acquired the same resource');
    $a->release(); $b->acquire(); $b->release();
    $a->acquire(); $first->pdo = null; // Connection loss must release the session lock.
    // TCP close is asynchronous at the server. Keep production acquisition
    // nonblocking, but allow MySQL a bounded interval to observe the disconnect.
    $disconnectedAt = microtime(true);
    while (true) {
        try { $b->acquire(); break; }
        catch (RuntimeException $error) {
            if ($error->getCode() !== 102 || microtime(true) - $disconnectedAt > 2) throw $error;
            usleep(10000);
        }
    }
    $b->release();
    // Deploy/order resources must remain independently locked on MySQL5.6,
    // where taking a second resource on one connection releases its first.
    $deployLock = new app\service\TaskLockService($second, $resource . ':deploy');
    $orderLock = new app\service\TaskLockService($third, $resource . ':order');
    $deployLock->acquire(); $orderLock->acquire();
    $probe = new app\service\TaskLockService($schema, $resource . ':deploy');
    $blocked = false;
    try { $probe->acquire(); } catch (RuntimeException $e) { $blocked = $e->getCode() === 102; }
    mysqlCheck($blocked, 'Independent order lock released the deployment lock');
    $orderLock->release(); $deployLock->release();

    $source = file_get_contents(__DIR__ . '/../app/sql/features.sql');
    if ($source === false) throw new RuntimeException('Feature schema is missing');
    $applySchema = static function () use ($schema, $source, $prefix) {
        foreach (explode(';', str_replace('dnsmgr_', $prefix, $source)) as $statement) {
            if (trim($statement) !== '') $schema->pdo->exec($statement);
        }
    };
    $applySchema();
    $expected = [
        $tables[2] => ['oid','claim','did','aid','thirdid','domain','rr','type','value','managed'],
        $tables[0] => ['id','did','uid','domain','aid','thirdid','label','record_count','records','checksum','addtime'],
        $tables[1] => ['id','uid','kid','did','actor','name','identity','plan','results','total','completed','status','cancel_requested','attempts','error','snapshot_id','addtime','updatetime','expiretime'],
    ];
    foreach ($expected as $table => $fields) {
        $columns = $schema->pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_COLUMN);
        mysqlCheck(array_diff($fields, $columns) === [], 'Required feature columns are missing from ' . $table);
    }
    $records = json_encode([['name'=>'www','value'=>str_repeat('x', 70000)]], JSON_THROW_ON_ERROR);
    $insert = $schema->pdo->prepare('INSERT INTO `' . $tables[0] . '` (did,uid,domain,aid,thirdid,label,record_count,records,checksum,addtime) VALUES (1,1,?,1,?,?,1,?,?,NOW())');
    $insert->execute(['example.com','zone-1','数据库回归 🧪',$records,hash('sha256', $records)]);
    $snapshotId = (int)$schema->pdo->lastInsertId();
    $insert = $schema->pdo->prepare('INSERT INTO `' . $tables[1] . '` (uid,kid,did,actor,name,identity,plan,results,total,snapshot_id,addtime,updatetime,expiretime) VALUES (1,0,1,?,?,?,?,?,1,?,NOW(),NOW(),DATE_ADD(NOW(), INTERVAL 1 DAY))');
    $insert->execute([str_repeat('a',64),'后台回归 🧪','{"kind":"web"}',$records,$records,$snapshotId]);
    $taskId = (int)$schema->pdo->lastInsertId();
    $applySchema(); // Upgrade repeats must preserve existing rows and progress.
    $row = $schema->pdo->query('SELECT label,records,checksum FROM `' . $tables[0] . '` WHERE id=' . $snapshotId)->fetch(PDO::FETCH_ASSOC);
    mysqlCheck($row && $row['label'] === '数据库回归 🧪' && $row['records'] === $records && $row['checksum'] === hash('sha256', $records), 'Snapshot data was truncated or changed by an idempotent upgrade');
    $row = $schema->pdo->query('SELECT * FROM `' . $tables[1] . '` WHERE id=' . $taskId)->fetch(PDO::FETCH_ASSOC);
    mysqlCheck($row && $row['name'] === '后台回归 🧪' && $row['plan'] === $records && $row['results'] === $records && (int)$row['snapshot_id'] === $snapshotId, 'Batch JSON or Unicode data was truncated');
    mysqlCheck((int)$row['status'] === 0 && (int)$row['completed'] === 0 && (int)$row['cancel_requested'] === 0 && (int)$row['attempts'] === 0, 'Queued task defaults are invalid');
    $schema->pdo->beginTransaction();
    $schema->pdo->exec('UPDATE `' . $tables[1] . '` SET status=1,completed=1 WHERE id=' . $taskId);
    $schema->pdo->rollBack();
    mysqlCheck((int)$schema->pdo->query('SELECT completed FROM `' . $tables[1] . '` WHERE id=' . $taskId)->fetchColumn() === 0, 'Task progress is not transactional');
    echo 'MySQL integration passed: advisory exclusion, commit retention, disconnect recovery, separate resources; schema checks: ' . $checks . "\n";
} finally {
    foreach ($tables as $table) $schema->pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
    $first->pdo = null; $second->pdo = null; $third->pdo = null; $schema->pdo = null;
}
