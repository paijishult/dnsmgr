<?php
namespace app;

use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\exception\Handle;
use think\exception\HttpException;
use think\exception\HttpResponseException;
use think\exception\ValidateException;
use think\Response;
use Throwable;

/**
 * 应用异常处理类
 */
class ExceptionHandle extends Handle
{
    /**
     * 不需要记录信息（日志）的异常类列表
     * @var array
     */
    protected $ignoreReport = [
        HttpException::class,
        HttpResponseException::class,
        ModelNotFoundException::class,
        DataNotFoundException::class,
        ValidateException::class,
    ];

    /**
     * 记录异常信息（包括日志或者其它方式记录）
     *
     * @access public
     * @param  Throwable $exception
     * @return void
     */
    public function report(Throwable $exception): void
    {
        // 使用内置的方式记录异常日志
        parent::report($exception);
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @access public
     * @param \think\Request   $request
     * @param Throwable $e
     * @return Response
     */
    public function render($request, Throwable $e): Response
    {
        // API接口统一返回JSON，且不输出调用栈等内部信息
        if (str_starts_with(ltrim($request->pathinfo(), '/'), 'api/') && !($e instanceof HttpResponseException)) {
            $status = $e instanceof HttpException ? $e->getStatusCode() : 500;
            if ($e instanceof HttpException) {
                $msg = match ($status) {
                    400 => '请求参数不正确',
                    401 => '请先完成身份验证',
                    403 => '无权限',
                    404 => '请求资源不存在',
                    405 => '请求方法不支持',
                    429 => '请求过于频繁，请稍后重试',
                    default => $status >= 500 ? '服务器内部错误，请稍后重试' : '请求处理失败',
                };
            } elseif ($e instanceof \think\db\exception\DbException || $e instanceof \PDOException) {
                $msg = '服务器内部错误（数据库）';
            } elseif ($e instanceof \Error || $e instanceof \ErrorException || $e instanceof \think\exception\ErrorException) {
                // TypeError、null 参数警告等多为参数缺失或格式不对，不输出内部路径
                $status = 400;
                $msg = '请求参数缺失或格式不正确，请对照接口文档检查必填参数';
            } else {
                $msg = '服务器内部错误，请稍后重试';
            }
            $response = json(['code' => -1, 'msg' => $msg], $status);
            return $e instanceof HttpException ? $response->header($e->getHeaders()) : $response;
        }

        // 其他错误交给系统处理
        return parent::render($request, $e);
    }
}
