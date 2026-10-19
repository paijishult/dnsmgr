<?php
/** Exercise the real ORM closure predicates, including legacy SSO/user ID collisions. */
$root=dirname(__DIR__);$vendor=getenv('DNSMGR_COMPOSER_VENDOR')?:$root.'/vendor';
if(!is_file($vendor.'/autoload.php')){echo "Log visibility requires Composer/SQLite (covered by CI)\n";return;}
$_SERVER['REMOTE_ADDR']='127.0.0.1';$_SERVER['REQUEST_METHOD']='POST';
require $vendor.'/autoload.php';$app=new think\App($root.'/');$app->setRuntimePath(sys_get_temp_dir().'/dnsmgr-log-tests-'.getmypid().'/');$app->initialize();
set_exception_handler(static function(Throwable $e){echo (string)$e;exit(1);});
$app->config->set(['default'=>'sqlite','connections'=>['sqlite'=>['type'=>'sqlite','database'=>':memory:','prefix'=>'dnsmgr_','fields_cache'=>false]]],'database');
think\facade\Db::execute('CREATE TABLE dnsmgr_log (id INTEGER PRIMARY KEY AUTOINCREMENT,uid INTEGER,domain TEXT,action TEXT,data TEXT,addtime TEXT)');
$app->request->isApi=false;$app->request->apiKey=null;$app->request->withPost(['offset'=>0,'limit'=>100]);
$app->request->user=['type'=>'domain','id'=>1001,'name'=>'other.example.net','level'=>0];
$domain=new app\controller\Domain($app);(new ReflectionMethod($domain,'add_log'))->invoke($domain,'other.example.net','添加解析','SSO fixture');
$checks=0;function logCheck($ok,$message){$GLOBALS['checks']++;if(!$ok)throw new RuntimeException($message);}
logCheck((int)think\facade\Db::name('log')->value('uid')===0,'New SSO logs never use a domain ID as user ID');
foreach ([['uid'=>1001,'domain'=>'other.example.net','data'=>'legacy SSO collision'],['uid'=>1001,'domain'=>'example.com','data'=>'authorized own change'],['uid'=>1001,'domain'=>'','data'=>'own login'],['uid'=>1002,'domain'=>'example.com','data'=>'another user']] as $row)think\facade\Db::name('log')->insert($row+['action'=>'fixture','addtime'=>date('Y-m-d H:i:s')]);
$app->request->user=['type'=>'user','id'=>1001,'username'=>'fixture-user','level'=>1,'permission'=>['example.com']];
$response=(new app\controller\User($app))->log_data()->getData();logCheck($response['total']===2&&array_column($response['rows'],'data')===['own login','authorized own change'],'Normal user sees only own login and authorized domain, never legacy colliding SSO rows');
$principal=$app->request->user;$principal['permission']=[];$app->request->user=$principal;$response=(new app\controller\User($app))->log_data()->getData();logCheck($response['total']===1&&$response['rows'][0]['data']==='own login','Revoking all domains still permits own login history');
$app->request->user=['type'=>'domain','id'=>1001,'name'=>'other.example.net','level'=>0];$response=(new app\controller\User($app))->log_data()->getData();logCheck($response['total']===2&&array_unique(array_column($response['rows'],'domain'))===['other.example.net'],'SSO history remains restricted to its domain');
echo "Log visibility: $checks checks passed\n";
