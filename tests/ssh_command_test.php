<?php
/** Real local shells stand in for SSH transport; no remote server is contacted. */
namespace app\lib\deploy {
    function ssh2_exec($connection,$command){
        $GLOBALS['command_sent'][]=$command;
        if(!empty($GLOBALS['command_stream_failure']))return false;
        if(function_exists('post_message_to_js')||!empty($GLOBALS['command_fake'])){
            $decoded=$command;
            if(preg_match('/-EncodedCommand ([A-Za-z0-9+\/=]+)/',$command,$encoded))$decoded=mb_convert_encoding(base64_decode($encoded[1]),'UTF-8','UTF-16LE');
            preg_match('/__DNSMGR_EXIT_[a-f0-9]+__/',$decoded,$marker);
            $text=$GLOBALS['command_no_marker']?'':"\n".$marker[0].$GLOBALS['command_exit'];
            if(!empty($GLOBALS['command_oversize']))$text=str_repeat('x',1048577).$text;
        }else{
            if(PHP_OS_FAMILY==='Windows'){
                if(!preg_match('/-EncodedCommand ([A-Za-z0-9+\/=]+)/',$command,$encoded))throw new \RuntimeException('Expected encoded PowerShell transport');
                // Execute the generated transport directly; PHP's Windows argv
                // quoting differs from a remote SSH shell's command-line parser.
                $argv=['powershell.exe','-NoLogo','-NoProfile','-NonInteractive','-EncodedCommand',$encoded[1]];
            }else $argv=['sh','-c',$command];
            $process=proc_open($argv,[1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
            if(!is_resource($process))throw new \RuntimeException('Cannot start isolated shell');
            $text=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($process);
            if(PHP_OS_FAMILY==='Windows'&&($error!==''||!preg_match('/__DNSMGR_EXIT_[a-f0-9]+__[0-9]+(?:\r?\n)?\z/D',$text)))fwrite(STDERR,json_encode(['test_transport_exit'=>$status,'stdout64'=>base64_encode(substr($text,-800)),'stderr64'=>base64_encode(substr($error,-800))]).PHP_EOL);
            $text.=$error;
        }
        $stream=fopen('php://memory','r+');fwrite($stream,$text);rewind($stream);return $stream;
    }
    function ssh2_fetch_stream(...$args){return fopen('php://memory','r+');}
}
namespace {
    require __DIR__.'/../app/lib/DeployInterface.php';require __DIR__.'/../app/lib/SensitiveLog.php';require __DIR__.'/../app/lib/deploy/ssh.php';
    if(!defined('SSH2_STREAM_STDERR'))define('SSH2_STREAM_STDERR',1);
    $checks=0;function commandCheck($ok,$message){$GLOBALS['checks']++;if(!$ok)throw new RuntimeException($message);}
    function commandReject($callback,$message,$reason=null){try{$callback();}catch(Throwable $e){if($reason!==null&&!str_contains($e->getMessage(),$reason))throw $e;commandCheck(true,$message);return;}throw new RuntimeException($message);}
    $windows=PHP_OS_FAMILY==='Windows';$GLOBALS['command_sent']=[];$GLOBALS['command_exit']=0;$GLOBALS['command_no_marker']=false;
    $client=new app\lib\deploy\ssh(['windows'=>$windows?'1':'0']);$logs=[];$client->setLogger(function($line)use(&$logs){$logs[]=$line;});$exec=new ReflectionMethod($client,'exec');
    $GLOBALS['command_exit']=7;commandReject(fn()=>$exec->invoke($client,null,$windows?'exit /b 7':'exit 7'),'A nonzero exit with empty stderr must fail','退出码7');
    $GLOBALS['command_exit']=0;$exec->invoke($client,null,$windows?'echo harmless-warning 1>&2':'printf harmless-warning >&2');
    commandCheck(!str_contains(implode("\n",$logs),'harmless-warning'),'Successful stderr output is accepted and never persisted');
    $exec->invoke($client,null,$windows?'echo SecretFixtureOnly42':'printf SecretFixtureOnly42');
    commandCheck(!str_contains(implode("\n",$logs),'SecretFixtureOnly42'),'Neither command input nor arbitrary stdout is logged');
    if(function_exists('post_message_to_js')){$GLOBALS['command_no_marker']=true;commandReject(fn()=>$exec->invoke($client,null,'true'),'Missing exit receipt must fail');$GLOBALS['command_no_marker']=false;}
    else {commandReject(fn()=>$exec->invoke($client,null,$windows?'exit /b 19':'exit 19'),'A real shell does not hide an explicit exit','退出码19');}
    if(!$windows&&!function_exists('post_message_to_js')){
        $output=$exec->invoke($client,null,"printf '%s' 'value with spaces; & dollar $ literal'");
        commandCheck($output==='value with spaces; & dollar $ literal','A real POSIX shell preserves command quoting');
    }
    // Decode Windows transport to check that IIS argument values remain inert PowerShell literals.
    $win=new app\lib\deploy\ssh(['windows'=>'1']);$win->setLogger(function($line)use(&$logs){$logs[]=$line;});
    if($windows&&!function_exists('post_message_to_js')){
        $output=$exec->invoke($win,null,"[Console]::Out.Write('literal with spaces; & quote'' stays data')",'PowerShell参数验证',true);
        commandCheck($output==="literal with spaces; & quote' stays data",'A real PowerShell receives literals without command injection');
    }
    $GLOBALS['command_fake']=true;
    {
        $password="fixture';&echo PfxOnly42";(new ReflectionMethod($win,'deploy_iis'))->invoke($win,null,'example.com',"/C:/cert's folder/cert.pfx",$password,str_repeat('a',40));
        $script='';foreach($GLOBALS['command_sent'] as $sent)if(preg_match('/-EncodedCommand ([A-Za-z0-9+\/=]+)/',$sent,$m))$script.=mb_convert_encoding(base64_decode($m[1]),'UTF-8','UTF-16LE');
        commandCheck(str_contains($script,"'fixture'';&echo PfxOnly42'")&&str_contains($script,"'C:/cert''s folder/cert.pfx'"),'IIS passwords and paths are quoted as data');
        commandCheck(!str_contains(implode("\n",$logs),'PfxOnly42'),'IIS command arguments never enter logs');
    }
    $GLOBALS['command_no_marker']=true;commandReject(fn()=>$exec->invoke($client,null,'fixture'),'A transport without an exit receipt fails closed');$GLOBALS['command_no_marker']=false;
    $GLOBALS['command_stream_failure']=true;commandReject(fn()=>$exec->invoke($client,null,'fixture'),'A failed SSH channel fails without reading an invalid stream');$GLOBALS['command_stream_failure']=false;
    $GLOBALS['command_oversize']=true;commandReject(fn()=>$exec->invoke($client,null,'fixture'),'Oversized command output fails closed');$GLOBALS['command_oversize']=false;
    $sslConfig=tempnam(sys_get_temp_dir(),'ssh-key-fixture-');file_put_contents($sslConfig,"[req]\ndistinguished_name=dn\n[dn]\n");
    try{
        $key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048,'config'=>$sslConfig]);$pass='FixtureKeyPass42';
        commandCheck($key!==false&&openssl_pkey_export($key,$encrypted,$pass,['config'=>$sslConfig]),'Generate an isolated encrypted RSA fixture');
        $auth=new app\lib\deploy\ssh(['passphrase'=>$pass]);$public=(new ReflectionMethod($auth,'getPublicKey'))->invoke($auth,$encrypted);
        commandCheck(str_starts_with($public,'ssh-rsa '),'A configured passphrase is used while deriving the authentication public key');
    }finally{unlink($sslConfig);}
    echo "SSH command execution: $checks checks passed\n";
}
