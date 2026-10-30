<?php

namespace app\service;

use Exception;
use think\facade\Db;
use app\lib\CertHelper;
use app\utils\CertDnsUtils;

/**
 * SSL证书订单处理
 */
class CertOrderService
{
    private static $retry_interval = [60, 180, 300, 600, 600];

    private $db;
    private $executionLock;
    private $lockDepth = 0;
    private ?\Closure $authorization;
    private $client;
    private $aid;
    private $atype;
    private $order;
    private $info;
    private $dnsList;
    private $domainList;
    private $cnameDomainList = [];
    private $domainsAliasList = [];

    // 订单状态：0:待提交 1:待验证 2:正在验证 3:已签发 4:已吊销 -1:购买证书失败 -2:创建订单失败 -3:添加DNS失败 -4:验证DNS失败 -5:验证订单失败 -6:订单验证未通过 -7:签发证书失败
    public function __construct($oid, ?\Closure $authorization = null)
    {
        $this->authorization = $authorization;
        $this->db = TaskLockService::connection();
        $this->loadOrder($oid);
    }

    private function loadOrder($oid)
    {
        $order = $this->db->name('cert_order')->where('id', $oid)->find();
        if (!$order) throw new Exception('该证书订单不存在', 102);
        $this->order = $order;
        $this->cnameDomainList = [];
        $this->domainsAliasList = [];

        $this->aid = $order['aid'];
        $account = $this->db->name('cert_account')->where('id', $this->aid)->find();
        if (!$account) throw new Exception('该证书账户不存在', 102);
        $config = json_decode($account['config'], true);
        $ext = $account['ext'] ? json_decode($account['ext'], true) : null;
        $this->atype = $account['type'];
        $this->client = CertHelper::getModel2($account['type'], $config, $ext);
        if (!$this->client) throw new Exception('该证书类型不存在', 102);

        $domainList = $this->db->name('cert_domain')->where('oid', $oid)->order('sort', 'asc')->column('domain');
        if (!$domainList) throw new Exception('该证书订单没有绑定域名', 102);
        $this->domainList = $domainList;
        $this->info = $order['info'] ? json_decode($order['info'], true) : null;
        $this->dnsList = $order['dns'] ? json_decode($order['dns'], true) : null;
    }

    //执行证书申请
    public function process($isManual = false)
    {
        $this->lockOrder();
        try {
            if ((int)$this->order['status'] === 3) { $this->delDns(); return 3; }
            if ((int)$this->order['status'] === 4) throw new Exception('证书已吊销', 102);
            if (!$isManual && !empty($this->order['retrytime']) && strtotime($this->order['retrytime']) > time()) throw new Exception('尚未到达重试时间', 102);
            if ($this->order['retry2'] >= 3 && !$isManual) {
                throw new Exception('已超出最大重试次数('.$this->order['error'].')', 103);
            }
            if ($this->order['status'] != 1 && $this->order['status'] != 2 && $this->order['retry'] >= 3 && !$isManual) {
                if ($this->order['status'] == -2 || $this->order['status'] == -5 || $this->order['status'] == -6 || $this->order['status'] == -7) {
                    $this->cancel();
                    if($this->order['status'] <= -5) $this->delDns();
                    $this->db->name('cert_order')->where('id', $this->order['id'])->data(['status' => 0, 'retry' => 0, 'retrytime' => null, 'updatetime' => date('Y-m-d H:i:s')])->inc('retry2')->update();
                    $this->order['status'] = 0;
                    $this->order['retry'] = 0;
                } else {
                    throw new Exception('已超出最大重试次数('.$this->order['error'].')', 103);
                }
            }

            if (empty($this->atype) || !isset(CertHelper::$cert_config[$this->atype])) {
                throw new Exception('该证书类型不存在', 102);
            }
            $cname = CertHelper::$cert_config[$this->atype]['cname'] ?? false;
            foreach($this->domainList as $domain){
                $mainDomain = getMainDomain($domain);
                $drow = $this->db->name('domain')->where('name', $mainDomain)->find();
                if (!$drow && preg_match('/^xn--/', $mainDomain)) {
                    $drow = $this->db->name('domain')->where('name', idn_to_utf8($mainDomain))->find();
                }
                if (!$drow) {
                    $drow = $this->db->name('domain_alias')->alias('A')->join('domain B', 'A.did = B.id')->where('A.name', $mainDomain)->field('A.name as alias,B.name as maindomain')->find();
                    if ($drow) {
                        $this->domainsAliasList[$drow['alias']] = $drow['maindomain'];
                    }
                }
                if (!$drow) {
                    if (substr($domain, 0, 2) == '*.') $domain = substr($domain, 2);
                    $cname_row = $this->db->name('cert_cname')->where('domain', $domain)->where('status', 1)->find();
                    if (!$cname || !$cname_row) {
                        $errmsg = '域名'.$domain.'未在本系统添加';
                        $this->db->name('cert_order')->where('id', $this->order['id'])->update(['error' => $errmsg]);
                        throw new Exception($errmsg, 103);
                    } else {
                        $this->cnameDomainList[] = $cname_row['id'];
                    }
                }
            }
            return $this->processOrder($isManual);
        } finally {
            try {
                if (($this->order['status'] == -2 || $this->order['status'] == -5 || $this->order['status'] == -6 || $this->order['status'] == -7) && $this->order['retry'] >= 3) {
                    $this->db->name('cert_order')->where('id', $this->order['id'])->update(['retrytime' => date('Y-m-d H:i:s', time() + 3600)]);
                }
            } finally {
                $this->unlockOrder();
            }
        }
    }

