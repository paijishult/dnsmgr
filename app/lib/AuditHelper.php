<?php

namespace app\lib;

use think\facade\Cache;
use think\facade\Db;
use think\Response;

/**
 * 审计日志
 *
 * 记录后台的修改操作和所有API调用（含认证失败），用于事后审计：
 * 谁（用户 / API密钥）、什么时候、从哪个IP、用什么客户端、调用了哪个接口、传了什么参数（已脱敏）、结果如何、耗时多少。
 * 参数中的密码、密钥、令牌、证书私钥等字段一律脱敏，账户里保存的密钥值也会在日志和API返回中被替换掉。
 */
class AuditHelper
{
    const RESULT_FAIL = 0;
    const RESULT_SUCCESS = 1;
    const RESULT_DENIED = 2;

    /**
     * 操作名称（控制器/方法 => 名称，或 子操作 => 名称）
     */
    private static $names = [
        'auth/login' => '登录后台',
        'auth/totp' => '登录动态口令验证',
        'auth/quicklogin' => '域名快捷登录',
        'auth/logout' => '退出登录',
        'index/index' => '查看系统概览',
        'index/setpwd' => '修改密码',
        'index/totp' => ['generate' => '生成TOTP密钥', 'bind' => '开启TOTP二次验证', 'close' => '关闭TOTP二次验证', '*' => 'TOTP设置'],
        'index/changeskin' => '切换后台皮肤',
        'index/cleancache' => '清理缓存',
        'user/user_op' => ['get' => '查看用户', 'add' => '添加用户', 'edit' => '修改用户', 'set' => '启用/封禁用户', 'del' => '删除用户', '*' => '用户管理'],
        'user/log_data' => '查看操作日志',
        'user/user_data' => '查看用户列表',
        'domain/account_op' => ['add' => '添加DNS账户', 'edit' => '修改DNS账户', 'del' => '删除DNS账户', '*' => 'DNS账户管理'],
        'domain/account_data' => '查看DNS账户列表',
        'domain/domain_op' => ['get' => '查看域名', 'add' => '添加域名', 'edit' => '修改域名设置', 'del' => '删除域名', 'batchadd' => '批量添加域名', 'batchedit' => '批量修改域名备注', 'batchsetnotice' => '批量设置到期提醒', 'batchdel' => '批量删除域名', 'updateexpire' => '刷新域名到期时间', '*' => '域名管理'],
        'domain/domain_data' => '查看域名列表',
        'domain/domain_info' => '查看域名详情',
        'domain/domain_list' => '查询DNS账户下的域名',
        'domain/update_date' => '查询域名注册/到期时间',
        'domain/expire_notice' => '域名到期提醒设置',
        'domain/category_op' => ['add' => '添加域名分类', 'edit' => '修改域名分类', 'del' => '删除域名分类', '*' => '域名分类管理'],
        'domain/category_data' => '查看域名分类',
        'domain/category_list' => '查看域名分类',
        'domain/domain_set_category' => '批量设置域名分类',
        'domain/record_data' => '查看解析记录',
        'domain/record_list' => '按主机记录查询解析',
        'domain/record_add' => '添加解析记录',
        'domain/record_update' => '修改解析记录',
        'domain/record_delete' => '删除解析记录',
        'domain/record_status' => '启用/暂停解析记录',
        'domain/record_remark' => '修改解析备注',
        'domain/record_check' => '检测解析是否生效',
        'domain/record_batch' => ['open' => '批量启用解析', 'pause' => '批量暂停解析', 'delete' => '批量删除解析', 'remark' => '批量修改解析备注', 'group' => '批量修改解析分组', '*' => '批量操作解析'],
        'domain/record_batch_edit' => ['value' => '批量修改解析记录值', 'line' => '批量修改解析线路', '*' => '批量修改解析'],
        'domain/record_batch_add' => '批量添加解析',
        'domain/record_batch_edit2' => '按主机记录修改解析',
        'domain/record_log' => '查看DNS平台解析日志',
        'domain/record_groups' => '查看解析分组',
        'domain/quickinfo' => '查看解析线路',
        'domain/weight' => ['status' => '开关解析权重', 'update' => '修改解析权重', '*' => '设置解析权重'],
        'domain/weight_data' => '查看解析权重',
        'domain/alias' => ['list' => '查看域名别名', 'add' => '添加域名别名', 'delete' => '删除域名别名', '*' => '域名别名'],
        'cert/account_op' => ['add' => '添加证书/部署账户', 'edit' => '修改证书/部署账户', 'del' => '删除证书/部署账户', 'check' => '测试证书/部署账户', 'test' => '测试证书/部署账户配置', '*' => '证书/部署账户管理'],
        'cert/account_data' => '查看证书/部署账户列表',
        'cert/order_data' => '查看证书订单',
        'cert/order_info' => '下载证书和私钥',
        'cert/order_process' => '执行证书申请',
        'cert/order_op' => ['get' => '下载证书和私钥', 'add' => '添加证书订单', 'edit' => '修改证书订单', 'del' => '删除证书订单', 'setauto' => '设置自动续签', 'reset' => '重置证书订单', 'revoke' => '吊销证书', 'operation' => '批量操作证书订单', 'show_log' => '查看证书申请日志', '*' => '证书订单管理'],
        'cert/deploy_data' => '查看部署任务',
        'cert/deploy_process' => '执行证书部署',
        'cert/deploy_op' => ['add' => '添加部署任务', 'edit' => '修改部署任务', 'del' => '删除部署任务', 'setactive' => '启用/停用部署任务', 'reset' => '重置部署任务', 'operation' => '批量操作部署任务', 'show_log' => '查看部署日志', '*' => '部署任务管理'],
        'cert/cname_data' => '查看CNAME代理',
        'cert/cname_op' => ['add' => '添加CNAME代理', 'edit' => '修改CNAME代理', 'del' => '删除CNAME代理', 'check' => '检测CNAME代理', '*' => 'CNAME代理管理'],
        'dmonitor/task_data' => '查看容灾策略',
        'dmonitor/tasklog_data' => '查看容灾切换日志',
        'dmonitor/task_op' => ['add' => '添加容灾策略', 'edit' => '修改容灾策略', 'del' => '删除容灾策略', 'setactive' => '启用/停用容灾策略', 'operation' => '批量操作容灾策略', '*' => '容灾策略管理'],
        'dmonitor/clean' => '清理容灾日志',
        'schedule/stask_data' => '查看定时切换任务',
        'schedule/stask_op' => ['add' => '添加定时切换任务', 'edit' => '修改定时切换任务', 'del' => '删除定时切换任务', 'setactive' => '启用/停用定时切换任务', 'operation' => '批量操作定时切换任务', '*' => '定时切换管理'],
        'optimizeip/opiplist_data' => '查看优选IP任务',
        'optimizeip/opipform' => ['add' => '添加优选IP任务', 'edit' => '修改优选IP任务', 'del' => '删除优选IP任务', 'setactive' => '启用/停用优选IP任务', 'run' => '立即执行优选IP任务', '*' => '优选IP任务管理'],
        'optimizeip/opipset' => '修改优选IP设置',
        'optimizeip/queryapi' => '查询优选IP接口',
        'system/set' => '修改系统设置',
        'system/proxytest' => '测试代理',
        'cloudflare/hostnames_data' => '查看CF自定义主机名',
        'cloudflare/hostnames_add' => '添加CF自定义主机名',
        'cloudflare/hostnames_update' => '修改CF自定义主机名',
        'cloudflare/hostnames_delete' => '删除CF自定义主机名',
        'cloudflare/hostnames_refresh' => '刷新CF自定义主机名',
        'cloudflare/hostnames_txt_targets' => '查询CF验证记录目标',
        'cloudflare/hostnames_batch_add' => '批量添加CF自定义主机名',
        'cloudflare/hostnames_batch_delete' => '批量删除CF自定义主机名',
        'cloudflare/hostnames_batch_update' => '批量修改CF自定义主机名',
        'cloudflare/fallback_get' => '查看CF回退源',
        'cloudflare/fallback_set' => '设置CF回退源',
        'cloudflare/fallback_delete' => '删除CF回退源',
        'cloudflare/dcv_delegation_uuid' => '查看CF DCV委派',
        'cloudflare/get_domain_default_line' => '查询默认线路',
        'cloudflare/tunnels_data' => '查看CF Tunnel',
        'cloudflare/tunnels_add' => '创建CF Tunnel',
        'cloudflare/tunnels_delete' => '删除CF Tunnel',
        'cloudflare/tunnels_token' => '查看CF Tunnel令牌',
        'cloudflare/tunnels_public_hostnames_data' => '查看Tunnel公网主机名',
        'cloudflare/tunnels_public_hostnames_save' => '配置Tunnel公网主机名',
        'cloudflare/tunnels_public_hostnames_delete' => '删除Tunnel公网主机名',
        'cloudflare/tunnels_cidr_data' => '查看Tunnel CIDR路由',
        'cloudflare/tunnels_cidr_add' => '添加Tunnel CIDR路由',
        'cloudflare/tunnels_cidr_delete' => '删除Tunnel CIDR路由',
        'cloudflare/tunnels_hostname_routes_data' => '查看Tunnel主机名路由',
        'cloudflare/tunnels_hostname_routes_add' => '添加Tunnel主机名路由',
        'cloudflare/tunnels_hostname_routes_delete' => '删除Tunnel主机名路由',
        'apikey/op' => ['add' => '创建API密钥', 'edit' => '修改API密钥', 'reset' => '重置API密钥', 'status' => '启用/禁用API密钥', 'del' => '删除API密钥', 'setting' => '修改API设置', '*' => 'API密钥管理'],
        'apikey/data' => '查看API密钥列表',
        'audit/data' => '查看审计日志',
        'audit/export' => '导出审计日志',
        'audit/clean' => '清理审计日志',
        'audit/setting' => '修改审计设置',
    ];

