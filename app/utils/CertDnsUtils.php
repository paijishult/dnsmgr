<?php

namespace app\utils;

use Exception;
use think\facade\Db;
use app\lib\DnsHelper;

class CertDnsUtils
{
    public static function addDns($dnsList, callable $log, $cname = false, int $oid = 0)
    {
        \app\service\CertDnsService::add($dnsList, $log, (bool)$cname, $oid);
    }

    public static function delDns($dnsList, callable $log, $cname = false, int $oid = 0)
    {
        \app\service\CertDnsService::remove($oid, $log);
    }

    public static function verifyDns($dnsList)
    {
        if (empty($dnsList)) return true;
        foreach ($dnsList as $mainDomain => $list) {
            foreach ($list as $row) {
                if ($row['type'] == 'CAA') continue;
                $domain = $row['name'] . '.' . $mainDomain;
                $result = DnsQueryUtils::get_dns_records($domain, $row['type']);
                if (!self::valueMatches($row['value'], $result)) {
                    $result = DnsQueryUtils::query_dns_doh($domain, $row['type']);
                    if (!self::valueMatches($row['value'], $result)) {
                        return false;
                    }
                }
            }
        }
        return true;
    }

    private static function valueMatches($expected, $result)
    {
        if (empty($result)) return false;
        if (in_array($expected, $result)) return true;
        if (in_array(strtolower($expected), $result)) return true;
        $expectedBin = @inet_pton($expected);
        if ($expectedBin !== false) {
            $normalized = array_map(fn($v) => @inet_pton($v) ?: $v, $result);
            return in_array($expectedBin, $normalized);
        }
        return false;
    }
}
