<?php

namespace app\service;

use app\lib\DnsHelper;
use app\lib\RecordGuard;
use RuntimeException;
use think\facade\Cache;
use think\facade\Db;

/** Reviewed DNS changes use existing adapters, database and cache; no credentials enter snapshots. */
class DnsChangeService
{
    public const MAX_SNAPSHOT_BYTES = 8388608;
    public const MAX_PREVIEW_BYTES = 2097152;
    public const MAX_ACTIVE_PREVIEWS = 3;
    private $connection;

    public function __construct($connection = null)
    {
        $this->connection = $connection;
    }

    private function table(string $name)
    {
        return $this->connection ? $this->connection->name($name) : Db::name($name);
    }

    public function snapshot(array $domain, object $dns, string $label = '手动快照', ?array $records = null): int
    {
        $records ??= RecordGuard::all($dns, $domain['name']);
        $safe = [];
        foreach ($records as $record) $safe[] = ['RecordId' => $record['RecordId']] + RecordGuard::canonical($record);
        $json = json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($json) > self::MAX_SNAPSHOT_BYTES) throw new RuntimeException('记录快照超过8MB安全上限');
        $id = $this->table('dns_snapshot')->insertGetId([
            'did' => $domain['id'], 'uid' => request()->user['id'] ?? 0,
            'domain' => $domain['name'], 'aid' => $domain['aid'], 'thirdid' => (string)$domain['thirdid'],
            'label' => mb_substr($label, 0, 120), 'record_count' => count($safe),
            'records' => $json, 'checksum' => RecordGuard::fingerprint($safe), 'addtime' => date('Y-m-d H:i:s'),
        ]);
        // Bound history storage. A snapshot is a recovery point, never an unbounded event stream.
        $expired = $this->table('dns_snapshot')->where('did', $domain['id'])->order('id', 'desc')->limit(100, 1000)->column('id');
        if ($expired) $this->table('dns_snapshot')->whereIn('id', $expired)->delete();
        return (int)$id;
    }

    public function preview(array $domain, object $dns, array $operations, string $actor): array
    {
        if (strlen(json_encode($operations, JSON_THROW_ON_ERROR)) > 1048576) throw new RuntimeException('变更数据超过1MB');
        if (!$operations || count($operations) > RecordGuard::MAX_BATCH) throw new RuntimeException('每次预览应包含1至500项变更');
        $records = RecordGuard::all($dns, $domain['name']);
        return $this->storePreview($domain, $records, $operations, $actor);
    }

    private function storePreview(array $domain, array $records, array $operations, string $actor): array
    {
        $byId = [];
        foreach ($records as $record) $byId[(string)$record['RecordId']] = $record;
        $seen = [];
        $changes = [];
        foreach ($operations as $operation) {
            if (!is_array($operation)) throw new RuntimeException('变更格式不正确');
            $action = $operation['action'] ?? '';
            if (!in_array($action, ['add', 'update', 'delete', 'status', 'remark'], true)) throw new RuntimeException('不支持的变更操作');
            $before = null;
            if ($action !== 'add') {
                $id = $operation['recordid'] ?? null;
                if ((!is_string($id) && !is_int($id)) || !isset($byId[(string)$id])) throw new RuntimeException('记录不存在或不属于当前域名');
                if (isset($seen[(string)$id])) throw new RuntimeException('每次预览中同一记录只能修改一次');
                $seen[(string)$id] = true;
                $before = $byId[(string)$id];
                if (self::protectedRecord($before)) throw new RuntimeException('根域名NS/SOA记录不支持通过变更预览修改');
            }
            if ($action === 'add' || $action === 'update') {
                RecordGuard::validateRecord($operation);
                if (self::protectedRecord(['Name' => $operation['name'], 'Type' => $operation['type']])) throw new RuntimeException('根域名NS/SOA记录不支持通过变更预览修改');
                $operation = array_intersect_key($operation, array_flip(['action', 'recordid', 'name', 'type', 'value', 'line', 'ttl', 'mx', 'weight', 'remark']));
                $operation += ['line' => $before['Line'] ?? 'default', 'ttl' => $before['TTL'] ?? 600, 'mx' => $before['MX'] ?? 1, 'weight' => $before['Weight'] ?? null, 'remark' => $before['Remark'] ?? null];
                RecordGuard::validateRecord($operation);
            } elseif ($action === 'status') {
                if (!in_array((string)($operation['status'] ?? ''), ['0', '1'], true)) throw new RuntimeException('状态参数不正确');
                $operation = ['action' => $action, 'recordid' => $operation['recordid'], 'status' => (string)$operation['status']];
            } elseif ($action === 'remark') {
                if (!is_string($operation['remark'] ?? '') || strlen($operation['remark'] ?? '') > 2000) throw new RuntimeException('备注格式不正确或过长');
                $operation = ['action' => $action, 'recordid' => $operation['recordid'], 'remark' => $operation['remark'] ?? null];
            } else {
                $operation = ['action' => $action, 'recordid' => $operation['recordid']];
            }
            $changes[] = ['operation' => $operation, 'before' => $before ? RecordGuard::canonical($before) : null];
        }
        $token = bin2hex(random_bytes(32));
        $expires = time() + 600;
        $plan = ['actor' => $actor, 'domain' => self::domainIdentity($domain), 'fingerprint' => RecordGuard::fingerprint($records), 'changes' => $changes, 'expires' => $expires];
        if (strlen(json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) > self::MAX_PREVIEW_BYTES) throw new RuntimeException('变更计划及原记录超过2MB，请减少每批记录数');
        // File-cache expiry is lazy: abandoned random tokens otherwise leave
        // their files behind forever. Bound retained plans per user, across keys.
        $principal = request()->user;
        $owner = hash('sha256', json_encode([$principal['type'] ?? '', (string)($principal['id'] ?? 0)], JSON_THROW_ON_ERROR));
        $indexKey = 'dns_preview_owner_' . $owner;
        $lock = self::resourceLock('preview-owner:' . $owner);
        try {
            $active = [];
            foreach ((array)Cache::get($indexKey) as $entry) {
                if (!is_array($entry) || !preg_match('/\A[a-f0-9]{64}\z/D', $entry['token'] ?? '')) continue;
                if (($entry['expires'] ?? 0) <= time()) Cache::delete('dns_preview_' . $entry['token']);
                elseif (is_array(Cache::get('dns_preview_' . $entry['token']))) $active[] = $entry;
            }
            while (count($active) >= self::MAX_ACTIVE_PREVIEWS) {
                $oldest = array_shift($active);
                if (Cache::delete('dns_preview_' . $oldest['token']) === false) throw new RuntimeException('无法清理旧预览，请检查缓存目录权限');
            }
            $active[] = ['token' => $token, 'expires' => $expires];
            if (Cache::set($indexKey, $active, 0) === false || Cache::set('dns_preview_' . $token, $plan, 600) === false) throw new RuntimeException('无法保存变更预览，请检查缓存目录权限');
        } finally { flock($lock, LOCK_UN); fclose($lock); }
        return ['token' => $token, 'expires' => $expires, 'changes' => $changes];
    }

    public function consumePlan(array $domain, string $token, string $actor): array
    {
        $lock = self::lock($domain);
        try {
            $plan = $this->readPlan($domain, $token, $actor);
            if (Cache::delete('dns_preview_' . $token) === false) throw new RuntimeException('无法消费预览凭证，请检查缓存目录权限');
            return $plan;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function readPlan(array $domain, string $token, string $actor): array
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $token)) throw new RuntimeException('预览凭证无效，请重新预览');
        $plan = Cache::get('dns_preview_' . $token);
        if (!is_array($plan) || ($plan['actor'] ?? '') !== $actor || ($plan['domain'] ?? '') !== self::domainIdentity($domain) || ($plan['expires'] ?? 0) < time()) throw new RuntimeException('预览已过期或不属于当前用户，请重新预览');
        return $plan;
    }

    public function apply(array $domain, object $dns, string $token, string $actor): array
    {
        return $this->applyPlan($domain, $dns, $this->consumePlan($domain, $token, $actor), $actor);
    }

    /** Trusted worker entry point. Persist plans only from consumePlan(), never accept one from HTTP. */
    public function applyPlan(array $domain, object $dns, array $plan, string $actor, ?callable $checkpoint = null): array
    {
        if (($plan['actor'] ?? '') !== $actor || ($plan['domain'] ?? '') !== self::domainIdentity($domain) || empty($plan['changes']) || count($plan['changes']) > RecordGuard::MAX_BATCH) throw new RuntimeException('待执行变更不属于当前用户或域名');
        $lock = self::lock($domain);
        try {
            $records = RecordGuard::all($dns, $domain['name']);
            if (!hash_equals($plan['fingerprint'], RecordGuard::fingerprint($records))) throw new RuntimeException('DNS记录在预览后发生变化，请重新预览');
            $snapshot = $this->snapshot($domain, $dns, '变更执行前自动快照', $records);
            $results = [];
            $completed = 0;
            $expectedFingerprint = RecordGuard::fingerprint($records);
            foreach ($plan['changes'] as $index => $change) {
                try {
                    $current = RecordGuard::all($dns, $domain['name']);
                    if (!hash_equals($expectedFingerprint, RecordGuard::fingerprint($current))) throw new RuntimeException('执行过程中检测到其他DNS变更，已停止，请重新预览剩余项目');
                    $op = $change['operation'];
                    if ($op['action'] !== 'add') {
                        // Positional IDs (PowerDNS/Technitium) may shift after a preceding delete.
                        $matches = array_values(array_filter($current, fn($r) => RecordGuard::canonical($r) === $change['before']));
                        if (count($matches) !== 1) throw new RuntimeException('无法唯一定位预览中的记录，已停止');
                        $op['recordid'] = $matches[0]['RecordId'];
                    }
                    $ok = self::execute($dns, $op);
                    if ($ok === false || $ok === null) throw new RuntimeException('DNS平台执行失败：' . $dns->getError());
                    $records = RecordGuard::all($dns, $domain['name']);
                    self::assertOutcome($current, $records, $op, $change['before'], $dns instanceof \app\lib\dns\powerdns);
                    if ($op['action'] === 'update') \app\lib\AutomaticDnsTarget::synchronize($dns, $domain, (string)$op['recordid'], $op, $records, $this->connection);
                    $expectedFingerprint = RecordGuard::fingerprint($records);
                    $results[] = ['index' => $index, 'success' => true];
                    $completed++;
                    if ($checkpoint) $checkpoint(['remaining_changes' => array_slice($plan['changes'], $completed), 'fingerprint' => $expectedFingerprint, 'completed' => $completed, 'snapshot_id' => $snapshot]);
                } catch (\Throwable $e) {
                    $results[] = ['index' => $index, 'success' => false, 'message' => mb_substr($e->getMessage(), 0, 500)];
                    break;
                }
            }
            $remaining = array_slice($plan['changes'], $completed);
            return ['complete' => !$remaining, 'snapshot_id' => $snapshot, 'results' => $results, 'remaining' => count($remaining), 'remaining_changes' => $remaining, 'fingerprint' => $expectedFingerprint];
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private static function assertOutcome(array $before, array $after, array $op, ?array $original, bool $powerDns = false): void
    {
        $unchanged = $before;
        if ($original !== null) {
            foreach ($unchanged as $i => $record) if ((string)$record['RecordId'] === (string)$op['recordid']) { unset($unchanged[$i]); break; }
        }
        if ($op['action'] === 'delete') {
            if (RecordGuard::fingerprint($unchanged) !== RecordGuard::fingerprint($after)) throw new RuntimeException('删除后的记录与预览不一致，已停止后续变更，请检查快照');
            return;
        }
        $expected = $original ?? [];
        if (in_array($op['action'], ['add', 'update'], true)) {
            foreach (['name' => 'Name', 'type' => 'Type', 'value' => 'Value', 'line' => 'Line', 'ttl' => 'TTL', 'mx' => 'MX', 'weight' => 'Weight', 'remark' => 'Remark'] as $from => $to) {
                if ($op[$from] !== null && !($from === 'mx' && $op['type'] !== 'MX')) $expected[$to] = $op[$from];
            }
        } elseif ($op['action'] === 'status') $expected['Status'] = $op['status'];
        elseif ($op['action'] === 'remark') $expected['Remark'] = $op['remark'];
        $possible = [];
        foreach ($after as $i => $record) {
            $matches = true;
            foreach ($expected as $key => $value) {
                if ($value === null) continue;
                $actual = $record[$key] ?? null;
                if ($powerDns && $key === 'Value' && ($op['type'] ?? '') === 'TXT' && is_string($value)) {
                    $wire = str_starts_with($value, '"') ? $value : '"' . $value . '"';
                    if ($actual !== $wire) $matches = false;
                } elseif (in_array($key, ['Value', 'Name'], true) && in_array($op['type'] ?? ($original['Type'] ?? ''), ['CNAME', 'NS', 'MX', 'PTR'], true) && is_string($actual) && is_string($value)) {
                    if (strcasecmp(rtrim($actual, '.'), rtrim($value, '.')) !== 0) $matches = false;
                } elseif (is_array($actual) || is_array($value)) { if ($actual !== $value) $matches = false; }
                elseif ((string)$actual !== (string)$value) $matches = false;
            }
            if ($matches) $possible[] = $i;
        }
        foreach ($possible as $i) {
            $rest = $after;
            unset($rest[$i]);
            if (RecordGuard::fingerprint($unchanged) === RecordGuard::fingerprint($rest)) return;
        }
        throw new RuntimeException('DNS平台返回成功，但实际记录与预览不一致，已停止后续变更，请检查解析及快照');
    }

    public static function execute(object $dns, array $op)
    {
        return match ($op['action']) {
            'add' => $dns->addDomainRecord($op['name'], $op['type'], $op['value'], $op['line'], $op['ttl'], $op['mx'], $op['weight'], $op['remark']),
            'update' => $dns->updateDomainRecord($op['recordid'], $op['name'], $op['type'], $op['value'], $op['line'], $op['ttl'], $op['mx'], $op['weight'], $op['remark']),
            'delete' => $dns->deleteDomainRecord($op['recordid']),
            'status' => $dns->setDomainRecordStatus($op['recordid'], $op['status']),
            'remark' => $dns->updateDomainRecordRemark($op['recordid'], $op['remark']),
            default => throw new RuntimeException('不支持的变更操作'),
        };
    }

    public function rollbackPreview(array $domain, object $dns, int $snapshotId, string $actor): array
    {
        $snapshot = $this->table('dns_snapshot')->where('id', $snapshotId)->where('did', $domain['id'])->find();
        if (!$snapshot || $snapshot['domain'] !== $domain['name'] || (string)$snapshot['aid'] !== (string)$domain['aid'] || (string)$snapshot['thirdid'] !== (string)$domain['thirdid']) throw new RuntimeException('快照不存在或不属于当前域名账户');
        $target = json_decode($snapshot['records'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($target) || count($target) > RecordGuard::MAX_RECORDS || !hash_equals($snapshot['checksum'], RecordGuard::fingerprint($target))) throw new RuntimeException('快照校验失败，无法回滚');
        $type = $this->table('account')->where('id', $domain['aid'])->value('type');
        // These adapters omit recordset/routing metadata needed for a lossless restore.
        if (in_array($type, ['qingcloud', 'aws', 'powerdns', 'technitium', 'tencenteo', 'aliyunesa'], true)) throw new RuntimeException('当前平台存在记录集或路由元数据，暂不支持自动回滚；可查看快照后按原生页面恢复');
        $current = RecordGuard::all($dns, $domain['name']);
        $remaining = [];
        foreach ($current as $record) if (!self::protectedRecord($record)) $remaining[] = $record;
        $operations = [];
        foreach ($target as $record) {
            if (self::protectedRecord($record)) continue;
            $found = null;
            foreach ($remaining as $i => $candidate) if (RecordGuard::canonical($record) === RecordGuard::canonical($candidate)) { $found = $i; break; }
            if ($found !== null) { unset($remaining[$found]); continue; }
            $candidates = array_filter($remaining, fn($r) => $r['Name'] === $record['Name'] && $r['Type'] === $record['Type'] && (string)$r['Line'] === (string)$record['Line']);
            $operation = ['action' => 'add', 'name' => $record['Name'], 'type' => $record['Type'], 'value' => $record['Value'], 'line' => $record['Line'], 'ttl' => $record['TTL'], 'mx' => $record['MX'], 'weight' => $record['Weight'], 'remark' => $record['Remark']];
            if (count($candidates) === 1) {
                $key = array_key_first($candidates);
                $candidate = $candidates[$key];
                if ((string)$candidate['Status'] !== (string)$record['Status'] || !empty($record['Weight']) || (string)$candidate['Remark'] !== (string)$record['Remark']) throw new RuntimeException('快照包含状态、备注或加权差异，需在原生页面逐项恢复');
                $operation['action'] = 'update';
                $operation['recordid'] = $candidate['RecordId'];
                unset($remaining[$key]);
            } elseif (($record['Status'] ?? '1') !== '1' || !empty($record['Weight']) || !empty($record['Remark'])) {
                throw new RuntimeException('快照包含暂停、备注或加权记录，需在原生页面逐项恢复');
            }
            $operations[] = $operation;
        }
        // Restore with creates first, then removes. The user reviews every destructive item.
        foreach ($remaining as $record) $operations[] = ['action' => 'delete', 'recordid' => $record['RecordId']];
        if (!$operations) return ['token' => null, 'expires' => null, 'changes' => []];
        if (count($operations) > RecordGuard::MAX_BATCH) throw new RuntimeException('回滚变更超过500项，请分批恢复');
        return $this->storePreview($domain, $current, $operations, $actor);
    }

    public static function protectedRecord(array $record): bool
    {
        return ($record['Type'] ?? '') === 'SOA' || (($record['Name'] ?? '') === '@' && ($record['Type'] ?? '') === 'NS');
    }

    public static function actor(): string
    {
        $request = request();
        return hash('sha256', json_encode([$request->user['type'] ?? '', $request->user['id'] ?? 0, $request->apiKey['id'] ?? null, !empty($request->isApi)], JSON_THROW_ON_ERROR));
    }

    private static function domainIdentity(array $domain): string
    {
        return hash('sha256', json_encode([(string)$domain['id'], (string)$domain['aid'], $domain['name'], (string)$domain['thirdid']], JSON_THROW_ON_ERROR));
    }

    public static function lock(array $domain)
    {
        return self::resourceLock((string)$domain['aid'] . ':' . $domain['name']);
    }

    private static function resourceLock(string $resource)
    {
        $dir = runtime_path() . 'dns-locks';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('无法创建DNS变更锁');
        $handle = fopen($dir . '/' . hash('sha256', $resource) . '.lock', 'c');
        if (!$handle || !flock($handle, LOCK_EX | LOCK_NB)) { if ($handle) fclose($handle); throw new RuntimeException('该域名正在执行其他变更，请稍后重试'); }
        return $handle;
    }
}