    /**
     * 只读操作（后台默认不记录，API在“只记录修改操作”模式下不记录）
     */
    private static $readActions = [
        'index/index', 'user/log_data', 'domain/account_data', 'domain/domain_data', 'domain/domain_info', 'domain/domain_list',
        'domain/update_date', 'domain/category_data', 'domain/category_list', 'domain/record_data', 'domain/record_list',
        'domain/record_check', 'domain/record_log', 'domain/record_groups', 'domain/quickinfo', 'domain/weight_data',
        'cert/account_data', 'cert/order_data', 'cert/deploy_data', 'cert/cname_data', 'dmonitor/task_data', 'dmonitor/tasklog_data',
        'schedule/stask_data', 'optimizeip/opiplist_data', 'optimizeip/queryapi', 'cloudflare/hostnames_data', 'cloudflare/hostnames_txt_targets',
        'cloudflare/fallback_get', 'cloudflare/dcv_delegation_uuid', 'cloudflare/get_domain_default_line', 'cloudflare/tunnels_data',
        'cloudflare/tunnels_public_hostnames_data', 'cloudflare/tunnels_cidr_data', 'cloudflare/tunnels_hostname_routes_data',
        'apikey/data', 'audit/data', 'system/proxytest', 'domain/dnscheck',
    ];
    private static $readSubActions = ['get', 'list', 'show_log', 'check', 'test', 'generate'];

