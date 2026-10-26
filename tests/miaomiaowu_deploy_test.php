<?php
/** Exercise native module loading and the upload contract without contacting a panel. */
namespace think\facade {
    class Db
    {
        public static function name($table) { return new \MiaomiaowuAccountQuery(); }
    }
}
namespace {
    require __DIR__ . '/../app/lib/DeployInterface.php';
    require __DIR__ . '/../app/lib/DeployHelper.php';
    require __DIR__ . '/../app/lib/SensitiveLog.php';
    require __DIR__ . '/../app/lib/deploy/miaomiaowu.php';

    class MiaomiaowuAccountQuery
    {
        public function where($key, $value) { return $this; }
        public function find() { return $GLOBALS['mmw_account']; }
    }
    function http_request($url, $body = null, $referer = null, $cookie = null, $headers = null, $proxy = false, $method = null, $timeout = 10)
    {
        $GLOBALS['mmw_requests'][] = compact('url', 'body', 'headers', 'proxy', 'method', 'timeout');
        $response = array_shift($GLOBALS['mmw_responses']);
        if (!$response) throw new RuntimeException('Unexpected panel request');
        return $response;
    }
    $checks = 0;
    function mmwCheck($ok, $message)
    {
        $GLOBALS['checks']++;
        if (!$ok) throw new RuntimeException($message);
    }
    function mmwFailure(callable $action, $message)
    {
        try { $action(); } catch (Exception $e) { return $e->getMessage(); }
        throw new RuntimeException($message);
    }
    $account = ['url' => ' https://panel.example.com/mmw/ ', 'token' => 'fixture-token', 'proxy' => '1'];
    $GLOBALS['mmw_account'] = ['type' => 'miaomiaowu', 'config' => json_encode($account)];
    $GLOBALS['mmw_requests'] = [];
    $GLOBALS['mmw_responses'] = [];
    $definition = \app\lib\DeployHelper::getList()['miaomiaowu'];
    mmwCheck(isset(\app\lib\DeployHelper::$class_config[$definition['class']]) && $definition['icon'] !== '', 'Native account card has a registered category and icon');
    mmwCheck(array_keys($definition['inputs']) === ['url', 'token', 'proxy'] && isset($definition['taskinputs']['domain']), 'Both native forms receive their configuration');
    $client = \app\lib\DeployHelper::getModel(1);
    mmwCheck($client instanceof \app\lib\DeployInterface, 'Saved accounts load through the native factory');
    mmwCheck(\app\lib\DeployHelper::getModel2('miaomiaowu', $account) instanceof \app\lib\DeployInterface, 'Unsaved account connection tests use the same module');

    $GLOBALS['mmw_responses'][] = ['code' => 200, 'body' => '{"certificates":[]}'];
    mmwCheck($client->check() === true, 'Account connection check succeeds');
    $request = end($GLOBALS['mmw_requests']);
    mmwCheck($request['method'] === 'GET' && $request['body'] === null && $request['url'] === 'https://panel.example.com/mmw/api/admin/certificates', 'Account checks only read the certificate list and preserve proxy subpaths');
    mmwCheck($request['headers']['Authorization'] === 'Bearer fixture-token' && $request['proxy'] === true, 'Bearer authentication and the native proxy option are used');

    $fullchain = "-----BEGIN CERTIFICATE-----\nleaf-fixture\n-----END CERTIFICATE-----\n-----BEGIN CERTIFICATE-----\nchain-fixture\n-----END CERTIFICATE-----\n";
    $privatekey = "-----BEGIN PRIVATE KEY-----\nprivate-fixture\n-----END PRIVATE KEY-----\n";
    $info = ['existing' => 'receipt'];
    $logs = [];
    $client->setLogger(function ($text) use (&$logs) { $logs[] = $text; });
    $GLOBALS['mmw_responses'][] = ['code' => 200, 'body' => '{"id":1,"domain":"*.example.com"}'];
    $client->deploy($fullchain, $privatekey, ['domain' => null, 'domainList' => ['*.example.com', 'example.com']], $info);
    $request = end($GLOBALS['mmw_requests']);
    $payload = json_decode($request['body'], true, 512, JSON_THROW_ON_ERROR);
    mmwCheck($request['method'] === 'POST' && $request['url'] === 'https://panel.example.com/mmw/api/admin/certificates/upload' && $request['headers']['Content-Type'] === 'application/json', 'Uploads use the provided JSON endpoint');
    mmwCheck(array_keys($payload) === ['domain', 'cert_pem', 'key_pem'] && $payload['domain'] === '*.example.com', 'An empty task domain defaults to the first ordered certificate domain, including wildcards');
    mmwCheck(base64_decode($payload['cert_pem'], true) === $fullchain && base64_decode($payload['key_pem'], true) === $privatekey, 'Full chain, private key and PEM newlines survive Base64 encoding');
    mmwCheck($info === ['existing' => 'receipt'] && count($logs) === 1 && !str_contains($logs[0], 'fixture-token') && !str_contains($logs[0], 'private-fixture'), 'Native logging preserves receipts and does not expose credentials');

