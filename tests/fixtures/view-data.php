<?php
/** Deterministic public view data, without live credentials or remote requests. */
return static function (string $name, array $user): array {
    $info = [
        'id'=>1,
        'did'=>1,
        'aid'=>1,
        'oid'=>1,
        'type'=>'aliyun',
        'name'=>'example.com',
        'config'=>'{}',
        'ext'=>'{}',
        'remark'=>'示例',
        'deploy'=>0,
        'status'=>0,
        'totp_open'=>1,
        'addtime'=>'2026-10-19 10:00:00',
        'fail_count'=>0,
        'switch_count'=>0,
        'action_name'=>[0=>'未变化',1=>'发生异常',2=>'恢复正常'],
        'framework_version'=>'8.1.4',
        'php_version'=>PHP_VERSION,
        'mysql_version'=>'fixture',
        'software'=>'fixture',
        'os'=>'fixture',
        'date'=>'2026-10-19 10:00:00',
        'run_state'=>1,
        'run_count'=>5,
        'run_time'=>'2026-10-19 10:00:00',
        'run_error'=>'',
        'swoole'=>'测试环境',
        'domains'=>['example.com'],
        'keytype'=>'RSA',
        'keysize'=>'2048',
        'isauto'=>1,
        'islock'=>0,
        'recordinfo'=>'{}',
        'rr'=>'www',
        'recordid'=>'1',
        'main_value'=>'192.0.2.1',
        'backup_value'=>'192.0.2.2',
        'checktype'=>0,
        'cycle'=>3,
        'frequency'=>30,
        'timeout'=>2,
        'active'=>1,
        'checkurl'=>'',
        'tcpport'=>443,
        'proxy'=>0,
        'cdn'=>0,
        'ip_type'=>'v4',
        'cdn_type'=>1,
        'recordnum'=>1,
        'ttl'=>600,
        'switchtype'=>0,
        'switchdate'=>'1',
        'switchtime'=>'10:00',
        'value'=>'192.0.2.1',
        'line'=>'default',
        'processid'=>null,
        'fullchain'=>'',
        'privatekey' => ''
    ];
    $base = [
        'islogin'=>true,
        'user'=>$user,
        'skin'=>'skin-modern',
        'csrfToken'=>str_repeat('a',64),
        'info'=>$info,
        'version'=>'1064',
        'siteurl'=>'http://fixture.local',
        'dbconfig'=>'0',
        'is_user_www'=>false,
        'qqbot_webhook'=>'http://fixture.local/qqbot/webhook',
        'qqbot_bind'=>false,
        'action'=>'add',
        'accounts'=>[['id'=>1,
        'name'=>'示例账户',
        'type'=>'aliyun',
        'add'=>true]],
        'types'=>['aliyun'=>'阿里云'],
        'categorys'=>[['id'=>1,
        'name'=>'示例分类']],
        'domains'=>[['id'=>1,
        'name'=>'example.com',
        'type'=>'aliyun']],
        'domainId'=>1,
        'domainName'=>'example.com',
        'domainList'=>[['id'=>1,
        'name'=>'example.com',
        'type'=>'aliyun',
        'dnsType'=>'阿里云',
        'icon'=>'aliyun.png']],
        'recordLine'=>[['id'=>'default',
        'name'=>'默认',
        'parent'=>null]],
        'minTTL'=>600,
        'defaultLine'=>'default',
        'dnsconfig'=>['type'=>'aliyun',
        'name'=>'阿里云',
        'redirect'=>true,
        'weight'=>true,
        'remark'=>true,
        'status'=>true,
        'line'=>true,
        'sort'=>true,
        'page'=>false,
        'ttl'=>true,
        'group'=>true,
        'alias'=>true,
        'log'=>true,
        'add'=>true],
        'typeList'=>\app\lib\DnsHelper::getList(),
        'support_ping'=>true,
        'scopes'=>\app\lib\ApiKeyHelper::$scopes,
        'presets'=>\app\lib\ApiKeyHelper::$presets,
        'ipSources'=>\app\lib\ApiKeyHelper::$ipSources,
        'users'=>[['id'=>1000,
        'username'=>'fixture-admin',
        'level'=>2]],
        'myip'=>'127.0.0.1',
        'groups'=>[],
        'total'=>0,
        'keys'=>[],
        'settings'=>['audit_web'=>'1',
        'api_calllog'=>'all',
        'audit_days'=>'180'],
        'classList'=>\app\lib\CertHelper::$class_config,
        'deploy'=>0,
        'title'=>'SSL证书账户',
        'orders'=>[['id'=>1,
        'domains'=>['example.com'],
        'name'=>'example.com']],
        'domainAliasList'=>[],
        'accountName'=>'示例CF账户',
        'accountId'=>1,
        'cfAccountId'=>'fixture-account',
        'zoneId'=>'fixture-zone',
        'cfTokenAuth'=>true,
        'certList'=>[],
        'certAccounts'=>[],
        'deployAccounts' => []
    ];

 if ($name === 'cert/deploy_form') {
     $base['typeList'] = \app\lib\DeployHelper::getList();
     $base['accounts'] = [1 => ['id' => 1, 'type' => 'btpanel', 'name' => '示例部署账户']];
 }
 if ($name === 'domain/domain_add') $base['accounts'] = [1 => '示例账户'];
 if ($name === 'cert/cname' || $name === 'optimizeip/opipform') $base['domains'] = [1 => 'example.com'];
 if ($name === 'user/user') $base['domains'] = ['example.com'];
 if ($name === 'cert/account_form') {
     $base['typeList'] = \app\lib\CertHelper::getList();
 }
 if (str_starts_with($name, 'apikey/')) {
     foreach ($base['scopes'] as $key => &$scope) $scope += ['scope' => $key, 'chip' => '', 'nodomain' => false];
     unset($scope);
     $base['domains'] = ['example.com'];
 }
 if ($name === 'apikey/doc') {
     $base['groups'] = \app\lib\ApiDoc::groups();
     $total = 0;
     foreach ($base['groups'] as &$group) {
         foreach ($group['items'] as &$item) {
             $scope = $item[1];
             $item = [
                 'path' => $item[0], 'query' => $item[6] ?? '', 'scope' => $scope,
                 'scope_name' => \app\lib\ApiKeyHelper::$scopes[$scope]['name'] ?? $scope,
                 'chip' => $scope === 'cert:download' ? 'is-danger' : (str_ends_with($scope, ':write') ? 'is-write' : ''),
                 'summary' => $item[2], 'params' => $item[3], 'returns' => $item[4] ?? '',
                 'origin' => ($item[5] ?? null) === 'origin',
                 'curl' => 'curl -X POST "http://dnsmgr.fixture' . str_replace('{id}', '1', $item[0]) . '"',
                 'anchor' => 'api-' . (++$total),
             ];
         }
         unset($item);
     }
     unset($group);
     $base['total'] = $total;
 }
 if ($name === 'dispatch_jump') $base += ['code' => 'error', 'msg' => '权限错误测试 fixture & <text>', 'url' => null, 'wait' => 10];
 return $base;
};