    private function processOrder($isManual = false)
    {
        $this->client->setLogger(function ($txt) {
            $this->saveLog($txt);
        });
        // step1: 购买证书
        if ($this->order['status'] == 0 || $this->order['status'] == -1) {
            $this->saveLog(date('Y-m-d H:i:s').' - 开始购买证书');
            $this->buyCert();
        }
        // step2: 创建订单
        if ($this->order['status'] == 0 || $this->order['status'] == -2) {
            $this->saveLog(date('Y-m-d H:i:s').' - 开始创建订单');
            $this->createOrder();
        }
        // step3: 添加DNS
        if ($isManual && $this->order['status'] == -3 && CertDnsUtils::verifyDns($this->dnsList)) {
            $this->saveResult(1);
            $this->saveLog('检测到DNS记录已添加成功');
            return 1;
        }
        if ($this->order['status'] == 0 || $this->order['status'] == -3) {
            $this->saveLog(date('Y-m-d H:i:s').' - 开始添加DNS记录');
            $this->addDns();
            $this->saveLog('添加DNS记录成功，请等待生效后进行验证...');
            if (CertHelper::$cert_config[$this->atype]['cname']) {
                $this->db->name('cert_order')->where('id', $this->order['id'])->update(['retrytime' => date('Y-m-d H:i:s', time() + 180)]);
            }
            return 1;
        }
        // step4: 查询DNS
        if ($this->order['status'] == 1 || $this->order['status'] == -4) {
            $this->verifyDns();
        }
        // step5: 验证订单
        if ($this->order['status'] == 1 || $this->order['status'] == -5) {
            $this->saveLog(date('Y-m-d H:i:s').' - 开始验证订单');
            $this->authOrder();
        }
        // step6: 查询验证结果
        if ($this->order['status'] == 2 || $this->order['status'] == -6) {
            $this->saveLog(date('Y-m-d H:i:s').' - 开始查询验证结果');
            $this->getAuthStatus();
        }
        // step7: 签发证书
        if ($this->order['status'] == 2 || $this->order['status'] == -7) {
            $this->saveLog(date('Y-m-d H:i:s').' - 开始签发证书');
            $this->finalizeOrder();
        }
        $this->delDns();
        $this->resetRetry2();
        $this->saveLog('[Success] 证书签发成功');
        return 3;
    }

    private function lockOrder()
    {
        if ($this->lockDepth > 0) { $this->lockDepth++; return; }
        $this->executionLock = new TaskLockService($this->db, 'cert-order:' . (int)$this->order['id']);
        $this->executionLock->acquire();
        try {
            $this->db->startTrans();
            $current = $this->db->name('cert_order')->where('id', $this->order['id'])->lock(true)->find();
            if (!$current) throw new Exception('该证书订单不存在', 102);
            // The advisory lock establishes that no live worker owns this task.
            // Refresh status, retry counters and locktime from the locked row.
            $this->loadOrder($current['id']);
            if ($this->authorization) ($this->authorization)((int)$this->order['id'], $this->domainList);
            $update = ['islock' => 1, 'locktime' => date('Y-m-d H:i:s')];
            if (empty($current['processid'])) $update['processid'] = getSid();
            $this->db->name('cert_order')->where('id', $current['id'])->update($update);
            $this->order = array_merge($this->order, $update);
            $this->db->commit();
            $this->lockDepth = 1;
        } catch (\Throwable $e) {
            try { $this->db->rollback(); } finally { $this->executionLock->release(); }
            throw $e;
        }
    }

