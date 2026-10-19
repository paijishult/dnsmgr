<?php
require_once __DIR__ . '/../app/service/CertVerificationService.php';
use app\service\CertVerificationService as Verification;
$checks = 0;
function verificationCheck($result, $message) { global $checks; if (!$result) throw new RuntimeException($message); $checks++; }
$domains = ['example.com', '*.example.net'];
$config = Verification::normalizeConfig(['enabled'=>true,'host'=>'WWW.EXAMPLE.NET.','port'=>'8443','timeout'=>'3'], $domains);
verificationCheck($config === ['enabled'=>true,'host'=>'www.example.net','port'=>8443,'timeout'=>3], 'Configuration normalization');
verificationCheck(Verification::normalizeConfig(['enabled'=>'false'], $domains) === ['enabled'=>false], 'String false does not enable verification');
foreach (['127.0.0.1','https://example.com/','other.com','a.b.example.net','example.com@localhost','example.com/path','example.com:443'] as $host) {
    try { Verification::normalizeConfig(['enabled'=>true,'host'=>$host], $domains); throw new RuntimeException('Unsafe host accepted: '.$host); }
    catch (InvalidArgumentException $expected) { $checks++; }
}
foreach ([['port'=>22],['port'=>'443a'],['timeout'=>0],['timeout'=>11]] as $bad) {
    try { Verification::normalizeConfig(array_merge(['enabled'=>true,'host'=>'example.com'], $bad), $domains); throw new RuntimeException('Unsafe limits accepted'); }
    catch (InvalidArgumentException $expected) { $checks++; }
}
foreach (['127.0.0.1','10.0.0.1','169.254.169.254','100.64.0.1','192.0.0.1','198.18.0.1','224.0.0.1','::1','::ffff:127.0.0.1','fc00::1','fe80::1','2001:db8::1','2002:7f00:1::','2001:20::1','3fff::1'] as $address) verificationCheck(!Verification::isPublicAddress($address), 'Reserved address accepted: '.$address);
foreach (['1.1.1.1','223.5.5.5','2606:4700:4700::1111','2001:4860:4860::8888'] as $address) verificationCheck(Verification::isPublicAddress($address), 'Public address refused: '.$address);
if (!function_exists('openssl_x509_fingerprint')) throw new RuntimeException('OpenSSL required for verification tests');
$pem = (require __DIR__.'/fixtures/tls-example.php');
$fingerprint = Verification::fingerprint($pem);
verificationCheck(strlen($fingerprint) === 64, 'SHA256 fingerprint');
class OfflineVerification extends Verification {
    public $certificate;
    public $reads = 0;
    protected function resolvePublicAddresses(string $host, float $deadline): array { return ['1.1.1.1']; }
    protected function readCertificate(array $config, string $address, float $deadline): string { $this->reads++; return $this->certificate; }
}
$service = new OfflineVerification(); $service->certificate = $pem;
$verified = $service->verify($config, $fingerprint);
verificationCheck($verified['status'] === 'passed' && $verified['observed_sha256'] === $fingerprint, 'Matching certificate passes');
$failed = $service->verify($config, str_repeat('0',64));
verificationCheck($failed['status'] === 'failed' && $failed['observed_sha256'] === $fingerprint && $failed['error'] !== null, 'Mismatching certificate records actual failure');
echo "TLS verification checks passed: $checks\n";
