<?php
namespace app\service;

use app\lib\DnsHelper;
use app\lib\GuardedDns;
use RuntimeException;
use think\facade\Db;

/** ACME records coexist with other orders; leases distinguish our values from pre-existing DNS. */
class CertDnsService
{
    public static function add(array $dnsList, callable $log, bool $cname, int $oid): void
    {
        foreach ($dnsList as $main => $rows) foreach ($rows as $row) {
            [$domain, $row] = self::resolve($main, $row, $cname);
            $model = DnsHelper::getModel($domain['aid'], $domain['name'], $domain['thirdid']);
            if (!$model) throw new RuntimeException('DNS模块不存在');
            $dns = new GuardedDns($model, $domain);
            try {
                $records = self::matching($dns->records(), $row);
                $existing = array_values(array_filter($records, fn($r) => self::contains($r['Value'], $row['value'], $row['type'])));
                $lease = self::lease($oid, $domain, $row);
                if ($oid > 0) {
                    $managed = $existing && Db::name('cert_dns_lease')->where('claim', $lease['claim'])->where('managed', 1)->count() > 0;
                    $lease['managed'] = $managed ? 1 : 0;
                    // Persist intent before the provider write. An interrupted uncertain write is never assumed owned.
                    Db::name('cert_dns_lease')->replace()->insert($lease);
                }
                if ($existing) { $log('保留已有验证记录：' . $row['name'] . '.' . $domain['name']); continue; }
                if ($row['type'] === 'CNAME' && $records) throw new RuntimeException('验证CNAME与已有记录冲突，请人工核对；未删除已有记录');
                $line = DnsHelper::$line_name[$domain['type']]['DEF'] ?? 'default';
                $ttl = $domain['type'] === 'namesilo' ? 3600 : 600;
                self::assertBinding($domain);
                if ($domain['type'] === 'huawei' && $records) {
                    if (count($records) !== 1 || !is_array($records[0]['Value'])) throw new RuntimeException('验证记录集无法唯一定位');
                    $record = $records[0];
                    $values = array_merge($record['Value'], [self::wireValue($row)]);
                    $ok = $dns->updateDomainRecord($record['RecordId'], $row['name'], $row['type'], $values, $record['Line'], $record['TTL'], $record['MX'] ?? 1, $record['Weight'] ?? null, $record['Remark'] ?? null);
                } else {
                    $ok = $dns->addDomainRecord($row['name'], $row['type'], $row['value'], $line, $ttl);
                }
                if (!$ok) throw new RuntimeException('添加验证记录失败：' . $dns->getError());
                if ($oid > 0) Db::name('cert_dns_lease')->where('claim', $lease['claim'])->update(['managed' => 1]);
                $log('Add DNS Record: ' . $row['name'] . '.' . $domain['name'] . ' ' . $row['type']);
            } finally { unset($dns); }
        }
    }

    public static function remove(int $oid, callable $log): void
    {
        if ($oid <= 0) { $log('旧订单没有DNS创建凭据，保留验证记录，请按需人工清理'); return; }
        foreach (Db::name('cert_dns_lease')->where('oid', $oid)->select() as $lease) {
            $domain = Db::name('domain')->where('id', $lease['did'])->find();
            if (!$domain || (string)$domain['aid'] !== (string)$lease['aid'] || $domain['name'] !== $lease['domain'] || (string)$domain['thirdid'] !== (string)$lease['thirdid']) {
                throw new RuntimeException('验证域名已删除或重新绑定，停止自动清理');
            }
            $model = DnsHelper::getModel($domain['aid'], $domain['name'], $domain['thirdid']);
            if (!$model) throw new RuntimeException('DNS模块不存在');
            $dns = new GuardedDns($model, $domain);
            try {
                if ($lease['managed'] && Db::name('cert_dns_lease')->where('claim', $lease['claim'])->where('oid', '<>', $oid)->count() === 0) {
                    self::assertBinding($domain);
                    $row = ['name' => $lease['rr'], 'type' => $lease['type'], 'value' => $lease['value']];
                    $records = array_values(array_filter(self::matching($dns->records(), $row), fn($r) => self::contains($r['Value'], $row['value'], $row['type'])));
                    if (count($records) > 1) throw new RuntimeException('验证记录不唯一，停止自动清理');
                    foreach ($records as $record) {
                        $remaining = is_array($record['Value']) ? array_values(array_filter($record['Value'], fn($v) => !self::contains($v, $row['value'], $row['type']))) : [];
                        $ok = $remaining
                            ? $dns->updateDomainRecord($record['RecordId'], $row['name'], $row['type'], $remaining, $record['Line'], $record['TTL'], $record['MX'] ?? 1, $record['Weight'] ?? null, $record['Remark'] ?? null)
                            : $dns->deleteDomainRecord($record['RecordId']);
                        if (!$ok) throw new RuntimeException('清理验证记录失败：' . $dns->getError());
                        $log('Delete DNS Record: ' . $row['name'] . '.' . $domain['name'] . ' ' . $row['type']);
                    }
                }
                Db::name('cert_dns_lease')->where('oid', $oid)->where('claim', $lease['claim'])->delete();
            } finally { unset($dns); }
        }
    }

