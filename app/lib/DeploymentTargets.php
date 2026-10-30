<?php
namespace app\lib;

/** Preserve individual provider receipts so retries do not repeat successful targets. */
class DeploymentTargets
{
    public static function run(string $certificate, array $targets, array &$info, callable $deploy, ?callable $checkpoint = null, ?callable $redact = null): void
    {
        if (!$targets) throw new \RuntimeException('没有要部署的目标');
        $version = hash('sha256', $certificate);
        if (($info['_targets_version'] ?? '') !== $version) {
            $info['_targets_version'] = $version;
            $info['_targets'] = [];
        }
        $failed = 0;
        foreach ($targets as $id=>$target) {
            $key = hash('sha256', (string)$id);
            if (($info['_targets'][$key]['status'] ?? '') === 'success') continue;
            $receipt = ['target'=>(string)$id, 'status'=>'success', 'error'=>null, 'updated_at'=>date('Y-m-d H:i:s')];
            try { $deploy($target); }
            catch (\Throwable $e) {
                $receipt['status'] = 'failed';
                $error = $redact ? $redact($e->getMessage()) : SensitiveLog::filter($e->getMessage());
                $receipt['error'] = mb_substr($error, 0, 500);
                $failed++;
            }
            $info['_targets'][$key] = $receipt;
            // A persistence failure must stop here without overwriting the remote receipt.
            if ($checkpoint) $checkpoint();
        }
        if ($failed) throw new \RuntimeException('部分目标部署失败（' . $failed . '/' . count($targets) . '），重试将只处理失败目标');
    }
}
