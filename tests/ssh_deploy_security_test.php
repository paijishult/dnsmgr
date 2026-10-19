<?php
namespace app\lib {
    function ssh2_connect($host,$port){$GLOBALS['ssh_events'][]='connect';return $GLOBALS['ssh_resource'];}
    function ssh2_fingerprint(...$args){$GLOBALS['ssh_events'][]='fingerprint';return $GLOBALS['ssh_actual'];}
    function ssh2_sftp_chmod($sftp,$path,$mode){$GLOBALS['ssh_modes'][$path]=$mode;return !$GLOBALS['ssh_chmod_fail'];}
    function ssh2_sftp_rename($sftp,$from,$to){$GLOBALS['ssh_files'][$to]=$GLOBALS['ssh_files'][$from];unset($GLOBALS['ssh_files'][$from]);return true;}
    function ssh2_sftp_unlink($sftp,$path){unset($GLOBALS['ssh_files'][$path]);return true;}
}
namespace app\lib\deploy {
    function ssh2_auth_password(...$args){$GLOBALS['ssh_events'][]='authenticate';return true;}
    function ssh2_sftp($connection){return $GLOBALS['ssh_resource'];}
    function ssh2_sftp_mkdir($sftp,$path,$mode){$GLOBALS['ssh_directories'][$path]=$mode;return true;}
    function ssh2_sftp_unlink($sftp,$path){unset($GLOBALS['ssh_files'][$path]);return true;}
    function ssh2_sftp_rmdir($sftp,$path){unset($GLOBALS['ssh_directories'][$path]);return true;}
    function ssh2_exec($connection,$cmd){
        $GLOBALS['ssh_commands'][]=$cmd;$output='';
        if(str_contains($cmd,'cat /usr/trim/etc/network_cert_all.conf'))$output=json_encode([['san'=>['example.com'],'domain'=>'example.com','certificate'=>"/trusted/cert ' space.pem",'privateKey'=>"/trusted/key ' space.pem"]]);
        if(preg_match('/__DNSMGR_EXIT_[a-f0-9]+__/',$cmd,$m))$output.="\n".$m[0].$GLOBALS['ssh_exit'];
        $stream=fopen('php://memory','r+');fwrite($stream,$output);rewind($stream);return $stream;
    }
    function ssh2_fetch_stream(...$args){return fopen('php://memory','r+');}
}
namespace {
    if(!function_exists('ssh2_connect')){function ssh2_connect(...$args){throw new RuntimeException('Real SSH must never be contacted');}}
    foreach(['SSH2_FINGERPRINT_SHA1'=>1,'SSH2_FINGERPRINT_HEX'=>0,'SSH2_STREAM_STDERR'=>1] as $key=>$value)if(!defined($key))define($key,$value);
    require __DIR__.'/../app/lib/SshSecurity.php';require __DIR__.'/../app/lib/SecureFiles.php';require __DIR__.'/../app/lib/DeployInterface.php';require __DIR__.'/../app/lib/deploy/fnos.php';
    class SecuritySftpStream {
        public $context;private string $path;
        public function stream_open($path,$mode,$options,&$opened){$this->path=parse_url($path,PHP_URL_PATH);$GLOBALS['ssh_files'][$this->path]='';return true;}
        public function stream_write($bytes){if(!isset($GLOBALS['ssh_modes'][$this->path]))throw new RuntimeException('Key bytes written before permissions were restricted');$GLOBALS['ssh_files'][$this->path].=$bytes;return strlen($bytes);}
        public function stream_flush(){return true;}public function stream_close(){}public function stream_stat(){return [];}
    }
    $hadWrapper=in_array('ssh2.sftp',stream_get_wrappers(),true);if($hadWrapper)stream_wrapper_unregister('ssh2.sftp');stream_wrapper_register('ssh2.sftp',SecuritySftpStream::class);
    $GLOBALS['ssh_resource']=fopen('php://memory','r+');$GLOBALS['ssh_actual']=str_repeat('a',40);$GLOBALS['ssh_events']=[];$GLOBALS['ssh_files']=[];$GLOBALS['ssh_modes']=[];$GLOBALS['ssh_directories']=[];$GLOBALS['ssh_commands']=[];$GLOBALS['ssh_exit']=0;$GLOBALS['ssh_chmod_fail']=false;
    $config=['host'=>'192.0.2.1','port'=>22,'username'=>'fixture','password'=>'fixture-only','host_fingerprint'=>str_repeat('a',40)];
    $checks=0;function sshCheck($ok,$message){$GLOBALS['checks']++;if(!$ok)throw new RuntimeException($message);}
    function sshReject($callback,$message){try{$callback();}catch(Throwable $e){sshCheck(true,$message);return;}throw new RuntimeException($message);}
    function getMillisecond(){return (int)(microtime(true)*1000);}
    $directory=app\lib\SecureFiles::temporaryDirectory();
    try {
        $bad=$config;$bad['host_fingerprint']='';sshReject(fn()=>(new app\lib\deploy\fnos($bad))->check(),'Missing pins fail closed');sshCheck($GLOBALS['ssh_events']===[],'A missing pin makes no connection or authentication attempt');
        $bad['host_fingerprint']=str_repeat('b',40);sshReject(fn()=>(new app\lib\deploy\fnos($bad))->check(),'Wrong host pin fails closed');sshCheck($GLOBALS['ssh_events']===['connect','fingerprint'],'Credentials are never sent to a mismatched server');
        $GLOBALS['ssh_events']=[];(new app\lib\deploy\fnos($config))->check();sshCheck($GLOBALS['ssh_events']===['connect','fingerprint','authenticate'],'A matching host is authenticated only after verification');
        app\lib\SshSecurity::upload($GLOBALS['ssh_resource'],'/trusted/target.key','fixture-private-bytes');sshCheck($GLOBALS['ssh_files']['/trusted/target.key']==='fixture-private-bytes'&&array_values($GLOBALS['ssh_modes'])[0]===0600,'SFTP key bytes are private before atomic replacement');
        $GLOBALS['ssh_chmod_fail']=true;sshReject(fn()=>app\lib\SshSecurity::upload($GLOBALS['ssh_resource'],'/trusted/failed.key','must-not-write'),'SFTP permission failure stops writing');sshCheck(!isset($GLOBALS['ssh_files']['/trusted/failed.key']),'A permission failure never replaces the target');$GLOBALS['ssh_chmod_fail']=false;
        $sslConfig=$directory.'/openssl.cnf';app\lib\SecureFiles::write($sslConfig,"[req]\ndefault_bits=2048\ndistinguished_name=dn\nprompt=no\n[dn]\nCN=example.com\n");
        $key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);$issuer="fixture' ; $(touch /tmp/dnsmgr-must-not-exist) ; END_CERT";
        $csr=openssl_csr_new(['commonName'=>$issuer],$key,['config'=>$sslConfig]);$cert=openssl_csr_sign($csr,null,$key,1,['config'=>$sslConfig]);openssl_x509_export($cert,$pem);openssl_pkey_export($key,$private,null,['config'=>$sslConfig]);
        $info=[];(new app\lib\deploy\fnos($config))->deploy($pem,$private,['domainList'=>['example.com']],$info);
        sshCheck($GLOBALS['ssh_directories']===[],'FnOS upload directories are cleaned up');
        $commands=implode("\n",$GLOBALS['ssh_commands']);sshCheck(!str_contains($commands,'BEGIN PRIVATE KEY')&&!str_contains($commands,'BEGIN CERTIFICATE')&&!str_contains($commands,base64_encode($private)),'Certificate and private key bytes never enter shell commands');
        sshCheck(!str_contains($commands,'chmod -R')&&!str_contains($commands,'EOF'),'FnOS has no world-readable recursive chmod or certificate heredocs');
        sshCheck(str_contains($commands,'install -m 600')&&str_contains($commands,'install -m 644'),'FnOS distinguishes private key and public certificate modes');
        $sql=array_values(array_filter($GLOBALS['ssh_commands'],fn($c)=>str_contains($c,'psql -v ON_ERROR_STOP=1')))[0];
        sshCheck(str_contains($sql,'standard_conforming_strings=on')&&str_contains($sql,str_replace("'", "'\\''", "fixture''")),'PostgreSQL literals handle apostrophes and backslashes');
        if(!function_exists('post_message_to_js')){
            // Let a real POSIX shell parse the generated command, replacing only the fixed psql program.
            $probe=str_replace('sudo -u postgres psql','printf \'%s\\n\'',$sql);$p=proc_open(['sh','-c',$probe],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($p);
            sshCheck($status===0&&$error===''&&str_contains($output,'$(touch /tmp/dnsmgr-must-not-exist)')&&!file_exists('/tmp/dnsmgr-must-not-exist'),'A real shell keeps malicious issuer text inert inside the SQL argument');
        }
        $GLOBALS['ssh_exit']=7;$fnos=new app\lib\deploy\fnos($config);sshReject(fn()=>(new ReflectionMethod($fnos,'exec'))->invoke($fnos,$GLOBALS['ssh_resource'],'probe','false'),'Non-zero remote exit status is reported even with empty stderr');
        echo "SSH/FnOS security: $checks checks passed\n";
    } finally {app\lib\SecureFiles::removeDirectory($directory);fclose($GLOBALS['ssh_resource']);stream_wrapper_unregister('ssh2.sftp');if($hadWrapper)stream_wrapper_restore('ssh2.sftp');}
}
