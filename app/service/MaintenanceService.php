<?php
namespace app\service;

/** Web cron and CLI execute exactly the same durable maintenance queues. */
class MaintenanceService
{
    public static function execute(): void
    {
        $jobs = [
            '批量任务'=>fn()=>(new BatchTaskService())->execute(),
            'DNS清理'=>fn()=>CertDnsService::retryCleanup(static fn($line)=>print($line . PHP_EOL)),
            '定时任务'=>fn()=>(new ScheduleService())->execute(),
            '优选IP'=>fn()=>(new OptimizeService())->execute(),
            '证书任务'=>fn()=>(new CertTaskService())->execute(),
            '到期提醒'=>fn()=>(new ExpireNoticeService())->task(),
            '运行检查'=>fn()=>OperationsService::execute(),
            '容灾进程'=>fn()=>OperationsService::monitorWatchdog(),
            '通知队列'=>fn()=>NotificationService::execute(),
        ];
        foreach ($jobs as $name=>$job) {
            try { $job(); }
            catch (\Throwable $e) { echo $name . '：' . \app\lib\SensitiveLog::filter($e->getMessage()) . PHP_EOL; }
        }
        config_set('maintenance_time', date('Y-m-d H:i:s'));
    }
}
