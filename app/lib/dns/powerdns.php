<?php

namespace app\lib\dns;

use app\lib\DnsInterface;
use Exception;

class powerdns implements DnsInterface, \app\lib\AtomicDns
{
    private $url;
    private $apikey;
    private $server_id = 'localhost';
    private $error;
    private $domain;
    private $domainid;
    private $proxy;
    private array $rrsets = [];
    private ?array $batchChanges = null;
    private array $batchOriginal = [];

    function __construct($config)
    {
        $this->url = 'http://' . $config['ip'] . ':' . $config['port'] . '/api/v1';
        $this->apikey = $config['apikey'];
        $this->proxy = isset($config['proxy']) ? $config['proxy'] == 1 : false;
        $this->domain = $config['domain'];
        $this->domainid = $config['domainid'];
    }

    public function getError()
    {
        return $this->error;
    }

    public function check()
    {
        if ($this->getDomainList() !== false) {
            return true;
        }
        return false;
    }

    //获取域名列表
    public function getDomainList($KeyWord = null, $PageNumber = 1, $PageSize = 20)
    {
        $data = $this->send_reuqest('GET', '/servers/' . $this->server_id . '/zones');
        if ($data) {
            $list = [];
            foreach ($data as $row) {
                $list[] = [
                    'DomainId' => $row['id'],
                    'Domain' => rtrim($row['name'], '.'),
                    'RecordCount' => 0,
                ];
            }
            return ['total' => count($list), 'list' => $list];
        }
        return false;
    }

    //获取解析记录列表
    public function getDomainRecords($PageNumber = 1, $PageSize = 20, $KeyWord = null, $SubDomain = null, $Value = null, $Type = null, $Line = null, $Status = null)
    {
        $data = $this->batchChanges !== null ? ['rrsets'=>$this->rrsets] : $this->send_reuqest('GET', '/servers/' . $this->server_id . '/zones/' . $this->domainid);
        if ($data !== false && (!is_array($data) || !isset($data['rrsets']) || !is_array($data['rrsets']))) { $this->setError('PowerDNS记录列表响应无效');return false; }
        if ($data) {
            $list = [];
            $rrset_id = 0;
            foreach ($data['rrsets'] as &$row) {
                $rrset_id = substr(hash('sha256', $row['name'] . '|' . $row['type']), 0, 20);
                $name = $row['name'] == $this->domainid ? '@' : str_replace('.' . $this->domainid, '', $row['name']);
                $row['host'] = $name;
                $row['id'] = $rrset_id;
                $record_id = 0;
                foreach ($row['records'] as &$record) {
                    $record_id = substr(hash('sha256', $record['content']), 0, 20);
                    $record['id'] = $record_id;
                    $remark = !empty($row['comments']) ? $row['comments'][0]['content'] : null;
                    $value = $record['content'];
                    if ($row['type'] == 'MX') list($record['mx'], $value) = explode(' ', $record['content']);
                    $list[] = [
                        'RecordId' => $rrset_id . '_' . $record_id,
                        'Domain' => $this->domain,
                        'Name' => $name,
                        'Type' => $row['type'],
                        'Value' => $value,
                        'Line' => 'default',
                        'TTL' => $row['ttl'],
                        'MX' => isset($record['mx']) ? $record['mx'] : null,
                        'Status' => $record['disabled'] ? '0' : '1',
                        'Weight' => null,
                        'Remark' => $remark,
                        'UpdateTime' => null,
                    ];
                }
            }
            $this->rrsets = $data['rrsets'];
            if (!isNullOrEmpty($SubDomain)) {
                $list = array_values(array_filter($list, function ($v) use ($SubDomain) {
                    return strcasecmp($v['Name'], $SubDomain) === 0;
                }));
            } else {
                if (!isNullOrEmpty($KeyWord)) {
                    $list = array_values(array_filter($list, function ($v) use ($KeyWord) {
                        return strpos($v['Name'], $KeyWord) !== false || strpos($v['Value'], $KeyWord) !== false;
                    }));
                }
                if (!isNullOrEmpty($Value)) {
                    $list = array_values(array_filter($list, function ($v) use ($Value) {
                        return $v['Value'] == $Value;
                    }));
                }
                if (!isNullOrEmpty($Type)) {
                    $list = array_values(array_filter($list, function ($v) use ($Type) {
                        return $v['Type'] == $Type;
                    }));
                }
                if (!isNullOrEmpty($Status)) {
                    $list = array_values(array_filter($list, function ($v) use ($Status) {
                        return $v['Status'] == $Status;
                    }));
                }
            }
            return ['total' => count($list), 'list' => $list];
        }
        return false;
    }

