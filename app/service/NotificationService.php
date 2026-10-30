<?php
namespace app\service;

use think\facade\Db;

/** Durable per-channel delivery. Acknowledgements happen only after every channel succeeds. */
class NotificationService
{
    public static function enqueue(string $event, string $title, string $content, array $channels, array $ack = []): bool
    {
        if (!$channels) return true;
        $channels = array_values(array_unique($channels));
        sort($channels, SORT_STRING);
        $db = TaskLockService::connection();
        try {
            $db->startTrans();
            foreach ($channels as $channel) {
                $key = hash('sha256', $event . ':' . $channel);
                $row = ['dedup'=>$key, 'event'=>hash('sha256', $event), 'channel'=>$channel, 'payload'=>json_encode(['title'=>$title,'content'=>$content,'ack'=>$ack], JSON_THROW_ON_ERROR), 'status'=>0, 'attempts'=>0, 'nexttime'=>time(), 'addtime'=>time()];
                // Unique deduplication protects concurrent cron and manual requests.
                try { $db->name('notification_job')->insert($row); }
                catch (\Throwable $e) { if (!$db->name('notification_job')->where('dedup', $key)->lock(true)->find()) throw $e; }
            }
            // Make the complete channel set visible at once. A fast worker must not
            // acknowledge the event while remaining channels are still being added.
            $db->commit();
        } catch (\Throwable $e) { $db->rollback(); throw $e; }
        finally { $db->close(); }
        return Db::name('notification_job')->where('event', hash('sha256', $event))->where('status', '<>', 1)->count() === 0;
    }

    public static function execute(int $limit = 10): void
    {
        $rows = Db::name('notification_job')->where('status', 0)->where('nexttime', '<=', time())->order('id')->limit($limit)->select();
        foreach ($rows as $row) {
            $db = TaskLockService::connection(); $lock = new TaskLockService($db, 'notification-event:' . $row['event']);
            try {
                $lock->acquire();
                $row = $db->name('notification_job')->where('id', $row['id'])->find();
                if (!$row || (int)$row['status'] !== 0 || $row['nexttime'] > time()) continue;
                $payload = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
                try {
                    $result = \app\utils\MsgNotice::deliver($row['channel'], $payload['title'], $payload['content']);
                    if ($result !== true) throw new \RuntimeException(is_string($result) ? $result : '通知渠道未配置或发送失败');
                    $db->startTrans();
                    try {
                        $db->name('notification_job')->where('id', $row['id'])->update(['status'=>1,'error'=>null,'attempts'=>$row['attempts']+1]);
                        if ($db->name('notification_job')->where('event', $row['event'])->where('status','<>',1)->count() === 0) self::acknowledge($db, $payload['ack'] ?? []);
                        $db->commit();
                    } catch (\Throwable $e) { $db->rollback(); throw $e; }
                } catch (\Throwable $e) {
                    $attempts = $row['attempts'] + 1;
                    $db->name('notification_job')->where('id', $row['id'])->update(['status'=>$attempts >= 8 ? -1 : 0,'attempts'=>$attempts,'nexttime'=>time()+min(21600, 60 * (2 ** min($attempts, 9))), 'error'=>mb_substr(\app\lib\SensitiveLog::filter($e->getMessage()),0,500)]);
                    continue;
                }
            } catch (\Throwable $e) {
                if ($e->getCode() !== 102) error_log('Notification job ' . $row['id'] . ': ' . \app\lib\SensitiveLog::filter($e->getMessage()));
            } finally { try { $lock->release(); } finally { $db->close(); } }
        }
        Db::name('notification_job')->where('status',1)->where('addtime','<',time()-30*86400)->delete();
    }

    private static function acknowledge($db, array $ack): void
    {
        if (!in_array($ack['table'] ?? '', ['cert_order','cert_deploy','domain'], true) || empty($ack['where']) || empty($ack['update'])) return;
        $db->name($ack['table'])->where($ack['where'])->update($ack['update']);
    }
}