    private function unlockOrder()
    {
        if (--$this->lockDepth > 0) return;
        try {
            $this->db->name('cert_order')->where('id', $this->order['id'])->update(['islock' => 0]);
        } finally {
            $this->executionLock->release();
        }
    }

    private function saveResult($status, $error = null, $retrytime = null)
    {
        $this->order['status'] = $status;
        if (!empty($error) && strlen($error) > 300) {
            $error = mb_strcut($error, 0, 300);
        }
        $update = ['status' => $status, 'error' => $error ? str_replace(["\r", "\n"], '', $error) : null, 'updatetime' => date('Y-m-d H:i:s'), 'retrytime' => $retrytime];
        $res = $this->db->name('cert_order')->where('id', $this->order['id'])->data($update);
        if ($status < 0 || $retrytime) {
            $this->order['retry']++;
            $res->inc('retry');
        }
        $res->update();
        if ($error) {
            $this->saveLog('[Error] ' . $error);
        }
    }

    private function resetRetry()
    {
        if ($this->order['retry'] > 0) {
            $this->order['retry'] = 0;
            $this->db->name('cert_order')->where('id', $this->order['id'])->update(['retry' => 0, 'retrytime' => null]);
        }
    }

    private function resetRetry2()
    {
        if ($this->order['retry2'] > 0) {
            $this->order['retry2'] = 0;
            $this->db->name('cert_order')->where('id', $this->order['id'])->update(['retry2' => 0, 'retrytime' => null]);
        }
    }

    //重置订单
    public function reset($automatic = false)
    {
        $this->lockOrder();
        try {
            if ($automatic && (empty($this->order['isauto']) || (int)$this->order['status'] !== 3 || strtotime($this->order['expiretime']) >= time() + max(1, (int)config_get('cert_renewdays', 7)) * 86400)) return;

            $this->db->name('cert_order')->where('id', $this->order['id'])->data(['status' => 0, 'retry' => 0, 'retry2' => 0, 'retrytime' => null, 'processid' => null, 'updatetime' => date('Y-m-d H:i:s'), 'issend' => 0])->update();
            $file_name = app()->getRuntimePath().'log/'.$this->order['processid'].'.log';
            if (file_exists($file_name)) unlink($file_name);
            $this->order['status'] = 0;
            $this->order['retry'] = 0;
            $this->order['retry2'] = 0;
            $this->order['processid'] = null;
        } finally {
            $this->unlockOrder();
        }
    }

    //购买证书
    public function buyCert()
    {
        try {
            $this->client->buyCert($this->domainList, $this->info);
        } catch (\Throwable $e) {
            $this->saveResult(-1, $e->getMessage());
            throw $e;
        }
        if($this->info){
            $this->db->name('cert_order')->where('id', $this->order['id'])->update(['info' => json_encode($this->info)]);
        }
        $this->order['status'] = 0;
        $this->resetRetry();
    }

    //创建订单
    public function createOrder()
    {
        try {
            if (!empty($this->cnameDomainList)) {
                foreach($this->cnameDomainList as $cnameId){
                    $this->checkDomainCname($cnameId);
                }
            }
            try {
                $this->dnsList = $this->client->createOrder($this->domainList, $this->info, $this->order['keytype'], $this->order['keysize']);
            } catch (\Throwable $e) {
                if (strpos($e->getMessage(), 'KeyID header contained an invalid account URL') !== false) {
                    $ext = $this->client->register();
                    $this->db->name('cert_account')->where('id', $this->aid)->update(['ext' => json_encode($ext)]);
                    $this->dnsList = $this->client->createOrder($this->domainList, $this->info, $this->order['keytype'], $this->order['keysize']);
                } else {
                    throw $e;
                }
            }
        } catch (\Throwable $e) {
            $this->saveResult(-2, $e->getMessage());
            throw $e;
        }

        foreach ($this->domainsAliasList as $alias => $mainDomain) {
            if (isset($this->dnsList[$alias])) {
                if (!isset($this->dnsList[$mainDomain])) {
                    $this->dnsList[$mainDomain] = $this->dnsList[$alias];
                } else {
                    $this->dnsList[$mainDomain] = array_merge($this->dnsList[$mainDomain], $this->dnsList[$alias]);
                }
                unset($this->dnsList[$alias]);
            }
        }

        $this->db->name('cert_order')->where('id', $this->order['id'])->update(['info' => json_encode($this->info), 'dns' => json_encode($this->dnsList)]);

        if (!empty($this->dnsList)) {
            $dns_txt = '需验证的DNS记录如下:';
            foreach ($this->dnsList as $mainDomain => $list) {
                foreach ($list as $row) {
                    $domain = $row['name'] . '.' . $mainDomain;
                    $dns_txt .= PHP_EOL.'主机记录: '.$domain.' 类型: '.$row['type'].' 记录值: '.$row['value'];
                }
            }
            $this->saveLog($dns_txt);
        }
        $this->order['status'] = 0;
        $this->resetRetry();
    }

