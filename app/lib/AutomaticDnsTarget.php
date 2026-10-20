<?php
namespace app\lib;

/** Persistent tasks must identify a record, never a row number in a zone listing. */
class AutomaticDnsTarget
{
    /** Paired schedules keep referring to the same record after a value switch. */
    public static function retarget($connection, int $did, string $oldId, string $newId, array $metadata = []): void
    {
        if ($oldId === $newId) return;
        $connection->startTrans();
        try {
            foreach (['dmtask','sctask'] as $table) {
                $connection->name($table)->where('did', $did)->where('recordid', $oldId)->update(['recordid'=>$newId] + array_intersect_key($metadata, array_flip(['rr', 'recordinfo'])));
            }
            $connection->commit();
        } catch (\Throwable $e) { $connection->rollback(); throw $e; }
    }

    public static function usesContentId(object $dns): bool
    {
        return $dns instanceof \app\lib\dns\powerdns || $dns instanceof \app\lib\dns\technitium;
    }

    /** All application write paths, including forms and reviewed jobs, share this hook. */
    public static function synchronize(object $dns, array $domain, string $oldId, array $operation, array $records, $connection = null): void
    {
        if (!self::usesContentId($dns)) return;
        $value = $operation['value'];
        if ($dns instanceof \app\lib\dns\powerdns && $operation['type'] === 'TXT' && !str_starts_with($value, '"')) $value = '"' . $value . '"';
        $matches = array_values(array_filter($records, static function ($record) use ($operation, $value) {
            if (strcasecmp($record['Name'], $operation['name']) !== 0 || $record['Type'] !== $operation['type'] || (string)$record['Line'] !== (string)$operation['line']) return false;
            if (in_array($operation['type'], ['CNAME','MX','NS','PTR'], true)) return strcasecmp(rtrim($record['Value'], '.'), rtrim($value, '.')) === 0;
            if (is_string($record['Value']) && is_string($value) && @inet_pton($value) !== false) return @inet_pton($record['Value']) === @inet_pton($value);
            return $record['Value'] === $value;
        }));
        if (count($matches) !== 1) throw new \RuntimeException('DNS已修改，但无法唯一同步关联任务，请核对解析并重新选择任务记录');
        $record = $matches[0];
        self::retarget($connection ?? \think\facade\Db::connect(), (int)$domain['id'], $oldId, (string)$record['RecordId'], ['rr'=>$record['Name'], 'recordinfo'=>json_encode($record, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    }

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