    private static function resolve(string $main, array $row, bool $cname): array
    {
        $query = fn($name) => Db::name('domain')->alias('A')->join('account B', 'A.aid = B.id')->where('A.name', $name)->field('A.*,B.type')->find();
        $domain = $query($main);
        if (!$domain && str_starts_with($main, 'xn--') && function_exists('idn_to_utf8')) $domain = $query(idn_to_utf8($main));
        if (!$domain && $cname) {
            $name = $row['name'] === '_acme-challenge' ? $main : preg_replace('/^_acme-challenge\./', '', $row['name']) . '.' . $main;
            $alias = Db::name('cert_cname')->alias('A')->join('domain B', 'A.did = B.id')->where('A.domain', $name)->field('A.*,B.name cnamedomain')->find();
            if ($alias) { $domain = $query($alias['cnamedomain']); $row['name'] = $alias['rr']; }
        }
        if (!$domain) throw new RuntimeException('域名' . $main . '未在本系统添加');
        return [$domain, $row];
    }

    private static function matching(array $records, array $row): array
    {
        return array_values(array_filter($records, fn($r) => strcasecmp($r['Name'], $row['name']) === 0 && ($r['Type'] ?? '') === $row['type']));
    }

    private static function assertBinding(array $domain): void
    {
        $fresh = Db::name('domain')->where('id', $domain['id'])->find();
        foreach (['aid','name','thirdid'] as $field) {
            if (!$fresh || (string)$fresh[$field] !== (string)$domain[$field]) throw new RuntimeException('验证域名已删除或重新绑定，停止DNS变更');
        }
    }

    private static function contains($actual, string $expected, string $type): bool
    {
        foreach ((array)$actual as $value) {
            if ($type === 'TXT' && str_starts_with($value, '"') && str_ends_with($value, '"')) $value = stripcslashes(substr($value, 1, -1));
            if ($type === 'CNAME' ? strcasecmp(rtrim($value, '.'), rtrim($expected, '.')) === 0 : $value === $expected) return true;
        }
        return false;
    }

    private static function wireValue(array $row): string
    {
        return $row['type'] === 'TXT' ? '"' . addcslashes($row['value'], "\\\"") . '"' : $row['value'];
    }

    private static function lease(int $oid, array $domain, array $row): array
    {
        $value = $row['type'] === 'CNAME' ? strtolower(rtrim($row['value'], '.')) : $row['value'];
        $claim = hash('sha256', json_encode([(string)$domain['aid'], (string)$domain['thirdid'], strtolower($domain['name']), strtolower($row['name']), $row['type'], $value], JSON_THROW_ON_ERROR));
        return ['oid' => $oid, 'claim' => $claim, 'did' => $domain['id'], 'aid' => $domain['aid'], 'thirdid' => (string)$domain['thirdid'], 'domain' => $domain['name'], 'rr' => $row['name'], 'type' => $row['type'], 'value' => $row['value'], 'managed' => 0];
    }
}
