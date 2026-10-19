<?php

namespace app\service;

use Exception;
use think\facade\Db;
use app\lib\DnsHelper;

/**
 * CF优选IP
 */
class OptimizeService
{
    private $ip_address = [];
    private $add_num = 0;
    private $change_num = 0;
    private $del_num = 0;

    public static function get_license($api, $key)
    {
        if ($api == 2) {
            throw new Exception('xingpingcn.top 接口免费使用，无需密钥，无积分限制');
        } elseif ($api == 1) {
            $url = 'https://api.hostmonit.com/get_license?license='.$key;
        } else {
            $url = 'https://www.wetest.vip/api/cf2dns/get_license?license='.$key;
        }
        $response = get_curl($url);
        if (!$response) throw new Exception('接口请求失败');
        $arr = json_decode($response, true);
        if (isset($arr['code']) && $arr['code'] == 200 && isset($arr['count'])) {
            return $arr['count'];
        } elseif (isset($arr['info'])) {
            throw new Exception('获取剩余请求次数失败，'.$arr['info']);
        } else {
            throw new Exception('获取剩余请求次数失败');
        }
    }

    public function get_ip_address($cdn_type = 1, $ip_type = 'v4')
    {
        $api = config_get('optimize_ip_api', 0);
        if ($api == 2) {
            return $this->get_ip_address_xingpingcn($ip_type);
        } elseif ($api == 1) {
            $url = 'https://api.hostmonit.com/get_optimization_ip';
        } else {
            $url = 'https://www.wetest.vip/api/cf2dns/';
            if ($cdn_type == 1) {
                $url .= 'get_cloudflare_ip';
            } elseif ($cdn_type == 2) {
                $url .= 'get_cloudfront_ip';
            } elseif ($cdn_type == 3) {
                $url .= 'get_gcore_ip';
            } elseif ($cdn_type == 4) {
                $url .= 'get_edgeone_ip';
            }
        }
        $params = [
            'key' => config_get('optimize_ip_key', 'o1zrmHAF'),
            'type' => $ip_type,
        ];
        $response = get_curl($url, json_encode($params), 0, 0, 0, 0, ['Content-Type' => 'application/json; charset=UTF-8']);
        if (!$response) throw new Exception('接口请求失败');
        $arr = json_decode($response, true);
        if (isset($arr['code']) && $arr['code'] == 200) {
            return $arr['info'];
        } elseif (isset($arr['info'])) {
            throw new Exception('获取优选IP数据失败，'.$arr['info']);
        } elseif (isset($arr['msg'])) {
            throw new Exception('获取优选IP数据失败，'.$arr['msg']);
        } else {
            throw new Exception('获取优选IP数据失败，原因未知');
        }
    }

    /**
     * 从 xingpingcn.top 获取优选IP数据
     * @param string $ip_type IP类型 v4/v6
     * @return array
     * @throws Exception
     */
    private function get_ip_address_xingpingcn($ip_type = 'v4')
    {
        if ($ip_type == 'v6') {
            throw new Exception('xingpingcn.top 接口暂不支持IPv6');
        }
        $proxy = config_get('optimize_ip_proxy', '');
        if (!empty($proxy)) {
            $proxy = trim($proxy);
            if (filter_var($proxy, FILTER_VALIDATE_URL) === false) {
                throw new Exception('无效的代理地址配置：URL 格式错误');
            }
            $scheme = parse_url($proxy, PHP_URL_SCHEME);
            if (!in_array($scheme, ['http', 'https'], true)) {
                throw new Exception('无效的代理地址配置：仅支持 http 和 https 协议');
            }
            $url = rtrim($proxy, '/') . '/xingpingcn/enhanced-FaaS-in-China/refs/heads/main/Cf.json';
        } else {
            $url = 'https://raw.githubusercontent.com/xingpingcn/enhanced-FaaS-in-China/refs/heads/main/Cf.json';
        }
        $response = get_curl($url);
        if (!$response) {
            throw new Exception('获取优选IP数据失败，网络请求失败，请检查网络连接或代理地址');
        }
        $arr = json_decode($response, true);
        if (isset($arr['Cf']['result'])) {
            $result = $arr['Cf']['result'];
            $info = [];
            // 转换格式：dianxin->CT, liantong->CU, yidong->CM, default->DEF
            if (isset($result['dianxin']) && is_array($result['dianxin'])) {
                $info['CT'] = array_map(function($ip) { return ['ip' => $ip]; }, $result['dianxin']);
            }
            if (isset($result['liantong']) && is_array($result['liantong'])) {
                $info['CU'] = array_map(function($ip) { return ['ip' => $ip]; }, $result['liantong']);
            }
            if (isset($result['yidong']) && is_array($result['yidong'])) {
                $info['CM'] = array_map(function($ip) { return ['ip' => $ip]; }, $result['yidong']);
            }
            // 不使用他的默认线路数据, 因为这真的是默认. 由后续逻辑自己决定是否把CT线路当DEF来用
            // if (isset($result['default']) && is_array($result['default'])) {
            //     $info['DEF'] = array_map(function($ip) { return ['ip' => $ip]; }, $result['default']);
            // }
            return $info;
        } else {
            throw new Exception('获取优选IP数据失败，接口返回数据格式错误');
        }
    }