    $renewed = $fullchain . "\nrenewed-fixture\n";
    $GLOBALS['mmw_responses'][] = ['code' => 201, 'body' => '{"success":true}'];
    $client->deploy($renewed, $privatekey, ['domain' => ' example.com ', 'domainList' => ['*.example.com']], $info);
    $payload = json_decode(end($GLOBALS['mmw_requests'])['body'], true);
    mmwCheck($payload['domain'] === 'example.com' && base64_decode($payload['cert_pem']) === $renewed, 'An explicit domain is trimmed and a renewed chain is uploaded');
    $noProxy = \app\lib\DeployHelper::getModel2('miaomiaowu', ['url' => 'http://192.0.2.1:8080/', 'token' => 'fixture-token']);
    $GLOBALS['mmw_responses'][] = ['code' => 204, 'body' => ''];
    $noProxy->deploy($fullchain, $privatekey, ['domain' => 'example.com'], $info);
    mmwCheck(end($GLOBALS['mmw_requests'])['proxy'] === false, 'Omitting the proxy option defaults to a direct connection');

    foreach ([
        ['code' => 401, 'body' => '{"error":"invalid API token"}'],
        ['code' => 403, 'body' => '{"message":"forbidden"}'],
        ['code' => 404, 'body' => '<html>Not found</html>'],
        ['code' => 500, 'body' => '{"success":true}'],
        ['code' => 200, 'body' => '{"error":"upload rejected"}'],
        ['code' => 200, 'body' => '{"success":false,"message":"upload rejected"}'],
        ['code' => 200, 'body' => '{"code":-1,"msg":"upload rejected"}'],
        ['code' => 200, 'body' => '<html>Login</html>'],
        ['code' => 200, 'body' => 'null'],
        ['code' => 200, 'body' => '"success"'],
    ] as $response) {
        $before = count($logs);
        $GLOBALS['mmw_responses'][] = $response;
        $error = mmwFailure(function () use ($client, $fullchain, $privatekey, &$info) { $client->deploy($fullchain, $privatekey, ['domain' => 'example.com'], $info); }, 'A failed or malformed response was accepted');
        mmwCheck(str_contains($error, 'httpCode=' . $response['code']) && count($logs) === $before, 'Failed responses propagate to native retries without success logs');
    }
    $GLOBALS['mmw_responses'][] = ['code' => 401, 'body' => '{"error":"invalid API token"}'];
    mmwCheck(str_contains(mmwFailure(fn() => $client->check(), 'Invalid token accepted'), 'invalid API token'), 'Native account tests report authentication errors');
    $GLOBALS['mmw_responses'][] = ['code' => 400, 'body' => json_encode(['error' => 'echo fixture-token ' . $privatekey . ' ' . base64_encode($privatekey)])];
    $error = mmwFailure(function () use ($client, $fullchain, $privatekey, &$info) { $client->deploy($fullchain, $privatekey, ['domain' => 'example.com'], $info); }, 'Echoed credentials accepted');
    mmwCheck(!str_contains($error, 'fixture-token') && !str_contains($error, 'private-fixture') && !str_contains($error, base64_encode($privatekey)), 'Provider error messages redact tokens and both representations of private keys');

    $before = count($GLOBALS['mmw_requests']);
    foreach ([[], ['domain' => []], ['domain' => 'example.com/path'], ['domain' => "example.com\ninjected"]] as $config) {
        mmwFailure(function () use ($client, $fullchain, $privatekey, $config, &$info) { $client->deploy($fullchain, $privatekey, $config, $info); }, 'Invalid domain accepted');
    }
    foreach ([[], ['url' => 'https://panel.example.com'], ['url' => 'ftp://panel.example.com', 'token' => 'fixture-token'], ['url' => 'https://panel.example.com?token=bad', 'token' => 'fixture-token'], ['url' => 'https://user:pass@panel.example.com', 'token' => 'fixture-token'], ['url' => 'https://panel.example.com', 'token' => "bad\ntoken"]] as $config) {
        mmwFailure(fn() => \app\lib\DeployHelper::getModel2('miaomiaowu', $config)->check(), 'Invalid account accepted');
    }
    mmwFailure(function () use ($client, &$info) { $client->deploy('', '', ['domain' => 'example.com'], $info); }, 'Empty certificate accepted');
    mmwCheck(count($GLOBALS['mmw_requests']) === $before, 'Invalid account, domain and certificate inputs fail before network access');
    mmwCheck($GLOBALS['mmw_responses'] === [], 'Every expected request was exercised');
    echo 'Miaomiaowu native deployment: ' . $checks . " checks passed\n";
}
