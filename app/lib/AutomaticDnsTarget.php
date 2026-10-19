<?php
namespace app\lib;

/** Persistent tasks must identify a record, never a row number in a zone listing. */
class AutomaticDnsTarget
{
    public static function validate(array $task, array $domain): void
    {
        $type = $domain['type'] ?? '';
        if (($type === 'powerdns' && !preg_match('/\A[a-f0-9]{20}_[a-f0-9]{20}\z/D', (string)$task['recordid']))
            || ($type === 'technitium' && !preg_match('/\A[a-f0-9]{40}\z/D', (string)$task['recordid']))) {
            throw new \RuntimeException('记录标识已升级，请编辑任务并重新选择解析记录后保存');
        }
    }

    public static function afterUpdate(object $dns, array $domain, string $name, string $value, $line): ?string
    {
        if (!in_array($domain['type'] ?? '', ['powerdns','technitium'], true)) return null;
        $matches = array_values(array_filter(RecordGuard::all($dns, $domain['name']), function ($record) use ($name, $value, $line) {
            $actual = $record['Value'];
            return strcasecmp($record['Name'], $name) === 0 && $record['Type'] === getDnsType($value)
                && (string)$record['Line'] === (string)$line
                && is_string($actual) && ((@inet_pton($actual) !== false && @inet_pton($actual) === @inet_pton($value)) || strcasecmp(rtrim($actual, '.'), rtrim($value, '.')) === 0);
        }));
        if (count($matches) !== 1) throw new \RuntimeException('修改后无法唯一核实任务记录，请重新选择解析记录');
        return (string)$matches[0]['RecordId'];
    }
}
