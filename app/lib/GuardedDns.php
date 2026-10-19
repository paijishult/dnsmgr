<?php

namespace app\lib;

use app\service\DnsChangeService;
use RuntimeException;

/** Request-local adapter decorator for legacy record forms and APIs. */
class GuardedDns
{
    private array $initial;
    private ?string $error = null;
    private bool $snapshotted = false;
    private $lock;

    public function __construct(private object $dns, private array $domain, private $connection = null)
    {
        $this->lock = DnsChangeService::lock($domain);
        $this->initial = RecordGuard::all($dns, $domain['name'], true);
    }

    public function __destruct()
    {
        if (is_resource($this->lock)) { flock($this->lock, LOCK_UN); fclose($this->lock); }
    }

    public function getError() { return $this->error ?? $this->dns->getError(); }

    public function owned(array $ids): array
    {
        if (!$ids || count($ids) > RecordGuard::MAX_BATCH) throw new RuntimeException('每批应包含1至500条记录');
        $out = [];
        foreach ($ids as $id) {
            if ((!is_string($id) && !is_int($id)) || (string)$id === '' || isset($out[(string)$id])) throw new RuntimeException('记录ID无效或重复');
            $matches = array_values(array_filter($this->initial, fn($r) => (string)$r['RecordId'] === (string)$id));
            if (count($matches) !== 1) throw new RuntimeException('记录不存在或不属于当前域名，请刷新后重试');
            $out[(string)$id] = $matches[0];
        }
        return array_values($out);
    }

    public function __call(string $method, array $args)
    {
        if (!in_array($method, ['addDomainRecord', 'updateDomainRecord', 'deleteDomainRecord', 'setDomainRecordStatus', 'updateDomainRecordRemark', 'updateRecordWeight', 'changeRecordGroup', 'setWeightStatus'], true)) return $this->dns->$method(...$args);
        try {
            if (in_array($method, ['addDomainRecord', 'updateDomainRecord'], true)) {
                $fields = array_slice($args, $method === 'updateDomainRecord' ? 1 : 0);
                RecordGuard::validateRecord(['name' => $fields[0] ?? null, 'type' => $fields[1] ?? null, 'value' => $fields[2] ?? null, 'line' => $fields[3] ?? null, 'ttl' => $fields[4] ?? 600, 'mx' => $fields[5] ?? null, 'weight' => $fields[6] ?? null, 'remark' => $fields[7] ?? null]);
            }
            if ($method === 'updateDomainRecordRemark' && isset($args[1]) && (!is_string($args[1]) || strlen($args[1]) > 2000)) throw new RuntimeException('备注格式不正确或过长');
            if ($method === 'updateRecordWeight' && (!is_numeric($args[1] ?? null) || $args[1] < 0 || $args[1] > 65535)) throw new RuntimeException('权重范围不正确');
            if ($method === 'setDomainRecordStatus' && !in_array((string)($args[1] ?? ''), ['0', '1'], true)) throw new RuntimeException('记录状态无效');
            if ($method === 'setWeightStatus') {
                RecordGuard::validateRecord(['name' => $args[0] ?? null, 'type' => $args[2] ?? 'A', 'value' => '0']);
                if (!in_array((string)($args[1] ?? ''), ['0', '1'], true)) throw new RuntimeException('权重状态无效');
            }
            if (!in_array($method, ['addDomainRecord', 'setWeightStatus'], true)) {
                $ids = $method === 'changeRecordGroup' ? (array)$args[0] : [$args[0]];
                $selected = $this->owned($ids);
                $fresh = RecordGuard::all($this->dns, $this->domain['name'], true);
                $resolved = [];
                foreach ($selected as $record) {
                    $matches = array_values(array_filter($fresh, fn($r) => RecordGuard::canonical($r) === RecordGuard::canonical($record)));
                    if (count($matches) !== 1) throw new RuntimeException('记录在加载后已改变或无法唯一定位，请刷新后重试');
                    $resolved[] = $matches[0]['RecordId'];
                }
                $args[0] = $method === 'changeRecordGroup' ? $resolved : $resolved[0];
            }
            if (!$this->snapshotted) {
                // Persist a recovery point before contacting the mutating provider API.
                (new DnsChangeService($this->connection))->snapshot($this->domain, $this->dns, '解析变更前自动快照');
                $this->snapshotted = true;
            }
            return $this->dns->$method(...$args);
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
            return false;
        }
    }
}
