<?php
namespace app\utils {
    function curl_init($url) { return (object)['url'=>$url,'options'=>[],'errno'=>0]; }
    function curl_setopt_array($handle,$options) { $handle->options=$options;$GLOBALS['health_options']=$options;return true; }
    function curl_exec($handle) {
        if (!empty($GLOBALS['health_timeout'])) {$handle->errno=28;return false;}
        foreach ($GLOBALS['health_chunks'] as $chunk) if (($handle->options[CURLOPT_WRITEFUNCTION])($handle,$chunk)!==strlen($chunk)) {$handle->errno=CURLE_WRITE_ERROR;return false;}
        return true;
    }
    function curl_getinfo($handle,$option) { return $GLOBALS['health_status']; }
    function curl_errno($handle) { return $handle->errno; }
    function curl_error($handle) { return 'simulated timeout'; }
    function curl_close($handle) {}
    function gethostbyname($host) { return $host; }
}
namespace {
    foreach (['CURLOPT_RETURNTRANSFER','CURLOPT_FOLLOWLOCATION','CURLOPT_MAXREDIRS','CURLOPT_PROTOCOLS','CURLOPT_REDIR_PROTOCOLS','CURLOPT_CONNECTTIMEOUT_MS','CURLOPT_TIMEOUT_MS','CURLOPT_NOSIGNAL','CURLOPT_SSL_VERIFYPEER','CURLOPT_SSL_VERIFYHOST','CURLOPT_PROXY','CURLOPT_USERAGENT','CURLOPT_WRITEFUNCTION','CURLOPT_RESOLVE','CURLOPT_CONNECT_TO','CURLOPT_PROXYUSERPWD','CURLINFO_RESPONSE_CODE','CURLE_WRITE_ERROR','CURLPROTO_HTTP','CURLPROTO_HTTPS'] as $index=>$constant) if (!defined($constant)) define($constant,80000+$index);
    require __DIR__.'/../app/utils/CheckUtils.php';
    $checks=0;function healthCheck($ok,$message){$GLOBALS['checks']++;if(!$ok)throw new RuntimeException($message);}
    function config_get($key){return ['proxy_server'=>'127.0.0.1','proxy_port'=>'8080','proxy_type'=>'http','proxy_user'=>'fixture','proxy_pwd'=>'fixture'][$key]??null;}
    $GLOBALS['health_chunks']=['server ','ready'];$GLOBALS['health_status']=200;
    $result=app\utils\CheckUtils::curl('https://primary.example.com/health',0.25,'2001:4860:4860::8888',false,['contains'=>'server ready']);
    healthCheck($result['status'],'Body assertions span transport chunks');$options=$GLOBALS['health_options'];
    healthCheck($options[CURLOPT_RESOLVE]===['primary.example.com:443:[2001:4860:4860::8888]'],'Health probes pin IPv6 while preserving hostname and SNI');
    healthCheck($options[CURLOPT_TIMEOUT_MS]===250&&$options[CURLOPT_CONNECTTIMEOUT_MS]===250,'Fractional probe budgets reach the transport');
    healthCheck($options[CURLOPT_SSL_VERIFYPEER]&&$options[CURLOPT_SSL_VERIFYHOST]===2&&$options[CURLOPT_PROXY]==='','TLS verification is default and environment proxies cannot bypass pinning');
    app\utils\CheckUtils::curl('http://primary.example.com/health',1,'192.0.2.1',true);
    healthCheck($GLOBALS['health_options'][CURLOPT_CONNECT_TO]===['primary.example.com::192.0.2.1:']&&$GLOBALS['health_options'][CURLOPT_PROXY]==='http://127.0.0.1:8080','Proxy tunnels and same-host port redirects retain the primary target');
    $GLOBALS['health_chunks']=[str_repeat('x',1048576),'too large'];
    healthCheck(app\utils\CheckUtils::curl('https://primary.example.com',1,null,false)['status'],'A bounded body does not turn a valid status-only probe into an error');
    healthCheck(!app\utils\CheckUtils::curl('https://primary.example.com',1,null,false,['contains'=>'too large'])['status'],'Assertions cannot match content beyond the body limit');
    $GLOBALS['health_chunks']=['ready'];$GLOBALS['health_status']=503;
    healthCheck(!app\utils\CheckUtils::curl('https://primary.example.com',1)['status'],'Default status policy rejects server errors');
    healthCheck(app\utils\CheckUtils::curl('https://primary.example.com',1,null,false,['statuses'=>[503],'verify_tls'=>false])['status'],'Explicit status policy is honored');
    healthCheck($GLOBALS['health_options'][CURLOPT_SSL_VERIFYPEER]===false&&$GLOBALS['health_options'][CURLOPT_SSL_VERIFYHOST]===0,'Explicit TLS opt-out reaches both checks');
    $GLOBALS['health_timeout']=true;
    healthCheck(!app\utils\CheckUtils::curl('https://primary.example.com',1)['status'],'Real transport failures are never masked by status policy');
    healthCheck(!app\utils\CheckUtils::curl('https://primary.example.com',1,'unresolvable.invalid')['status'],'Unresolved primary never silently probes public fallback DNS');
    echo "Health transport: $checks checks passed\n";
}
