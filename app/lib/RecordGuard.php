<?php

namespace app\lib;

use RuntimeException;

/** Domain-scoped verification. Never trust client recordinfo or account-wide ID lookups. */
class RecordGuard
{
    public const MAX_RECORDS = 10000;
    public const MAX_BATCH = 500;
    private static int $fullZoneReads = 0;

    public static function isFullZoneRead(): bool { return self::$fullZoneReads > 0; }

    public static function domainName($name): string
    {
        if (!is_string($name)) throw new RuntimeException('域名格式不正确');
        $name = strtolower(rtrim(trim($name), '.'));
        if (preg_match('/[^\x00-\x7f]/', $name)) {
            $name = function_exists('idn_to_ascii') ? idn_to_ascii($name, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) : false;
        }
        if (!$name || strlen($name) > 253 || !str_contains($name, '.')) throw new RuntimeException('域名格式不正确');
        foreach (explode('.', $name) as $label) {
            if (!preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D', $label)) throw new RuntimeException('域名格式不正确');
        }
        return $name;
    }

    public static function validateRecord(array $record): void
    {
        $name = $record['name'] ?? null;
        $type = $record['type'] ?? null;
        $value = $record['value'] ?? null;
        if (!is_string($name) || strlen($name) > 253 || ($name !== '@' && !preg_match('/\A(?:\*|[\p{L}\p{N}_-]+)(?:\.[\p{L}\p{N}_-]+)*\z/uD', $name))) throw new RuntimeException('主机记录格式不正确');
        if (!is_string($type) || !preg_match('/\A[A-Z][A-Z0-9_-]{0,15}\z/D', $type)) throw new RuntimeException('记录类型格式不正确');
        if ((!is_string($value) && !is_array($value)) || $value === '' || strlen(json_encode($value, JSON_THROW_ON_ERROR)) > 131072 || (is_string($value) && str_contains($value, "\0"))) throw new RuntimeException('记录值格式不正确或过长');
        if (is_array($value)) {
            if (!array_is_list($value) || !$value || count($value) > 100) throw new RuntimeException('记录值数组格式不正确');
            foreach ($value as $item) if (!is_string($item) || str_contains($item, "\0")) throw new RuntimeException('记录值数组格式不正确');
        }
        foreach (['line' => 255, 'remark' => 2000] as $key => $limit) if (isset($record[$key]) && ((!is_string($record[$key]) && !is_int($record[$key])) || strlen((string)$record[$key]) > $limit)) throw new RuntimeException('线路或备注格式不正确');
        foreach (['mx', 'weight'] as $key) if (isset($record[$key]) && (!is_numeric($record[$key]) || $record[$key] < 0 || $record[$key] > 65535)) throw new RuntimeException('优先级或权重范围不正确');
        if (isset($record['ttl']) && (!is_numeric($record['ttl']) || $record['ttl'] < 1 || $record['ttl'] > 2147483647)) throw new RuntimeException('TTL范围不正确');
        if (isset($record['remark']) && (!is_string($record['remark']) || strlen($record['remark']) > 2000)) throw new RuntimeException('备注过长');
    }

    /** Bind imported zone IDs to provider-owned names, never to client labels. */
    public static function assertDomainBindings(object $dns, array $domains): void
    {
        $pending = [];
        foreach ($domains as $domain) {
            $name = self::domainName($domain['name'] ?? null);
            $id = $domain['id'] ?? null;
            if ((!is_string($id) && !is_int($id)) || (string)$id === '' || strlen((string)$id) > 255) throw new RuntimeException('域名ID格式不正确');
            $pending[$name] = (string)$id;
        }
        if (!$pending || count($pending) > 500) throw new RuntimeException('每批应包含1至500个域名');
        $keyword = count($pending) === 1 ? array_key_first($pending) : null;
        $seen = [];
        for ($page = 1; $page <= 100; $page++) {
            $result = $dns->getDomainList($keyword, $page, 100);
            if (!is_array($result) || !is_array($result['list'] ?? null) || !is_numeric($result['total'] ?? null) || $result['total'] < 0 || count($result['list']) > 10000) throw new RuntimeException('无法从DNS平台核实域名与ID，请稍后重试');
            foreach ($result['list'] as $row) {
                $name = self::domainName($row['Domain'] ?? null);
                $id = $row['DomainId'] ?? null;
                if ((!is_string($id) && !is_int($id)) || isset($seen[(string)$id])) throw new RuntimeException('DNS平台域名分页重复或ID格式不正确');
                $seen[(string)$id] = true;
                if (isset($pending[$name]) && hash_equals($pending[$name], (string)$id)) unset($pending[$name]);
            }
            if (!$pending) return;
            if (!$result['list'] || count($seen) >= (int)$result['total'] || count($seen) >= 10000) break;
        }
        throw new RuntimeException('域名与DNS平台ID不匹配或超出10000项核实上限，请重新从平台列表导入');
    }

    /** Obtain a complete bounded zone, including adapters that return an entire zone on page 1. */
    public static function all(object $dns, string $domain, bool $includeGroups = false): array
    {
        self::$fullZoneReads++;
        try { return self::readAll($dns, $domain, $includeGroups); }
        finally { self::$fullZoneReads--; }
    }

    private static function readAll(object $dns, string $domain, bool $includeGroups): array
    {
        $domain = self::domainName($domain);
        $records = [];
        $seen = [];
        $hosts = [];
        for ($page = 1; $page <= 101; $page++) {
            $result = $dns->getDomainRecords($page, 100);
            if (!is_array($result) || !isset($result['total'], $result['list']) || !is_array($result['list']) || !is_numeric($result['total']) || $result['total'] < 0 || $result['total'] > self::MAX_RECORDS) throw new RuntimeException('无法完整验证域名记录（查询失败或记录数超过10000）');
            if (count($result['list']) > self::MAX_RECORDS) throw new RuntimeException('域名记录超过安全处理上限');
            if (!$result['list'] && count($seen) < (int)$result['total']) throw new RuntimeException('域名记录分页不完整，请重试');
            foreach ($result['list'] as $record) {
                self::assertListedRecord($record, $domain);
                $key = (string)$record['RecordId'];
                if (isset($seen[$key])) throw new RuntimeException('域名记录分页重复或记录ID不唯一，请重试');
                $seen[$key] = true;
                if (($record['Type'] ?? null) === null && isset($record['Count'])) {
                    // QingCloud lists hosts first; value IDs are only available from each host.
                    $hosts[] = $record;
                    if ($includeGroups) $records[] = $record;
                } else {
                    $records[] = $record;
                }
            }
            if (count($seen) >= (int)$result['total']) break;
            if ($page === 101) throw new RuntimeException('域名记录分页超过安全处理上限');
        }
        foreach ($hosts as $host) {
            $result = $dns->getSubDomainRecords($host['Name'], 1, self::MAX_RECORDS);
            if (!is_array($result) || !isset($result['list']) || !is_array($result['list']) || (isset($result['total']) && $result['total'] > count($result['list']))) throw new RuntimeException('无法完整验证主机记录');
            foreach ($result['list'] as $record) {
                self::assertListedRecord($record, $domain);
                if (($record['Type'] ?? null) === null) throw new RuntimeException('主机记录详情不完整');
                $records[] = $record;
                if (count($records) > self::MAX_RECORDS) throw new RuntimeException('域名记录超过安全处理上限');
            }
        }
        return $records;
    }

    private static function assertListedRecord($record, string $domain): void
    {
        if (!is_array($record) || !array_key_exists('RecordId', $record) || (!is_int($record['RecordId']) && !is_string($record['RecordId'])) || (string)$record['RecordId'] === '' || strlen((string)$record['RecordId']) > 2048) throw new RuntimeException('DNS平台返回了无效记录');
        if (isset($record['Domain'])) {
            $actual = self::domainName($record['Domain']);
            if ($actual !== $domain && !str_ends_with($actual, '.' . $domain)) throw new RuntimeException('DNS平台返回了其他域名的记录');
        }
    }

    public static function owned(object $dns, string $domain, array $ids): array
    {
        if (!$ids || count($ids) > self::MAX_BATCH) throw new RuntimeException('每批应包含1至500条记录');
        $wanted = [];
        foreach ($ids as $id) {
            if ((!is_string($id) && !is_int($id)) || (string)$id === '' || strlen((string)$id) > 2048) throw new RuntimeException('记录ID无效');
            if (array_key_exists((string)$id, $wanted)) throw new RuntimeException('记录ID不能重复');
            $wanted[(string)$id] = null;
        }
        foreach (self::all($dns, $domain, true) as $record) {
            if (array_key_exists((string)$record['RecordId'], $wanted)) $wanted[(string)$record['RecordId']] = $record;
        }
        foreach ($wanted as $record) if ($record === null) throw new RuntimeException('记录不存在或不属于当前域名，请刷新后重试');
        return array_values($wanted);
    }

    /** Exclude provider-generated timestamps and IDs from semantic comparisons. */
    public static function canonical(array $record): array
    {
        $out = [];
        foreach (['Name', 'Type', 'Value', 'Line', 'TTL', 'MX', 'Status', 'Weight', 'Remark', 'Mode'] as $key) {
            $out[$key] = $record[$key] ?? null;
            if ($out[$key] !== null && !is_array($out[$key])) $out[$key] = (string)$out[$key];
        }
        return $out;
    }

    public static function fingerprint(array $records): string
    {
        $rows = array_map(fn($r) => json_encode(self::canonical($r), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $records);
        sort($rows, SORT_STRING);
        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }

    public static function selectForBatchEdit(array $records, string $name, string $type, string $line): array
    {
        $records = array_filter($records, fn($r) => strcasecmp((string)$r['Name'], $name) === 0);
        $list = array_filter($records, fn($r) => $r['Type'] === $type);
        if (!$list && in_array($type, ['A', 'AAAA', 'CNAME'], true)) $list = array_filter($records, fn($r) => in_array($r['Type'], ['A', 'AAAA', 'CNAME'], true));
        $preferred = array_filter($list, fn($r) => (string)$r['Line'] === $line);
        return array_values($preferred ?: $list);
    }
}
