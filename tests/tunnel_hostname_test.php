<?php
namespace app {class BaseController {}}
namespace think\facade {class Db {public static function name($t){return new \TunnelQuery($t);}}}
namespace app\service {
    class CloudflareEnhanceService {
        public static array $ingress=[],$deletions=[];
        public function __construct($c){}public function isApiTokenAuth(){return true;}
        public function getTunnelConfig(...$a){return ['config'=>['ingress'=>self::$ingress]];}
        public function updateTunnelConfig($a,$t,$c){self::$ingress=$c['ingress'];}
        public function deleteTunnelCnameRecordIfMatch($z,$h,$t){self::$deletions[]=$h;return ['deleted'=>true];}
    }
}
namespace {
    class TunnelQuery {
        public function __construct(private string $table){}public function where(...$a){return $this;}
        public function find(){return ['id'=>1,'type'=>'cloudflare','name'=>'fixture-account','remark'=>'fixture','config'=>json_encode(['account_id'=>'fixture-account-id'])];}
        public function select(){return $this;}public function toArray(){return [['id'=>1,'aid'=>1,'name'=>'example.com','thirdid'=>'fixture-zone']];}public function insert($a){}
    }
    function input($k,$d=null,...$a){return ['param.id/d'=>1,'post.tunnel_id'=>'fixture-tunnel','post.hostname'=>'APP.EXAMPLE.COM.','post.path'=>$GLOBALS['tunnel_path']][$k]??$d;}
    function checkPermission(...$a){return true;}function convertDomainToAscii($d){return $d;}function json($v){return $v;}function request(){return (object)['isApi'=>false,'apiKey'=>null,'user'=>['id'=>1]];}
    require __DIR__.'/../app/controller/Cloudflare.php';
    use app\service\CloudflareEnhanceService as Tunnel;
    Tunnel::$ingress=[['hostname'=>'app.example.com','path'=>'/a','service'=>'http://fixture-a'],['hostname'=>'app.example.com','path'=>'/b','service'=>'http://fixture-b'],['service'=>'http_status:404']];
    $GLOBALS['tunnel_path']='/a';$result=(new app\controller\Cloudflare())->tunnels_public_hostnames_delete();
    if($result['code']!==0||count(Tunnel::$ingress)!==2||Tunnel::$deletions!==[])throw new RuntimeException('Deleting one path must preserve another path and its CNAME');
    $GLOBALS['tunnel_path']='/b';$result=(new app\controller\Cloudflare())->tunnels_public_hostnames_delete();
    if($result['code']!==0||count(Tunnel::$ingress)!==1||count(Tunnel::$deletions)!==1)throw new RuntimeException('The last hostname path releases its CNAME');
    echo "Tunnel hostname sharing: 2 checks passed\n";
}