    //获取子域名解析记录列表
    public function getSubDomainRecords($SubDomain, $PageNumber = 1, $PageSize = 20, $Type = null, $Line = null)
    {
        return $this->getDomainRecords($PageNumber, $PageSize, null, $SubDomain, null, $Type, $Line);
    }

    //获取解析记录详细信息
    public function getDomainRecordInfo($RecordId)
    {
        return false;
    }

    //添加解析记录
    public function addDomainRecord($Name, $Type, $Value, $Line = 'default', $TTL = 600, $MX = 1, $Weight = null, $Remark = null)
    {
        if ($Type == 'TXT' && substr($Value, 0, 1) != '"') $Value = '"' . $Value . '"';
        if (in_array($Type, ['CNAME', 'MX', 'ALIAS'], true) && substr($Value, -1) != '.') $Value .= '.';
        if ($Type == 'MX') $Value = intval($MX) . ' ' . $Value;
        $records = [];
        $rrsets = $this->rrsets;
        if ($rrsets) {
            $rrsets_filter = array_filter($rrsets, function ($row) use ($Name, $Type) {
                return $row['host'] == $Name && $row['type'] == $Type;
            });
            if (!empty($rrsets_filter)) {
                $rrset = $rrsets_filter[array_key_first($rrsets_filter)];
                $records = $rrset['records'];
                $records_filter = array_filter($records, function ($record) use ($Value) {
                    return $record['content'] == $Value;
                });
                if (!empty($records_filter)) {
                    $this->setError('已存在相同记录');
                    return false;
                }
            }
        }
        $records[] = ['content' => $Value, 'disabled' => false];
        return $this->rrset_replace($Name, $Type, $TTL, $records, $Remark);
    }

    // Build both sides of a move before submitting one zone PATCH.
    public function updateDomainRecord($RecordId, $Name, $Type, $Value, $Line = 'default', $TTL = 600, $MX = 1, $Weight = null, $Remark = null)
    {
        if ($Type === 'TXT' && !str_starts_with($Value, '"')) $Value = '"' . $Value . '"';
        if (in_array($Type, ['CNAME', 'MX', 'ALIAS'], true) && !str_ends_with($Value, '.')) $Value .= '.';
        if ($Type === 'MX') $Value = intval($MX) . ' ' . $Value;
        foreach ($this->rrsets as $source) {
            foreach ($source['records'] as $index => $record) {
                if ($source['id'] . '_' . $record['id'] !== (string)$RecordId) continue;
                $same = $source['host'] === $Name && $source['type'] === $Type;
                $target = $same ? $source : ['host'=>$Name, 'type'=>$Type, 'records'=>[], 'comments'=>[]];
                if (!$same) foreach ($this->rrsets as $candidate) {
                    if ($candidate['host'] === $Name && $candidate['type'] === $Type) $target = $candidate;
                }
                foreach ($target['records'] as $i => $other) {
                    if ((!$same || $i !== $index) && $other['content'] === $Value) { $this->setError('已存在相同记录'); return false; }
                }
                $record['content'] = $Value;
                if ($same) $target['records'][$index] = $record;
                else $target['records'][] = $record;
                $changes = [];
                if (!$same) {
                    unset($source['records'][$index]);
                    $changes[] = $this->replacement($source['host'], $source['type'], $source['ttl'], array_values($source['records']));
                }
                $changes[] = $this->replacement($Name, $Type, $TTL, $target['records'], $Remark);
                return $this->patchRrsets($changes);
            }
        }
        $this->setError('记录不存在，请刷新页面重试');
        return false;
    }

