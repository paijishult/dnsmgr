# 聚合CC-DNS

> 基于 [彩虹聚合DNS管理系统](https://github.com/netcccyun/dnsmgr) 二次开发。

<div align="center">

[![GitHub stars](https://img.shields.io/github/stars/netcccyun/dnsmgr?style=flat)](https://github.com/netcccyun/dnsmgr/stargazers)
[![GitHub forks](https://img.shields.io/github/forks/netcccyun/dnsmgr?style=flat)](https://github.com/netcccyun/dnsmgr/forks)
[![Docker Pulls](https://img.shields.io/docker/pulls/netcccyun/dnsmgr?style=flat)](https://hub.docker.com/r/netcccyun/dnsmgr)
[![GitHub release (latest by date)](https://img.shields.io/github/v/release/netcccyun/dnsmgr)](https://github.com/netcccyun/dnsmgr/releases)
[![GitHub last commit](https://img.shields.io/github/last-commit/netcccyun/dnsmgr)](https://github.com/netcccyun/dnsmgr/commits/main)

</div>

彩虹聚合DNS管理系统 是一款基于ThinkPHP开发的网站程序，可实现在单一网站内管理多个平台的域名解析，目前已支持的域名解析平台有：阿里云、腾讯云、华为云、百度云、西部数码、火山引擎、DNSLA、CloudFlare、AWS Route 53、Namesilo、PowerDNS

## 功能特性

- 多用户管理，可为每个用户可分配不同的域名解析权限；
- 提供API接口，可获取域名单独的登录链接，方便各种IDC系统对接；
- 容灾切换功能，支持ping、tcp、http(s)检测协议并自动暂停/修改域名解析，并支持发送通知；
- 定时切换功能，设置在指定时间/周期，自动修改/开启/暂停/删除域名解析；
- CF优选IP功能，支持获取最新的Cloudflare优选IP，并自动更新到解析记录；
- SSL证书申请与自动部署功能，支持从Let's Encrypt等渠道申请SSL证书，并自动部署到各种面板、云服务商、服务器等；
- 支持邮件、微信公众号、Telegram、钉钉、飞书、企业微信等多种通知渠道。

## 本分支的改动

在原版基础上增加了开放API与权限控制，并重做了后台界面，原有功能和原有API对接方式保持不变。

### 开放API

- 后台「API接口 → API密钥」可以创建多个密钥，每个密钥单独勾选权限，例如只开启“申请证书”和“推送证书”，不允许修改解析；
- 每个密钥还可以限定域名范围（含子域名）、IP白名单（支持 CIDR）、每分钟调用次数和有效期；
- Secret 只在创建或重置时显示一次，数据库中加密保存；
- 解析、域名、证书申请、证书推送、容灾切换、定时切换、CF优选IP、Cloudflare 增强功能、操作日志都可以通过API操作，接口沿用原生方式（`POST /api/...`，`sign = md5(keyid + timestamp + secret)`）；
- 后台「API接口 → 接口文档」列出所有接口的参数、返回示例和 curl 示例，支持一键复制；
- 原有的用户API（`uid + timestamp + sign`）继续可用。

安全措施：

- API 只能看到DNS账户、证书账户、部署账户的ID、类型和备注，不返回账户名称、面板地址和任何密钥，也不能按账户名称搜索；部署任务配置中的密码类字段会被脱敏，返回内容中如出现系统保存的密钥值也会被替换为 `******`；
- 下载证书私钥是单独的权限，默认不勾选；
- SSH、FTP、复制到本机、S3存储类型的部署任务只能在后台创建和修改（可以把私钥写到任意位置或执行命令），API只能执行；
- 同一IP认证失败20次会被封禁15分钟；签名带时间戳，5分钟后失效；
- 获取客户端IP的方式可以在「API密钥」页面设置，默认只在经过本机/内网反向代理时才信任代理请求头，防止伪造IP绕过白名单。

### 界面

- 新的默认皮肤（Modern），右上角扳手图标仍可切换回原来的皮肤；
- 快速搜索：按 `⌘K` / `Ctrl+K` 搜索域名和功能页面；
- 首页统计改为合并查询，容灾进程的心跳写库频率降低，减少数据库压力。

### 原生安全与运维增强

- DNS 变更预览、自动/手动快照与安全回滚；后台批量任务支持逐项进度、失败续跑及取消；
- 证书部署可选线上 TLS 验收，验收失败只重试验收，避免重复部署；
- 修复表格下拉菜单裁切、页面输出转义、解析跨域名越权、登录/OTP/CSRF/API 并发限流及任务并发问题；
- 增加 PHP、MySQL、浏览器回归和镜像启动检查，依赖审计通过后才发布镜像。

升级步骤、功能限制、API、修改清单和审查复现命令见 [2026-10-19 修改文档](docs/changes-2026-10-19.md)。升级前停止旧任务进程，备份数据库与 `.env`，更新后重启任务进程。

### 审计日志

- 后台「审计日志」记录每一次API调用（含签名错误、无权限、超频等被拒绝的请求）和后台的登录、所有修改操作、下载私钥等敏感查看；
- 每条记录包含时间、来源（后台 / API密钥名称和KeyID）、操作者、IP、User-Agent、操作名称、操作对象、请求路径、请求参数、结果、返回信息和耗时；
- 请求参数中的密码、密钥、令牌、证书私钥一律脱敏，账户里保存的密钥值也会被替换为 `******`；
- 支持按来源、结果、API密钥、用户、IP、关键词、时间筛选，支持导出 CSV，可设置保留天数（默认 180 天）。

### 日志开关

- 审计日志：「审计日志 → 审计设置」可分别关闭后台操作记录和API调用记录，或设置API只记录修改操作；
- API 调用产生的操作日志：「API密钥」页面的“记录API操作日志”开关；
- 程序日志（`runtime/log`）：在 `.env` 中加入 `[LOG]` 段 `CLOSE = true`，或设置环境变量 `PHP_LOG_CLOSE=true`；
- Docker 镜像中的 nginx 访问日志：设置环境变量 `NGINX_ACCESS_LOG=off`。

### Docker 镜像

推送到 main 分支后由 GitHub Actions 自动构建（amd64 / arm64）：

```
docker run --name dnsmgr -dit -p 8081:80 -v /var/dnsmgr:/app/www ghcr.io/paijishult/dnsmgr:latest
```

## 部署方式

### 自部署

可以使用宝塔、Kangle等任意支持PHP-MySQL的环境部署

* 从[Release](https://github.com/netcccyun/dnsmgr/releases)页面下载安装包

* 运行环境要求PHP8.2+，MySQL5.6+

* 设置网站运行目录为`public`

* 设置伪静态为`ThinkPHP`

* 如果是下载的Source code包，还需Composer安装依赖（Release页面下载的安装包不需要）

  ```
  composer install --no-dev
  ```

* 访问网站，会自动跳转到安装页面，根据提示安装完成

* 访问首页登录控制面板

* 后续更新方式：重新下载安装包上传覆盖即可

##### 伪静态规则

* Nginx

```
location ~* (runtime|application)/ {
    return 403;
}
location / {
    if (!-e $request_filename) {
        rewrite ^(.*)$ /index.php?s=$1 last; break;
    }
}
```

* Apache

```
<IfModule mod_rewrite.c>
  Options +FollowSymlinks -Multiviews
  RewriteEngine On

  RewriteCond %{REQUEST_FILENAME} !-d
  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteRule ^(.*)$ index.php/$1 [QSA,PT,L]
</IfModule>
```

### Docker 部署

首先需要安装Docker，然后执行以下命令拉取镜像并启动（启动后监听8081端口）：

```
docker run --name dnsmgr -dit -p 8081:80 -v /var/dnsmgr:/app/www netcccyun/dnsmgr
```

访问并安装好后如果容灾切换未自动启动，重启容器即可：

```
docker restart dnsmgr
```

从国内镜像地址拉取：

```
docker pull swr.cn-east-3.myhuaweicloud.com/netcccyun/dnsmgr:latest
```

### docker-compose 部署

```
services:
  dnsmgr-web:
    container_name: dnsmgr-web
    stdin_open: true
    tty: true
    ports:
      - 8081:80
    volumes:
      - ./web:/app/www
    image: netcccyun/dnsmgr
    depends_on:
      - dnsmgr-mysql
    networks:
      - dnsmgr-network

  dnsmgr-mysql:
    container_name: dnsmgr-mysql
    restart: always
    ports:
      - 3306:3306
    volumes:
      - ./mysql/conf/my.cnf:/etc/mysql/my.cnf
      - ./mysql/logs:/logs
      - ./mysql/data:/var/lib/mysql
    environment:
      - MYSQL_ROOT_PASSWORD=123456
      - TZ=Asia/Shanghai
    image: mysql:5.7
    networks:
      - dnsmgr-network

networks:
  dnsmgr-network:
    driver: bridge
```

在运行之前请创建好目录

```
mkdir -p ./web
mkdir -p ./mysql/conf
mkdir -p ./mysql/logs
mkdir -p ./mysql/data

vim mysql/conf/my.cnf
[mysqld]
sql_mode=STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION
```

登陆mysql容器创建数据库

```
docker exec -it dnsmgr-mysql /bin/bash
mysql -uroot -p123456
create database dnsmgr;
```

在install界面链接IP填写dnsmgr-mysql

## 演示截图

添加域名账户

![](https://p0.meituan.net/csc/090508cdc7aaabd185ba9c76a8c099f9283946.png)

域名管理列表

![](https://p0.meituan.net/csc/60bf3f607d40f30f152ad1f6ee3be098357839.png)

域名DNS解析管理，支持解析批量操作

![](https://p0.meituan.net/csc/f99c599d4febced404c88672dd50d62c212895.png)

用户管理添加用户，支持为用户开启API接口

![](https://p0.meituan.net/csc/d1bd90bedca9b6cbc5da40286bdb5cd5228438.png)

CF优选IP功能，添加优选IP任务

![](https://p1.meituan.net/csc/da70c76753aee4bce044d16fadd56e5f217660.png)

SSL证书申请功能

![](https://blog.cccyun.cn/content/uploadfile/202412/QQ%E6%88%AA%E5%9B%BE20241221154857.png)

![](https://blog.cccyun.cn/content/uploadfile/202412/QQ%E6%88%AA%E5%9B%BE20241221154652.png?a)

SSL证书自动部署功能

![](https://blog.cccyun.cn/content/uploadfile/202412/QQ%E6%88%AA%E5%9B%BE20241221154702.png)

![](https://blog.cccyun.cn/content/uploadfile/202412/QQ%E6%88%AA%E5%9B%BE20241221154804.png)

## 支持与反馈

🌐 作者信息：消失的彩虹海(https://blog.cccyun.cn)

⭐ 如果您觉得本项目对您有帮助，欢迎给项目点个 Star

🤝 捐赠：

<img height="240" src="https://wkphoto.bj.bcebos.com/d8f9d72a6059252db065f556249b033b5bb5b976.jpg">

### 其他推荐

- [彩虹云主机 - 免备案CDN/虚拟主机](https://www.cccyun.net/)
- [小白云高防云服务器](https://www.xiaobaiyun.cn/aff/GMLPMFOV)

