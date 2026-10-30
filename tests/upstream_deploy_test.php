<?php
/** Exercise the imported adapters with real certificates and isolated HTTP responses. */
namespace app\lib\deploy {
    function http_request($url, $body = null, $referer = null, $cookie = null, $headers = null, $proxy = false, $method = null)
    {
        $GLOBALS['up_requests'][] = compact('url', 'body', 'cookie', 'headers', 'proxy', 'method');
        if (str_contains($url, '/api/v1/')) {
            $response = array_shift($GLOBALS['up_responses']);
            if (!$response) throw new \RuntimeException('Unexpected SamWaf request');
            return $response;
        }
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
        if (($query['c'] ?? '') === 'sso') {
            return ['code' => 302, 'body' => '', 'redirect_url' => '/admin/?r=fixture-session', 'headers' => ['Set-Cookie' => ['PHPSESSID=panel-session-fixture; Path=/; HttpOnly']]];
        }
        if (($query['a'] ?? '') === 'impLogin') return ['code' => 302, 'body' => ''];
        if ($body === null) return ['code' => 200, 'body' => '<html>panel</html>'];
        parse_str($body, $params);
        $target = $params['domain'] ?? (($query['c'] ?? '') === 'security' ? 'panel' : 'account');
        $GLOBALS['up_targets'][] = $target;
        if ($target === 'bad.example.com' && $GLOBALS['up_fail']) return ['code' => 200, 'body' => "alert('部署失败')"];
        return ['code' => $GLOBALS['up_http_code'], 'body' => '成功'];
    }
}
namespace {
    foreach (['DeployInterface', 'DeployHelper', 'SensitiveLog', 'DeploymentTargets', 'deploy/kangle', 'deploy/kangleadmin', 'deploy/samwaf'] as $file) require __DIR__ . '/../app/lib/' . $file . '.php';
    if (!function_exists('mb_substr')) { function mb_substr($text, $start, $length) { return substr($text, $start, $length); } }
    $checks = 0;
    function upCheck($ok, $message) { $GLOBALS['checks']++; if (!$ok) throw new \RuntimeException($message); }
    function upFails(callable $call): string { try { $call(); } catch (\Throwable $e) { $GLOBALS['checks']++; return $e->getMessage(); } throw new \RuntimeException('Expected failure'); }
    function upResponse($data = null, $code = 0, $http = 200, $msg = '') { return ['code' => $http, 'body' => json_encode(['code' => $code, 'data' => $data, 'msg' => $msg])]; }
    $certificate = require __DIR__ . '/fixtures/tls-example.php';
    $privatekey = 'private-key-fixture';
    $GLOBALS['up_requests'] = $GLOBALS['up_targets'] = [];
    $GLOBALS['up_http_code'] = 200;
    $account = ['url'=>'https://panel.example.com','username'=>'fixture','password'=>'unused','auth'=>'1','skey'=>'fixture-skey','path'=>'/custom-admin','proxy'=>0];
    foreach (['kangle', 'kangleadmin'] as $type) {
        $model = \app\lib\DeployHelper::getModel2($type, $account);
        $logs = []; $info = []; $checkpoints = 0; $GLOBALS['up_targets'] = []; $GLOBALS['up_fail'] = true;
        $model->setLogger(function ($text) use (&$logs) { $logs[] = $text; });
        $config = ['name'=>'fixture','type'=>'1','domains'=>"good.example.com\nbad.example.com\ngood.example.com", '_checkpoint'=>function () use (&$checkpoints) { $checkpoints++; }];
        upCheck(str_contains(upFails(function () use ($model, $certificate, $privatekey, $config, &$info) { $model->deploy($certificate, $privatekey, $config, $info); }), '部分目标部署失败'), 'Partial Kangle failure must fail the task');
        upCheck($GLOBALS['up_targets'] === ['good.example.com','bad.example.com'] && $checkpoints === 2, 'Distinct targets persist individual receipts');
        upCheck(array_column($info['_targets'], 'status') === ['success', 'failed'], 'Receipts distinguish success and failure');
        $GLOBALS['up_fail'] = false; $GLOBALS['up_targets'] = [];
        $model->deploy($certificate, $privatekey, $config, $info);
        upCheck($GLOBALS['up_targets'] === ['bad.example.com'], 'Retries skip targets that succeeded');
        upCheck(!str_contains(implode("\n", $logs), 'panel-session-fixture'), 'Login cookies are never logged');
        $GLOBALS['up_targets'] = []; $info = [];
        $model->deploy($certificate, $privatekey, ['name'=>'fixture','type'=>'0'], $info);
        upCheck($GLOBALS['up_targets'] === ['account'], 'Legacy tasks without target still deploy the website account');
        $GLOBALS['up_targets'] = []; $info = [];
        upFails(function () use ($model, $certificate, $privatekey, &$info) { $model->deploy($certificate, $privatekey, ['name'=>'fixture','type'=>'1','domains'=>" \n"], $info); });
        upCheck(!$GLOBALS['up_targets'], 'Empty single-domain tasks cannot fall through to account deployment');
    }
    $model = \app\lib\DeployHelper::getModel2('kangleadmin', $account); $info = []; $GLOBALS['up_targets'] = [];
    $model->deploy($certificate, $privatekey, ['target'=>'1'], $info);
    $request = end($GLOBALS['up_requests']); parse_str($request['body'], $params);
    upCheck($GLOBALS['up_targets'] === ['panel'] && str_contains($request['url'], '/custom-admin/index.php?c=security&a=ssl'), 'Panel target uses configured admin path and needs no website username');
    upCheck($params === ['crt'=>$certificate,'key'=>$privatekey], 'EasyPanel receives the complete chain and key');
    $GLOBALS['up_http_code'] = 500;
    upFails(function () use ($model, $certificate, $privatekey, &$info) { $model->deploy($certificate, $privatekey, ['target'=>'1'], $info); });
    $GLOBALS['up_http_code'] = 200; $count = count($GLOBALS['up_requests']);
    upFails(function () use ($model, $certificate, $privatekey, &$info) { $model->deploy($certificate, $privatekey, ['target'=>'invalid'], $info); });
    upCheck(count($GLOBALS['up_requests']) === $count, 'Unknown targets fail before network calls');
    $legacy = \app\lib\SensitiveLog::filter("登录成功 cookie:PHPSESSID=panel-session-fixture; other=second-secret\n正常日志");
    upCheck(!str_contains($legacy, 'panel-session-fixture') && !str_contains($legacy, 'second-secret') && str_contains($legacy, '正常日志'), 'Historical cookie logs redact the entire jar');

