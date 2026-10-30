<?php
namespace app\service;

use think\facade\Db;

class ApiRequestService
{
    public static function execute($request, callable $next)
    {
        $auth = $request->apiV2;
        $authorization = hash('sha256', json_encode([$request->user['level'] ?? null,$request->user['permission'] ?? [],$request->user['api_domains'] ?? [],$request->apiKey['scopes'] ?? []]));
        $nonce = hash('sha256', $auth['identity'] . ':' . $auth['nonce']);
        try { Db::name('api_nonce')->insert(['id'=>$nonce, 'expires'=>time()+601]); }
        catch (\Throwable $e) {
            if (Db::name('api_nonce')->where('id',$nonce)->find()) return json(['code'=>-1,'msg'=>'Nonce已使用，请为每次请求生成新Nonce'])->code(409);
            throw $e;
        }
        if ($auth['idempotency'] === '') return $next();
        $path = parse_url($auth['uri'] ?? $request->url(), PHP_URL_PATH);
        if (!preg_match('~\A/api/(?:record/(?:add|update|delete|status|remark|batch|batchedit|batchadd|apply)/[1-9]\d*|batch/(?:enqueue|task/(?:cancel|retry))|cert/deploy/process)\z~D', $path)) return json(['code'=>-1,'msg'=>'此接口不支持幂等键'])->code(400);
        $key = hash('sha256', $auth['identity'] . ':' . $auth['idempotency']);
        $db = TaskLockService::connection(); $lock = new TaskLockService($db, 'api-idempotency:' . $key);
        try {
            try { $lock->acquire(); } catch (\Throwable $e) { return json(['code'=>-1,'msg'=>'同一幂等请求正在执行'])->code(409); }
            $receipt = $db->name('api_receipt')->where('id',$key)->find();
            if ($receipt) {
                if (!hash_equals($receipt['digest'],$auth['digest'])) return json(['code'=>-1,'msg'=>'幂等键已用于不同请求'])->code(409);
                if (!hash_equals($receipt['authorization'],$authorization)) return json(['code'=>-1,'msg'=>'授权范围已改变，请先核对原请求结果'])->code(409);
                if ((int)$receipt['status'] !== 1) return json(['code'=>-1,'msg'=>'上次执行结果不确定，请先核对远端状态，勿直接重复提交'])->code(409);
                return response($receipt['body'], (int)$receipt['http_code'])->contentType('application/json')->header(['Idempotency-Replayed'=>'true']);
            }
            $db->name('api_receipt')->insert(['id'=>$key,'digest'=>$auth['digest'],'authorization'=>$authorization,'status'=>0,'expires'=>time()+7*86400]);
            $response = $next();
            $content = $response->getContent();
            if (!is_string($content) || strlen($content)>1048576 || json_decode($content,true) === null) throw new \RuntimeException('请求已执行，响应无法保存；请核对远端状态');
            $db->name('api_receipt')->where('id',$key)->update(['status'=>1,'body'=>$content,'http_code'=>$response->getCode()]);
            return $response;
        } finally { try { $lock->release(); } finally { $db->close(); } }
    }
}
