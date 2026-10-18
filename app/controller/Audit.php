<?php

namespace app\controller;

use app\BaseController;
use app\lib\AuditHelper;
use think\facade\Cache;
use think\facade\Db;
use think\facade\View;

/**
 * 审计日志（仅管理员）
 */
class Audit extends BaseController
{
    public function index()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        db_update_check();
        View::assign('keys', Db::name('apikey')->field('id,name')->order('id', 'desc')->select()->toArray());
        View::assign('users', Db::name('user')->field('id,username')->order('id', 'asc')->select()->toArray());
        View::assign('settings', [
            'audit_web' => config_get('audit_web', '1'),
            'api_calllog' => config_get('api_calllog', 'all'),
            'audit_days' => config_get('audit_days', '180'),
        ]);
        return view();
    }

    /**
     * 列表与统计
     */
    public function data()
    {
        if (!checkPermission(2)) return json(['total' => 0, 'rows' => []]);
        $offset = max(0, input('post.offset/d', 0));
        $limit = min(500, max(1, input('post.limit/d', 20)));
        $select = $this->buildQuery($this->filters('post.'));
        $total = (clone $select)->count();
        $rows = $select->order('id', 'desc')->limit($offset, $limit)->select()->toArray();
        foreach ($rows as &$row) {
            $row['result_name'] = AuditHelper::resultName($row['result']);
        }
        $ret = ['total' => $total, 'rows' => $rows];
        if (input('post.withstat/d') == 1) {
            $ret['stat'] = $this->stat();
        }
        return json($ret);
    }

    /**
     * 导出CSV（使用当前筛选条件，最多5万条）
     */
    public function export()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $select = $this->buildQuery($this->filters('get.'));
        $rows = $select->order('id', 'desc')->limit(50000)->select();
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['ID', '时间', '来源', '用户', 'API密钥', 'KeyID', 'IP', '操作', '对象', '请求', '结果', 'HTTP状态', '返回码', '返回信息', '耗时(ms)', '参数', 'User-Agent']);
        foreach ($rows as $row) {
            fputcsv($out, array_map([$this, 'csvCell'], [
                $row['id'], $row['addtime'], $row['source'] === 'api' ? 'API' : '后台', $row['username'], $row['kname'], $row['keyid'], $row['ip'],
                $row['name'], $row['target'], $row['method'] . ' ' . $row['path'], AuditHelper::resultName($row['result']), $row['status'],
                $row['code'], $row['msg'], $row['duration'], $row['params'], $row['ua'],
            ]));
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);
        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="audit-' . date('YmdHis') . '.csv"',
        ]);
    }

    /**
     * 清理指定天数之前的日志
     */
    public function clean()
    {
        if (!checkPermission(2)) return json(['code' => -1, 'msg' => '无权限']);
        $days = input('post.days/d', 0);
        if ($days < 1) return json(['code' => -1, 'msg' => '只能清理1天之前的日志']);
        $count = Db::name('audit')->where('addtime', '<', date('Y-m-d H:i:s', time() - $days * 86400))->delete();
        return json(['code' => 0, 'msg' => '已清理' . $count . '条' . $days . '天前的审计日志']);
    }

    /**
     * 审计设置
     */
    public function setting()
    {
        if (!checkPermission(2)) return json(['code' => -1, 'msg' => '无权限']);
        $web = input('post.audit_web') == '1' ? '1' : '0';
        $api = input('post.api_calllog');
        if (!in_array($api, ['all', 'write', 'off'], true)) return json(['code' => -1, 'msg' => 'API调用记录方式不正确']);
        $days = input('post.audit_days/d', 180);
        if ($days < 0 || $days > 3650) return json(['code' => -1, 'msg' => '保留天数范围 0-3650（0 表示永久保留）']);
        config_set('audit_web', $web);
        config_set('api_calllog', $api);
        config_set('audit_days', (string)$days);
        Cache::delete('configs');
        return json(['code' => 0, 'msg' => '设置已保存']);
    }

    private function filters($prefix)
    {
        $f = [];
        foreach (['source', 'result', 'kid', 'uid', 'ip', 'kw', 'name', 'start', 'end'] as $k) {
            $v = input($prefix . $k);
            $f[$k] = is_scalar($v) ? trim((string)$v) : '';
        }
        return $f;
    }

    private function buildQuery(array $f)
    {
        $select = Db::name('audit');
        if (in_array($f['source'], ['web', 'api'], true)) $select->where('source', $f['source']);
        if (in_array($f['result'], ['0', '1', '2'], true)) $select->where('result', intval($f['result']));
        if ($f['kid'] !== '' && ctype_digit($f['kid'])) $select->where('kid', intval($f['kid']));
        if ($f['uid'] !== '' && ctype_digit($f['uid'])) $select->where('uid', intval($f['uid']));
        if ($f['ip'] !== '') $select->where('ip', $f['ip']);
        if ($f['name'] !== '') $select->where('name', $f['name']);
        if ($f['kw'] !== '') $select->whereLike('name|path|target|msg|params|username|kname', '%' . $f['kw'] . '%');
        if ($f['start'] !== '' && strtotime($f['start'])) $select->where('addtime', '>=', date('Y-m-d H:i:s', strtotime($f['start'])));
        if ($f['end'] !== '' && strtotime($f['end'])) $select->where('addtime', '<=', date('Y-m-d H:i:s', strtotime($f['end'])));
        return $select;
    }

    private function stat()
    {
        $prefix = env('database.prefix', 'dnsmgr_');
        $since = date('Y-m-d H:i:s', time() - 86400);
        $r = Db::query('SELECT COUNT(*) AS c, IFNULL(SUM(source=\'api\'),0) AS api, IFNULL(SUM(result=0),0) AS fail, IFNULL(SUM(result=2),0) AS denied, COUNT(DISTINCT ip) AS ips FROM `' . $prefix . 'audit` WHERE addtime >= ?', [$since])[0];
        $topIps = Db::query('SELECT ip, COUNT(*) AS c FROM `' . $prefix . 'audit` WHERE addtime >= ? AND result <> 1 GROUP BY ip ORDER BY c DESC LIMIT 3', [$since]);
        return [
            'total' => (int)$r['c'],
            'api' => (int)$r['api'],
            'fail' => (int)$r['fail'],
            'denied' => (int)$r['denied'],
            'ips' => (int)$r['ips'],
            'top_fail_ips' => $topIps,
            'all' => (int)Db::name('audit')->count(),
            'first' => Db::name('audit')->order('id', 'asc')->value('addtime'),
        ];
    }

    /**
     * 防止导出的CSV在Excel中被当作公式执行
     */
    private function csvCell($v)
    {
        $v = (string)$v;
        if ($v !== '' && !is_numeric($v) && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            $v = "'" . $v;
        }
        return $v;
    }
}
