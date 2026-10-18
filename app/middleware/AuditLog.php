<?php

declare (strict_types=1);

namespace app\middleware;

use app\lib\AuditHelper;

/**
 * 审计日志：记录后台修改操作和API调用，任何异常都不影响请求本身
 */
class AuditLog
{
    public function handle($request, \Closure $next)
    {
        $start = microtime(true);
        $response = $next($request);
        try {
            AuditHelper::record($request, $response, $start);
        } catch (\Throwable $e) {
        }
        return $response;
    }
}
