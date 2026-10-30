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
        $iouringError = $this->iouringError();
        if ($iouringError !== null) {
            $output->writeln('[Error] ' . $iouringError);
            config_set('run_error', $iouringError);
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

    /**
     * Swoole built with io_uring terminates the whole process (exit 1, no exception) when
     * the ring cannot be created, e.g. under Docker's default seccomp profile or on kernels
     * older than the build host. Probe it in a child process so the reason reaches the UI.
     */
    private function iouringError(): ?string
    {
        ob_start();
        (new \ReflectionExtension('swoole'))->info();
        $info = (string)ob_get_clean();
        if (!preg_match('/\bio_uring\s*=>\s*enabled\b/i', $info) || !function_exists('proc_open')) {
            return null;
        }
        $code = 'Swoole\Coroutine\run(function(){Swoole\Coroutine::sleep(0.001);});echo "ok";';
        $process = @proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return null;
        }
        $stdout = (string)stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode === 0 && trim($stdout) === 'ok') {
            return null;
        }
        return 'Swoole扩展启用了io_uring，但当前环境不允许使用（Docker默认安全策略会禁止，或内核版本过低），容灾监控进程无法运行。请改用未启用io_uring编译的Swoole，或更新到最新的Docker镜像';
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
