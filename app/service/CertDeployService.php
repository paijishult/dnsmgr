<?php
namespace app\service;

use Exception;
use app\lib\DeployHelper;

/** SSL证书自动部署。状态2仅重试线上验收，不重复调用部署提供商。 */
class CertDeployService
{
    private static $retry_interval = [60, 300, 600, 1800, 3600];
    private $db;
    private $client;
    private $task;
    private $info;
    private $executionLock;
    private ?\Closure $authorization;
    private array $accountSecrets = [];

    // 0:待部署 1:部署并验收成功 2:部署完成待验收 -1:部署失败
    public function __construct($tid, ?\Closure $authorization = null)
    {
        $this->authorization = $authorization;
        $this->db = TaskLockService::connection();
        $this->loadTask($tid);
    }

    private function loadTask($tid): void
    {
        $task = $this->db->name('cert_deploy')->where('id', $tid)->find();
        if (!$task) throw new Exception('该自动部署任务不存在', 102);
        $this->task = $task;
        $this->client = DeployHelper::getModel($task['aid']);
        if (!$this->client) throw new Exception('该自动部署任务类型不存在', 102);
        $this->info = $task['info'] ? json_decode($task['info'], true) : [];
        if (!is_array($this->info)) $this->info = [];
    }

    public function process($isManual = false)
    {
        $this->lockTaskData();
        $orderConnection = null;
        $orderLock = null;
        try {
            if (!$isManual && empty($this->task['active'])) return;
            if ((int)$this->task['status'] === 1) return;
            if (!$isManual && !empty($this->task['retrytime']) && strtotime($this->task['retrytime']) > time()) throw new Exception('尚未到达重试时间', 102);
            if ($this->task['retry'] >= 6 && !$isManual) throw new Exception('已超出最大重试次数(' . $this->task['error'] . ')', 103);
            // MySQL5.6 supports one advisory lock per session, so retain
            // the task lock on this session and the order lock on another.
            $orderConnection = TaskLockService::connection();
            $orderLock = new TaskLockService($orderConnection, 'cert-order:' . (int)$this->task['oid']);
            $orderLock->acquire();
            $order = $this->db->name('cert_order')->where('id', $this->task['oid'])->find();
            if (!$order) throw new Exception('SSL证书订单不存在', 102);
            if ($order['status'] == 4) throw new Exception('SSL证书订单已吊销', 102);
            if ($order['status'] != 3) throw new Exception('SSL证书订单未完成签发', 102);
            if (empty($order['fullchain']) || empty($order['privatekey'])) throw new Exception('SSL证书或私钥内容不存在', 102);
            $config = json_decode($this->task['config'], true);
            if (!is_array($config)) throw new Exception('部署任务配置无效', 102);
            $domains = $this->db->name('cert_domain')->where('oid', $this->task['oid'])->order('sort', 'asc')->column('domain');
            if ($this->authorization) ($this->authorization)((int)$this->task['oid'], $domains);
            $verification = CertVerificationService::normalizeConfig($config['_verify_tls'] ?? null, $domains);
            $expected = CertVerificationService::fingerprint($order['fullchain']);
            if ((int)$this->task['status'] !== 2) {
                $this->deploy($order['fullchain'], $order['privatekey'], $verification, $expected);
                if (empty($verification['enabled'])) return;
            } elseif (!hash_equals((string)($this->info['_tls_verification']['expected_sha256'] ?? ''), $expected)) {
                // Renewal normally resets status to0. Never silently accept a
                // different certificate if task/order state was manually changed.
                throw new Exception('待验收证书与当前订单不一致，请重新部署', 102);
            }
            if (empty($verification['enabled'])) { $this->saveResult(1); return; }
            $result = $this->verificationService()->verify($verification, $expected);
            $this->info['_tls_verification'] = $result;
            $this->saveInfo();
            if ($result['status'] !== 'passed') {
                $error = '部署已完成，线上证书验收失败: ' . $result['error'];
                $this->saveResult(2, $error, $this->nextRetryTime());
                throw new Exception($error, $this->task['retry'] >= 6 ? 103 : 0);
            }
            $this->saveResult(1);
            $this->saveLog('[Success] 线上证书验收通过');
        } finally {
            try {
                if ($orderLock) $orderLock->release();
            } finally {
                try { if ($orderConnection) $orderConnection->close(); } finally { $this->unlockTaskData(); }
            }
        }
    }

