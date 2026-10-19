<?php
namespace app\controller;

use app\BaseController;
use app\service\BatchTaskService;
use app\service\DnsChangeService;
use think\facade\Db;
use think\facade\View;

class Batch extends BaseController
{
    public function index()
    {
        if (!checkPermission(1)) return $this->alert('error', '无权限');
        $query = Db::name('domain');
        if ((int)$this->request->user['level'] !== 2) $query->whereIn('name', $this->request->user['permission']);
        View::assign('domains', $query->field('id,name')->order('name', 'asc')->select()->toArray());
        return view('batch/index');
    }

    private function ownedQuery()
    {
        if (!checkPermission(1)) throw new \RuntimeException('无权限');
        $query = Db::name('batch_task');
        if ((int)$this->request->user['level'] !== 2 || !empty($this->request->isApi)) $query->where('actor', DnsChangeService::actor());
        if (api_domains() !== null) $query->whereIn('did', Db::name('domain')->whereIn('name', api_domains())->column('id') ?: [0]);
        return $query;
    }

    public function data()
    {
        try {
            $query = $this->ownedQuery();
            $total = (clone $query)->count();
            $rows = $query->field('id,did,name,total,completed,status,cancel_requested,attempts,error,snapshot_id,addtime,updatetime,expiretime')->order('id', 'desc')
                ->limit(max(0, input('post.offset/d', 0)), min(100, max(1, input('post.limit/d', 20))))->select()->toArray();
            return json(['total' => $total, 'rows' => $rows]);
        } catch (\Throwable $e) { return json(['code' => -1, 'msg' => $e->getMessage(), 'total' => 0, 'rows' => []]); }
    }

    public function enqueue()
    {
        try {
            $domain = Db::name('domain')->where('id', input('post.did/d'))->find();
            if (!$domain || !checkPermission(0, $domain['name'])) throw new \RuntimeException('域名不存在或无权限');
            $token = input('post.token'); $name = input('post.name', '');
            if (!is_string($token) || !is_string($name)) throw new \RuntimeException('任务参数格式不正确');
            $id = (new BatchTaskService())->enqueue($domain, $token, $name);
            api_write_log('创建批量任务', '任务ID:' . $id, $domain['name']);
            return json(['code' => 0, 'msg' => '已加入后台任务，将由现有计划任务继续执行', 'id' => $id]);
        } catch (\Throwable $e) { return json(['code' => -1, 'msg' => $e->getMessage()]); }
    }

    public function details()
    {
        try {
            $task = $this->ownedQuery()->where('id', input('post.id/d'))->find();
            if (!$task) throw new \RuntimeException('任务不存在或无权限');
            $results = [];
            foreach (json_decode($task['results'] ?? '[]', true) ?: [] as $item) {
                if (!is_array($item) || !isset($item['index']) || !is_int($item['index']) || $item['index'] < 0 || $item['index'] >= (int)$task['total']) continue;
                $row = ['index' => $item['index'], 'success' => !empty($item['success'])];
                if (empty($item['success'])) $row['message'] = mb_substr(\app\lib\AuditHelper::scrub((string)($item['message'] ?? '执行失败')), 0, 500);
                $results[$item['index']] = $row;
            }
            ksort($results);
            return json(['code' => 0, 'msg' => 'ok', 'task' => array_intersect_key($task, array_flip(['id', 'did', 'name', 'total', 'completed', 'status', 'cancel_requested', 'attempts', 'error', 'snapshot_id', 'addtime', 'updatetime', 'expiretime'])), 'results' => array_values($results)]);
        } catch (\Throwable $e) { return json(['code' => -1, 'msg' => $e->getMessage()]); }
    }

    public function operation()
    {
        try {
            $task = $this->ownedQuery()->where('id', input('post.id/d'))->find();
            if (!$task) throw new \RuntimeException('任务不存在或无权限');
            $action = input('param.action');
            if ($action === 'cancel') {
                Db::name('batch_task')->where('id', $task['id'])->whereIn('status', [0, 1, 3])->update(['cancel_requested' => 1, 'updatetime' => date('Y-m-d H:i:s')]);
                // The worker may have claimed it since find(). Never mark an active
                // cloud request cancelled; its checkpoint observes the request.
                Db::name('batch_task')->where('id', $task['id'])->whereIn('status', [0, 3])->where('cancel_requested', 1)->update(['status' => 4, 'updatetime' => date('Y-m-d H:i:s')]);
            } elseif ($action === 'retry') {
                if (strtotime($task['expiretime']) <= time()) throw new \RuntimeException('任务已过期，请重新预览');
                $domain = Db::name('domain')->where('id', $task['did'])->find();
                if (!$domain || !checkPermission(0, $domain['name'])) throw new \RuntimeException('域名不存在或无权限');
                Db::name('batch_task')->where('id', $task['id'])->where('status', 3)->update(['status' => 0, 'cancel_requested' => 0, 'error' => null, 'updatetime' => date('Y-m-d H:i:s')]);
            } else throw new \RuntimeException('不支持的操作');
            api_write_log('批量任务:' . $action, '任务ID:' . $task['id']);
            return json(['code' => 0, 'msg' => '设置成功']);
        } catch (\Throwable $e) { return json(['code' => -1, 'msg' => $e->getMessage()]); }
    }
}
