<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2018 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------
use app\middleware\AuthApi;
use app\middleware\CheckLogin;
use app\middleware\ViewOutput;
use think\facade\Route;
use think\middleware\SessionInit;

Route::pattern([
    'id'   => '\d+',
]);

Route::any('/install', 'install/index')
->middleware(ViewOutput::class);

Route::any('/login', 'auth/login')->middleware(SessionInit::class)
->middleware(ViewOutput::class);
Route::get('/verifycode', 'auth/verifycode')->middleware(SessionInit::class);
Route::post('/auth/totp', 'auth/totp')->middleware(SessionInit::class);
Route::post('/logout', 'auth/logout')->middleware(SessionInit::class);
Route::any('/quicklogin', 'auth/quicklogin');
Route::any('/dmtask/status', 'dmonitor/status');
Route::any('/optimizeip/status', 'optimizeip/status');
Route::get('/cron', 'system/cron');
Route::any('/qqbot/webhook', 'qqbot/webhook');

Route::group(function () {
    Route::any('/', 'index/index');
    Route::post('/changeskin', 'index/changeskin');
    Route::post('/cleancache', 'index/cleancache');
    Route::any('/setpwd', 'index/setpwd');
    Route::post('/totp/:action', 'index/totp');
    Route::get('/test', 'index/test');

    Route::post('/user/data', 'user/user_data');
    Route::post('/user/op', 'user/user_op');
    Route::get('/user', 'user/user');
    
    Route::post('/log/data', 'user/log_data');
    Route::get('/log', 'user/log');

    Route::post('/account/data', 'domain/account_data');
    Route::post('/account/:action', 'domain/account_op');
    Route::get('/account/:action', 'domain/account_add');
    Route::get('/account', 'domain/account');

    Route::get('/cloudflare/hostnames/:id', 'cloudflare/hostnames');
    Route::post('/cloudflare/hostnames/data/:id', 'cloudflare/hostnames_data');
    Route::post('/cloudflare/hostnames/add/:id', 'cloudflare/hostnames_add');
    Route::post('/cloudflare/hostnames/update/:id', 'cloudflare/hostnames_update');
    Route::post('/cloudflare/hostnames/delete/:id', 'cloudflare/hostnames_delete');
    Route::post('/cloudflare/hostnames/refresh/:id', 'cloudflare/hostnames_refresh');
    Route::post('/cloudflare/hostnames/txttargets/:id', 'cloudflare/hostnames_txt_targets');
    Route::post('/cloudflare/hostnames/batch_add/:id', 'cloudflare/hostnames_batch_add');
    Route::post('/cloudflare/hostnames/batch_delete/:id', 'cloudflare/hostnames_batch_delete');
    Route::post('/cloudflare/hostnames/batch_update/:id', 'cloudflare/hostnames_batch_update');
    Route::post('/cloudflare/fallback/get/:id', 'cloudflare/fallback_get');
    Route::post('/cloudflare/fallback/set/:id', 'cloudflare/fallback_set');
    Route::post('/cloudflare/fallback/delete/:id', 'cloudflare/fallback_delete');
    Route::post('/cloudflare/dcv_delegation_uuid/:id', 'cloudflare/dcv_delegation_uuid');
    Route::post('/cloudflare/get_domain_default_line', 'cloudflare/get_domain_default_line');
    Route::get('/cloudflare/tunnels/:id', 'cloudflare/tunnels');
    Route::post('/cloudflare/tunnels/data/:id', 'cloudflare/tunnels_data');
    Route::post('/cloudflare/tunnels/add/:id', 'cloudflare/tunnels_add');
    Route::post('/cloudflare/tunnels/delete/:id', 'cloudflare/tunnels_delete');
    Route::post('/cloudflare/tunnels/token/:id', 'cloudflare/tunnels_token');
    Route::post('/cloudflare/tunnels/publichostnames/data/:id', 'cloudflare/tunnels_public_hostnames_data');
    Route::post('/cloudflare/tunnels/publichostnames/save/:id', 'cloudflare/tunnels_public_hostnames_save');
    Route::post('/cloudflare/tunnels/publichostnames/delete/:id', 'cloudflare/tunnels_public_hostnames_delete');
    Route::post('/cloudflare/tunnels/cidr/data/:id', 'cloudflare/tunnels_cidr_data');
    Route::post('/cloudflare/tunnels/cidr/add/:id', 'cloudflare/tunnels_cidr_add');
    Route::post('/cloudflare/tunnels/cidr/delete/:id', 'cloudflare/tunnels_cidr_delete');
    Route::post('/cloudflare/tunnels/hostnameroutes/data/:id', 'cloudflare/tunnels_hostname_routes_data');
    Route::post('/cloudflare/tunnels/hostnameroutes/add/:id', 'cloudflare/tunnels_hostname_routes_add');
    Route::post('/cloudflare/tunnels/hostnameroutes/delete/:id', 'cloudflare/tunnels_hostname_routes_delete');

    Route::any('/domain/expirenotice', 'domain/expire_notice');
    Route::post('/domain/updatedate', 'domain/update_date');
    Route::post('/domain/check', 'domain/domain_check');
    Route::post('/domain/data', 'domain/domain_data');
    Route::post('/domain/op', 'domain/domain_op');
    Route::post('/domain/list', 'domain/domain_list');
    Route::any('/domain/dnscheck', 'domain/dnscheck');
    Route::post('/domain/category/data', 'domain/category_data');
    Route::post('/domain/category/:action', 'domain/category_op');
    Route::get('/domain/category/list', 'domain/category_list');
    Route::post('/domain/setcategory', 'domain/domain_set_category');
    Route::get('/domain/add', 'domain/domain_add');
    Route::get('/domain/category', 'domain/category');
    Route::get('/domain', 'domain/domain');

    Route::post('/record/data/:id', 'domain/record_data');
    Route::post('/record/add/:id', 'domain/record_add');
    Route::post('/record/update/:id', 'domain/record_update');
    Route::post('/record/delete/:id', 'domain/record_delete');
    Route::post('/record/status/:id', 'domain/record_status');
    Route::post('/record/remark/:id', 'domain/record_remark');
    Route::post('/record/check/:id', 'domain/record_check');
    Route::post('/record/batch/:id', 'domain/record_batch');
    Route::post('/record/batchedit/:id', 'domain/record_batch_edit');
    Route::any('/record/batchadd/:id', 'domain/record_batch_add');
    Route::get('/record/batchadd', 'domain/record_batch_add2');
    Route::any('/record/batchedit', 'domain/record_batch_edit2');
    Route::any('/record/log/:id', 'domain/record_log');
    Route::post('/record/groups/:id', 'domain/record_groups');
    Route::post('/record/list', 'domain/record_list');
    Route::post('/record/weight/data/:id', 'domain/weight_data');
    Route::any('/record/weight/:id', 'domain/weight');
    Route::any('/record/alias/:id', 'domain/alias');
    Route::get('/record/import/:id', 'domain/record_import');
    Route::get('/record/search', 'domain/record_search');
    Route::get('/record/:id', 'domain/record');
    Route::get('/record/smartparse', 'domain/smartparse');
    Route::post('/record/quickinfo/:id', 'domain/quickinfo');
    Route::get('/record/history/:id', 'domain/record_history');
    Route::post('/record/history/data/:id', 'domain/record_history_data');
    Route::post('/record/snapshot/:id', 'domain/record_snapshot');
    Route::post('/record/preview/:id', 'domain/record_preview');
    Route::post('/record/apply/:id', 'domain/record_apply');
    Route::post('/record/rollback/preview/:id', 'domain/record_rollback_preview');
    Route::get('/batch', 'batch/index');
    Route::post('/batch/data', 'batch/data');
    Route::post('/batch/details', 'batch/details');
    Route::post('/batch/enqueue', 'batch/enqueue');
    Route::post('/batch/task/:action', 'batch/operation');

    Route::get('/dmonitor/overview', 'dmonitor/overview');
    Route::post('/dmonitor/task/data', 'dmonitor/task_data');
    Route::post('/dmonitor/task/log/data/:id', 'dmonitor/tasklog_data');
    Route::get('/dmonitor/task/info/:id', 'dmonitor/taskinfo');
    Route::post('/dmonitor/task/:action', 'dmonitor/task_op');
    Route::get('/dmonitor/task/:action', 'dmonitor/taskform');
    Route::get('/dmonitor/task', 'dmonitor/task');
    Route::post('/dmonitor/clean', 'dmonitor/clean');

    Route::any('/optimizeip/opipset', 'optimizeip/opipset');
    Route::post('/optimizeip/queryapi', 'optimizeip/queryapi');
    Route::post('/optimizeip/opiplist/data', 'optimizeip/opiplist_data');
    Route::get('/optimizeip/opiplist', 'optimizeip/opiplist');
    Route::any('/optimizeip/opipform/:action', 'optimizeip/opipform');

    Route::get('/cert/certaccount', 'cert/certaccount');
    Route::get('/cert/deployaccount', 'cert/deployaccount');
    Route::post('/cert/account/data', 'cert/account_data');
    Route::post('/cert/account/:action', 'cert/account_op');
    Route::get('/cert/account/:action', 'cert/account_form');

    Route::get('/cert/certorder', 'cert/certorder');
    Route::post('/cert/order/data', 'cert/order_data');
    Route::post('/cert/order/process', 'cert/order_process');
    Route::post('/cert/order/:action', 'cert/order_op');
    Route::get('/cert/order/:action', 'cert/order_form');

    Route::get('/cert/deploytask', 'cert/deploytask');
    Route::post('/cert/deploy/data', 'cert/deploy_data');
    Route::post('/cert/deploy/process', 'cert/deploy_process');
    Route::post('/cert/deploy/:action', 'cert/deploy_op');
    Route::get('/cert/deploy/:action', 'cert/deploy_form');

    Route::get('/cert/cname', 'cert/cname');
    Route::post('/cert/cname/data', 'cert/cname_data');
    Route::post('/cert/cname/:action', 'cert/cname_op');
    
    Route::get('/cert/certset', 'cert/certset');

    Route::post('/schedule/stask/data', 'schedule/stask_data');
    Route::post('/schedule/stask/:action', 'schedule/stask_op');
    Route::get('/schedule/stask/:action', 'schedule/staskform');
    Route::get('/schedule/stask', 'schedule/stask');

    Route::get('/system/loginset', 'system/loginset');
    Route::get('/system/noticeset', 'system/noticeset');
    Route::get('/system/proxyset', 'system/proxyset');
    Route::post('/system/set', 'system/set');
    Route::post('/system/mailtest', 'system/mailtest');
    Route::post('/system/tgbottest', 'system/tgbottest');
    Route::post('/system/webhooktest', 'system/webhooktest');
    Route::post('/system/customwebhooktest', 'system/customwebhooktest');
    Route::post('/system/proxytest', 'system/proxytest');
    Route::get('/system/cronset', 'system/cronset');

    Route::get('/apikey/doc', 'apikey/doc');
    Route::post('/apikey/data', 'apikey/data');
    Route::post('/apikey/:action', 'apikey/op');
    Route::get('/apikey', 'apikey/index');

    Route::post('/audit/data', 'audit/data');
    Route::get('/audit/export', 'audit/export');
    Route::post('/audit/clean', 'audit/clean');
    Route::post('/audit/setting', 'audit/setting');
    Route::get('/audit', 'audit/index');

})->middleware(CheckLogin::class)
->middleware(ViewOutput::class);

