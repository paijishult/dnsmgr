<?php

declare(strict_types=1);

namespace app\command;

use Exception;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;
use think\facade\Config;
use app\service\TaskRunner;

class Dmtask extends Command
{
    protected function configure()
    {
        // 指令配置
        $this->setName('dmtask')
            ->setDescription('容灾切换任务');
    }

    protected function execute(Input $input, Output $output)
    {
        $res = Db::name('config')->cache('configs', 0)->column('value', 'key');
        Config::set($res, 'sys');
        \app\service\FeatureMigrationService::ensure();

        config_set('run_error', '');
        if (!extension_loaded('swoole')) {
            $output->writeln('[Error] 未安装Swoole扩展');
            config_set('run_error', '未安装Swoole扩展');
            return;
        }
        try {
            $output->writeln('进程启动成功.');
            $this->runtask();
        } catch (\Throwable $e) {
            $output->writeln('[Error] ' . $e->getMessage());
            config_set('run_error', $e->getMessage());
        }
    }

    private function runtask()
    {
        $hookFlags = SWOOLE_HOOK_ALL;
        if (!function_exists('proc_open') && defined('SWOOLE_HOOK_PROC')) {
            $hookFlags &= ~SWOOLE_HOOK_PROC;
        }
        \Swoole\Coroutine::set(['hook_flags' => $hookFlags]);
        \Swoole\Coroutine\run(function () {
            $date = date("Ymd");
            $count = config_get('run_count', null, true) ?? 0;
            $lastCount = -1;
            $lastBeat = 0;
            while (true) {
                sleep(1);
                if ($date != date("Ymd")) {
                    $count = 0;
                    $date = date("Ymd");
                }

                $rows = Db::name('dmtask')->where('checknexttime', '<=', time())->where('active', 1)->order('id', 'ASC')->select();
                foreach ($rows as $row) {
                    // Claim the scheduled occurrence before starting a coroutine. Two
                    // monitor processes must not count the same check twice.
                    $claimed = Db::name('dmtask')->where('id', $row['id'])
                        ->where('active', 1)->where('checknexttime', $row['checknexttime'])
                        ->update(['checktime' => time(), 'checknexttime' => time() + max(1, (int)$row['frequency'])]);
                    if (!$claimed) continue;
                    \go(function () use ($row) {
                        try {
                            (new TaskRunner())->execute($row);
                        } catch (\Swoole\ExitException $e) {
                            echo $e->getStatus() . "\n";
                        } catch (\Throwable $e) {
                            echo $e->__toString() . "\n";
                        }
                    });
                    $count++;
                }

                // 降低数据库写入频率：运行状态每3秒更新一次（后台判断10秒内有心跳即为运行中）
                if (time() - $lastBeat >= 3 || $count != $lastCount) {
                    if (time() - $lastBeat >= 3) {
                        config_set('run_time', date("Y-m-d H:i:s"));
                        $lastBeat = time();
                    }
                    if ($count != $lastCount) {
                        config_set('run_count', $count);
                        $lastCount = $count;
                    }
                }
            }
        });
    }
}
