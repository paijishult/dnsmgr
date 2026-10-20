<?php
require __DIR__.'/../app/lib/SecureFiles.php';require __DIR__.'/../app/lib/CertificateArchive.php';require __DIR__.'/../app/lib/SensitiveLog.php';
use app\lib\SecureFiles;use app\lib\CertificateArchive;use app\lib\SensitiveLog;
$checks=0;function archiveCheck($ok,$message){$GLOBALS['checks']++;if(!$ok)throw new RuntimeException($message);}
foreach ([JSON_UNESCAPED_SLASHES,0] as $flags) {
    $raw=json_encode(['DownloadCertificateUrl'=>'https://download.example.com/opaque?signature=private-link','CertificateUrl'=>'https://download.example.com/another/private-link','keyBase64'=>'encoded-fixture-key','password'=>'fixture-pass'],$flags);
    $safe=SensitiveLog::filter($raw);archiveCheck(!str_contains($safe,'private-link')&&!str_contains($safe,'encoded-fixture-key')&&!str_contains($safe,'fixture-pass'),'Raw and JSON-escaped bearer URLs and key fields are masked');
}
archiveCheck(SensitiveLog::filter("-----BEGIN PRIVATE KEY-----\nfixture\n-----END PRIVATE KEY-----")==='[PRIVATE KEY]','PEM key bodies are masked');
$sensitive=json_encode(['password'=>'fixture words with spaces and "quotes"','ClientSecret'=>'client secret with spaces','access_token'=>'bearer fixture token','pfx_pass'=>'pfx passphrase with spaces']);
$filtered=SensitiveLog::filter($sensitive);
archiveCheck(!str_contains($filtered,'spaces')&&!str_contains($filtered,'quotes')&&!str_contains($filtered,'bearer fixture'),'Quoted multiword secrets and JSON escapes are completely redacted');
archiveCheck(!str_contains(SensitiveLog::filter('Authorization: Bearer fixture-header-credential'),'fixture-header-credential'),'Unquoted authorization headers are redacted');
foreach(['certutil -p FixturePfxOnly42 -importPFX C:/cert.pfx','CERTUTIL.EXE -p "fixture pass with spaces" -importPFX C:/cert.pfx'] as $oldLog)archiveCheck(!str_contains(SensitiveLog::filter($oldLog),' -p '),'Historical positional certutil passwords are hidden');
$secret="fixture\npass \"quoted\"";$filtered=SensitiveLog::filter('native output '.$secret.' '.json_encode($secret),SensitiveLog::secrets(['pfx_pass'=>$secret]));
archiveCheck(!str_contains($filtered,'quoted')&&!str_contains($filtered,'fixture'),'Known task secrets are masked even in multi-line and JSON-escaped output');
$legacy="开始部署\n执行命令：sshpass -p LegacyUnknownFixture42 tool\n执行命令成功：\nUnknownOutputFixture84";
$filtered=SensitiveLog::filter($legacy);archiveCheck(!str_contains($filtered,'LegacyUnknownFixture42')&&!str_contains($filtered,'UnknownOutputFixture84'),'An entire legacy SSH body is hidden even for unknown command and multiline-output credentials');
archiveCheck(SensitiveLog::filter('部署后命令执行成功')==='部署后命令执行成功','New safe step-only SSH logs remain readable');
$directory=SecureFiles::temporaryDirectory();
try {
    SecureFiles::write($directory.'/private.key','fixture',0600);SecureFiles::write($directory.'/private.key','replacement',0600);
    archiveCheck(file_get_contents($directory.'/private.key')==='replacement','Private files are atomically replaced');
    if(!str_contains(PHP_OS,'Emscripten'))archiveCheck((fileperms($directory.'/private.key')&0777)===0600&&(fileperms($directory)&0777)===0700,'Private file and temporary directory modes are enforced');
    if(!class_exists(ZipArchive::class)){echo "Certificate archive ZIP tests require native zip (covered by CI); $checks log/file checks passed\n";return;}
    $config=$directory.'/openssl.cnf';SecureFiles::write($config,"[req]\ndefault_bits=2048\ndistinguished_name=dn\nx509_extensions=extensions\nprompt=no\n[dn]\nCN=example.com\n[extensions]\nsubjectAltName=DNS:example.com,DNS:*.example.com\n");
    $key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);$csr=openssl_csr_new(['commonName'=>'example.com'],$key,['config'=>$config]);$cert=openssl_csr_sign($csr,null,$key,1,['config'=>$config]);if(!openssl_pkey_export($key,$private,null,['config'=>$config]))throw new RuntimeException('Test private key generation failed');openssl_x509_export($cert,$pem);
    $zipBytes=static function(array $files)use($directory){$path=$directory.'/fixture-'.bin2hex(random_bytes(4)).'.zip';$zip=new ZipArchive();if($zip->open($path,ZipArchive::CREATE)!==true)throw new RuntimeException('Cannot create ZIP fixture');foreach($files as $name=>$value)$zip->addFromString($name,$value);$zip->close();return file_get_contents($path);};
    $bytes=$zipBytes(['Nginx/example.key'=>$private,'Nginx/example.pem'=>$pem]);$read=CertificateArchive::read($bytes,['example.com'],'Nginx');archiveCheck($read['private_key']===$private&&$read['fullchain']===$pem,'Per-download archive matches the requested certificate and private key');
    $bytes2=$zipBytes(['other/example.key'=>$private,'other/example.crt'=>$pem]);$second=CertificateArchive::read($bytes2,['www.example.com']);archiveCheck($second['fullchain']===$pem,'Wildcard SAN matching supports a single child label');
    foreach ([[$bytes,['wrong.example.net'],'Nginx'],[$bytes,['nested.www.example.com'],'Nginx'],[$zipBytes(['../escape.key'=>$private]),['example.com'],null],[$zipBytes(['Nginx/a.key'=>$private,'Nginx/a.pem'=>'invalid']),['example.com'],'Nginx']] as $case) {
        $threw=false;try{CertificateArchive::read(...$case);}catch(Throwable $e){$threw=true;}archiveCheck($threw,'Unsafe archive paths, wrong SANs and mismatched contents fail closed');
    }
    archiveCheck(file_get_contents($directory.'/private.key')==='replacement','Reading another archive cannot remove another task’s files');
} finally {SecureFiles::removeDirectory($directory);}
echo "Certificate archives/logs/files: $checks checks passed\n";
