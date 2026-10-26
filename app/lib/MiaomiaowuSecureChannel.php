<?php

namespace app\lib;

use Exception;

/** Native transport for the authenticated v2 protocol shipped by Miaomiaowu X. */
class MiaomiaowuSecureChannel
{
    // Public verification keys from the official v0.6.0 client, never credentials.
    private const AUTH_KEY = 'b0df3f2d9530baf77e96a3909747f717c108333868a16561d8fa7c2b87499657'; // gitleaks:allow public X25519 key
    private const RUNTIME_KEY = 'S3HXq8JWiqZo+fG4QvEeNa9Rn0O03D18vDUqa4SnoOg='; // gitleaks:allow public Ed25519 key
    private const ATTESTATION_KEY = '3aGta5gVWH1jVUInTJopAT7xB8soc4A8FgGEgHrVq6k='; // gitleaks:allow public Ed25519 key

    private $privateKey;
    private $publicKey;
    private $sessionId;
    private $txKey;
    private $rxKey;
    private $txNonce;
    private $rxNonce;
    private $sendSequence = 0;
    private $receiveSequence = 0;

    public function __construct()
    {
        if (!function_exists('sodium_crypto_scalarmult') || !function_exists('sodium_crypto_sign_verify_detached')
            || !function_exists('openssl_encrypt') || !in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) {
            throw new Exception('妙妙屋安全通道需要 PHP Sodium 和 OpenSSL 扩展');
        }
        $this->privateKey = random_bytes(32);
        $this->publicKey = sodium_crypto_scalarmult_base($this->privateKey);
    }

    public function handshakeRequest($audience)
    {
        return ['client_pub_b64' => base64_encode($this->publicKey), 'audience' => $audience, 'proto' => 'v2'];
    }

    public function acceptHandshake(array $response, $audience)
    {
        $session = $response['session_id'] ?? null;
        if (($response['proto'] ?? '') !== 'v2' || !is_string($session) || $session === ''
            || strlen($session) > 256 || preg_match('/[^\x21-\x7e]/', $session)) {
            throw new Exception('妙妙屋安全通道握手格式不正确或协议版本不受支持');
        }
        $server = $this->decode($response['server_pub_b64'] ?? '', 32);
        $bindings = [
            'session_id' => $session,
            'client_public_key' => base64_encode($this->publicKey),
            'server_public_key' => $response['server_pub_b64'],
            'origin' => $audience,
        ];
        $message = "mmwx-runtime-proof-v1\n" . implode("\n", [
            $session, $bindings['client_public_key'], $bindings['server_public_key'], $audience,
        ]);
        $this->verify($response['runtime_proof'] ?? '', $message, $this->decode(self::RUNTIME_KEY, 32));
        if (isset($response['backend_proof'])) {
            $this->verifyBackendProof($response['backend_proof'], $bindings, time());
        }

        try {
            $shared = sodium_crypto_scalarmult($this->privateKey, $server);
            $authenticated = sodium_crypto_scalarmult($this->privateKey, hex2bin(self::AUTH_KEY));
        } catch (\SodiumException $e) {
            throw new Exception('妙妙屋安全通道密钥交换失败');
        }
        $material = hash_hkdf('sha256', $shared . $authenticated, 88, "securechan-v2\n" . $session, $this->publicKey . $server);
        $this->rxKey = substr($material, 0, 32);
        $this->txKey = substr($material, 32, 32);
        $this->rxNonce = substr($material, 64, 12);
        $this->txNonce = substr($material, 76, 12);
        $this->sessionId = $session;
        $this->sendSequence = $this->receiveSequence = 0;
        sodium_memzero($this->privateKey);
        sodium_memzero($material);
        sodium_memzero($shared);
        sodium_memzero($authenticated);
    }

    public function headers()
    {
        if ($this->sessionId === null) throw new Exception('妙妙屋安全通道尚未建立');
        // The envelope header remains v1; the authenticated key exchange is v2.
        return ['X-Secure-Channel' => 'v1', 'X-Session-Id' => $this->sessionId, 'Content-Type' => 'application/octet-stream'];
    }

