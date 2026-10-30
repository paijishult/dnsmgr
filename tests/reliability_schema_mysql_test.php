<?php
if(!getenv('TEST_MYSQL_DSN')){echo "Reliability schema integration requires disposable MySQL (covered by CI matrix)\n";return;}
require __DIR__.'/../app/service/ReliabilityMigrationService.php';
$prefix='review_'.bin2hex(random_bytes(5)).'_';$checks=0;
class SchemaConnection {
 public PDO $pdo;
 public function __construct(){$this->pdo=new PDO(getenv('TEST_MYSQL_DSN'),getenv('TEST_MYSQL_USER')?:'root',getenv('TEST_MYSQL_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}
 public function query($sql){return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);}public function execute($sql){return $this->pdo->exec($sql);}public function getSchemaInfo($name,$force){return $this->query('SHOW COLUMNS FROM `'.$name.'`');}
}
function schemaCheck($ok,$message){$GLOBALS['checks']++;if(!$ok)throw new RuntimeException($message);}
$db=new SchemaConnection();
try {
 $db->execute("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
 foreach(['install.sql','features.sql'] as $file){$sql=str_replace('dnsmgr_',$prefix,file_get_contents(__DIR__.'/../app/sql/'.$file));foreach(explode(';',$sql) as $statement)if(trim($statement)!=='')$db->execute($statement);}
 // Emulate the old deployed field sizes and preserve an existing task through migration.
 $db->execute('ALTER TABLE `'.$prefix.'dmtask` MODIFY recordinfo varchar(200), MODIFY frequency tinyint(5) NOT NULL, MODIFY cycle tinyint(5) NOT NULL DEFAULT 3, DROP health_options');
 $db->execute('ALTER TABLE `'.$prefix.'cert_deploy` MODIFY info text');
 $db->execute("INSERT INTO `".$prefix."dmtask` (did,rr,recordid,frequency,recordinfo) VALUES (1,'www','existing',5,'{}')");
 app\service\ReliabilityMigrationService::apply($db,$prefix);app\service\ReliabilityMigrationService::apply($db,$prefix);
 $json=json_encode(['value'=>str_repeat('x',66000)]);$longId=str_repeat('a',2048);
 $q=$db->pdo->prepare('UPDATE `'.$prefix.'dmtask` SET frequency=?,cycle=?,errcount=?,recordid=?,recordinfo=?,checkurl=?,remark=?');$q->execute([86400,10000,10000,$longId,$json,'https://example.com/'.str_repeat('a',4000),str_repeat('备',600)]);
 $row=$db->query('SELECT * FROM `'.$prefix.'dmtask`')[0];schemaCheck((int)$row['frequency']===86400 && (int)$row['cycle']===10000,'Accepted numeric limits survive strict MySQL');
 schemaCheck($row['recordid']===$longId&&$row['recordinfo']===$json,'Long record identities and metadata round trip');schemaCheck($row['rr']==='www','Migration preserves existing data');
 $q=$db->pdo->prepare('INSERT INTO `'.$prefix.'sctask` (did,rr,recordid,recordinfo,value,line) VALUES (1,?,?,?,?,?)');$q->execute(['www',$longId,$json,str_repeat('v',2048),str_repeat('l',255)]);
 schemaCheck($db->query('SELECT recordinfo FROM `'.$prefix.'sctask`')[0]['recordinfo']===$json,'Schedule schema accepts validated limits');
 $columns=array_column($db->query('SHOW COLUMNS FROM `'.$prefix.'dmtask`'),'Field');schemaCheck(in_array('health_options',$columns,true),'Health policy field installed idempotently');
 $db->execute('ALTER TABLE `'.$prefix.'sctask` MODIFY recordid varchar(4096) NOT NULL');app\service\ReliabilityMigrationService::apply($db,$prefix);
 $columns=array_column($db->query('SHOW COLUMNS FROM `'.$prefix.'sctask`'),null,'Field');schemaCheck($columns['recordid']['Type']==='varchar(4096)','Migration never narrows a customized larger column');
 $q=$db->pdo->prepare('INSERT INTO `'.$prefix.'cert_deploy` (aid,oid,info) VALUES (1,1,?)');$q->execute([$json]);schemaCheck($db->query('SELECT info FROM `'.$prefix.'cert_deploy`')[0]['info']===$json,'Large deployment matrices survive beyond the old TEXT boundary');
 echo "Review MySQL schema: $checks checks passed\n";
}finally{foreach($db->query('SHOW TABLES') as $r){$name=array_values($r)[0];if(str_starts_with($name,$prefix))$db->execute('DROP TABLE `'.$name.'`');}}