    /**
     * 敏感的读取操作（总是记录）
     */
    private static $sensitive = ['cert/order_info', 'cert/order_op:get', 'cloudflare/tunnels_token', 'audit/export'];

    /**
     * 记录一次请求（由 AuditLog 中间件调用）
     */
    public static function record($request, Response $response, $start)
    {
        if (empty(config('sys.sys_key'))) return;
        $path = '/' . ltrim($request->pathinfo(), '/');
        $isApi = str_starts_with($path, '/api/') || $path === '/api';
        $method = $request->method();
        $controller = strtolower((string)$request->controller());
        $action = strtolower((string)$request->action());
        $key = $controller . '/' . $action;
        $sub = self::subAction($request, $key);
        $status = $response->getCode();

        if ($isApi) {
            $mode = config_get('api_calllog', 'all');
            if ($mode === 'off') return;
        } else {
            if (config_get('audit_web', '1') != '1') return;
            $alwaysGet = in_array($key, ['auth/quicklogin', 'auth/logout', 'index/cleancache', 'audit/export']);
            if ($method !== 'POST' && !$alwaysGet) return;
            if ($controller === '' || $controller === 'install') return;
            if ($controller !== 'auth' && empty($request->islogin)) return;
        }

        $isRead = self::isRead($key, $sub, $request);
        $isSensitive = in_array($key, self::$sensitive) || in_array($key . ':' . $sub, self::$sensitive);

        // 解析结果
        $data = null;
        if ($response instanceof \think\response\Json) {
            $data = $response->getData();
        }
        $code = is_array($data) && isset($data['code']) && is_numeric($data['code']) ? intval($data['code']) : null;
        $msg = '';
        if (is_array($data)) {
            if (isset($data['msg']) && is_scalar($data['msg'])) {
                $msg = (string)$data['msg'];
            } elseif (isset($data['total']) && isset($data['rows']) && is_array($data['rows'])) {
                $msg = '返回' . count($data['rows']) . '条，共' . intval($data['total']) . '条';
            }
        } elseif ($status >= 300 && $status < 400) {
            $msg = '跳转 ' . $response->getHeader('Location');
        }
        $denied = $isApi && !empty($request->auditApiDenied);
        if ($denied) {
            $result = self::RESULT_DENIED;
        } elseif ($status >= 400 || ($code !== null && $code < 0)) {
            $result = self::RESULT_FAIL;
        } else {
            $result = self::RESULT_SUCCESS;
        }

        // 只读操作：后台不记录；API在“只记录修改操作”模式下只记录失败/拒绝的只读请求
        if ($isRead && !$isSensitive) {
            if (!$isApi) return;
            if (config_get('api_calllog', 'all') === 'write' && $result === self::RESULT_SUCCESS) return;
        }

        // 认证失败被封禁后的重复请求，每个IP每分钟只记录一次，防止刷日志
        $ip = $isApi ? ApiKeyHelper::clientIp() : real_ip(env('app.ip_type', 0));
        if ($isApi && $status == 429 && str_contains($msg, '认证失败次数过多')) {
            $flood = 'auditban_' . md5((string)$ip);
            if (Cache::get($flood)) return;
            Cache::set($flood, 1, 60);
        }

        $query = $request->get();
        unset($query['s']);
        $query = $query ? self::sanitize($query) : [];
        $row = [
            'source' => $isApi ? 'api' : 'web',
            'uid' => 0,
            'username' => null,
            'kid' => 0,
            'keyid' => null,
            'kname' => null,
            'ip' => mb_substr((string)$ip, 0, 64),
            'ua' => mb_substr((string)$request->header('user-agent', ''), 0, 255),
            'method' => $method,
            'path' => mb_substr($path . ($query ? '?' . urldecode(http_build_query($query)) : ''), 0, 255),
            'name' => mb_substr(self::actionName($key, $sub, $request, $isApi), 0, 64),
            'target' => null,
            'params' => null,
            'status' => $status,
            'result' => $result,
            'code' => $code,
            'msg' => null,
            'duration' => max(0, intval((microtime(true) - $start) * 1000)),
            'addtime' => date('Y-m-d H:i:s'),
        ];

        // 操作者
        if ($isApi) {
            if (!empty($request->apiKey)) {
                $row['kid'] = intval($request->apiKey['id']);
                $row['keyid'] = $request->apiKey['keyid'];
                $row['kname'] = $request->apiKey['name'];
                $row['uid'] = intval($request->user['id'] ?? 0);
                $row['username'] = $request->user['username'] ?? null;
            } elseif (!empty($request->isApi) && !empty($request->user['id'])) {
                $row['uid'] = intval($request->user['id']);
                $row['username'] = $request->user['username'] ?? null;
                $row['kname'] = '用户API';
            } else {
                // 认证未通过：记录对方声称的身份
                $keyid = $request->post('keyid');
                if (is_string($keyid) && $keyid !== '') {
                    $row['keyid'] = mb_substr($keyid, 0, 24);
                    if (preg_match('/^[0-9a-f]{16}$/', $keyid)) {
                        $k = Db::name('apikey')->where('keyid', $keyid)->field('id,name,uid')->find();
                        if ($k) {
                            $row['kid'] = intval($k['id']);
                            $row['kname'] = $k['name'];
                            $row['uid'] = intval($k['uid']);
                            $row['username'] = Db::name('user')->where('id', $row['uid'])->value('username');
                        }
                    }
                } elseif (is_numeric($request->post('uid'))) {
                    $row['uid'] = intval($request->post('uid'));
                    $row['username'] = Db::name('user')->where('id', $row['uid'])->value('username');
                    $row['kname'] = '用户API';
                }
            }
        } elseif ($controller === 'auth' && in_array($action, ['login', 'totp'])) {
            $u = $request->post('username');
            $row['username'] = is_string($u) ? mb_substr(trim($u), 0, 64) : null;
            if ($action === 'totp' && empty($row['username'])) {
                $row['username'] = $request->user['username'] ?? null;
            }
        } elseif (!empty($request->user)) {
            if (($request->user['type'] ?? '') === 'domain') {
                $row['username'] = '域名:' . ($request->user['name'] ?? '');
            } else {
                $row['uid'] = intval($request->user['id'] ?? 0);
                $row['username'] = $request->user['username'] ?? null;
            }
        }

        $secrets = self::secretValues();
        $row['target'] = self::cut(self::scrub(self::target($request, $controller, $action), $secrets), 255);
        $params = self::sanitize(array_merge($request->get(), $request->auditOriginalPost ?? $request->post()), $key);
        $row['params'] = $params ? self::cut(self::scrub(json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $secrets), 20000) : null;
        $row['msg'] = $msg !== '' ? self::cut(self::scrub($msg, $secrets), 500) : null;

        self::write($row);
    }

