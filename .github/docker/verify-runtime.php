<?php
// Used during the build and against CLI/FPM in a disposable CI container.
// This file is removed from the published image.
if (PHP_SAPI !== 'cli') {
    header('Content-Type: application/json');
}

try {
    if (PHP_VERSION_ID !== 80336) {
        throw new RuntimeException('Expected PHP 8.3.36, got ' . PHP_VERSION);
    }
    $extensions = [
        'ctype', 'curl', 'dom', 'fileinfo', 'ftp', 'gd', 'gettext', 'iconv',
        'intl', 'mbstring', 'mysqli', 'mysqlnd', 'openssl', 'pcntl', 'PDO',
        'pdo_mysql', 'pdo_sqlite', 'Phar', 'posix', 'session', 'SimpleXML',
        'sockets', 'sodium', 'ssh2', 'swoole', 'tokenizer', 'xml', 'xmlreader',
        'xmlwriter', 'zip', 'Zend OPcache',
    ];
    foreach ($extensions as $extension) {
        if (!extension_loaded($extension)) {
            throw new RuntimeException('Missing extension: ' . $extension);
        }
    }
    if (phpversion('swoole') !== '6.2.2' || phpversion('ssh2') !== '1.5.0') {
        throw new RuntimeException('Unexpected Swoole/SSH2 extension version');
    }
    if (!class_exists('Co') || !class_exists(Swoole\ConnectionPool::class)
        || !defined('SWOOLE_HOOK_PDO_MYSQL')) {
        throw new RuntimeException('Missing Swoole coroutine/MySQL support');
    }

    $database = new PDO('sqlite::memory:');
    $database->exec('CREATE TABLE runtime_probe (value TEXT NOT NULL)');
    $statement = $database->prepare('INSERT INTO runtime_probe VALUES (?)');
    $statement->execute(['runtime-ok']);
    if ($database->query('SELECT value FROM runtime_probe')->fetchColumn() !== 'runtime-ok') {
        throw new RuntimeException('SQLite round trip failed');
    }

    $writer = new XMLWriter();
    $writer->openMemory();
    $writer->writeElement('probe', 'runtime-ok');
    $reader = new XMLReader();
    $reader->XML($writer->outputMemory());
    if (!$reader->read() || $reader->readString() !== 'runtime-ok') {
        throw new RuntimeException('XML reader/writer round trip failed');
    }
    $reader->close();

    $keyPair = sodium_crypto_sign_seed_keypair(random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES));
    $signature = sodium_crypto_sign_detached('runtime-probe', sodium_crypto_sign_secretkey($keyPair));
    $publicKey = sodium_crypto_sign_publickey($keyPair);
    if (!sodium_crypto_sign_verify_detached($signature, 'runtime-probe', $publicKey)
        || sodium_crypto_sign_verify_detached($signature, 'tampered-probe', $publicKey)) {
        throw new RuntimeException('Sodium signature verification failed');
    }

    $appRoot = getenv('DNSMGR_RUNTIME_APP_ROOT') ?: '/app/www';
    require_once $appRoot . '/app/lib/QqBot.php';
    $bot = new app\lib\QqBot('runtime-fixture', 'runtime-fixture-secret');
    $timestamp = (string) time();
    $validation = $bot->signValidation($timestamp, 'runtime-probe');
    if (!$bot->verifySignature($timestamp, $validation['signature'], 'runtime-probe')
        || $bot->verifySignature($timestamp, $validation['signature'], 'tampered-probe')) {
        throw new RuntimeException('Application signature round trip failed');
    }

    $checks = ['extensions', 'sqlite', 'xml', 'sodium', 'application-signature'];
    if (PHP_SAPI === 'cli') {
        Co::set(['hook_flags' => SWOOLE_HOOK_ALL]);
        $received = null;
        Swoole\Coroutine\run(static function () use (&$received): void {
            $channel = new Swoole\Coroutine\Channel(1);
            Swoole\Coroutine::create(static function () use ($channel): void {
                $channel->push('runtime-ok');
            });
            $received = $channel->pop(2);
        });
        if ($received !== 'runtime-ok') {
            throw new RuntimeException('Swoole coroutine channel failed');
        }
        $checks[] = 'swoole-coroutine';
    }
    echo json_encode(['php' => PHP_VERSION, 'sapi' => PHP_SAPI, 'checks' => $checks], JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['error' => $error->getMessage()], JSON_THROW_ON_ERROR) . "\n";
    exit(1);
}