    public function get_ip_address2($cdn_type = 1, $ip_type = 'v4')
    {
        $key = $cdn_type.'_'.$ip_type;
        if (!isset($this->ip_address[$key])) {
            $info = $this->get_ip_address($cdn_type, $ip_type);
            $res = [];
            if (isset($info['DEF'])) {
                $res['DEF'] = $info['DEF'];
            }
            if (isset($info['CT'])) {
                $res['CT'] = $info['CT'];
            }
            if (isset($info['CU'])) {
                $res['CU'] = $info['CU'];
            }
            if (isset($info['CM'])) {
                $res['CM'] = $info['CM'];
            }
            $this->ip_address[$key] = $res;
        }
        return $this->ip_address[$key];
    }

    //批量执行优选任务
    public function execute()
    {
        $minute = config_get('optimize_ip_min', '30');
        $last = config_get('optimize_ip_time', null, true);
        if ($last && strtotime($last) > time() - $minute * 60) {
            return false;
        }
        $list = Db::name('optimizeip')->where('active', 1)->select();
        if (count($list) == 0) {
            return false;
        }
        echo '开始执行IP优选任务，共获取到'.count($list).'个待执行任务'."\n";
        foreach ($list as $row) {
            try {
                $result = $this->execute_one($row, false);
                Db::name('optimizeip')->where('id', $row['id'])->update(['status' => 1, 'errmsg' => null, 'updatetime' => date('Y-m-d H:i:s')]);
                echo '优选任务'.$row['id'].'执行成功：'.$result."\n";
            } catch (Exception $e) {
                Db::name('optimizeip')->where('id', $row['id'])->update(['status' => 2, 'errmsg' => $e->getMessage(), 'updatetime' => date('Y-m-d H:i:s')]);
                echo '优选任务'.$row['id'].'执行失败：'.$e->getMessage()."\n";
            }
        }
        config_set('optimize_ip_time', date("Y-m-d H:i:s"));
        return true;
    }