    //验证DNS记录
    public function verifyDns()
    {
        $verify = CertDnsUtils::verifyDns($this->dnsList);
        if (!$verify) {
            if ($this->order['retry'] >= 10) {
                $this->saveResult(-4, '未查询到DNS解析记录');
            } else {
                $this->saveLog('未查询到DNS解析记录(尝试第'.($this->order['retry']+1).'次)');
                $this->saveResult(1, null, date('Y-m-d H:i:s', time() + (array_key_exists($this->order['retry'], self::$retry_interval) ? self::$retry_interval[$this->order['retry']] : 1800)));
            }
            throw new Exception('未查询到DNS解析记录(尝试第'.($this->order['retry']).'次)，请稍后再试');
        }
        if($this->order['retry'] == 0 && time() - strtotime($this->order['updatetime']) < 10){
            throw new Exception('请等待'.(10 - (time() - strtotime($this->order['updatetime']))).'秒后再试');
        }
        $this->order['status'] = 1;
        $this->resetRetry();
    }

    //验证订单
    public function authOrder()
    {
        try {
            $this->client->authOrder($this->domainList, $this->info);
        } catch (\Throwable $e) {
            $this->saveResult(-5, $e->getMessage());
            throw $e;
        }
        $this->saveResult(2);
        $this->resetRetry();
    }

    //查询验证结果
    public function getAuthStatus()
    {
        try {
            $status = $this->client->getAuthStatus($this->domainList, $this->info);
        } catch (\Throwable $e) {
            $this->saveResult(-6, $e->getMessage());
            throw $e;
        }
        if(!$status){
            if ($this->order['retry'] >= 10) {
                $this->saveResult(-6, '订单验证未通过');
            } else {
                $this->saveLog('订单验证未通过(尝试第'.($this->order['retry']+1).'次)');
                $this->saveResult(2, null, date('Y-m-d H:i:s', time() + (array_key_exists($this->order['retry'], self::$retry_interval) ? self::$retry_interval[$this->order['retry']] : 1800)));
            }
            throw new Exception('订单验证未通过(尝试第'.($this->order['retry']).'次)，请稍后再试');
        }
        $this->order['status'] = 2;
        $this->resetRetry();
    }

    //签发证书
    public function finalizeOrder()
    {
        try {
            $result = $this->client->finalizeOrder($this->domainList, $this->info, $this->order['keytype'], $this->order['keysize']);
        } catch (\Throwable $e) {
            $this->saveResult(-7, $e->getMessage());
            throw $e;
        }
        $this->db->startTrans();
        try {
            $this->db->name('cert_order')->where('id', $this->order['id'])->update(['fullchain'=>$result['fullchain'], 'privatekey'=>$result['private_key'], 'issuer'=>$result['issuer'], 'issuetime'=>date('Y-m-d H:i:s', $result['validFrom']), 'expiretime'=>date('Y-m-d H:i:s', $result['validTo']), 'status'=>3, 'retry'=>0, 'retrytime'=>null, 'error'=>null, 'issend'=>0]);
            $this->db->name('cert_deploy')->where('oid', $this->order['id'])->update(['status'=>0, 'retry'=>0, 'retrytime'=>null, 'issend'=>0]);
            $this->db->commit();
        } catch (\Throwable $e) { $this->db->rollback(); throw $e; }
        $this->order['status'] = 3;
        $this->order['retry'] = 0;
        $this->order['issuer'] = $result['issuer'];
    }

    //吊销证书
    public function revoke()
    {
        $this->lockOrder();
        try {
            if ((int)$this->order['status'] === 4) return;
            if ((int)$this->order['status'] !== 3) throw new Exception('仅已签发证书可以吊销', 102);
            $this->client->setLogger(function ($txt) { $this->saveLog($txt); });
            $this->client->revoke($this->info, $this->order['fullchain']);
            $this->saveResult(4);
        } finally {
            $this->unlockOrder();
        }
    }

