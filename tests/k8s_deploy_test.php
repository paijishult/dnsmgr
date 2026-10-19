<?php
/** Exercise the real Kubernetes payload and TLS transport without contacting a cluster. */
namespace Symfony\Component\Yaml {
    class Yaml { public static function parse($value) { return json_decode($value, true, 512, JSON_THROW_ON_ERROR); } }
}
namespace app\lib\deploy {
    function curl_init() { return (object)['options'=>[]]; }
    function curl_setopt($handle,$key,$value) { $handle->options[$key]=$value;return true; }
    function curl_exec($handle) {
        $GLOBALS['k8s_requests'][]=$handle->options;
        $handle->response=array_shift($GLOBALS['k8s_responses']);
        if (!$handle->response) throw new \RuntimeException('Unexpected request');
        return $handle->response[1];
    }
    function curl_getinfo($handle,$key) { return $handle->response[0]; }
    function curl_error($handle) { return ''; }
}
namespace {
    foreach (['CURLOPT_URL','CURLOPT_CUSTOMREQUEST','CURLOPT_RETURNTRANSFER','CURLOPT_TIMEOUT','CURLOPT_HTTPHEADER','CURLOPT_POSTFIELDS','CURLOPT_SSL_VERIFYPEER','CURLOPT_SSL_VERIFYHOST','CURLOPT_CAINFO','CURLOPT_SSLCERT','CURLOPT_SSLKEY','CURLINFO_RESPONSE_CODE'] as $i=>$constant) if(!defined($constant))define($constant,98000+$i);
    require __DIR__.'/../app/lib/DeployInterface.php';
    require __DIR__.'/../app/lib/deploy/k8s.php';
    $checks=0;
    function kcheck($ok,$message){$GLOBALS['checks']++;if(!$ok)throw new RuntimeException($message);}
    function kconfig($server='https://cluster.example.com',$insecure=false){return ['current-context'=>'ctx','contexts'=>[['name'=>'ctx','context'=>['cluster'=>'cluster','user'=>'admin']]],'clusters'=>[['name'=>'cluster','cluster'=>['server'=>$server,'certificate-authority-data'=>base64_encode('fixture-ca'),'insecure-skip-tls-verify'=>$insecure]]],'users'=>[['name'=>'admin','user'=>['token'=>'fixture-token','client-certificate-data'=>base64_encode('fixture-client-cert'),'client-key-data'=>base64_encode('fixture-client-key')]]]];}
    function client($config){return new \app\lib\deploy\k8s(['kubeconfig'=>json_encode($config),'proxy'=>0]);}
    $GLOBALS['k8s_requests']=[];$GLOBALS['k8s_responses']=[[404,'{}'],[201,'{}']];$info=[];
    $client=client(kconfig());$client->deploy('supplied-fullchain','supplied-privatekey',['namespace'=>'default','secret_name'=>'tls-example'],$info);
    kcheck(count($GLOBALS['k8s_requests'])===2,'create only when Secret is absent');
    $request=$GLOBALS['k8s_requests'][1];$payload=json_decode($request[CURLOPT_POSTFIELDS],true);
    kcheck($request[CURLOPT_CUSTOMREQUEST]==='POST'&&$payload['data']['tls.crt']===base64_encode('supplied-fullchain')&&$payload['data']['tls.key']===base64_encode('supplied-privatekey'),'deploy arguments populate Secret, no missing config keys');
    kcheck($request[CURLOPT_SSL_VERIFYPEER]===true&&$request[CURLOPT_SSL_VERIFYHOST]===2,'Kubernetes TLS secure by default');
    kcheck(file_get_contents($request[CURLOPT_CAINFO])==='fixture-ca'&&file_get_contents($request[CURLOPT_SSLCERT])==='fixture-client-cert'&&file_get_contents($request[CURLOPT_SSLKEY])==='fixture-client-key','native kubeconfig CA and client credentials supported');
    $temporary=[$request[CURLOPT_CAINFO],$request[CURLOPT_SSLCERT],$request[CURLOPT_SSLKEY]];
    foreach($temporary as $file)kcheck((fileperms($file)&0777)===0600,'temporary credentials private');
    unset($client);foreach($temporary as $file)kcheck(!file_exists($file),'temporary credentials removed');
    $GLOBALS['k8s_requests']=[];$GLOBALS['k8s_responses']=[[200,'{}'],[200,'{}'],[200,json_encode(['spec'=>['rules'=>[['host'=>'example.com']],'tls'=>[['secretName'=>'other','hosts'=>['other.example.com']]]]])],[200,'{}']];
    $client=client(kconfig());$client->deploy('new-cert','new-key',['namespace'=>'default','secret_name'=>'tls-example','ingresses'=>'web'],$info);
    $patch=json_decode($GLOBALS['k8s_requests'][1][CURLOPT_POSTFIELDS],true);
    kcheck($GLOBALS['k8s_requests'][1][CURLOPT_CUSTOMREQUEST]==='PATCH'&&$patch['data']['tls.key']===base64_encode('new-key'),'existing Secret patched');
    $patch=json_decode($GLOBALS['k8s_requests'][3][CURLOPT_POSTFIELDS],true);
    kcheck(count($patch['spec']['tls'])===2&&$patch['spec']['tls'][0]['secretName']==='other'&&$patch['spec']['tls'][1]['hosts']===['example.com'],'Ingress TLS merge preserves unrelated entries');unset($client);
    foreach(['http://cluster.example.com','https://user@cluster.example.com','https://cluster.example.com?token=secret','https://cluster.example.com#fragment'] as $server){
        try{$client=client(kconfig($server));$client->check();throw new RuntimeException('accepted invalid server');}catch(Exception $e){kcheck(str_contains($e->getMessage(),'HTTPS'),'unsafe server rejected');}unset($client);
    }
    $GLOBALS['k8s_requests']=[];$client=client(kconfig());
    try{$client->deploy('cert','key',['namespace'=>'../kube-system','secret_name'=>'tls'],$info);throw new RuntimeException('accepted traversal');}catch(Exception $e){kcheck(str_contains($e->getMessage(),'格式')&&count($GLOBALS['k8s_requests'])===0,'namespace traversal fails before network');}unset($client);
    $GLOBALS['k8s_responses']=[[200,'{}']];$client=client(kconfig('https://cluster.example.com',true));$client->check();$last=end($GLOBALS['k8s_requests']);kcheck($last[CURLOPT_SSL_VERIFYPEER]===false&&$last[CURLOPT_SSL_VERIFYHOST]===0,'explicit native kubeconfig TLS opt-out retained');unset($client);
    echo 'Kubernetes deployment: '.$checks." checks passed\n";
}