    $model = \app\lib\DeployHelper::getModel2('samwaf', ['url'=>' https://panel.example.com/safe/ ', 'api_key'=>'fixture-api-key','proxy'=>1]);
    $GLOBALS['up_responses'] = [upResponse(['list'=>[],'total'=>0])];
    upCheck($model->check() === true, 'SamWaf account checks use a valid list response');
    $request = end($GLOBALS['up_requests']);
    upCheck($request['url'] === 'https://panel.example.com/safe/api/v1/sslconfig/list' && $request['headers']['X-API-Key'] === 'fixture-api-key' && $request['proxy'] && json_decode($request['body'],true)['pageSize'] === 1, 'Authenticated read-only connection check preserves safe path and proxy');
    $record = ['id'=>'cert-fixture','cert_content'=>$certificate,'key_content'=>$privatekey,'serial_no'=>'11373283820013370707','cert_path'=>'/custom/cert.pem','key_path'=>'/custom/key.pem'];
    // Matching uses the actual certificate, regardless of numeric serial rounding or missing CN filters.
    $wrong = $record; $wrong['id'] = 'different-cert'; $wrong['cert_content'] = 'invalid certificate';
    $GLOBALS['up_responses'] = [upResponse(['list'=>[$wrong],'total'=>2]), upResponse(['list'=>[$record],'total'=>2])];
    $info = []; $before = count($GLOBALS['up_requests']);
    $model->deploy($certificate, $privatekey, [], $info);
    upCheck($info['config']['id'] === 'cert-fixture' && count($GLOBALS['up_requests']) === $before + 2, 'Paginated fingerprint recovery finds the correct certificate without a duplicate upload');
    upCheck(!isset(json_decode(end($GLOBALS['up_requests'])['body'], true)['domains']), 'Certificate lookup does not depend on a CN');
    $GLOBALS['up_responses'] = [upResponse(['list'=>[],'total'=>0]), upResponse(), upResponse(['list'=>[$record],'total'=>1])];
    $info = []; $before = count($GLOBALS['up_requests']);
    $model->deploy($certificate, $privatekey, [], $info);
    $upload = $GLOBALS['up_requests'][$before + 1];
    upCheck(str_ends_with($upload['url'], '/sslconfig/add') && json_decode($upload['body'], true) === ['cert_content'=>$certificate,'key_content'=>$privatekey] && $info['config']['id'] === 'cert-fixture', 'New uploads save their resolved ID for renewal');
    $GLOBALS['up_responses'] = [upResponse($record), upResponse(), upResponse($record)];
    $before = count($GLOBALS['up_requests']); $info = [];
    $model->deploy($certificate, $privatekey, ['id'=>'cert-fixture'], $info);
    $edit = json_decode($GLOBALS['up_requests'][$before + 1]['body'], true);
    upCheck($edit['cert_path'] === '/custom/cert.pem' && $edit['key_path'] === '/custom/key.pem' && $edit['id'] === 'cert-fixture', 'Renewals preserve existing import paths');
    $GLOBALS['up_responses'] = [upResponse(['id'=>''])];
    upFails(function () use ($model, $certificate, $privatekey, &$info) { $model->deploy($certificate, $privatekey, ['id'=>'missing'], $info); });
    upCheck($GLOBALS['up_responses'] === [], 'Missing IDs fail before edit');
    $GLOBALS['up_responses'] = [upResponse($record), upResponse(), upResponse(array_merge($record, ['cert_content'=>'wrong']))];
    upFails(function () use ($model, $certificate, $privatekey, &$info) { $model->deploy($certificate, $privatekey, ['id'=>'cert-fixture'], $info); });
    foreach ([upResponse(null, 0, 500), ['code'=>200,'body'=>'<html>login</html>'], upResponse(null, -1, 200, 'echo fixture-api-key')] as $response) {
        $GLOBALS['up_responses'] = [$response];
        upCheck(!str_contains(upFails(fn() => $model->check()), 'fixture-api-key'), 'HTTP, application and malformed errors propagate without credentials');
    }
    $GLOBALS['up_responses'] = [upResponse($record), upResponse(null, -1, 200, 'echo '.$privatekey.' fixture-api-key')];
    $error = upFails(function () use ($model, $certificate, $privatekey, &$info) { $model->deploy($certificate, $privatekey, ['id'=>'cert-fixture'], $info); });
    upCheck(!str_contains($error, $privatekey) && !str_contains($error, 'fixture-api-key'), 'Provider errors cannot echo uploaded key material');
    $GLOBALS['up_responses'] = [upResponse(['list'=>[$record],'total'=>2]), upResponse(['list'=>[$record],'total'=>2])];
    upFails(function () use ($model, $certificate, $privatekey, &$info) { $model->deploy($certificate, $privatekey, [], $info); });
    upCheck($GLOBALS['up_responses'] === [], 'Repeated pagination fails without upload');
    $GLOBALS['up_responses'] = [upResponse(['list'=>[array_merge($record, ['key_content'=>''])],'total'=>1])];
    $before = count($GLOBALS['up_requests']);
    $error = upFails(function () use ($model, $certificate, $privatekey, &$info) { $model->deploy($certificate, $privatekey, [], $info); });
    upCheck(str_contains($error, '请指定证书ID') && count($GLOBALS['up_requests']) === $before + 1, 'An existing certificate without its key cannot be reported as successfully deployed');
    $GLOBALS['up_responses'] = [upResponse($record), upResponse(), upResponse(array_merge($record, ['key_content'=>'']))];
    upFails(function () use ($model, $certificate, $privatekey, &$info) { $model->deploy($certificate, $privatekey, ['id'=>'cert-fixture'], $info); });
    echo "Upstream deployment contracts: $checks checks passed\n";
}
