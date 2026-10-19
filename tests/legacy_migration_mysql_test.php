<?php
namespace think\facade {
    class Db {public static function connect(...$args){return new \LegacyConnection();}}
    class Cache {public static function delete($key){}}
    class Config {public static function set($values,$section){$GLOBALS['legacy_config']=array_merge($GLOBALS['legacy_config'],$values);}}
}
namespace app\lib {class DnsHelper {public static $dns_config=[];}}
namespace {
    if(!getenv('TEST_MYSQL_DSN')){echo "Legacy migration integration requires disposable MySQL (covered by CI matrix)\n";return;}
    require __DIR__.'/../app/service/TaskLockService.php';require __DIR__.'/../app/service/LegacyMigrationService.php';
    $GLOBALS['legacy_prefix']='dnsmgr_legacy_'.bin2hex(random_bytes(5)).'_';$GLOBALS['legacy_config']=['version'=>'1051'];$GLOBALS['legacy_deny']='proxy';
    function config($key,$default=null){return match($key){'app.dbversion'=>1052,'database.connections.mysql'=>['database'=>'integration','prefix'=>$GLOBALS['legacy_prefix']],'database.connections.mysql.prefix'=>$GLOBALS['legacy_prefix'],default=>$default};}
    function config_get($key,$default=null){return $GLOBALS['legacy_config'][$key]??$default;}function env($key,$default=null){return $default;}
    function app(){return new class{public function getAppPath(){return dirname(__DIR__).'/app/';}};}
    class LegacyConnection {
        public PDO $pdo;
        public function __construct(){$this->pdo=new PDO(getenv('TEST_MYSQL_DSN'),getenv('TEST_MYSQL_USER')?:'root',getenv('TEST_MYSQL_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}
        public function query($sql,$bind=[],$master=true){$q=$this->pdo->prepare($sql);$q->execute($bind);return $q->fetchAll(PDO::FETCH_ASSOC);}
        public function execute($sql){if($GLOBALS['legacy_deny']!==''&&str_contains($sql,'ADD COLUMN `'.$GLOBALS['legacy_deny'].'`'))throw new RuntimeException('simulated DDL permission denial');return $this->pdo->exec($sql);}
        public function name($table){return new LegacyQuery($this,$GLOBALS['legacy_prefix'].$table);}public function close(){}
    }
    class LegacyQuery {
        private string $where='';private array $bind=[];
        public function __construct(private LegacyConnection $db,private string $table){}
        public function where($key,$value){$this->where=' WHERE `'.$key.'`=?';$this->bind=[$value];return $this;}
        public function value($key){return $this->db->query('SELECT `'.$key.'` FROM `'.$this->table.'`'.$this->where,$this->bind)[0][$key]??null;}
        public function select(){return $this->db->query('SELECT * FROM `'.$this->table.'`'.$this->where,$this->bind);}
        public function replace(){return $this;}public function insert($row){$q=$this->db->pdo->prepare('REPLACE INTO `'.$this->table.'` (`'.implode('`,`',array_keys($row)).'`) VALUES ('.implode(',',array_fill(0,count($row),'?')).')');return $q->execute(array_values($row));}
    }
    $db=new LegacyConnection();$prefix=$GLOBALS['legacy_prefix'];$checks=0;
    function legacyCheck($ok,$message){$GLOBALS['checks']++;if(!$ok)throw new RuntimeException($message);}
    try {
        $db->pdo->exec('CREATE TABLE `'.$prefix.'config` (`key` varchar(32) PRIMARY KEY,`value` text) ENGINE=InnoDB');
        $db->pdo->exec('CREATE TABLE `'.$prefix.'user` (`id` int PRIMARY KEY,`totp_open` tinyint NOT NULL DEFAULT 0) ENGINE=InnoDB');
        $db->pdo->exec('CREATE TABLE `'.$prefix.'domain` (`id` int PRIMARY KEY,`remark` varchar(100)) ENGINE=InnoDB');
        $db->pdo->exec('CREATE TABLE `'.$prefix.'account` (`id` int PRIMARY KEY,`type` varchar(20),`ak` varchar(255) NOT NULL) ENGINE=InnoDB');
        $db->pdo->exec("INSERT INTO `".$prefix."config` VALUES ('version','1051')");
        $db->pdo->exec("INSERT INTO `".$prefix."account` VALUES (1,'fixture','preserve-account-name')");
        $failed=false;try{app\service\LegacyMigrationService::ensure();}catch(RuntimeException $e){$failed=str_contains($e->getMessage(),'permission denial');}
        legacyCheck($failed,'DDL errors must propagate');legacyCheck($db->name('config')->where('key','version')->value('value')==='1051','Failed migration never advances the version marker');
        $GLOBALS['legacy_deny']='';app\service\LegacyMigrationService::ensure();
        legacyCheck($db->name('config')->where('key','version')->value('value')==='1052','Successful migration commits the new marker');
        $columns=array_column($db->query('SHOW COLUMNS FROM `'.$prefix.'user`'),'Field');legacyCheck(in_array('totp_secret',$columns,true),'Partially applied multi-column ALTER resumes missing columns');
        legacyCheck($db->query('SELECT name FROM `'.$prefix.'account`')[0]['name']==='preserve-account-name','Legacy account rename preserves its value');
        $GLOBALS['legacy_config']['version']='1051';app\service\LegacyMigrationService::ensure();legacyCheck($db->name('config')->where('key','version')->value('value')==='1052','Reapplying all legacy DDL is idempotent');
        $db->pdo->exec('ALTER TABLE `'.$prefix.'cert_order` DROP COLUMN `issuer`');$GLOBALS['legacy_config']['version']='1051';$db->pdo->exec("UPDATE `".$prefix."config` SET value='1051' WHERE `key`='version'");
        $failed=false;try{app\service\LegacyMigrationService::ensure();}catch(RuntimeException $e){$failed=true;}legacyCheck($failed&&$db->name('config')->where('key','version')->value('value')==='1051','An incomplete pre-existing CREATE TABLE is not marked ready');
        echo "Legacy MySQL migration: $checks checks passed\n";
    } finally {
        foreach($db->query('SHOW TABLES') as $row){$name=array_values($row)[0];if(str_starts_with($name,$prefix))$db->pdo->exec('DROP TABLE `'.$name.'`');}
    }
}
