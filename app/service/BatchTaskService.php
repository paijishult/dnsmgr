<?php
namespace app\service;

use app\lib\ApiKeyHelper;
use app\lib\AuditHelper;
use app\lib\DnsHelper;
use think\facade\Db;

/** Durable background execution of an already-reviewed DNS plan. */
class BatchTaskService
{
    public static function identity(): array
    {
        $request = request();
        if (($request->user['type'] ?? '') !== 'user' || !checkPermission(1)) throw new \RuntimeException('当前用户不能创建后台任务');
        $user = Db::name('user')->where('id', $request->user['id'])->find();
        if (!$user || $user['status'] != 1) throw new \RuntimeException('用户状态已变更');
        $kid = (int)($request->apiKey['id'] ?? 0);
        $key = $kid ? Db::name('apikey')->where('id', $kid)->find() : null;
        return ['uid' => (int)$user['id'], 'kid' => $kid, 'kind' => $kid ? 'key' : (!empty($request->isApi) ? 'user_api' : 'web'),
            'version' => self::version($user, $key), 'ip' => ApiKeyHelper::clientIp()];
    }

    private static function version(array $user, ?array $key): string
    {
        return hash_hmac('sha256', $user['password'] . '|' . ($user['apikey'] ?? '') . '|' . ($key['secret'] ?? ''), (string)config_get('sys_key'));
    }

    /** Re-check current permissions and credential revocation before and between changes. */
    public static function authorize(array $identity, array $domain): void
    {
        $current = Db::name('domain')->where('id', $domain['id'])->find();
        if (!$current || array_map('strval', [$current['aid'], $current['name'], $current['thirdid']])
            !== array_map('strval', [$domain['aid'], $domain['name'], $domain['thirdid']])) {
            throw new \RuntimeException('任务域名已删除或账户绑定已变更，请重新预览');
        }
        $user = Db::name('user')->where('id', (int)($identity['uid'] ?? 0))->find();
        $key = !empty($identity['kid']) ? Db::name('apikey')->where('id', (int)$identity['kid'])->find() : null;
        if (!$user || $user['status'] != 1 || !in_array((int)$user['level'], [1, 2], true)
            || !is_string($identity['version'] ?? null) || !hash_equals(self::version($user, $key), $identity['version'])) throw new \RuntimeException('任务创建者或凭据已变更，请重新预览创建任务');
        $user['type'] = 'user';
        $user['permission'] = (int)$user['level'] === 1 ? Db::name('permission')->where('uid', $user['id'])->column('domain') : [];
        $kind = $identity['kind'] ?? '';
        $apiKey = null;
        if ($kind === 'key') {
            if (!$key || (int)$key['uid'] !== (int)$user['id'] || $key['status'] != 1 || config_get('api_key_open', '1') != '1'
                || (!empty($key['expiretime']) && strtotime($key['expiretime']) <= time())) throw new \RuntimeException('API密钥已失效');
            $domains = array_values(array_filter(explode("\n", $key['domains'] ?? '')));
            $scopes = ApiKeyHelper::normalizeScopes($key['scopes'], (int)$user['level'] === 2, !empty($domains));
            if (!in_array('record:write', $scopes, true) || !ApiKeyHelper::ipAllowed($identity['ip'] ?? '', array_filter(explode("\n", $key['ipwhitelist'] ?? '')))) throw new \RuntimeException('API密钥权限或IP白名单已变更');
            if ($domains) $user['api_domains'] = (int)$user['level'] === 1 ? (array_values(array_intersect($domains, $user['permission'])) ?: ['-']) : $domains;
            $apiKey = ['id' => $key['id'], 'keyid' => $key['keyid'], 'name' => $key['name'], 'scopes' => $scopes];
        } elseif ($kind === 'user_api') {
            if (empty($user['is_api']) || empty($user['apikey'])) throw new \RuntimeException('用户API权限已关闭');
        } elseif ($kind !== 'web') throw new \RuntimeException('任务身份无效');
        unset($user['password'], $user['totp_secret'], $user['apikey']);
        request()->user = $user;
        request()->isApi = $kind !== 'web';
        request()->apiKey = $apiKey;
        if (!checkPermission(0, $domain['name'])) throw new \RuntimeException('该域名已不在任务创建者的权限范围内');
    }