    // Manual runs and cron share one non-stealable task lock and the DNS change guard.
    public function execute_one($row, bool $manual = true)
    {
        $db = TaskLockService::connection();
        $lock = new TaskLockService($db, 'optimize:' . (int)$row['id']);
        try {
            $lock->acquire();
            $row = $db->name('optimizeip')->where('id', $row['id'])->find();
            if (!$row || (!$manual && !$row['active'])) throw new Exception('优选任务已删除或停用');
            $this->add_num = $this->change_num = $this->del_num = 0;
            $domain = $db->name('domain')->alias('A')->join('account B', 'A.aid = B.id')->where('A.id', $row['did'])->field('A.*,B.type')->find();
            if (!$domain || !isset(DnsHelper::$line_name[$domain['type']])) throw new Exception('域名不存在或DNS服务商不支持优选线路');
            $authorize = static function () use ($db, $row, $domain, $manual) {
                $current = $db->name('optimizeip')->where('id', $row['id'])->find();
                $zone = $db->name('domain')->where('id', $domain['id'])->find();
                if (!$current || (!$manual && !$current['active']) || (string)$current['did'] !== (string)$row['did']) throw new Exception('优选任务已删除、停用或修改');
                foreach (['aid','name','thirdid'] as $key) if (!$zone || (string)$zone[$key] !== (string)$domain[$key]) throw new Exception('域名已删除或重新绑定');
            };
            foreach (array_filter(explode(',', $row['ip_type'])) as $ipType) {
                if (!in_array($ipType, ['v4','v6'], true)) throw new Exception('优选IP类型无效');
                $info = $this->get_ip_address2($row['cdn_type'], $ipType);
                $fallback = (int)$row['type'] === 1 && empty($info['DEF']);
                foreach ($info as $line => $ips) {
                    if (!$ips) continue;
                    if ($fallback && $line === 'CT') $line = 'DEF';
                    if (!isset(DnsHelper::$line_name[$domain['type']][$line])) continue;
                    $desired = [];
                    foreach ($ips as $item) {
                        $ip = $item['ip'] ?? null;
                        $flag = $ipType === 'v6' ? FILTER_FLAG_IPV6 : FILTER_FLAG_IPV4;
                        if (!is_string($ip) || !filter_var($ip, FILTER_VALIDATE_IP, $flag | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) throw new Exception('优选接口返回无效或非公网IP，停止写入');
                        $desired[] = inet_ntop(inet_pton($ip));
                    }
                    $count = (int)$row['recordnum'];
                    if ($count < 1 || $count > 50) throw new Exception('优选记录数量应在1至50之间');
                    $desired = array_slice(array_values(array_unique($desired)), 0, $count);
                    if (!$desired) throw new Exception('优选接口没有可用IP');
                    if ($domain['type'] === 'huawei') { sort($desired); $desired = [implode(',', $desired)]; }
                    $authorize();
                    $model = DnsHelper::getModel($domain['aid'], $domain['name'], $domain['thirdid']);
                    if (!$model) throw new Exception('DNS模块不存在');
                    $dns = new \app\lib\GuardedDns($model, $domain, $db);
                    try {
                        $this->process_dns_line($dns, $row, $dns->records(), $desired, DnsHelper::$line_name[$domain['type']][$line], $ipType, $authorize);
                    } finally { unset($dns); }
                }
            }
            return '成功添加'.$this->add_num.'条记录，修改'.$this->change_num.'条记录，删除'.$this->del_num.'条记录';
        } finally { try { $lock->release(); } finally { $db->close(); } }
    }

    private function process_dns_line($dns, $row, $records, array $desired, $line, $ipType, callable $authorize): void
    {
        $records = array_filter($records, fn($r) => strcasecmp($r['Name'], $row['rr']) === 0 && (string)$r['Line'] === (string)$line);
        $type = $ipType === 'v6' ? 'AAAA' : 'A';
        $excess = [];
        foreach ($records as $record) {
            if ($record['Type'] === 'CNAME') {
                $authorize();
                if (!$dns->deleteDomainRecord($record['RecordId'])) throw new Exception('删除冲突CNAME失败：'.$dns->getError());
                $this->del_num++;
            } elseif ($record['Type'] === $type) {
                $values = (array)$record['Value'];
                foreach ($values as &$value) { $binary = @inet_pton($value); if ($binary !== false) $value = inet_ntop($binary); }
                unset($value);
                if (is_array($record['Value'])) sort($values);
                $value = implode(',', $values);
                $index = array_search($value, $desired, true);
                if ($index !== false) unset($desired[$index]);
                else $excess[] = $record;
            }
        }
        foreach ($excess as $record) {
            $authorize();
            if ($desired) {
                $ip = array_shift($desired);
                if (!$dns->updateDomainRecord($record['RecordId'], $row['rr'], $type, $ip, $line, $row['ttl'])) throw new Exception('修改解析失败：'.$dns->getError());
                $this->change_num++;
            } else {
                if (!$dns->deleteDomainRecord($record['RecordId'])) throw new Exception('删除解析失败：'.$dns->getError());
                $this->del_num++;
            }
        }
        foreach ($desired as $ip) {
            $authorize();
            if (!$dns->addDomainRecord($row['rr'], $type, $ip, $line, $row['ttl'])) throw new Exception('添加解析失败：'.$dns->getError());
            $this->add_num++;
        }
    }
}
