<?php

declare (strict_types=1);

namespace app\middleware;

class CheckLogin
{
    public function handle($request, \Closure $next)
    {
        if (!$request->islogin) {
            if ($request->isAjax() || !$request->isGet()) {
                return json(['code' => -1, 'msg' => '未登录'])->code(401);
            }
            return redirect((string)url('/login'));
        }
        if (!in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true) && !\app\lib\CsrfToken::valid($request)) {
            return json(['code' => -1, 'msg' => '页面验证已失效，请刷新后重试'])->code(403);
        }
        return $next($request);
    }
}