    protected function verificationService(): CertVerificationService
    {
        return new CertVerificationService();
    }

    // 部署与验收分开保存状态，验收失败不能重新执行有副作用的部署。
    public function deploy($fullchain, $privatekey, array $verification = [], ?string $expected = null)
    {
        $this->saveLog(date('Y-m-d H:i:s'));
        $config = json_decode($this->task['config'], true);
        $account = $this->db->name('cert_account')->where('id', $this->task['aid'])->find();
        if (!$account) throw new Exception('部署账户已删除', 102);
        // Construct the client and receipt context from the same account snapshot.
        $this->client = DeployHelper::getModel2($account['type'], json_decode($account['config'] ?? '{}', true) ?: []);
        if (!$this->client) throw new Exception('部署账户类型不存在', 102);
        $this->client->setLogger(function ($txt) { $this->saveLog($txt); });
        $this->accountSecrets = array_merge(
            \app\lib\SensitiveLog::secrets(json_decode($account['config'] ?? '{}', true) ?: []),
            \app\lib\SensitiveLog::secrets(json_decode($account['ext'] ?? '{}', true) ?: []),
            [$privatekey, base64_encode($privatekey)]
        );
        $targetConfig = $config;
        unset($targetConfig['_verify_tls']);
        $context = hash('sha256', json_encode([$this->task['aid'], $targetConfig, $account['type'], $account['config'] ?? '', $account['ext'] ?? '']));
        if (($this->info['_targets_context'] ?? '') !== $context) {
            unset($this->info['_targets'], $this->info['_targets_version']);
            $this->info['_targets_context'] = $context;
        }
        $config['domainList'] = $this->db->name('cert_domain')->where('oid', $this->task['oid'])->order('sort', 'asc')->column('domain');
        if (in_array($account['type'] ?? '', ['lucky','btpanel','ratpanel','kangle','kangleadmin'], true)) {
            $config['_checkpoint'] = function () { $this->saveInfo(); };
            $config['_redact_error'] = fn($text) => \app\lib\SensitiveLog::filter($text, $this->accountSecrets);
        }
        unset($config['_verify_tls']);
        unset($this->info['_tls_verification']);
        try {
            $this->client->deploy($fullchain, $privatekey, $config, $this->info);
        } catch (\Throwable $e) {
            $this->saveResult(-1, $e->getMessage(), $this->nextRetryTime());
            $this->persistProviderInfo();
            throw $e;
        }
        $status = empty($verification['enabled']) ? 1 : 2;
        $this->info['_certificate_sha256'] = $expected ?? CertVerificationService::fingerprint($fullchain);
        if ($status === 2) {
            $this->info['_tls_verification'] = ['status' => 'pending', 'host' => $verification['host'], 'port' => $verification['port'], 'expected_sha256' => $expected, 'observed_sha256' => null, 'checked_at' => null, 'error' => null];
        }
        // Commit the provider receipt and deployment status together. A later
        // log/config/acceptance error must never downgrade a completed deploy.
        $this->db->name('cert_deploy')->where('id', $this->task['id'])->update(['status' => $status, 'info' => json_encode($this->info), 'retry' => 0, 'error' => null, 'retrytime' => null, 'lasttime' => date('Y-m-d H:i:s')]);
        $this->task['status'] = $status;
        $this->task['retry'] = 0;
        $this->persistProviderInfo();
        $this->saveLog('[Success] 证书部署成功');
    }

    private function persistProviderInfo(): void
    {
        if (isset($this->info['config']) && is_array($this->info['config'])) {
            $config = array_merge(json_decode($this->task['config'], true), $this->info['config']);
            $this->db->name('cert_deploy')->where('id', $this->task['id'])->update(['config' => json_encode($config)]);
            unset($this->info['config']);
        }
        $this->saveInfo();
    }