    //修改解析记录备注
    public function updateDomainRecordRemark($RecordId, $Remark)
    {
        return false;
    }

    //删除解析记录
    public function deleteDomainRecord($RecordId)
    {
        $rrsets = $this->rrsets;
        if (!$rrsets) {
            $this->setError('记录不存在，请刷新页面重试');
            return false;
        }
        [$rrset_id, $record_id] = explode('_', $RecordId);
        $exist = false;
        $res = false;
        foreach ($rrsets as &$rrset) {
            if ($rrset['id'] == $rrset_id) {
                $records = $rrset['records'];
                foreach ($records as $i => &$record) {
                    if ($record['id'] == $record_id) {
                        $exist = true;
                        unset($records[$i]);
                        break;
                    }
                }
                if (!$exist) break;
                $records = array_values($records);
                if (!empty($records)) {
                    $res = $this->rrset_replace($rrset['host'], $rrset['type'], $rrset['ttl'], $records);
                } else {
                    $res = $this->rrset_delete($rrset['host'], $rrset['type']);
                }
                $rrset['records'] = $records;
                break;
            }
        }
        if (!$exist) {
            $this->setError('记录不存在，请刷新页面重试');
            return false;
        }
        return $res;
    }

    //设置解析记录状态
    public function setDomainRecordStatus($RecordId, $Status)
    {
        $rrsets = $this->rrsets;
        if (!$rrsets) {
            $this->setError('记录不存在，请刷新页面重试');
            return false;
        }
        [$rrset_id, $record_id] = explode('_', $RecordId);
        $exist = false;
        $res = false;
        foreach ($rrsets as &$rrset) {
            if ($rrset['id'] == $rrset_id) {
                $records = $rrset['records'];
                foreach ($records as &$record) {
                    if ($record['id'] == $record_id) {
                        $exist = true;
                        $record['disabled'] = $Status == '0';
                        break;
                    }
                }
                if (!$exist) break;
                $res = $this->rrset_replace($rrset['host'], $rrset['type'], $rrset['ttl'], $records);
                $rrset['records'] = $records;
                break;
            }
        }
        if (!$exist) {
            $this->setError('记录不存在，请刷新页面重试');
            return false;
        }
        return $res;
    }

    //获取解析记录操作日志
    public function getDomainRecordLog($PageNumber = 1, $PageSize = 20, $KeyWord = null, $StartDate = null, $endDate = null)
    {
        return false;
    }

    //获取解析线路列表
    public function getRecordLine()
    {
        return ['default' => ['name' => '默认', 'parent' => null]];
    }

    //获取域名信息
    public function getDomainInfo()
    {
        return false;
    }

    //获取域名最低TTL
    public function getMinTTL()
    {
        return false;
    }

    public function addDomain($Domain)
    {
        if (substr($Domain, -1) != '.') {
            $Domain .= '.';
        }
        $param = [
            'name' => $Domain,
            'kind' => 'Native',
            'soa_edit_api' => 'INCREASE',
        ];
        $result = $this->send_reuqest('POST', '/servers/' . $this->server_id . '/zones', $param);
        if ($result) {
            return ['id' => $result['id'], 'name' => rtrim($result['name'], '.')];
        }
        return false;
    }

    private function replacement($host, $type, $ttl, $records, $remark = null): array
    {
        $name = $host === '@' ? $this->domainid : $host . '.' . $this->domainid;
        if (!$records) return ['name'=>$name, 'type'=>$type, 'changetype'=>'DELETE'];
        $comments = [];
        foreach ($this->rrsets as $set) if ($set['host'] === $host && $set['type'] === $type) $comments = $set['comments'] ?? [];
        if ($remark !== null) $comments = $remark === '' ? [] : [['account'=>'', 'content'=>$remark]];
        return ['name'=>$name, 'type'=>$type, 'ttl'=>(int)$ttl, 'changetype'=>'REPLACE',
            'records'=>array_map(static fn($r)=>array_intersect_key($r, array_flip(['content','disabled','set-ptr'])), array_values($records)), 'comments'=>$comments];
    }

