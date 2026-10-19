<?php
/** Exercise the real ThinkPHP error handler without a database or cloud calls. */
$root = dirname(__DIR__);
$vendor = getenv('DNSMGR_COMPOSER_VENDOR') ?: $root . '/vendor';
if (!is_file($vendor . '/autoload.php')) {
    echo "Exception security requires Composer dependencies (covered by native CI)\n";
    exit(0);
}
require $vendor . '/autoload.php';
$runtime = sys_get_temp_dir() . '/dnsmgr-exception-' . getmypid() . '/';
if (!is_dir($runtime)) mkdir($runtime, 0700, true);
$app = new \think\App($root . '/');
$app->setRuntimePath($runtime);
$app->initialize();
$app->debug(false);
$request = $app->request;
$request->setPathinfo('api/test');
$handler = new \app\ExceptionHandle($app);
$checks = 0;
function exceptionCheck($ok, $message) {
    $GLOBALS['checks']++;
    if (!$ok) throw new RuntimeException($message);
}
// These values are explicit isolated markers, never production credentials.
$private = 'fixture-private-detail /srv/internal/fixture.php credential=fixture-only';
foreach ([
    [new RuntimeException($private), 500],
    [new PDOException($private), 500],
    [new TypeError($private), 400],
    [new \think\exception\HttpException(403, $private), 403],
    [new \think\exception\HttpException(404, $private), 404],
    [new \think\exception\HttpException(503, $private), 503],
] as [$error, $status]) {
    $response = $handler->render($request, $error);
    exceptionCheck($response->getCode() === $status && $response->getData()['code'] === -1, 'API status and error shape preserved');
    exceptionCheck(!str_contains($response->getContent(), 'fixture-private-detail') && !str_contains($response->getContent(), '/srv/internal/') && !str_contains($response->getContent(), 'credential='), 'API exception details must remain private');
}
$response = $handler->render($request, new \think\exception\HttpException(405, $private, null, ['Allow' => 'POST']));
exceptionCheck($response->getCode() === 405 && $response->getHeader('Allow') === 'POST', 'HTTP exception headers preserved');
$expected = json(['code' => -1, 'msg' => '明确的业务提示'], 409);
$response = $handler->render($request, new \think\exception\HttpResponseException($expected));
exceptionCheck($response === $expected, 'Explicit application responses still pass through unchanged');
$request->setPathinfo('index/test');
$request->withHeader(['accept' => 'text/html']);
exceptionCheck($app->config->get('app.show_error_msg') === false, 'Production config hides unexpected exception messages');
$response = $handler->render($request, new RuntimeException($private));
exceptionCheck($response->getCode() === 500 && !str_contains($response->getContent(), 'fixture-private-detail') && !str_contains($response->getContent(), 'credential='), 'Production HTML response hides internal details');
echo 'Exception security: ' . $checks . " checks passed\n";