    //取消证书订单
    public function cancel()
    {
        $this->lockOrder();
        try {
            $this->client->setLogger(function ($txt) { $this->saveLog($txt); });
            if ($this->order['status'] == 1 || $this->order['status'] == 2 || $this->order['status'] < -2) {
                try { $this->client->cancel($this->info); } catch (\Throwable $e) {
                    $this->saveLog('[Warning] 取消远程订单失败: ' . $e->getMessage());
                }
            }
        } finally {
            $this->unlockOrder();
        }
    }

    /** Delete only while no issue/deploy worker can still use this order. */
    public function remove(): void
    {
        $this->lockOrder();
        try {
            if ($this->db->name('cert_deploy')->where('oid', $this->order['id'])->count()) throw new Exception('该证书关联了自动部署任务，无法删除');
            $this->cancel();
            $this->delDns(true);
            $this->db->startTrans();
            try {
                $this->db->name('cert_domain')->where('oid', $this->order['id'])->delete();
                $this->db->name('cert_order')->where('id', $this->order['id'])->delete();
                $this->db->commit();
            } catch (\Throwable $e) { $this->db->rollback(); throw $e; }
        } finally { $this->unlockOrder(); }
    }

    //添加DNS记录
    public function addDns()
    {
        if (empty($this->dnsList)) {
            $this->saveResult(1);
            return;
        }
        try {
            CertDnsUtils::addDns($this->dnsList, function ($txt) {
                $this->saveLog($txt);
            }, !empty($this->cnameDomainList), (int)$this->order['id']);
        } catch (\Throwable $e) {
            $this->saveResult(-3, $e->getMessage());
            throw $e;
        }
        $this->saveResult(1);
        $this->resetRetry();
    }

    //删除DNS记录
    public function delDns(bool $strict = false): bool
    {
        try {
            CertDnsUtils::delDns($this->dnsList, function ($txt) {
                $this->saveLog($txt);
            }, true, (int)$this->order['id']);
            if (str_starts_with((string)($this->order['error'] ?? ''), 'DNS清理待重试：')) {
                $this->db->name('cert_order')->where('id', $this->order['id'])->update(['error'=>null]);
                $this->order['error'] = null;
            }
            return true;
        } catch (\Throwable $e) {
            $error = 'DNS清理待重试：' . \app\lib\SensitiveLog::filter($e->getMessage());
            $this->saveLog('[Error] ' . $error);
            if (in_array((int)$this->order['status'], [3,4], true)) {
                $this->db->name('cert_order')->where('id', $this->order['id'])->update(['error'=>mb_substr($error,0,300)]);
            }
            if ($strict) throw new Exception('验证记录尚未清理，已保留订单，请修复DNS权限或域名绑定后重试', 102, $e);
            return false;
        }
    }

    //检查域名CNAME代理记录
    private function checkDomainCname($id)
    {
        $row = $this->db->name('cert_cname')->alias('A')->join('domain B', 'A.did = B.id')->where('A.id', $id)->field('A.*,B.name cnamedomain')->find();
        $domain = '_acme-challenge.' . $row['domain'];
        $record = $row['rr'] . '.' . $row['cnamedomain'];
        $result = \app\utils\DnsQueryUtils::get_dns_records($domain, 'CNAME');
        if (!$result || !in_array($record, $result)) {
            $result = \app\utils\DnsQueryUtils::query_dns_doh($domain, 'CNAME');
            if (!$result || !in_array($record, $result)) {
                if ($row['status'] == 1) {
                    $this->db->name('cert_cname')->where('id', $id)->update(['status' => 0]);
                }
                throw new Exception('域名' . $row['domain'] . '的CNAME代理记录未验证通过');
            }
        }
    }

    private function saveLog($txt)
    {
        $txt = \app\lib\SensitiveLog::filter((string)$txt);
        if (empty($this->order['processid'])) return;
        if (!is_dir(app()->getRuntimePath() . 'log')) mkdir(app()->getRuntimePath() . 'log', 0750, true);
        $file_name = app()->getRuntimePath().'log/'.$this->order['processid'].'.log';
        $file_exists = file_exists($file_name);
        file_put_contents($file_name, $txt . PHP_EOL, FILE_APPEND | LOCK_EX);
        if (!$file_exists) {
            @chmod($file_name, 0640);
        }
        if(php_sapi_name() == 'cli'){
            echo $txt . PHP_EOL;
        }
    }
}