    private static function subAction($request, $key)
    {
        $sub = $request->route('action');
        if ($sub === null || $sub === '') {
            $sub = $request->param('act');
        }
        if (in_array($key, ['domain/record_batch', 'domain/record_batch_edit'])) {
            $sub = $request->post('action');
        }
        return is_scalar($sub) ? strtolower((string)$sub) : '';
    }

    private static function isRead($key, $sub, $request)
    {
        if (in_array($key, self::$readActions)) return true;
        if (str_ends_with($key, '_data')) return true;
        if ($sub !== '' && in_array($sub, self::$readSubActions)) return true;
        return false;
    }

    private static function actionName($key, $sub, $request, $isApi)
    {
        $name = self::$names[$key] ?? null;
        if (is_array($name)) {
            $name = $name[$sub] ?? ($name['*'] ?? null);
        }
        if ($key === 'cert/account_op' && $name) {
            $deploy = intval($request->post('deploy'));
            $name = str_replace('证书/部署账户', $deploy ? '部署账户' : '证书账户', $name);
        }
        if ($key === 'cert/account_data' && $isApi) {
            $name = intval($request->get('deploy')) ? '查看部署账户列表' : '查看证书账户列表';
        }
        if (!$name) {
            if ($key === '/') return '访问不存在的接口';
            $name = $key . ($sub !== '' ? ':' . $sub : '');
        }
        return $name;
    }

