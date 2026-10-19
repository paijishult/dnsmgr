<?php
namespace app\service {
    function curl_init($url) { $handle=(object)['url'=>$url,'options'=>[]];$GLOBALS['tls_handles'][]=$handle;return $handle; }
    function curl_setopt_array($handle,$options) { $handle->options=$options;return true; }
    function curl_exec($handle) {
        if (str_contains($handle->url,'dns.alidns.com')) {
            $body=json_encode(['Status'=>0,'Answer'=>$GLOBALS['dns_answers']]);
            return ($handle->options[CURLOPT_WRITEFUNCTION])($handle,$body)>0;
        }
        return true;
    }
    function curl_getinfo($handle,$key) { return $key===CURLINFO_CERTINFO ? [['Cert'=>$GLOBALS['tls_fixture']]] : 200; }
    function curl_close($handle) {}
    function curl_error($handle) { return 'fake transport'; }
    function microtime($float=false) { return $GLOBALS['tls_clock']; }
}
namespace {
    foreach (['CURLOPT_RETURNTRANSFER','CURLOPT_FOLLOWLOCATION','CURLOPT_MAXREDIRS','CURLOPT_PROTOCOLS','CURLPROTO_HTTPS','CURLOPT_SSL_VERIFYPEER','CURLOPT_SSL_VERIFYHOST','CURLOPT_PROXY','CURLOPT_NOSIGNAL','CURLOPT_CONNECTTIMEOUT_MS','CURLOPT_TIMEOUT_MS','CURLOPT_RESOLVE','CURLOPT_WRITEFUNCTION','CURLINFO_RESPONSE_CODE','CURLOPT_NOBODY','CURLOPT_CERTINFO','CURLINFO_CERTINFO'] as $index=>$constant) if (!defined($constant)) define($constant, 90000+$index);
    require_once __DIR__.'/../app/service/CertVerificationService.php';
    $GLOBALS['tls_fixture']=require __DIR__.'/fixtures/tls-example.php';$GLOBALS['tls_clock']=100.0;
    $checks=0;
    function transportCheck($value,$message){global $checks;if(!$value)throw new RuntimeException($message);$checks++;}
    $service=new app\service\CertVerificationService();$config=['enabled'=>true,'host'=>'example.com','port'=>443,'timeout'=>1];
    $expected=app\service\CertVerificationService::fingerprint($GLOBALS['tls_fixture']);
    $GLOBALS['dns_answers']=[['type'=>1,'data'=>'1.1.1.1']];$GLOBALS['tls_handles']=[];
    $result=$service->verify($config,$expected);
    transportCheck($result['status']==='passed','Pinned public endpoint passes');
    transportCheck(count($GLOBALS['tls_handles'])===3,'Only two bounded DNS queries and one TLS endpoint read');
    foreach($GLOBALS['tls_handles'] as $handle){
        $options=$handle->options;
        transportCheck($options[CURLOPT_SSL_VERIFYPEER]===true && $options[CURLOPT_SSL_VERIFYHOST]===2,'Peer and hostname verification stay enabled');
        transportCheck($options[CURLOPT_FOLLOWLOCATION]===false && $options[CURLOPT_PROTOCOLS]===CURLPROTO_HTTPS && $options[CURLOPT_PROXY]==='','HTTPS only, no redirect or environment proxy');
        transportCheck($options[CURLOPT_TIMEOUT_MS]>0 && $options[CURLOPT_TIMEOUT_MS]<=1000 && $options[CURLOPT_CONNECTTIMEOUT_MS]<=$options[CURLOPT_TIMEOUT_MS],'Whole operation uses configured deadline');
        transportCheck(isset($options[CURLOPT_RESOLVE]) && count($options[CURLOPT_RESOLVE])===1,'Every connection pins a validated public address');
    }
    $target=$GLOBALS['tls_handles'][2];
    transportCheck($target->options[CURLOPT_RESOLVE]===['example.com:443:1.1.1.1'] && $target->options[CURLOPT_NOBODY]===true && $target->options[CURLOPT_CERTINFO]===true,'Target keeps its hostname/SNI while using the pinned public address');
    foreach (['127.0.0.1','169.254.169.254','::ffff:127.0.0.1'] as $address) {
        $GLOBALS['dns_answers']=[['type'=>1,'data'=>'1.1.1.1'],['type'=>str_contains($address,':')?28:1,'data'=>$address]];$GLOBALS['tls_handles']=[];
        $result=$service->verify($config,$expected);
        transportCheck($result['status']==='failed' && count($GLOBALS['tls_handles'])===1,'Mixed public/private DNS must fail before target connection');
    }
    echo "TLS transport checks passed: $checks\n";
}
