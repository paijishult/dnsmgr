<?php
/** Exercise the real ORM field cache and the actual deployment-log response. */
$root=dirname(__DIR__);$vendor=getenv('DNSMGR_COMPOSER_VENDOR')?:$root.'/vendor';
if(!is_file($vendor.'/autoload.php')){echo "ORM/cache/log regressions require Composer/SQLite (covered by CI)\n";return;}
require $vendor.'/autoload.php';
$_SERVER['REMOTE_ADDR']='127.0.0.1';$_SERVER['REQUEST_METHOD']='POST';
$app=new think\App($root.'/');$app->setRuntimePath(sys_get_temp_dir().'/dnsmgr-recheck-'.bin2hex(random_bytes(6)).'/');$app->initialize();
set_exception_handler(static function(Throwable $e){echo (string)$e;exit(1);});
$checks=0;function ormRecheck($ok,$message){$GLOBALS['checks']++;if(!$ok)throw new RuntimeException($message);}
class SchemaRecheckSqlite extends think\db\connector\Sqlite {
    public function query(string $sql,array $bind=[],bool $master=false):array {
        if(preg_match('/\ASHOW COLUMNS FROM `([^`]+)`\z/D',$sql,$m))return array_map(static fn($row)=>['Field'=>$row['name']],parent::query("PRAGMA table_info('".$m[1]."')"));
        return parent::query($sql,$bind,$master);
    }
}
$cache=new think\cache\driver\File($app,['path'=>$app->getRuntimePath().'schema-cache']);$cache->set('unrelated-fixture','preserved');
$manager=new think\DbManager();$manager->setCache($cache);
$config=['type'=>'\\'.SchemaRecheckSqlite::class,'builder'=>think\db\builder\Sqlite::class,'database'=>$app->getRuntimePath().'cache.sqlite','prefix'=>'proof_','fields_strict'=>true,'fields_cache'=>true];
if(!is_dir($app->getRuntimePath()))mkdir($app->getRuntimePath(),0700,true);
$manager->setConfig(['default'=>'sqlite','connections'=>['sqlite'=>$config]]);$db=$manager->connect($config,true);
$db->execute('CREATE TABLE proof_account(id INTEGER PRIMARY KEY,type TEXT,name TEXT,sk TEXT,ext TEXT,proxy INTEGER)');
$db->name('account')->insert(['id'=>1,'type'=>'aliyun','name'=>'fixture-id','sk'=>'fixture-secret','ext'=>'','proxy'=>0]);
ormRecheck(!in_array('config',$db->getTableInfo('proof_account','fields'),true),'Old schema is primed into a real persistent cache');
app\service\LegacyMigrationService::apply($db,'ALTER TABLE `proof_account` ADD COLUMN `config` TEXT DEFAULT NULL;');
(new ReflectionMethod(app\service\LegacyMigrationService::class,'convertAccounts'))->invoke(null,$db);
ormRecheck($db->name('account')->value('config')!==null,'The same migration connection writes a newly added field');
$db->close();$db=$manager->connect($config,true);
ormRecheck(in_array('config',$db->getTableInfo('proof_account','fields'),true),'A fresh connection sees the refreshed shared cache');
ormRecheck($cache->get('unrelated-fixture')==='preserved','Schema refresh does not clear unrelated caches');
$app->config->set(['default'=>'sqlite','connections'=>['sqlite'=>['type'=>'sqlite','database'=>':memory:','prefix'=>'proof_','fields_cache'=>false]]],'database');
foreach(['cert_deploy(id INTEGER PRIMARY KEY,oid INTEGER,aid INTEGER,processid TEXT,config TEXT)','cert_order(id INTEGER PRIMARY KEY,processid TEXT)','account(name TEXT,config TEXT)','cert_account(id INTEGER PRIMARY KEY,type TEXT,name TEXT,config TEXT,ext TEXT)','user(apikey TEXT)','config(key TEXT,value TEXT)'] as $schema)think\facade\Db::execute('CREATE TABLE proof_'.$schema);
$password="Fixture PFX'Only42\nsecond line";$pid=str_repeat('b',32);
think\facade\Db::name('cert_deploy')->insert(['id'=>20,'oid'=>10,'aid'=>1,'processid'=>$pid,'config'=>json_encode(['pfx_pass'=>$password])]);
think\facade\Db::name('cert_account')->insert(['id'=>1,'type'=>'ssh','name'=>'fixture-host','config'=>'{}','ext'=>null]);
$request=$app->request;$request->isApi=true;$request->user=['type'=>'user','id'=>1,'level'=>2];$request->apiKey=['scopes'=>['deploy:read']];$request->withPost(['action'=>'show_log','id'=>20]);
if(!is_dir($app->getRuntimePath().'log'))mkdir($app->getRuntimePath().'log',0700,true);$path=$app->getRuntimePath().'log/'.$pid.'.log';
file_put_contents($path,'执行命令：certutil -p "HistoricalPfxOnly42" -importPFX C:/cert.pfx'."\n".$password."\n".json_encode($password));
$response=(new app\controller\Cert($app))->deploy_op();$data=$response->getData();
$content=app\lib\AuditHelper::scrub($response->getContent(),app\lib\AuditHelper::apiScrubValues());
ormRecheck($data['code']===0&&!str_contains($content,'HistoricalPfxOnly42')&&!str_contains($content,'second line'),'Read-only deployment log API hides old positional and current multiline task passwords');
$service=(new ReflectionClass(app\service\CertDeployService::class))->newInstanceWithoutConstructor();(new ReflectionProperty($service,'task'))->setValue($service,['processid'=>$pid,'config'=>json_encode(['pfx_pass'=>$password])]);
file_put_contents($path,'');ob_start();(new ReflectionMethod($service,'saveLog'))->invoke($service,'remote output '.$password);ob_end_clean();
ormRecheck(!str_contains(file_get_contents($path),'second line'),'Task secrets are removed before log persistence');
$request->withPost(['action'=>'show_log','processid'=>$pid]);
ormRecheck((new app\controller\Cert($app))->order_op()->getData()['code']===-1,'An issuance-log reader cannot request a deployment process log');
think\facade\Db::name('cert_deploy')->where('id',20)->delete();
ormRecheck((new app\controller\Cert($app))->deploy_op()->getData()['code']===-1,'An orphaned deployment log is not served after its task has been deleted');
$db->close();$cache->clear();unlink($app->getRuntimePath().'cache.sqlite');
echo "ORM/cache/deployment logs: $checks checks passed\n";