    private function patchRrsets(array $changes)
    {
        $ok = $this->batchChanges !== null || $this->send_reuqest('PATCH', '/servers/' . $this->server_id . '/zones/' . $this->domainid, ['rrsets'=>$changes]);
        if ($ok === false) return false;
        foreach ($changes as $set) {
            if ($this->batchChanges !== null) $this->batchChanges[$set['name'].'|'.$set['type']]=$set;
            $this->rrsets = array_values(array_filter($this->rrsets, fn($r)=>$r['name'] !== $set['name'] || $r['type'] !== $set['type']));
            if ($set['changetype'] === 'DELETE') continue;
            $set['host'] = $set['name'] === $this->domainid ? '@' : substr($set['name'], 0, -strlen('.' . $this->domainid));
            $set['id'] = substr(hash('sha256', $set['name'] . '|' . $set['type']), 0, 20);
            foreach ($set['records'] as &$record) $record['id'] = substr(hash('sha256', $record['content']), 0, 20);
            unset($record);
            $this->rrsets[] = $set;
        }
        return true;
    }

    public function beginAtomicBatch(): void
    {
        if ($this->batchChanges !== null) throw new \RuntimeException('批量事务已经开始');
        $this->batchOriginal=$this->rrsets; $this->batchChanges=[];
    }

    public function commitAtomicBatch(): bool
    {
        if ($this->batchChanges === null) throw new \RuntimeException('批量事务尚未开始');
        $changes=array_values($this->batchChanges);
        $ok=!$changes || $this->send_reuqest('PATCH','/servers/'.$this->server_id.'/zones/'.$this->domainid,['rrsets'=>$changes]) !== false;
        if (!$ok) $this->rrsets=$this->batchOriginal;
        $this->batchChanges=null;$this->batchOriginal=[];
        return $ok;
    }

    public function cancelAtomicBatch(): void
    {
        if ($this->batchChanges !== null) $this->rrsets=$this->batchOriginal;
        $this->batchChanges=null;$this->batchOriginal=[];
    }

    private function rrset_replace($host, $type, $ttl, $records, $remark = null)
    {
        return $this->patchRrsets([$this->replacement($host, $type, $ttl, $records, $remark)]);
    }

    private function rrset_delete($host, $type)
    {
        return $this->patchRrsets([$this->replacement($host, $type, 0, [])]);
    }

    private function send_reuqest($method, $path, $params = null)
    {
        $url = $this->url . $path;
        $headers['X-API-Key'] = $this->apikey;
        $body = null;
        if ($method == 'GET' || $method == 'DELETE') {
            if ($params) {
                $url .= '?' . http_build_query($params);
            }
        } else {
            $body = json_encode($params);
            $headers['Content-Type'] = 'application/json';
        }
        try {
            $send = function () use ($url,$body,$headers,$method) {
                $response = http_request($url, $body, null, null, $headers, $this->proxy, $method);
                if ($method === 'GET' && in_array((int)$response['code'],[408,429,500,502,503,504],true)) throw new Exception('PowerDNS读取暂时失败', (int)$response['code']);
                return $response;
            };
            $response = $method === 'GET' ? \app\lib\ProviderReadRetry::run($send) : $send();
        } catch (Exception $e) {
            $this->setError($e->getMessage());
            return false;
        }

        $arr = json_decode($response['body'], true);
        if ($response['code'] < 400) {
            return is_array($arr) ? $arr : true;
        } elseif (isset($arr['error'])) {
            $this->setError($arr['error']);
            return false;
        } elseif (isset($arr['errors'])) {
            $this->setError(implode(',', $arr['errors']));
            return false;
        } else {
            $this->setError($response['body']);
            return false;
        }
    }

    private function setError($message)
    {
        $this->error = $message;
    }
}