    public function enqueue(array $domain, string $token, string $name): int
    {
        $identity = self::identity();
        if (!checkPermission(0, $domain['name']) || !api_has_scope('record:write')) throw new \RuntimeException('无域名修改权限');
        $name = trim($name) ?: mb_substr($domain['name'] . ' 解析变更', 0, 120);
        if (mb_strlen($name) > 120) throw new \RuntimeException('任务名称不能超过120个字符');
        $connection = TaskLockService::connection();
        $lock = new TaskLockService($connection, 'batch-enqueue:' . $identity['uid']);
        try {
            $lock->acquire();
            // Count and insert on one primary connection under a per-user mutex.
            // Concurrent requests/keys cannot spend the same last quota slot.
            if ($connection->name('batch_task')->where('uid', $identity['uid'])->whereIn('status', [0, 1, 3])->count() >= 20) throw new \RuntimeException('待处理任务过多，请先完成或取消已有任务');
            $actor = DnsChangeService::actor();
            $plan = (new DnsChangeService())->consumePlan($domain, $token, $actor);
            $encoded = json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (strlen($encoded) > 2097152) throw new \RuntimeException('任务数据超过2MB安全上限');
            return (int)$connection->name('batch_task')->insertGetId([
                'uid' => $identity['uid'], 'kid' => $identity['kid'], 'did' => $domain['id'], 'actor' => $actor, 'name' => $name,
                'identity' => json_encode($identity, JSON_THROW_ON_ERROR), 'plan' => $encoded, 'results' => '[]', 'total' => count($plan['changes']),
                'completed' => 0, 'status' => 0, 'cancel_requested' => 0, 'attempts' => 0, 'error' => null,
                'addtime' => date('Y-m-d H:i:s'), 'updatetime' => date('Y-m-d H:i:s'), 'expiretime' => date('Y-m-d H:i:s', time() + 86400),
            ]);
        } finally { try { $lock->release(); } finally { $connection->close(); } }
    }

    /** Called by the existing minute worker, with a small cooperative time slice. */
    public function execute(): void
    {
        $deadline = microtime(true) + 15;
        $ids = Db::name('batch_task')->whereIn('status', [0, 1])->order('id', 'asc')->limit(10)->column('id');
        foreach ($ids as $id) {
            if (microtime(true) >= $deadline) break;
            try { $this->process((int)$id, $deadline); }
            catch (\Throwable $e) { echo '批量任务' . (int)$id . '：' . $e->getMessage() . PHP_EOL; }
        }
        Db::name('batch_task')->whereIn('status', [2, 4])->where('updatetime', '<', date('Y-m-d H:i:s', time() - 86400 * 30))->limit(500)->delete();
        config_set('batchtask_time', date('Y-m-d H:i:s'));
    }