    /** Manual retry of status2 keeps the remote deployment and checks TLS again. */
    public function reset()
    {
        $this->lockTaskData();
        try {
            $status = (int)$this->task['status'] === 2 ? 2 : 0;
            if ((int)$this->task['status'] === 1) {
                unset($this->info['_targets'], $this->info['_targets_version']);
                $this->saveInfo();
            }
            $this->db->name('cert_deploy')->where('id', $this->task['id'])->update(['status' => $status, 'retry' => 0, 'retrytime' => null, 'issend' => 0]);
            $this->task['status'] = $status;
            $this->task['retry'] = 0;
        } finally { $this->unlockTaskData(); }
    }

    private function nextRetryTime(): string
    {
        return date('Y-m-d H:i:s', time() + (self::$retry_interval[$this->task['retry']] ?? 3600));
    }

    private function saveInfo(): void
    {
        foreach ($this->info['_targets'] ?? [] as $key=>$target) {
            if (!empty($target['error'])) $this->info['_targets'][$key]['error'] = \app\lib\SensitiveLog::filter($target['error'], $this->accountSecrets);
        }
        $this->db->name('cert_deploy')->where('id', $this->task['id'])->update(['info' => json_encode($this->info)]);
    }

    private function saveResult($status, $error = null, $retrytime = null)
    {
        $this->task['status'] = $status;
        if ($error) $error = \app\lib\SensitiveLog::filter($error, $this->accountSecrets);
        if (!empty($error) && strlen($error) > 300) $error = mb_strcut($error, 0, 300);
        $update = ['status' => $status, 'error' => $error ? str_replace(["\r", "\n"], '', $error) : null, 'retrytime' => $retrytime];
        if ($status == 1) { $update['retry'] = 0; $update['lasttime'] = date('Y-m-d H:i:s'); $this->task['retry'] = 0; }
        $res = $this->db->name('cert_deploy')->where('id', $this->task['id'])->data($update);
        if ($status < 0 || $retrytime) { $this->task['retry']++; $res->inc('retry'); }
        $res->update();
        if ($error) $this->saveLog('[Error] ' . $error);
    }

    private function lockTaskData()
    {
        $this->executionLock = new TaskLockService($this->db, 'cert-deploy:' . (int)$this->task['id']);
        $this->executionLock->acquire();
        try {
            $this->db->startTrans();
            $current = $this->db->name('cert_deploy')->where('id', $this->task['id'])->lock(true)->find();
            if (!$current) throw new Exception('该自动部署任务不存在', 102);
            $this->loadTask($current['id']);
            if ($this->authorization) ($this->authorization)((int)$this->task['oid'], $this->db->name('cert_domain')->where('oid', $this->task['oid'])->column('domain'));
            $update = ['islock' => 1, 'locktime' => date('Y-m-d H:i:s')];
            if (empty($current['processid'])) $update['processid'] = getSid();
            $this->db->name('cert_deploy')->where('id', $current['id'])->update($update);
            $this->task = array_merge($this->task, $update);
            $this->db->commit();
        } catch (\Throwable $e) {
            try { $this->db->rollback(); } finally { $this->executionLock->release(); }
            throw $e;
        }
    }

    private function unlockTaskData()
    {
        try { $this->db->name('cert_deploy')->where('id', $this->task['id'])->update(['islock' => 0]); }
        finally { $this->executionLock->release(); }
    }

    private function saveLog($txt)
    {
        $config = json_decode((string)($this->task['config'] ?? '{}'), true);
        $txt = \app\lib\SensitiveLog::filter((string)$txt, array_merge($this->accountSecrets, \app\lib\SensitiveLog::secrets(is_array($config) ? $config : [])));
        if (empty($this->task['processid'])) return;
        if (!is_dir(app()->getRuntimePath() . 'log')) mkdir(app()->getRuntimePath() . 'log', 0750, true);
        $file_name = app()->getRuntimePath() . 'log/' . $this->task['processid'] . '.log';
        $file_exists = file_exists($file_name);
        file_put_contents($file_name, $txt . PHP_EOL, FILE_APPEND | LOCK_EX);
        if (!$file_exists) @chmod($file_name, 0640);
        if (php_sapi_name() == 'cli') echo $txt . PHP_EOL;
    }
}
