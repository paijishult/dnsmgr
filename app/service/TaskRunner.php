<?php

namespace app\service;

use app\lib\NewDb;
use app\lib\DnsHelper;
use app\lib\GuardedDns;
use app\utils\CheckUtils;
use app\utils\MsgNotice;

/**
 * 容灾监控任务执行
 */
class TaskRunner
{
    private $conn;

    private function db()
    {
        if (!$this->conn) {
            $config = config('database.connections.mysql');
            $config['break_reconnect'] = false;
            $config['deploy'] = 0;
            $config['rw_separate'] = false;
            $config['params'][\PDO::ATTR_PERSISTENT] = false;
            $this->conn = NewDb::pool($config);
        }
        return $this->conn;
    }

    private function closeDb(bool $broken = false)
    {
        if ($this->conn) {
            NewDb::release($this->conn, $broken);
            $this->conn = null;
        }
    }

    public function execute($row)
    {
        $lock = new TaskLockService($this->db(), 'monitor:' . (int)$row['id']);
        $failure = null;
        $broken = false;
        try {
            $lock->acquire();
            $current = $this->db()->name('dmtask')->where('id', $row['id'])->find();
            if (!$current || empty($current['active'])) return false;
            return $this->handle($current);
        } catch (\Throwable $e) {
            $failure = $e;
            throw $e;
        } finally {
            try {
                $lock->release();
            } catch (\Throwable $unlockError) {
                $broken = true;
                if (!$failure) throw $unlockError;
            } finally {
                $this->closeDb($broken);
            }
        }
    }

    private function dnsModel(array $domain): GuardedDns
    {
        $dns = DnsHelper::getModel2($domain);
        if (!$dns) throw new \RuntimeException('DNS模块不存在');
        return new GuardedDns($dns, $domain, $this->db());
    }