    /**
     * 操作对象：域名/主机记录/ID
     */
    private static function target($request, $controller, $action)
    {
        $parts = [];
        $id = $request->route('id');
        if ($id !== null && in_array($controller, ['domain', 'cloudflare']) && is_numeric($id)) {
            if ($controller === 'domain' || str_starts_with($action, 'hostnames') || str_starts_with($action, 'fallback') || $action === 'dcv_delegation_uuid') {
                $domain = Db::name('domain')->where('id', intval($id))->value('name');
                $parts[] = $domain ?: ('域名ID:' . $id);
            }
        }
        $post = $request->auditOriginalPost ?? $request->post();
        if ($controller === 'domain' && str_starts_with($action, 'record') && isset($post['id']) && empty($parts) && is_numeric($post['id'])) {
            $domain = Db::name('domain')->where('id', intval($post['id']))->value('name');
            if ($domain) $parts[] = $domain;
        }
        if (in_array($controller . '/' . $action, ['domain/account_op', 'cert/account_op'])) {
            $t = trim((string)(is_scalar($post['type'] ?? null) ? $post['type'] : ''));
            $r = trim((string)(is_scalar($post['remark'] ?? null) ? $post['remark'] : ''));
            if ($t !== '' || $r !== '') $parts[] = trim($t . ($r !== '' ? ' · ' . $r : ''));
        }
        if ($controller . '/' . $action === 'user/user_op' && isset($post['username']) && is_scalar($post['username'])) {
            $parts[] = '用户 ' . trim((string)$post['username']);
        }
        foreach (['name', 'domain', 'rr', 'subdomain', 'hostname'] as $k) {
            if (isset($post[$k]) && is_scalar($post[$k]) && trim((string)$post[$k]) !== '' && !in_array($controller . '/' . $action, ['domain/account_op', 'cert/account_op', 'auth/login'])) {
                $parts[] = trim((string)$post[$k]);
                break;
            }
        }
        if (isset($post['domains']) && is_array($post['domains']) && !$parts) {
            $names = array_filter(array_map(fn($v) => is_scalar($v) ? (string)$v : (is_array($v) ? (string)($v['name'] ?? '') : ''), $post['domains']));
            if ($names) $parts[] = implode(',', array_slice($names, 0, 10)) . (count($names) > 10 ? ' 等' . count($names) . '个' : '');
        }
        if (isset($post['ids'])) {
            $ids = is_array($post['ids']) ? $post['ids'] : explode(',', (string)$post['ids']);
            $ids = array_filter(array_map(fn($v) => is_scalar($v) ? (string)intval($v) : '', $ids));
            if ($ids) $parts[] = 'ID:' . implode(',', array_slice($ids, 0, 30)) . (count($ids) > 30 ? '…' : '');
        } elseif (isset($post['id']) && is_scalar($post['id']) && $post['id'] !== '' && !($controller === 'domain' && str_starts_with($action, 'record'))) {
            $parts[] = 'ID:' . intval($post['id']);
        } elseif (isset($post['recordid']) && is_scalar($post['recordid']) && (string)$post['recordid'] !== '') {
            $parts[] = '记录ID:' . $post['recordid'];
        }
        return implode(' ', array_unique($parts));
    }

