<?php
namespace app\lib {
    // The recorded anonymous handshake is bound to this non-secret test seed.
    function random_bytes($length) { return str_repeat("\x01", $length); }
}
namespace {
    require __DIR__ . '/../app/lib/DeployInterface.php';
    require __DIR__ . '/../app/lib/SensitiveLog.php';
    require __DIR__ . '/../app/lib/MiaomiaowuSecureChannel.php';
    require __DIR__ . '/../app/lib/deploy/miaomiaowu.php';
    if (!function_exists('sodium_crypto_scalarmult') || !function_exists('openssl_encrypt')) {
        echo "Miaomiaowu secure channel: skipped (Sodium/OpenSSL unavailable)\n";
        exit(0);
    }
    $fixture = json_decode(file_get_contents(__DIR__ . '/fixtures/miaomiaowu-secure-channel.json'), true, 512, JSON_THROW_ON_ERROR);
    $checks = 0;
    function scCheck($ok, $message) { $GLOBALS['checks']++; if (!$ok) throw new RuntimeException($message); }
    function scFailure(callable $action, $message) {
        try { $action(); } catch (Exception $e) { $GLOBALS['checks']++; return $e->getMessage(); }
        throw new RuntimeException($message);
    }
    function scClient() {
        $client = new \app\lib\MiaomiaowuSecureChannel();
        $client->acceptHandshake($GLOBALS['fixture']['handshake'], $GLOBALS['fixture']['audience']);
        return $client;
    }
    function scFrame($plaintext, $sequence, $key, $nonce) {
        $seq = pack('J', $sequence);
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce ^ ("\0\0\0\0" . $seq), $tag, '', 16);
        return base64_encode("\x01" . $seq . $cipher . $tag);
    }
    $material = hex2bin($fixture['material_hex']);
    $channel = scClient();
    $reflection = new ReflectionClass($channel);
    $derived = '';
    foreach (['rxKey', 'txKey', 'rxNonce', 'txNonce'] as $field) $derived .= $reflection->getProperty($field)->getValue($channel);
    scCheck(hash_equals($material, $derived), 'Authenticated X25519/HKDF must match all 88 bytes derived by the official WASM SDK');
    scCheck($channel->encrypt('{}') === $fixture['official_empty_request_b64'], 'AES-GCM envelope exactly matches the official SDK');
    scCheck($channel->decrypt($fixture['response_b64']) === $fixture['response_plaintext'], 'Server and client keys and nonces have the correct direction');
    scFailure(fn() => $channel->decrypt($fixture['response_b64']), 'Replayed ciphertext accepted');
    $tampered = base64_decode($fixture['response_b64']);
    $tampered[12] = chr(ord($tampered[12]) ^ 1);
    $fresh = scClient();
    scFailure(fn() => $fresh->decrypt(base64_encode($tampered)), 'Tampered GCM ciphertext accepted');
    scCheck($fresh->decrypt($fixture['response_b64']) === $fixture['response_plaintext'], 'A bad MAC must not consume the receive sequence');
    foreach (['!', base64_encode('short'), base64_encode("\x02" . substr(base64_decode($fixture['response_b64']), 1))] as $invalid) {
        scFailure(fn() => scClient()->decrypt($invalid), 'Malformed or unknown envelope accepted');
    }
    $at256 = scFrame('sequence-256', 256, substr($material, 0, 32), substr($material, 64, 12));
    scCheck(scClient()->decrypt($at256) === 'sequence-256', 'Sequence and nonce use big-endian 64-bit encoding');
    foreach (['proto' => 'v1', 'session_id' => "bad\r\nheader", 'server_pub_b64' => base64_encode(str_repeat('x', 31)), 'runtime_proof' => base64_encode(str_repeat('x', 64))] as $field => $value) {
        $bad = $fixture['handshake']; $bad[$field] = $value;
        scFailure(fn() => (new \app\lib\MiaomiaowuSecureChannel())->acceptHandshake($bad, $fixture['audience']), 'Untrusted or mismatched handshake accepted');
    }
    scFailure(fn() => (new \app\lib\MiaomiaowuSecureChannel())->acceptHandshake($fixture['handshake'], 'https://different.example.com'), 'Handshake for another audience accepted');
    $bad = $fixture['handshake']; $bad['backend_proof'] = [];
    scFailure(fn() => (new \app\lib\MiaomiaowuSecureChannel())->acceptHandshake($bad, $fixture['audience']), 'Invalid optional backend attestation accepted');

    $requests = []; $serverSequence = 0; $mode = 'normal'; $handshakes = 0;
    function http_request($url, $body = null, $referer = null, $cookie = null, $headers = null, $proxy = false, $method = null, $timeout = 10) {
        global $fixture, $material, $requests, $serverSequence, $mode, $handshakes;
        $requests[] = compact('url', 'body', 'headers', 'proxy', 'method', 'timeout');
        scCheck($proxy === true && $method === 'POST', 'Handshake and uploads preserve native proxy/method');
        if (str_contains($url, '/api/securechan/handshake?')) {
            $handshakes++; $serverSequence = 0;
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            scCheck(!isset($headers['Authorization']) && !isset($headers['MM-Authorization']), 'Public handshakes never receive API tokens');
            scCheck(array_keys($payload) === ['client_pub_b64', 'audience', 'proto'] && $payload['audience'] === $fixture['audience'] && $payload['proto'] === 'v2', 'Handshake preserves URL origin and official request shape');
            scCheck(str_starts_with($url, 'https://panel.example.com/mmw/api/securechan/handshake?') && $timeout === 10, 'Handshake preserves reverse proxy subpath and timeout');
            if ($mode === 'bad-handshake') return ['code' => 503, 'body' => 'unavailable'];
            return ['code' => 200, 'body' => json_encode($fixture['handshake'])];
        }
        scCheck($url === 'https://panel.example.com/mmw/api/admin/certificates/upload', 'Only the documented upload endpoint is used');
        if (!isset($headers['X-Secure-Channel'])) {
            return ['code' => 403, 'body' => '{"code":"SECURE_CHANNEL_REQUIRED","error":"secure channel required"}'];
        }
        scCheck($headers['X-Secure-Channel'] === 'v1' && $headers['X-Session-Id'] === $fixture['handshake']['session_id']
            && $headers['Content-Type'] === 'application/octet-stream' && preg_match('/^[0-9a-f-]{36}$/D', $headers['X-MMWX-Client']), 'Encrypted request carries native protocol headers');
        $frame = base64_decode($body, true);
        $plain = openssl_decrypt(substr($frame, 9, -16), 'aes-256-gcm', substr($material, 32, 32), OPENSSL_RAW_DATA,
            substr($material, 76, 12) ^ ("\0\0\0\0" . substr($frame, 1, 8)), substr($frame, -16), '');
        scCheck(is_string($plain), 'The real encrypted provider payload decrypts with official protocol keys');
        if ($mode === 'expire-once' || $mode === 'always-expired') {
            if ($mode === 'expire-once') $mode = 'normal';
            return ['code' => 403, 'headers' => ['x-secure-channel-expired' => ['1']], 'body' => '{}'];
        }
        if ($mode === 'unavailable') return ['code' => 503, 'body' => '{"error":"unavailable"}'];
        if ($mode === 'plaintext-success') return ['code' => 200, 'body' => '{"success":true}'];
        if ($mode === 'tamper') return ['code' => 200, 'headers' => ['X-Secure-Channel' => ['v1']], 'body' => base64_encode(str_repeat('x', 30))];
        $valid = $headers['MM-Authorization'] === 'fixture-token';
        scCheck($headers['Authorization'] === 'Bearer ' . $headers['MM-Authorization'], 'Channel does not cache authentication across the invalid-token control');
        $payload = json_decode($plain, true, 512, JSON_THROW_ON_ERROR);
        if ($plain === '{}') {
            $code = $valid ? 400 : 401;
            $result = $valid ? $fixture['response_plaintext'] : '{"error":"invalid API token"}';
        } else {
            scCheck(array_keys($payload) === ['domain', 'cert_pem', 'key_pem'] && $payload['domain'] === '*.example.com'
                && base64_decode($payload['cert_pem']) === 'certificate-fixture' && base64_decode($payload['key_pem']) === 'private-fixture', 'Native certificate upload contract survives transport encryption');
            $code = 200; $result = '{"success":true}';
        }
        if ($mode === 'unverified') { $code = 400; $result = $fixture['response_plaintext']; }
        return ['code' => $code, 'headers' => ['x-secure-channel' => ['v1']], 'body' => scFrame($result, ++$serverSequence, substr($material, 0, 32), substr($material, 64, 12))];
    }
    function provider() { return new \app\lib\deploy\miaomiaowu(['url' => 'https://panel.example.com/mmw/', 'token' => 'fixture-token', 'proxy' => 1]); }
    $provider = provider();
    scCheck($provider->check() === true, 'Protected real upload route authenticates through the v2 channel');
    scCheck(count($requests) === 4 && $handshakes === 1, 'Account test uses one plaintext rejection, one anonymous handshake and two encrypted empty probes');
    $info = []; $logs = [];
    $provider->setLogger(function ($message) use (&$logs) { $logs[] = $message; });
    $provider->deploy('certificate-fixture', 'private-fixture', ['domainList' => ['*.example.com']], $info);
    scCheck($handshakes === 1 && count($logs) === 1, 'Native upload reuses the channel and emits one success log');
    $mode = 'unverified';
    scFailure(fn() => $provider->check(), 'Body validation without authentication gave a false success');
    $mode = 'normal';
    $invalid = new \app\lib\deploy\miaomiaowu(['url' => 'https://panel.example.com/mmw/', 'token' => 'invalid-token', 'proxy' => 1]);
    scFailure(fn() => $invalid->check(), 'Invalid token was accepted over an encrypted channel');
    foreach (['unavailable', 'plaintext-success', 'tamper'] as $failureMode) {
        $mode = $failureMode; $before = count($requests);
        scFailure(fn() => $provider->check(), 'Failed or unauthenticated response accepted');
        scCheck(count($requests) === $before + 1, 'Ambiguous failures never retry a potentially completed upload');
    }
    $mode = 'expire-once'; $before = $handshakes;
    scCheck($provider->check() === true && $handshakes === $before + 1, 'Only explicit pre-handler expiration renews and retries once');
    $mode = 'always-expired'; $before = count($requests);
    scFailure(fn() => $provider->check(), 'Repeated expiration did not stop');
    scCheck(count($requests) === $before + 3, 'Renewal is bounded to one handshake and one retry');
    $mode = 'bad-handshake';
    scFailure(fn() => provider()->check(), 'Failed handshake accepted');
    echo 'Miaomiaowu secure channel: ' . $checks . " checks passed\n";
}