    private function handle($row)
    {
        if ($row['type'] == 3) { //条件开启解析
            $action = 0;
            $remain = $this->db()->name('dmtask')->where(['did' => $row['did'], 'rr' => $row['rr'], 'type' => 1, 'status' => 0])->count();
            if ($remain <= $row['cycle'] && $row['status'] == 0) {
                $action = 2;
            } elseif ($remain > $row['cycle'] && $row['status'] == 1) {
                $action = 1;
            }
        } else {
            if ($row['checktype'] == 2) {
                $result = CheckUtils::curl($row['checkurl'], $row['timeout'], $row['main_value'], $row['proxy'] == 1);
            } elseif ($row['checktype'] == 1) {
                $result = CheckUtils::tcp($row['main_value'], $row['checkurl'], $row['tcpport'], $row['timeout']);
            } else {
                $result = CheckUtils::ping($row['main_value'], $row['checkurl']);
            }

            $action = 0;
            if ($result['status'] && $row['status'] == 1) {
                if ($row['cycle'] <= 1 || $row['errcount'] + 1 >= $row['cycle']) {
                    $action = 2;
                } else {
                    $this->db()->name('dmtask')->where('id', $row['id'])->inc('errcount')->update();
                }
            } elseif (!$result['status'] && $row['status'] == 0) {
                if ($row['cycle'] <= 1 || $row['errcount'] + 1 >= $row['cycle']) {
                    $action = 1;
                } else {
                    $this->db()->name('dmtask')->where('id', $row['id'])->inc('errcount')->update();
                }
            } elseif ($row['errcount'] > 0) {
                $this->db()->name('dmtask')->where('id', $row['id'])->update(['errcount' => 0]);
            }
        }

        if ($action > 0) {
            $drow = $this->db()->name('domain')->alias('A')->join('account B', 'A.aid = B.id')->where('A.id', $row['did'])->field('A.*,B.type,B.config')->find();
            if (!$drow) {
                echo '域名不存在（ID：'.$row['did'].'）'."\n";
                return false;
            }
            $row['domain'] = $row['rr'] . '.' . $drow['name'];
        }
        $res = false;
        if ($action > 0) \app\lib\AutomaticDnsTarget::validate($row, $drow);
        if ($action == 1) {
            if ($row['type'] == 2) {
                $dns = $this->dnsModel($drow);
                $recordinfo = json_decode($row['recordinfo'], true);
                if (!is_array($recordinfo) || !isset($recordinfo['Line'], $recordinfo['TTL'])) {
                    throw new \RuntimeException('解析记录信息不完整，无法执行切换');
                }
                if ($drow['type'] == 'cloudflare' && $row['cdn'] == 1) {
                    $recordinfo['Line'] = '1';
                }
                $res = $dns->updateDomainRecord($row['recordid'], $row['rr'], getDnsType($row['backup_value']), $row['backup_value'], $recordinfo['Line'], $recordinfo['TTL']);
                if (!$res) {
                    $this->db()->name('log')->insert(['uid' => 0, 'domain' => $drow['name'], 'action' => '修改解析失败', 'data' => $dns->getError(), 'addtime' => date("Y-m-d H:i:s")]);
                }
            } elseif ($row['type'] == 1 || $row['type'] == 3) {
                $dns = $this->dnsModel($drow);
                $res = $dns->setDomainRecordStatus($row['recordid'], '0');
                if (!$res) {
                    $this->db()->name('log')->insert(['uid' => 0, 'domain' => $drow['name'], 'action' => '暂停解析失败', 'data' => $dns->getError(), 'addtime' => date("Y-m-d H:i:s")]);
                }
            }
        } elseif ($action == 2) {
            if ($row['type'] == 2) {
                $dns = $this->dnsModel($drow);
                $recordinfo = json_decode($row['recordinfo'], true);
                if (!is_array($recordinfo) || !isset($recordinfo['Line'], $recordinfo['TTL'])) {
                    throw new \RuntimeException('解析记录信息不完整，无法执行切换');
                }
                if ($drow['type'] == 'cloudflare' && $row['cdn'] == 1) {
                    $recordinfo['Line'] = '0';
                }
                $res = $dns->updateDomainRecord($row['recordid'], $row['rr'], getDnsType($row['main_value']), $row['main_value'], $recordinfo['Line'], $recordinfo['TTL']);
                if (!$res) {
                    $this->db()->name('log')->insert(['uid' => 0, 'domain' => $drow['name'], 'action' => '修改解析失败', 'data' => $dns->getError(), 'addtime' => date("Y-m-d H:i:s")]);
                }
            } elseif ($row['type'] == 1 || $row['type'] == 3) {
                $dns = $this->dnsModel($drow);
                $res = $dns->setDomainRecordStatus($row['recordid'], '1');
                if (!$res) {
                    $this->db()->name('log')->insert(['uid' => 0, 'domain' => $drow['name'], 'action' => '启用解析失败', 'data' => $dns->getError(), 'addtime' => date("Y-m-d H:i:s")]);
                }
            }
        } else {
            return false;
        }

        if (!$res) {
            // Keep the previous effective state, so the next check retries this switch.
            return false;
        }
        if ($row['type'] == 2) {
            $value = $action == 1 ? $row['backup_value'] : $row['main_value'];
            $newId = \app\lib\AutomaticDnsTarget::afterUpdate($dns, $drow, $row['rr'], $value, $recordinfo['Line']);
            if ($newId !== null) $this->db()->name('dmtask')->where('id', $row['id'])->update(['recordid'=>$newId]);
        }
        $nextStatus = $row['type'] == 3 ? ($action == 2 ? 1 : 0) : ($action == 1 ? 1 : 0);
        $this->db()->name('dmtask')->where('id', $row['id'])->update(['status' => $nextStatus, 'errcount' => 0, 'switchtime' => time()]);
        $row['status'] = $nextStatus;
        $this->db()->name('dmlog')->insert([
            'taskid' => $row['id'],
            'action' => $action,
            'errmsg' => isset($result) ? ($result['status'] ? null : $result['errmsg']) : null,
            'date' => date('Y-m-d H:i:s')
        ]);

        if ($row['type'] != 3) {
            MsgNotice::send($action, $row, $result);
        }
        return true;
    }
}
