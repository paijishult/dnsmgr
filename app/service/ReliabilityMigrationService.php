<?php
namespace app\service;

/** Widen columns once, verify their actual type, and remain restartable after DDL failures. */
class ReliabilityMigrationService
{
    public static function apply($db, string $prefix): void
    {
        $shared = ['rr'=>'varchar(253)', 'recordid'=>'varchar(2048)', 'recordinfo'=>'mediumtext', 'remark'=>'text'];
        $tables = [
            'dmtask'=>$shared + ['main_value'=>'varchar(2048)', 'backup_value'=>'varchar(2048)', 'checkurl'=>'text', 'frequency'=>'int unsigned', 'cycle'=>'int unsigned', 'errcount'=>'int unsigned', 'health_options'=>'text'],
            'sctask'=>$shared + ['value'=>'varchar(2048)', 'line'=>'varchar(255)'],
            'cert_deploy'=>['info'=>'mediumtext'],
        ];
        foreach ($tables as $table=>$fields) {
            $name = $prefix . $table;
            $columns = array_column($db->query('SHOW COLUMNS FROM `' . $name . '`'), null, 'Field');
            foreach ($fields as $field=>$type) {
                $column = $columns[$field] ?? null;
                $actual = $column ? preg_replace('/int\(\d+\)/', 'int', strtolower($column['Type'])) : '';
                if (self::sufficient($actual, $type)) continue;
                if (!$column && $field !== 'health_options') throw new \RuntimeException('升级所需字段缺失：' . $name . '.' . $field);
                $nullable = !$column || $column['Null'] === 'YES';
                $default = $nullable ? ' NULL' : ' NOT NULL';
                if (str_starts_with($type, 'int')) $default .= ' DEFAULT ' . (int)($column['Default'] ?? 0);
                $db->execute('ALTER TABLE `' . $name . '` ' . ($column ? 'MODIFY' : 'ADD') . ' COLUMN `' . $field . '` ' . $type . $default);
                $after = array_column($db->query('SHOW COLUMNS FROM `' . $name . '`'), null, 'Field');
                if (preg_replace('/int\(\d+\)/', 'int', strtolower($after[$field]['Type'] ?? '')) !== $type) throw new \RuntimeException('数据库字段升级未完成');
            }
            $db->getSchemaInfo($name, true);
        }
    }

    private static function sufficient(string $actual, string $required): bool
    {
        if ($actual === $required) return true;
        $textSizes = ['text'=>65535, 'mediumtext'=>16777215, 'longtext'=>4294967295];
        if (preg_match('/\Avarchar\((\d+)\)\z/D', $required, $need)) {
            if (preg_match('/\Avarchar\((\d+)\)\z/D', $actual, $have)) return (int)$have[1] >= (int)$need[1];
            return isset($textSizes[$actual]) && $textSizes[$actual] >= (int)$need[1] * 4;
        }
        if (isset($textSizes[$required], $textSizes[$actual])) return $textSizes[$actual] >= $textSizes[$required];
        return $required === 'int unsigned' && in_array($actual, ['bigint','bigint unsigned'], true);
    }
}
