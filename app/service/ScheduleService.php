<?php
namespace app\service;

use think\facade\Db;
use app\lib\DnsHelper;
use app\lib\GuardedDns;

/** 域名定时切换解析。每月指定日期不存在时在该月最后一天执行。 */
class ScheduleService
{
    private $connection;

    private function db()
    {
        if (!$this->connection) $this->connection = TaskLockService::connection();
        return $this->connection;
    }

    public function execute()
    {
        $list = $this->db()->name('sctask')->where('nexttime', '>', 0)->where('nexttime', '<=', time())->where('active', 1)->select();
        if (count($list) == 0) return false;
        echo '开始执行定时切换解析任务，共获取到' . count($list) . '个待执行任务' . "\n";
        foreach ($list as $row) {
            try {
                if ($this->execute_one($row)) echo '定时切换任务' . $row['id'] . '执行成功' . "\n";
            } catch (\Throwable $e) {
                echo '定时切换任务' . $row['id'] . '执行失败,' . $e->getMessage() . "\n";
            }
        }
        config_set('schedule_time', date('Y-m-d H:i:s'));
        return true;
    }

    public function execute_one($row)
    {
        $lock = new TaskLockService($this->db(), 'schedule:' . (int)$row['id']);
        $lock->acquire();
        try {
            $current = $this->db()->name('sctask')->where('id', $row['id'])->find();
            if (!$current || !$current['active'] || !$current['nexttime'] || $current['nexttime'] > time()) return false;
            // Do not run a stale occurrence that another worker has already completed.
            if ((int)$current['nexttime'] !== (int)$row['nexttime']) return false;
            $row = $current;
            $this->db()->name('sctask')->where('id', $row['id'])->update(['updatetime' => time()]);
            try {
                self::validate($row);
                $this->switchRecord($row);
                $this->update_nexttime($row, true);
            } catch (\Throwable $e) {
                // Including one-shot schedules: a failed remote operation is still due.
                $this->db()->name('sctask')->where('id', $row['id'])->update(['nexttime' => time() + 60]);
                throw $e;
            }
            return true;
        } finally {
            $lock->release();
        }
    }

    private function switchRecord(array $row): void
    {
        $drow = $this->db()->name('domain')->alias('A')->join('account B', 'A.aid = B.id')->where('A.id', $row['did'])->field('A.*,B.type,B.config')->find();
        if (!$drow) throw new \RuntimeException('域名不存在');
        \app\lib\AutomaticDnsTarget::validate($row, $drow);
        $domain = $row['rr'] . '.' . $drow['name'];
        $dns = DnsHelper::getModel2($drow);
        if (!$dns) throw new \RuntimeException('DNS模块不存在');
        $dns = new GuardedDns($dns, $drow, $this->db());
        $actions = [1 => '启用解析', 2 => '暂停解析', 3 => '删除解析', 0 => '修改解析'];
        $action = $actions[(int)$row['switchtype']] ?? '修改解析';
        if ($row['switchtype'] == 1) {
            $res = $dns->setDomainRecordStatus($row['recordid'], '1');
        } elseif ($row['switchtype'] == 2) {
            $res = $dns->setDomainRecordStatus($row['recordid'], '0');
        } elseif ($row['switchtype'] == 3) {
            $res = $dns->deleteDomainRecord($row['recordid']);
        } else {
            $recordinfo = json_decode($row['recordinfo'], true);
            if (!is_array($recordinfo) || !isset($recordinfo['Line'], $recordinfo['TTL'])) throw new \RuntimeException('解析记录信息不完整，无法执行切换');
            if ($drow['type'] == 'cloudflare' && !isNullOrEmpty($row['line'])) $recordinfo['Line'] = $row['line'];
            $res = $dns->updateDomainRecord($row['recordid'], $row['rr'], getDnsType($row['value']), $row['value'], $recordinfo['Line'], $recordinfo['TTL']);
        }
        if (!$res) {
            $error = (string)$dns->getError();
            $this->add_log($domain, $action . '失败', $error);
            throw new \RuntimeException($error ?: '云端DNS操作失败');
        }
        if ((int)$row['switchtype'] === 0) {
            $newId = \app\lib\AutomaticDnsTarget::afterUpdate($dns, $drow, $row['rr'], $row['value'], $recordinfo['Line']);
            if ($newId !== null) $this->db()->name('sctask')->where('id', $row['id'])->update(['recordid'=>$newId]);
        }
        $this->add_log($domain, $action, '定时' . $action . '成功');
    }