    public function encrypt($plaintext)
    {
        $this->headers();
        if ($this->sendSequence === PHP_INT_MAX) throw new Exception('妙妙屋安全通道序号已耗尽');
        $sequence = pack('J', ++$this->sendSequence);
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $this->txKey, OPENSSL_RAW_DATA,
            $this->txNonce ^ ("\0\0\0\0" . $sequence), $tag, '', 16);
        if ($ciphertext === false) throw new Exception('妙妙屋安全通道加密失败');
        return base64_encode("\x01" . $sequence . $ciphertext . $tag);
    }

    public function decrypt($body)
    {
        $this->headers();
        $frame = $this->decode(trim($body));
        if (strlen($frame) < 25 || $frame[0] !== "\x01") throw new Exception('妙妙屋安全通道响应格式不正确');
        $sequenceBytes = substr($frame, 1, 8);
        $sequence = unpack('J', $sequenceBytes)[1];
        if ($sequence <= $this->receiveSequence) throw new Exception('妙妙屋安全通道响应序号重复或无效');
        $plaintext = openssl_decrypt(substr($frame, 9, -16), 'aes-256-gcm', $this->rxKey, OPENSSL_RAW_DATA,
            $this->rxNonce ^ ("\0\0\0\0" . $sequenceBytes), substr($frame, -16), '');
        if ($plaintext === false) throw new Exception('妙妙屋安全通道响应认证失败');
        $this->receiveSequence = $sequence;
        return $plaintext;
    }

    private function decode($value, $length = null)
    {
        $decoded = is_string($value) && preg_match('/^[A-Za-z0-9+\/_-]*={0,2}$/D', $value)
            ? base64_decode(strtr($value, '-_', '+/'), true) : false;
        if ($decoded === false || ($length !== null && strlen($decoded) !== $length)) {
            throw new Exception('妙妙屋安全通道数据编码不正确');
        }
        return $decoded;
    }

    private function verify($signature, $message, $key)
    {
        if (!sodium_crypto_sign_verify_detached($this->decode($signature, 64), $message, $key)) {
            throw new Exception('妙妙屋官方后端签名验证失败，请确认面板版本及系统时间');
        }
    }

    private function signedPayload($encoded)
    {
        $payload = json_decode($this->decode($encoded), true);
        if (!is_array($payload)) throw new Exception('妙妙屋官方后端身份数据格式不正确');
        return $payload;
    }

    private function verifyBackendProof($backend, array $bindings, $now)
    {
        $invalid = '妙妙屋官方后端身份验证失败，请确认面板版本及系统时间';
        if (!is_array($backend) || !is_string($backend['key_certificate'] ?? null)) throw new Exception($invalid);
        $parts = explode('.', $backend['key_certificate']);
        if (count($parts) !== 2) throw new Exception($invalid);
        $this->verify($parts[1], "mmwx-backend-attestation-key-v1\n" . $parts[0], $this->decode(self::ATTESTATION_KEY, 32));
        $certificate = $this->signedPayload($parts[0]);
        if (($certificate['version'] ?? null) !== 1 || ($certificate['issuer'] ?? '') !== 'miaomiaowux.com'
            || ($certificate['audience'] ?? '') !== 'mmwx-backend-attestation' || empty($certificate['guard_release'])
            || $now < ($certificate['issued_at'] ?? PHP_INT_MAX) - 300
            || (isset($certificate['expires_at']) && $now >= $certificate['expires_at'])) throw new Exception($invalid);
        $key = $this->decode($certificate['public_key'] ?? '', 32);
        if (substr(hash('sha256', $key), 0, 16) !== ($certificate['key_id'] ?? '')) throw new Exception($invalid);

        $manifest = $backend['manifest'] ?? [];
        if (!is_array($manifest) || !is_string($manifest['payload'] ?? null)) throw new Exception($invalid);
        $this->verify($manifest['signature'] ?? '', "mmwx-release-manifest-v1\n" . $manifest['payload'],
            $this->decode($certificate['release_manifest_public_key'] ?? '', 32));
        $release = $this->signedPayload($manifest['payload']);
        if (($release['version'] ?? null) !== 1 || ($release['issuer'] ?? '') !== 'miaomiaowux.com'
            || ($release['role'] ?? '') !== 'master' || !preg_match('/^[0-9a-f]{64}$/D', $release['executable_sha256'] ?? '')
            || $now < ($release['issued_at'] ?? PHP_INT_MAX) - 300
            || (isset($release['expires_at']) && $now >= $release['expires_at'])) throw new Exception($invalid);

        $proof = $backend['proof'] ?? [];
        $expected = array_merge($bindings, [
            'version' => 1, 'issuer' => 'guard.miaomiaowux.com', 'audience' => 'mmwx-frontend', 'role' => 'master',
            'guard_release' => $certificate['guard_release'], 'master_release' => $release['release'] ?? null,
            'executable_sha256' => $release['executable_sha256'], 'key_id' => $certificate['key_id'],
        ]);
        if (!is_array($proof)) throw new Exception($invalid);
        foreach ($expected as $field => $value) {
            if (!isset($proof[$field]) || $proof[$field] !== $value) throw new Exception($invalid);
        }
        if (($proof['issued_at'] ?? 0) <= 0 || ($proof['expires_at'] ?? 0) <= $proof['issued_at']
            || $now < $proof['issued_at'] - 120 || $now >= $proof['expires_at']) throw new Exception($invalid);
        $fields = ['version', 'issuer', 'audience', 'role', 'guard_release', 'master_release', 'executable_sha256',
            'client_public_key', 'server_public_key', 'session_id', 'origin', 'key_id', 'issued_at', 'expires_at'];
        $this->verify($backend['signature'] ?? '', "mmwx-backend-proof-v1\n" . implode("\n", array_map(fn($field) => $proof[$field], $fields)), $key);
    }
}