    private function process(int $id, float $deadline): void
    {
        $connection = TaskLockService::connection();
        $lock = new TaskLockService($connection, 'batch:' . $id);
        $request = request();
        $previous = [$request->user, $request->isApi, $request->apiKey ?? null];
        $held = false;
        try {
            $lock->acquire(); $held = true;
            $task = $connection->name('batch_task')->where('id', $id)->find();
            if (!$task || !in_array((int)$task['status'], [0, 1], true)) return;
            if ($task['cancel_requested']) { $connection->name('batch_task')->where('id', $id)->update(['status' => 4, 'updatetime' => date('Y-m-d H:i:s')]); return; }
            $domain = $connection->name('domain')->where('id', $task['did'])->find();
            if (!$domain) throw new \RuntimeException('域名不存在');
            $identity = json_decode($task['identity'], true, 512, JSON_THROW_ON_ERROR);
            self::authorize($identity, $domain);
            if (!hash_equals($task['actor'], DnsChangeService::actor())) throw new \RuntimeException('任务执行身份不一致');
            $plan = json_decode($task['plan'], true, 512, JSON_THROW_ON_ERROR);
            $domainIdentity = hash('sha256', json_encode([(string)$domain['id'], (string)$domain['aid'], $domain['name'], (string)$domain['thirdid']], JSON_THROW_ON_ERROR));
            if (!is_array($plan) || ($plan['actor'] ?? '') !== $task['actor'] || ($plan['domain'] ?? '') !== $domainIdentity || !is_array($plan['changes'] ?? null)) throw new \RuntimeException('任务计划与当前用户或域名不一致，请重新预览');
            $base = (int)$task['completed'];
            if ((int)$task['total'] < 1 || (int)$task['total'] > 500 || $base < 0 || $base > (int)$task['total'] || $base + count($plan['changes']) !== (int)$task['total']) throw new \RuntimeException('任务进度校验失败，请重新预览');
            // The last confirmed checkpoint may have committed before a worker exited.
            // An empty plan is complete; it must never be replayed or sent to applyPlan().
            if (!$plan['changes']) {
                $connection->name('batch_task')->where('id', $id)->update(['status' => 2, 'error' => null, 'updatetime' => date('Y-m-d H:i:s')]);
                return;
            }
            if (strtotime($task['expiretime']) <= time()) throw new \RuntimeException('任务已超过24小时有效期，请重新预览');
            $dns = DnsHelper::getModel($domain['aid'], $domain['name'], $domain['thirdid']);
            if (!$dns) throw new \RuntimeException('DNS模块不可用');
            // Cancel may race the initial read. Only claim a still-runnable row.
            if (!$connection->name('batch_task')->where('id', $id)->whereIn('status', [0, 1])->where('cancel_requested', 0)->inc('attempts')->update(['status' => 1, 'error' => null, 'updatetime' => date('Y-m-d H:i:s')])) return;
            $savedResults = self::resultMap(json_decode($task['results'], true) ?: [], (int)$task['total']);
            $yielded = false; $cancelled = false;
            $checkpointFailure = null; $confirmed = $base;
            $checkpoint = function (array $state) use ($connection, $id, $base, $deadline, $identity, $domain, &$plan, &$savedResults, &$yielded, &$cancelled, &$checkpointFailure, &$confirmed): void {
                $nextPlan = $plan;
                $nextPlan['changes'] = $state['remaining_changes'];
                $nextPlan['fingerprint'] = $state['fingerprint'];
                $nextResults = $savedResults;
                for ($index = $base; $index < $base + $state['completed']; $index++) $nextResults[$index] = ['index' => $index, 'success' => true];
                ksort($nextResults);
                try {
                    $connection->name('batch_task')->where('id', $id)->update([
                        'plan' => json_encode($nextPlan, JSON_THROW_ON_ERROR), 'results' => json_encode(array_values($nextResults), JSON_THROW_ON_ERROR),
                        'completed' => $base + $state['completed'], 'snapshot_id' => $state['snapshot_id'], 'updatetime' => date('Y-m-d H:i:s'),
                    ]);
                } catch (\Throwable $e) { $checkpointFailure = $e; throw $e; }
                $plan = $nextPlan; $savedResults = $nextResults; $confirmed = $base + $state['completed'];
                if (!$state['remaining_changes']) return;
                self::authorize($identity, $domain);
                if ($connection->name('batch_task')->where('id', $id)->value('cancel_requested')) { $cancelled = true; throw new \RuntimeException('任务已取消'); }
                if (empty($state['atomic_ready']) && microtime(true) >= $deadline) { $yielded = true; throw new \RuntimeException('任务将在下一次计划任务继续'); }
            };
            $result = (new DnsChangeService($connection))->applyPlan($domain, $dns, $plan, $task['actor'], $checkpoint);
            if ($checkpointFailure) throw $checkpointFailure;
            foreach ($result['results'] as $item) {
                $index = $base + (int)$item['index'];
                // A cooperative checkpoint can throw after its successful write.
                // Keep the durable success rather than replacing it with that boundary error.
                if (!empty($item['success']) || $index < $confirmed) continue;
                $savedResults[$index] = ['index' => $index, 'success' => false, 'message' => mb_substr(AuditHelper::scrub((string)($item['message'] ?? '执行失败')), 0, 500)];
            }
            ksort($savedResults);
            $cancelled = $cancelled || (bool)$connection->name('batch_task')->where('id', $id)->value('cancel_requested');
            $status = $result['complete'] ? 2 : ($cancelled ? 4 : ($yielded ? 0 : 3));
            $failed = array_values(array_filter($result['results'], fn($row) => empty($row['success'])));
            $error = $status === 3 ? ($failed[0]['message'] ?? '执行未完成，请检查记录变化后重试') : null;
            $connection->name('batch_task')->where('id', $id)->update(['status' => $status, 'results' => json_encode(array_values($savedResults), JSON_THROW_ON_ERROR), 'error' => $error ? mb_substr(AuditHelper::scrub($error), 0, 1000) : null, 'updatetime' => date('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {
            if ($held) $connection->name('batch_task')->where('id', $id)->whereIn('status', [0, 1])->update(['status' => 3, 'error' => mb_substr(AuditHelper::scrub($e->getMessage()), 0, 1000), 'updatetime' => date('Y-m-d H:i:s')]);
            throw $e;
        } finally {
            [$request->user, $request->isApi, $request->apiKey] = $previous;
            if ($held) $lock->release();
            $connection->close();
        }
    }

    /** Results are keyed by their original operation, so retry replaces a failure. */
    private static function resultMap(array $results, int $total): array
    {
        $mapped = [];
        foreach ($results as $result) {
            if (!is_array($result) || !isset($result['index']) || !is_int($result['index']) || $result['index'] < 0 || $result['index'] >= $total) continue;
            $index = $result['index'];
            $mapped[$index] = ['index' => $index, 'success' => !empty($result['success'])];
            if (empty($result['success'])) $mapped[$index]['message'] = mb_substr(AuditHelper::scrub((string)($result['message'] ?? '执行失败')), 0, 500);
        }
        ksort($mapped);
        return $mapped;
    }
}
