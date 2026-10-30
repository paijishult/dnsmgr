<?php
namespace app\service;

class HealthPolicy
{
    public static function normalize($value): array
    {
        if (is_string($value)) $value = json_decode($value ?: '{}', true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($value)) throw new \InvalidArgumentException('健康检查配置无效');
        $statuses = $value['statuses'] ?? [];
        if (is_string($statuses)) $statuses = array_values(array_filter(array_map('trim', explode(',', $statuses)), 'strlen'));
        if (!is_array($statuses) || count($statuses)>30) throw new \InvalidArgumentException('状态码最多30项');
        foreach ($statuses as &$code) { if (filter_var($code, FILTER_VALIDATE_INT) === false || $code<100 || $code>599) throw new \InvalidArgumentException('HTTP状态码无效'); $code=(int)$code; } unset($code);
        $contains = $value['contains'] ?? '';
        if (!is_string($contains) || strlen($contains)>1024) throw new \InvalidArgumentException('响应断言最多1024字节');
        $probes = $value['probes'] ?? [];
        if (is_string($probes)) $probes = array_values(array_filter(array_map('trim', explode("\n", $probes)), 'strlen'));
        if (!is_array($probes) || count($probes)>4) throw new \InvalidArgumentException('最多增加4个健康端点');
        foreach ($probes as $url) {
            $parts = is_string($url) ? parse_url($url) : false;
            if (!$parts || strlen($url)>4096 || !in_array(strtolower($parts['scheme'] ?? ''), ['http','https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) throw new \InvalidArgumentException('健康端点须为不含凭据的HTTP(S) URL');
        }
        $quorum = filter_var($value['quorum'] ?? 1, FILTER_VALIDATE_INT);
        if ($quorum === false || $quorum<1 || $quorum>count($probes)+1) throw new \InvalidArgumentException('通过端点数量超出范围');
        return ['verify_tls'=>!in_array($value['verify_tls'] ?? true,[false,0,'0','false'],true), 'statuses'=>$statuses, 'contains'=>$contains, 'probes'=>$probes, 'quorum'=>$quorum];
    }

    public static function check(array $task, ?callable $probe = null): array
    {
        $policy = self::normalize($task['health_options'] ?? []);
        $probe ??= static fn($url,$timeout,$ip,$proxy,$options)=>\app\utils\CheckUtils::curl($url,$timeout,$ip,$proxy,$options);
        $urls = array_merge([$task['checkurl']], $policy['probes']);
        $deadline = microtime(true)+(float)$task['timeout'];
        $passed=0; $results=[];
        foreach ($urls as $i=>$url) {
            $remaining = $deadline-microtime(true);
            try {
                $result = $remaining>0 ? $probe($url, $remaining / (count($urls)-$i), $i===0 ? $task['main_value'] : null, !empty($task['proxy']), $policy) : ['status'=>false,'errmsg'=>'检测总时限已用尽'];
            } catch (\Throwable $e) { $result=['status'=>false,'errmsg'=>\app\lib\SensitiveLog::filter($e->getMessage())]; }
            if (!empty($result['status'])) $passed++;
            $results[] = ['endpoint'=>$url,'status'=>!empty($result['status']),'error'=>$result['errmsg'] ?? null];
        }
        return ['status'=>$passed >= $policy['quorum'], 'errmsg'=>$passed >= $policy['quorum'] ? null : '健康端点通过 ' . $passed . '/' . count($urls) . '，要求 ' . $policy['quorum'], 'probes'=>$results];
    }
}
