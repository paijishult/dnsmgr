<?php

namespace app\controller;

use app\BaseController;
use Exception;
use think\facade\Db;
use think\facade\View;
use think\facade\Cache;

class Index extends BaseController
{
    public function index()
    {
        if ($this->request->user['type'] == 'domain') {
            return redirect('/record/' . $this->request->user['id']);
        }
        if ($this->request->isAjax()) {
            if (input('post.do') == 'stat' || !empty($this->request->isApi)) {
                $stat = [];
                if ($this->request->user['level'] == 2) {
                    $stat['domains'] = Db::name('domain')->count();
                } else {
                    $stat['domains'] = Db::name('domain')->where('name', 'in', $this->request->user['permission'])->count();
                }
                $now = date('Y-m-d H:i:s');
                // 合并统计查询，减少数据库往返
                $prefix = env('database.prefix', 'dnsmgr_');
                $dm = Db::query('SELECT COUNT(*) AS c, IFNULL(SUM(active=1),0) AS a, IFNULL(SUM(status=0),0) AS s0, IFNULL(SUM(status=1),0) AS s1 FROM `' . $prefix . 'dmtask`')[0];
                $op = Db::query('SELECT IFNULL(SUM(active=1),0) AS a, IFNULL(SUM(status=1),0) AS s1, IFNULL(SUM(status=2),0) AS s2 FROM `' . $prefix . 'optimizeip`')[0];
                $co = Db::query('SELECT COUNT(*) AS c, IFNULL(SUM(status=3),0) AS s3, IFNULL(SUM(status<0),0) AS s5, IFNULL(SUM(expiretime>=? AND expiretime<?),0) AS s6, IFNULL(SUM(expiretime<?),0) AS s7 FROM `' . $prefix . 'cert_order`', [$now, date('Y-m-d H:i:s', time() + 86400 * 7), $now])[0];
                $cd = Db::query('SELECT COUNT(*) AS c, IFNULL(SUM(status=0),0) AS s0, IFNULL(SUM(status=1),0) AS s1, IFNULL(SUM(status=-1),0) AS s2 FROM `' . $prefix . 'cert_deploy`')[0];

                $stat['tasks'] = (int)$dm['c'];
                $stat['certs'] = (int)$co['c'];
                $stat['deploys'] = (int)$cd['c'];

                $run_time = config_get('run_time', null, true);
                $run_state = $run_time ? (time() - strtotime($run_time) > 10 ? 0 : 1) : 0;
                $stat['dmonitor_state'] = $run_state;
                $stat['dmonitor_active'] = (int)$dm['a'];
                $stat['dmonitor_status_0'] = (int)$dm['s0'];
                $stat['dmonitor_status_1'] = (int)$dm['s1'];

                $stat['optimizeip_active'] = (int)$op['a'];
                $stat['optimizeip_status_1'] = (int)$op['s1'];
                $stat['optimizeip_status_2'] = (int)$op['s2'];

                $stat['certorder_status_3'] = (int)$co['s3'];
                $stat['certorder_status_5'] = (int)$co['s5'];
                $stat['certorder_status_6'] = (int)$co['s6'];
                $stat['certorder_status_7'] = (int)$co['s7'];

                $stat['certdeploy_status_0'] = (int)$cd['s0'];
                $stat['certdeploy_status_1'] = (int)$cd['s1'];
                $stat['certdeploy_status_2'] = (int)$cd['s2'];

                try {
                    $stat['apikeys'] = Db::name('apikey')->where('status', 1)->count();
                    $stat['apikey_last'] = Db::name('apikey')->whereNotNull('lasttime')->order('lasttime', 'desc')->value('lasttime');
                } catch (Exception $e) {
                    $stat['apikeys'] = 0;
                    $stat['apikey_last'] = null;
                }
                $stat['expire_domains'] = $this->request->user['level'] == 2 ? Db::name('domain')->where('expiretime', '<=', date('Y-m-d H:i:s', time() + 86400 * 30))->where('expiretime', '>', $now)->count() : 0;

                return json($stat);
            }
            return json(['code' => -3]);
        }

        db_update_check();

        $tmp = 'version()';
        $mysqlVersion = Db::query("select version()")[0][$tmp];
        $info = [
            'framework_version' => app()->version(),
            'php_version' => PHP_VERSION,
            'mysql_version' => $mysqlVersion,
            'software' => $_SERVER['SERVER_SOFTWARE'] ?? '未知',
            'os' => php_uname(),
            'date' => date("Y-m-d H:i:s"),
        ];
        View::assign('info', $info);
        View::assign('version', config('app.version'));
        return view();
    }