// ======================= API接口 =======================
// 鉴权方式：用户API（uid + timestamp + sign）或 后台创建的API密钥（keyid + timestamp + sign，按权限范围控制）
// option scope：调用该接口需要的API密钥权限，可按 action / act 参数区分（* 为默认）
Route::group('api', function () {
    // 域名
    Route::post('/domain/list', 'domain/domain_list')->option(['scope' => 'domain:write']);
    Route::post('/domain/op', 'domain/domain_op')->option(['scope' => ['get' => 'domain:read', '*' => 'domain:write']]);
    Route::post('/domain/updatedate', 'domain/update_date')->option(['scope' => 'domain:write']);
    Route::post('/domain/setcategory', 'domain/domain_set_category')->option(['scope' => 'domain:write']);
    Route::post('/domain/category/list', 'domain/category_list')->option(['scope' => 'domain:read']);
    Route::post('/domain/category/data', 'domain/category_data')->option(['scope' => 'domain:read']);
    Route::post('/domain/category/:action', 'domain/category_op')->option(['scope' => 'domain:write']);
    Route::post('/domain/:id', 'domain/domain_info')->option(['scope' => 'domain:read']);
    Route::post('/domain', 'domain/domain_data')->option(['scope' => 'domain:read']);
    Route::post('/account/data', 'domain/account_data')->option(['scope' => 'domain:read']);

    // 解析记录
    Route::post('/record/data/:id', 'domain/record_data')->option(['scope' => 'record:read']);
    Route::post('/record/list', 'domain/record_list')->option(['scope' => 'record:read']);
    Route::post('/record/quickinfo/:id', 'domain/quickinfo')->option(['scope' => 'record:read']);
    Route::post('/record/groups/:id', 'domain/record_groups')->option(['scope' => 'record:read']);
    Route::post('/record/check/:id', 'domain/record_check')->option(['scope' => 'record:read']);
    Route::post('/record/log/:id', 'domain/record_log')->option(['scope' => 'record:read']);
    Route::post('/record/add/:id', 'domain/record_add')->option(['scope' => 'record:write']);
    Route::post('/record/update/:id', 'domain/record_update')->option(['scope' => 'record:write']);
    Route::post('/record/delete/:id', 'domain/record_delete')->option(['scope' => 'record:write']);
    Route::post('/record/status/:id', 'domain/record_status')->option(['scope' => 'record:write']);
    Route::post('/record/remark/:id', 'domain/record_remark')->option(['scope' => 'record:write']);
    Route::post('/record/batch/:id', 'domain/record_batch')->option(['scope' => 'record:write']);
    Route::post('/record/batchedit/:id', 'domain/record_batch_edit')->option(['scope' => 'record:write']);
    Route::post('/record/batchadd/:id', 'domain/record_batch_add')->option(['scope' => 'record:write']);
    Route::post('/record/batchedit2', 'domain/record_batch_edit2')->option(['scope' => 'record:write']);
    Route::post('/record/weight/data/:id', 'domain/weight_data')->option(['scope' => 'record:read']);
    Route::post('/record/weight/:id', 'domain/weight')->option(['scope' => 'record:write']);
    Route::post('/record/alias/:id', 'domain/alias')->option(['scope' => ['list' => 'domain:read', '*' => 'domain:write']]);

    // Cloudflare 增强（SaaS自定义主机名 / Tunnel）
    Route::post('/record/history/data/:id', 'domain/record_history_data')->option(['scope' => 'record:read']);
    Route::post('/record/snapshot/:id', 'domain/record_snapshot')->option(['scope' => 'record:write']);
    Route::post('/record/preview/:id', 'domain/record_preview')->option(['scope' => 'record:read']);
    Route::post('/record/apply/:id', 'domain/record_apply')->option(['scope' => 'record:write']);
    Route::post('/record/rollback/preview/:id', 'domain/record_rollback_preview')->option(['scope' => 'record:write']);
    Route::post('/batch/data', 'batch/data')->option(['scope' => 'record:read']);
    Route::post('/batch/details', 'batch/details')->option(['scope' => 'record:read']);
    Route::post('/batch/enqueue', 'batch/enqueue')->option(['scope' => 'record:write']);
    Route::post('/batch/task/:action', 'batch/operation')->option(['scope' => 'record:write']);

    Route::post('/cloudflare/hostnames/data/:id', 'cloudflare/hostnames_data')->option(['scope' => 'cloudflare:read']);
    Route::post('/cloudflare/hostnames/txttargets/:id', 'cloudflare/hostnames_txt_targets')->option(['scope' => 'cloudflare:read']);
    Route::post('/cloudflare/hostnames/add/:id', 'cloudflare/hostnames_add')->option(['scope' => 'cloudflare:write']);
    Route::post('/cloudflare/hostnames/update/:id', 'cloudflare/hostnames_update')->option(['scope' => 'cloudflare:write']);
    Route::post('/cloudflare/hostnames/delete/:id', 'cloudflare/hostnames_delete')->option(['scope' => 'cloudflare:write']);
    Route::post('/cloudflare/hostnames/refresh/:id', 'cloudflare/hostnames_refresh')->option(['scope' => 'cloudflare:write']);
    Route::post('/cloudflare/hostnames/batch_add/:id', 'cloudflare/hostnames_batch_add')->option(['scope' => 'cloudflare:write']);
    Route::post('/cloudflare/hostnames/batch_delete/:id', 'cloudflare/hostnames_batch_delete')->option(['scope' => 'cloudflare:write']);
    Route::post('/cloudflare/hostnames/batch_update/:id', 'cloudflare/hostnames_batch_update')->option(['scope' => 'cloudflare:write']);
    Route::post('/cloudflare/fallback/get/:id', 'cloudflare/fallback_get')->option(['scope' => 'cloudflare:read']);
    Route::post('/cloudflare/fallback/set/:id', 'cloudflare/fallback_set')->option(['scope' => 'cloudflare:write']);
    Route::post('/cloudflare/fallback/delete/:id', 'cloudflare/fallback_delete')->option(['scope' => 'cloudflare:write']);
    Route::post('/cloudflare/dcv_delegation_uuid/:id', 'cloudflare/dcv_delegation_uuid')->option(['scope' => 'cloudflare:read']);
    Route::post('/cloudflare/tunnels/data/:id', 'cloudflare/tunnels_data')->option(['scope' => 'cloudflare:read']);
    Route::post('/cloudflare/tunnels/add/:id', 'cloudflare/tunnels_add')->option(['scope' => 'cloudflare:write']);
    Route::post('/cloudflare/tunnels/delete/:id', 'cloudflare/tunnels_delete')->option(['scope' => 'cloudflare:write']);
    Route::post('/cloudflare/tunnels/publichostnames/data/:id', 'cloudflare/tunnels_public_hostnames_data')->option(['scope' => 'cloudflare:read']);
    Route::post('/cloudflare/tunnels/publichostnames/save/:id', 'cloudflare/tunnels_public_hostnames_save')->option(['scope' => 'cloudflare:write']);
    Route::post('/cloudflare/tunnels/publichostnames/delete/:id', 'cloudflare/tunnels_public_hostnames_delete')->option(['scope' => 'cloudflare:write']);
    Route::post('/cloudflare/tunnels/cidr/data/:id', 'cloudflare/tunnels_cidr_data')->option(['scope' => 'cloudflare:read']);
    Route::post('/cloudflare/tunnels/cidr/add/:id', 'cloudflare/tunnels_cidr_add')->option(['scope' => 'cloudflare:write']);
    Route::post('/cloudflare/tunnels/cidr/delete/:id', 'cloudflare/tunnels_cidr_delete')->option(['scope' => 'cloudflare:write']);
    Route::post('/cloudflare/tunnels/hostnameroutes/data/:id', 'cloudflare/tunnels_hostname_routes_data')->option(['scope' => 'cloudflare:read']);
    Route::post('/cloudflare/tunnels/hostnameroutes/add/:id', 'cloudflare/tunnels_hostname_routes_add')->option(['scope' => 'cloudflare:write']);
    Route::post('/cloudflare/tunnels/hostnameroutes/delete/:id', 'cloudflare/tunnels_hostname_routes_delete')->option(['scope' => 'cloudflare:write']);

    // SSL证书
    Route::post('/cert/order', 'cert/order_info')->option(['scope' => 'cert:download']);
    Route::post('/cert/order/data', 'cert/order_data')->option(['scope' => 'cert:read']);
    Route::post('/cert/order/process', 'cert/order_process')->option(['scope' => 'cert:write']);
    Route::post('/cert/order/:action', 'cert/order_op')->option(['scope' => ['get' => 'cert:download', 'show_log' => 'cert:read', '*' => 'cert:write']]);
    Route::post('/cert/account/data', 'cert/account_data')->option(['scope' => ['deploy=1' => 'deploy:read', '*' => 'cert:read']]);
    Route::post('/cert/cname/data', 'cert/cname_data')->option(['scope' => 'cert:read']);
    Route::post('/cert/cname/:action', 'cert/cname_op')->option(['scope' => ['check' => 'cert:read', '*' => 'cert:write']]);

    // 证书部署（推送）
    Route::post('/cert/deploy/data', 'cert/deploy_data')->option(['scope' => 'deploy:read']);
    Route::post('/cert/deploy/process', 'cert/deploy_process')->option(['scope' => 'deploy:write']);
    Route::post('/cert/deploy/:action', 'cert/deploy_op')->option(['scope' => ['show_log' => 'deploy:read', '*' => 'deploy:write']]);

    // 容灾切换
    Route::post('/dmonitor/task/data', 'dmonitor/task_data')->option(['scope' => 'monitor:read']);
    Route::post('/dmonitor/task/log/data/:id', 'dmonitor/tasklog_data')->option(['scope' => 'monitor:read']);
    Route::post('/dmonitor/task/:action', 'dmonitor/task_op')->option(['scope' => 'monitor:write']);

    // 定时切换
    Route::post('/schedule/stask/data', 'schedule/stask_data')->option(['scope' => 'schedule:read']);
    Route::post('/schedule/stask/:action', 'schedule/stask_op')->option(['scope' => 'schedule:write']);

    // CF优选IP
    Route::post('/optimizeip/opiplist/data', 'optimizeip/opiplist_data')->option(['scope' => 'optimize:read']);
    Route::post('/optimizeip/opipform/:action', 'optimizeip/opipform')->option(['scope' => 'optimize:write']);

    // 操作日志与系统概览
    Route::post('/log/data', 'user/log_data')->option(['scope' => 'log:read']);
    Route::post('/stat', 'index/index')->option(['scope' => 'system:read']);

    Route::miss(function () {
        return json(['code' => -1, 'msg' => '接口不存在，API接口均为POST请求'])->code(404);
    });
})->completeMatch()->middleware(AuthApi::class);

Route::miss(function() {
    return response('404 Not Found')->code(404);
});
