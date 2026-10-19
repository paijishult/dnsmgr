<?php
/** Render every native view using ThinkPHP and a private SQLite fixture database.
 * Usage: composer install; DNSMGR_PAGE_FIXTURES=/tmp/dnsmgr-pages php tests/render-pages.php
 * No production database or cloud provider is contacted.
 */
declare(strict_types=1);
$root = dirname(__DIR__);
$vendor = getenv('DNSMGR_COMPOSER_VENDOR') ?: $root . '/vendor';
if (!is_file($vendor . '/autoload.php')) {
    fwrite(STDERR, "Composer autoloader missing. Run composer install first.\n");
    exit(1);
}
if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "Page fixtures require the pdo_sqlite extension.\n");
    exit(1);
}
require $vendor . '/autoload.php';
$out = rtrim(getenv('DNSMGR_PAGE_FIXTURES') ?: sys_get_temp_dir() . '/dnsmgr-pages', '/\\');
$runtime = $out . '/.runtime/';
foreach ([$out, $runtime, $runtime . 'temp'] as $directory) {
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Cannot create fixture directory');
}
$_SERVER = ['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'dnsmgr.fixture', 'REMOTE_ADDR' => '127.0.0.1', 'REQUEST_URI' => '/', 'SCRIPT_NAME' => '/index.php', 'SCRIPT_FILENAME' => $root . '/public/index.php', 'DOCUMENT_ROOT' => $root . '/public', 'SERVER_PORT' => 80, 'REQUEST_TIME_FLOAT' => microtime(true)];
$app = new \think\App($root . '/');
$app->setRuntimePath($runtime);
$app->initialize();
$app->config->set(['sys_key' => 'public-fixture-only-key', 'version' => '1063', 'cron_key' => 'public-fixture-cron-key', 'admin_skin' => 'skin-modern'], 'sys');
$app->config->set(['default' => 'sqlite', 'connections' => ['sqlite' => ['type' => 'sqlite', 'database' => ':memory:', 'prefix' => 'dnsmgr_', 'fields_cache' => false]]], 'database');
\think\facade\Db::execute('CREATE TABLE dnsmgr_config (key TEXT PRIMARY KEY,value TEXT)');
$user = ['id' => 1000, 'username' => 'fixture-admin', 'type' => 'user', 'level' => 2, 'permission' => ['example.com'], 'regtime' => '2026-10-19 10:00:00', 'totp_open' => 1, 'password' => '', 'is_api' => 1, 'apikey' => 'fixture'];
$app->request->user = $user;
$app->request->islogin = true;
$app->request->isApi = false;
$app->request->setController('Index');
$app->request->setAction('index');
$fixture = require __DIR__ . '/fixtures/view-data.php';
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line);
});
$manifest = [];
$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app/view', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) if ($file->getExtension() === 'html') $files[] = $file->getPathname();
sort($files);
foreach ($files as $file) {
    $name = substr($file, strlen($root . '/app/view/'), -5);
    if ($name === 'common/layout') continue; // Exercised through every inherited view.
    $data = $fixture($name, $user);
    $driver = new \think\view\driver\Think($app, ['view_path' => $root . '/app/view/', 'cache_path' => $runtime . 'temp/', 'tpl_cache' => false]);
    ob_start();
    try {
        // Bare names are resolved under the current controller by ThinkPHP.
        // Use the verified absolute source path for standalone dispatch pages.
        $driver->fetch($file, $data);
        $html = ob_get_clean();
        file_put_contents($out . '/' . str_replace('/', '__', $name) . '.html', $html);
        $manifest[] = ['name' => $name, 'error' => null];
    } catch (Throwable $error) {
        ob_end_clean();
        $manifest[] = ['name' => $name, 'error' => $error->getMessage(), 'at' => $error->getFile() . ':' . $error->getLine()];
    }
}
// The exception view is native PHP, rather than a Think template.
$traces = []; $message = '页面错误测试 fixture & <text>'; $datas = []; $tables = []; $echo = true;
ob_start();
try {
    require $root . '/app/view/exception.tpl';
    file_put_contents($out . '/exception.html', ob_get_clean());
    $manifest[] = ['name' => 'exception', 'error' => null];
} catch (Throwable $error) {
    ob_end_clean();
    $manifest[] = ['name' => 'exception', 'error' => $error->getMessage()];
}
restore_error_handler();
file_put_contents($out . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
$errors = array_values(array_filter($manifest, static fn ($page) => $page['error'] !== null));
echo json_encode(['pages' => count($manifest), 'errors' => $errors, 'output' => $out], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
exit($errors ? 1 : 0);