    public function changeskin()
    {
        $skin = input('post.skin');
        if ($this->request->user['level'] == 2) {
            if (cookie('admin_skin')) {
                cookie('admin_skin', null);
            }
            config_set('admin_skin', $skin);
            Cache::delete('configs');
        } else {
            cookie('admin_skin', $skin);
        }
        return json(['code' => 0, 'msg' => 'succ']);
    }

    public function cleancache()
    {
        if (!checkPermission(1)) return $this->alert('error', '无权限');
        Cache::clear();
        clearDirectory(app()->getRuntimePath().'cache/');
        clearDirectory(app()->getRuntimePath().'temp/');
        return json(['code' => 0, 'msg' => 'succ']);
    }

    public function doc()
    {
        if (!checkPermission(1)) return $this->alert('error', '无权限');
        View::assign('siteurl', $this->request->root(true));
        return view();
    }

    public function setpwd()
    {
        if (!checkPermission(1)) return $this->alert('error', '无权限');
        if ($this->request->isPost()) {
            $oldpwd = input('post.oldpwd');
            $newpwd = input('post.newpwd');
            $newpwd2 = input('post.newpwd2');
            if (empty($oldpwd) || empty($newpwd) || empty($newpwd2)) {
                return json(['code' => -1, 'msg' => '密码不能为空']);
            }
            if ($newpwd != $newpwd2) {
                return json(['code' => -1, 'msg' => '两次输入的密码不一致']);
            }
            if (!password_verify($oldpwd, $this->request->user['password'])) {
                return json(['code' => -1, 'msg' => '原密码错误']);
            }
            Db::name('user')->where('id', $this->request->user['id'])->update(['password' => password_hash($newpwd, PASSWORD_DEFAULT)]);
            return json(['code' => 0, 'msg' => 'succ']);
        }
        View::assign('user', $this->request->user);
        return view();
    }

    public function totp()
    {
        if (!checkPermission(1)) return $this->alert('error', '无权限');
        $action = input('param.action');
        if ($action == 'generate') {
            try {
                $totp = \app\lib\TOTP::create();
                $totp->setLabel($this->request->user['username']);
                $totp->setIssuer('DNS Manager');
                return json(['code' => 0, 'data' => ['secret' => $totp->getSecret(), 'qrcode' => $totp->getProvisioningUri()]]);
            } catch (Exception $e) {
                return json(['code' => -1, 'msg' => $e->getMessage()]);
            }
        } elseif ($action == 'bind') {
            $secret = input('post.secret');
            $code = input('post.code');
            if (empty($secret)) return json(['code' => -1, 'msg' => '密钥不能为空']);
            if (empty($code)) return json(['code' => -1, 'msg' => '请输入动态口令']);
            try {
                $totp = \app\lib\TOTP::create($secret);
                if (!$totp->verify($code)) {
                    return json(['code' => -1, 'msg' => '动态口令错误']);
                }
            } catch (Exception $e) {
                return json(['code' => -1, 'msg' => $e->getMessage()]);
            }
            Db::name('user')->where('id', $this->request->user['id'])->update(['totp_open' => 1, 'totp_secret' => $secret]);
            return json(['code' => 0, 'msg' => 'succ']);
        } elseif ($action == 'close') {
            Db::name('user')->where('id', $this->request->user['id'])->update(['totp_open' => 0, 'totp_secret' => null]);
            return json(['code' => 0, 'msg' => 'succ']);
        }
    }

    public function test()
    {

    }
}
