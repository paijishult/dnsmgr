<?php
namespace app\service;

/** MySQL session locks survive transactions and cannot be stolen from a live worker. */
class TaskLockService
{
    private $connection;
    private $name;
    private $held = false;

    public function __construct($connection, string $resource)
    {
        $this->connection = $connection;
        $database = (string)config('database.connections.mysql.database', '');
        $prefix = (string)config('database.connections.mysql.prefix', '');
        $this->name = 'dnsmgr:' . substr(hash('sha256', $database . ':' . $prefix . ':' . $resource), 0, 48);
    }

    public function acquire(): void
    {
        $result = $this->connection->query('SELECT GET_LOCK(:task_lock, 0) AS acquired', ['task_lock' => $this->name], true);
        if (!isset($result[0]['acquired']) || (int)$result[0]['acquired'] !== 1) {
            throw new \RuntimeException('任务正在处理中，请稍后再试', 102);
        }
        $this->held = true;
    }

    public function release(): void
    {
        if (!$this->held) return;
        $this->held = false;
        $this->connection->query('SELECT RELEASE_LOCK(:task_lock) AS released', ['task_lock' => $this->name], true);
    }

    /** A dedicated non-reconnecting connection keeps the lock on its original session. */
    public static function connection()
    {
        $config = config('database.connections.mysql');
        $config['break_reconnect'] = false;
        $config['deploy'] = 0;
        $config['rw_separate'] = false;
        $config['params'][\PDO::ATTR_PERSISTENT] = false;
        return \think\facade\Db::connect($config, true);
    }
}