    /**
     * 参数脱敏：去掉签名参数；密码、密钥、令牌、私钥等字段替换为 ******；证书内容只保留长度；超长内容截断
     */
    public static function sanitize($params, $key = '', $depth = 0)
    {
        if (!is_array($params)) return $params;
        $out = [];
        $n = 0;
        foreach ($params as $k => $v) {
            if ($depth === 0 && in_array($k, ['sign', 'timestamp', 'keyid', 's'], true)) continue;
            if (++$n > 200) {
                $out['…'] = '共' . count($params) . '项，已截断';
                break;
            }
            if (is_string($k) && ApiKeyHelper::isSecretField($k)) {
                $out[$k] = isNullOrEmpty($v) ? $v : '******';
                continue;
            }
            // 账户表单中的 name 通常就是 AccessKey 或面板地址
            if ($depth === 0 && $k === 'name' && in_array($key, ['domain/account_op', 'cert/account_op']) && is_scalar($v)) {
                $out[$k] = ApiKeyHelper::maskText((string)$v);
                continue;
            }
            if (is_array($v)) {
                $out[$k] = $depth >= 4 ? '[数组]' : self::sanitize($v, $key, $depth + 1);
                continue;
            }
            if (!is_string($v)) {
                $out[$k] = $v;
                continue;
            }
            $t = trim($v);
            if (str_contains($t, '-----BEGIN')) {
                $out[$k] = (stripos($t, 'PRIVATE KEY') !== false ? '[私钥 ' : '[证书 ') . strlen($t) . '字节]';
                continue;
            }
            if (strlen($t) > 1 && ($t[0] === '{' || $t[0] === '[')) {
                $json = json_decode($t, true);
                if (is_array($json)) {
                    $out[$k] = self::sanitize($json, $key, $depth + 1);
                    continue;
                }
            }
            $out[$k] = mb_strlen($v) > 2000 ? mb_substr($v, 0, 2000) . '…（共' . mb_strlen($v) . '字符）' : $v;
        }
        return $out;
    }