    public static function validate(array $row): void
    {
        foreach (['switchtime'=>32,'switchdate'=>32,'rr'=>253,'recordid'=>2048,'value'=>131072,'line'=>2048,'remark'=>2000,'recordinfo'=>65536] as $field => $maximum) {
            if (isset($row[$field]) && ((!is_string($row[$field]) && !is_int($row[$field])) || strlen((string)$row[$field]) > $maximum)) throw new \InvalidArgumentException('定时策略参数格式不正确或过长');
        }
        foreach (['type'=>[0, 1], 'cycle'=>[0, 1, 2], 'switchtype'=>[0, 1, 2, 3]] as $field => $allowed) {
            $integer = filter_var($row[$field] ?? null, FILTER_VALIDATE_INT);
            if ($integer === false || !in_array($integer, $allowed, true)) throw new \InvalidArgumentException('定时策略类型无效');
        }
        if ((int)$row['type'] === 1) {
            if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', (string)$row['switchtime'])) throw new \InvalidArgumentException('执行时间格式应为 HH:MM');
            if ($row['cycle'] == 1 && (!ctype_digit((string)$row['switchdate']) || (int)$row['switchdate'] > 6)) throw new \InvalidArgumentException('每周执行日应为0到6');
            if ($row['cycle'] == 2 && (!ctype_digit((string)$row['switchdate']) || (int)$row['switchdate'] < 1 || (int)$row['switchdate'] > 31)) throw new \InvalidArgumentException('每月执行日应为1到31');
        } else {
            $value = str_replace('T', ' ', (string)$row['switchtime']);
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $value);
            if (!$parsed || $parsed->format('Y-m-d H:i') !== $value) throw new \InvalidArgumentException('执行时间无效');
        }
    }

    public static function calculateNextTime(array $row, ?int $now = null): int
    {
        self::validate($row);
        $now = $now ?? time();
        if ((int)$row['type'] !== 1) {
            $next = strtotime($row['switchtime'] . ':00');
            return $next > $now ? $next : 0;
        }
        $today = new \DateTimeImmutable(date('Y-m-d', $now) . ' ' . $row['switchtime'] . ':00');
        if ($row['cycle'] == 2) {
            $month = $today->modify('first day of this month');
            for ($i = 0; $i < 2; $i++) {
                $day = min((int)$row['switchdate'], (int)$month->format('t'));
                $next = $month->setDate((int)$month->format('Y'), (int)$month->format('m'), $day)->getTimestamp();
                if ($next > $now) return $next;
                $month = $month->modify('first day of next month');
            }
        } elseif ($row['cycle'] == 1) {
            $days = ((int)$row['switchdate'] - (int)date('w', $now) + 7) % 7;
            $next = $today->modify('+' . $days . ' days');
            if ($next->getTimestamp() <= $now) $next = $next->modify('+1 week');
            return $next->getTimestamp();
        } else {
            return $today->getTimestamp() > $now ? $today->getTimestamp() : $today->modify('+1 day')->getTimestamp();
        }
        throw new \RuntimeException('无法计算下次执行时间');
    }

    public function update_nexttime($row, bool $completed = false)
    {
        $nexttime = $completed && (int)$row['type'] !== 1 ? 0 : self::calculateNextTime($row);
        $this->db()->name('sctask')->where('id', $row['id'])->update(['nexttime' => $nexttime]);
    }

    private function add_log($domain, $action, $data)
    {
        if (strlen($data) > 500) $data = mb_strcut($data, 0, 500);
        $this->db()->name('log')->insert(['uid' => 0, 'domain' => $domain, 'action' => $action, 'data' => $data, 'addtime' => date('Y-m-d H:i:s')]);
    }
}
