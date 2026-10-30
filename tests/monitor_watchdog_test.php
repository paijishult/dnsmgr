<?php
// The dmtask worker can die without leaving an exception behind (Swoole exits the
// process directly). The maintenance cron must then alert once per outage.
namespace app\utils {
    class MsgNotice {
        public static array $queued = [];
        public static function queue(...$args) { self::$queued[] = $args; return true; }
    }
}
namespace {
    $root = dirname(__DIR__);
    $vendor = getenv('DNSMGR_COMPOSER_VENDOR') ?: $root . '/vendor';
    if (!is_file($vendor . '/autoload.php')) { echo "Monitor watchdog requires Composer/SQLite (covered by CI)\n"; return; }
    require $vendor . '/autoload.php';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $app = new think\App($root . '/');
    $app->setRuntimePath(sys_get_temp_dir() . '/dnsmgr-watchdog-tests-' . bin2hex(random_bytes(6)) . '/');
    $app->initialize();
    set_exception_handler(static function (Throwable $e) { echo (string)$e; exit(1); });
    mkdir($app->getRuntimePath(), 0700, true);
    $app->config->set(['default' => 'mysql', 'connections' => ['mysql' => ['type' => 'sqlite', 'database' => $app->getRuntimePath() . 'watchdog.sqlite', 'prefix' => 'w_', 'fields_cache' => false]]], 'database');
    think\facade\Db::execute('CREATE TABLE w_dmtask(id INTEGER PRIMARY KEY, active INTEGER)');
    think\facade\Db::execute('CREATE TABLE w_config(`key` TEXT PRIMARY KEY, value TEXT)');
    $checks = 0;
    $check = function ($ok, $message) use (&$checks) { $checks++; if (!$ok) throw new RuntimeException($message); };
    $run = function () { app\utils\MsgNotice::$queued = []; app\service\OperationsService::monitorWatchdog(); return app\utils\MsgNotice::$queued; };

    think\facade\Db::name('dmtask')->insert(['id' => 1, 'active' => 0]);
    $check($run() === [], 'No alert without enabled monitoring tasks');

    think\facade\Db::name('dmtask')->where('id', 1)->update(['active' => 1]);
    $queued = $run();
    $check(count($queued) === 1 && $queued[0][0] === 'monitor-worker-down:never' && $queued[0][3] === 'notice_' && $queued[0][4] === false, 'Worker that never ran is reported through the monitoring channels');

    config_set('run_time', date('Y-m-d H:i:s', time() - 30));
    $check($run() === [], 'Recent heartbeat is healthy');

    $stale = date('Y-m-d H:i:s', time() - 900);
    config_set('run_time', $stale);
    config_set('run_error', '<b>io_uring</b> unavailable');
    $queued = $run();
    $check(count($queued) === 1 && $queued[0][0] === 'monitor-worker-down:' . $stale, 'Stale heartbeat alerts once per outage (event keyed by last heartbeat)');
    $check(str_contains($queued[0][2], '&lt;b&gt;io_uring&lt;/b&gt;') && !str_contains($queued[0][2], '<b>io_uring'), 'Worker error is escaped in the notification body');
    $check($run()[0][0] === $queued[0][0], 'Repeated cron runs reuse the same deduplication event');

    echo "Monitor watchdog: $checks checks passed\n";
}