    /**
     * 系统中保存的所有密钥值（DNS/证书/部署账户中的密钥字段、用户API密钥、系统配置中的密钥），用于从日志和API返回中替换掉
     */
    public static function secretValues()
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        $values = [];
        $collect = function ($arr) use (&$collect, &$values) {
            if (!is_array($arr)) return;
            foreach ($arr as $k => $v) {
                if (is_array($v)) {
                    $collect($v);
                } elseif (is_string($k) && ApiKeyHelper::isSecretField($k) && is_scalar($v)) {
                    $v = trim((string)$v);
                    if (self::scrubCandidate($v)) $values[] = $v;
                }
            }
        };
        try {
            foreach (Db::name('account')->column('config') as $config) {
                $collect(json_decode((string)$config, true));
            }
            foreach (Db::name('cert_account')->field('config,ext')->select() as $row) {
                $collect(json_decode((string)$row['config'], true));
                $collect(json_decode((string)$row['ext'], true));
            }
            foreach (Db::name('user')->whereNotNull('apikey')->column('apikey') as $v) {
                if (self::scrubCandidate((string)$v)) $values[] = (string)$v;
            }
            foreach (Db::name('config')->column('value', 'key') as $k => $v) {
                if (ApiKeyHelper::isSecretField($k) && self::scrubCandidate(trim((string)$v))) $values[] = trim((string)$v);
            }
        } catch (\Throwable $e) {
        }
        $values = array_values(array_unique($values));
        usort($values, fn($a, $b) => strlen($b) - strlen($a));
        return $cache = $values;
    }

    /**
     * 适合做全文替换的密钥值：足够长且不是纯数字/纯字母的普通单词，避免误伤正常数据
     */
    private static function scrubCandidate($v)
    {
        $len = strlen($v);
        if ($len >= 16) return !ctype_digit($v);
        if ($len < 8 || ctype_digit($v) || ctype_alpha($v)) return false;
        return (bool)preg_match('/[0-9]/', $v) && (bool)preg_match('/[A-Za-z]/', $v);
    }

    /**
     * API返回内容需要替换的值：所有密钥值，再加上各账户中的面板/接口地址（如 http://1.2.3.4:8888）
     * 不包含单独的IP、主机名和邮箱，避免误伤解析记录等正常数据
     */
    public static function apiScrubValues()
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        $values = self::secretValues();
        $collect = function ($arr) use (&$values) {
            if (!is_array($arr)) return;
            foreach ($arr as $k => $v) {
                if (is_string($v) && in_array(strtolower((string)$k), ['url', 'base_url', 'endpoint', 'api_url', 'panel_url', 'server'], true) && str_contains($v, '://') && strlen(trim($v)) >= 10) {
                    $values[] = rtrim(trim($v), '/');
                }
            }
        };
        try {
            foreach (array_merge(Db::name('account')->column('name'), Db::name('cert_account')->column('name')) as $name) {
                if (is_string($name) && str_contains($name, '://') && strlen(trim($name)) >= 10) $values[] = rtrim(trim($name), '/');
            }
            foreach (Db::name('account')->column('config') as $config) {
                $collect(json_decode((string)$config, true));
            }
            foreach (Db::name('cert_account')->column('config') as $config) {
                $collect(json_decode((string)$config, true));
            }
        } catch (\Throwable $e) {
        }
        $values = array_values(array_unique($values));
        usort($values, fn($a, $b) => strlen($b) - strlen($a));
        return $cache = $values;
    }

    /**
     * 把文本中出现的密钥值替换为 ******（同时处理JSON转义后的形式）
     */
    public static function scrub($text, ?array $secrets = null)
    {
        if (!is_string($text) || $text === '') return $text;
        $secrets = $secrets ?? self::secretValues();
        if (!$secrets) return $text;
        $search = [];
        foreach ($secrets as $s) {
            $search[$s] = true;
            $json = substr(json_encode($s, JSON_UNESCAPED_UNICODE), 1, -1);
            if ($json !== $s) $search[$json] = true;
            $json2 = substr(json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1, -1);
            if ($json2 !== $s) $search[$json2] = true;
        }
        $keys = array_keys($search);
        usort($keys, fn($a, $b) => strlen($b) - strlen($a));
        return str_replace($keys, '******', $text);
    }

    private static function cut($text, $len)
    {
        if ($text === null) return null;
        $text = (string)$text;
        return mb_strlen($text) > $len ? mb_substr($text, 0, $len - 1) . '…' : $text;
    }

    public static function write(array $row)
    {
        try {
            Db::name('audit')->insert($row);
            // 按保留天数清理（约每200次写入执行一次）
            if (random_int(1, 200) === 1) {
                self::cleanup();
            }
        } catch (\Throwable $e) {
        }
    }

    public static function cleanup()
    {
        $days = intval(config_get('audit_days', '180'));
        if ($days <= 0) return 0;
        return Db::name('audit')->where('addtime', '<', date('Y-m-d H:i:s', time() - $days * 86400))->limit(5000)->delete();
    }

    public static function resultName($result)
    {
        return [self::RESULT_FAIL => '失败', self::RESULT_SUCCESS => '成功', self::RESULT_DENIED => '拒绝'][$result] ?? '未知';
    }
}
