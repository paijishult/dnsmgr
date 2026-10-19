<?php

declare (strict_types=1);

namespace app\middleware;

use Exception;
use think\facade\Db;
use think\facade\Config;
use think\facade\Cache;

class LoadConfig
{
    /**
     * 处理请求
     *
     * @param \think\Request $request
     * @param \Closure       $next
     * @return Response
     */
    public function handle($request, \Closure $next)
    {
        if (!file_exists(app()->getRootPath().'.env')) {
            if (strpos($request->url(), '/install') === false) {
                return redirect((string)url('/install'))->header([
                    'Cache-Control' => 'no-store, no-cache, must-revalidate',
                    'Pragma' => 'no-cache',
                ]);
            } else {
                return $next($request);
            }
        }
        // 优先读取缓存的配置，已安装的情况下不再每次请求都执行 SHOW TABLES
        $res = null;
        try {
            $res = Db::name('config')->cache('configs', 0)->column('value', 'key');
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'doesn\'t exist') === false) {
                throw $e;
            }
            if (!checkTableExists('config') && !checkTableExists('user')) {
                if (strpos($request->url(), '/install') === false) {
                    return redirect((string)url('/install'))->header([
                        'Cache-Control' => 'no-store, no-cache, must-revalidate',
                        'Pragma' => 'no-cache',
                    ]);
                }
                return $next($request);
            }
        }
        if (is_array($res)) {
            if (empty($res['sys_key']) && !empty(env('app.sys_key'))) {
                config_set('sys_key', env('app.sys_key'));
                Cache::delete('configs');
                $res['sys_key'] = env('app.sys_key');
            }
            Config::set($res, 'sys');
            if (!empty($res['sys_key'])) {
                try {
                    \app\service\FeatureMigrationService::ensure();
                } catch (\Throwable $e) {
                    \think\facade\Log::error('Native feature migration failed: ' . $e->getMessage());
                    return json(['code' => -1, 'msg' => '数据库功能升级尚未完成，请检查数据库建表权限及程序日志后重试'])->code(503);
                }
            }
        }

        $request->isApi = false;

        $response = $next($request);
        $headers = [
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'same-origin',
        ];
        if (!$request->isApi) {
            $headers['X-Frame-Options'] = 'SAMEORIGIN';
        }
        return $response->header($headers);
    }
}
