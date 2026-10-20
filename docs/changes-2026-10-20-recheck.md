# 1066 再次复核修复记录

本轮对应 1065 发布后再次复核确认的六处问题，延续 ThinkPHP、MySQL、Bootstrap、原生 SSH 与现有计划任务方案。前一轮记录见 [1065 修改与升级说明](changes-2026-10-20.md)，项目基于 [netcccyun/dnsmgr](https://github.com/netcccyun/dnsmgr)，保留上游 MIT 版权与归属。

## 修复范围

| 问题与触发条件 | 修复后的行为 | 审查入口 |
| --- | --- | --- |
| IIS 导入命令的 PFX 密码进入日志，只读部署日志 API 也能读取 | SSH 仅记录步骤名称和结果；原始命令及任意远端输出不再持久化；写入、读取均按当前任务密码过滤；旧 certutil 命令行整体隐藏 | `deploy/ssh.php`、`SensitiveLog.php`、`CertDeployService.php`、`Cert.php` |
| 旧账户字段信息已缓存时，升级后的 `config` 列被 ORM 判为不存在 | 每个升级语句验证成功后强制刷新该表的连接内和共享字段缓存，再转换账户；保留其他缓存 | `LegacyMigrationService.php` |
| 手动编辑 PowerDNS / Technitium 记录后，关联监控或定时任务仍保存旧内容 ID | 原生表单、API、自动任务与预览执行共用同步入口；确认新记录后，在同域名范围内事务更新任务 ID、主机记录及记录信息 | `GuardedDns.php`、`AutomaticDnsTarget.php`、`DnsChangeService.php` |
| SSH 命令非零退出但 stderr 为空时，部署被判成功 | Linux 与 Windows 均要求有效退出码凭据；非零退出、缺少凭据、超时或超大输出均停止。零退出码的普通警告不会导致误报失败 | `deploy/ssh.php`、`ssh_command_test.php` |
| 证书签发后 DNS 清理失败没有重试；删除订单留下孤立租约 | 已签发订单的手动执行会重试清理；原生 certtask 每次运行检查已签发、已吊销及孤立租约；默认每轮最多 20 个订单，游标轮转；删除前必须清理成功 | `CertDnsService.php`、`CertOrderService.php`、`Certtask.php` |
| PowerDNS 给 TXT 值正常增加引号后，预览执行认为实际结果错误 | 仅对 PowerDNS 的 TXT 值兼容适配器既有引号格式；真实内容变化、其他记录被改动仍会停止 | `DnsChangeService.php`、`dns_safety_test.php` |

同时收紧日志归属：申请日志接口只读取现存订单的日志，部署日志接口只读取现存任务的日志，避免拿另一类进程编号或已删除任务编号读取缺少上下文的日志。IIS 使用编码后的 PowerShell 脚本，通过系统 ProcessStartInfo 按 Windows 原生参数规则启动工具；空密码、含引号的密码和带空格、反斜杠的路径均保留原值。加密 RSA PEM 的公钥派生使用账户里配置的私钥密码。

## 升级与使用

1. 备份数据库及部署配置，更新源码或拉取新镜像后重新创建容器，并重启原生任务进程。
2. 程序版本为 **1066**。本轮没有新增表或列，数据库版本仍为 **1052**，原生功能标记仍为 **20261020.1**。
3. 保持原有 `php think certtask` 计划任务运行。DNS 暂时不可用时保留清理凭据，之后轮转重试；签发成功状态保持成功，清理异常记录在日志及订单错误信息中。
4. 域名已删除或重新绑定时继续停止自动清理。需要核对并恢复原绑定后重试，防止修改新账户下的域名。正在签发或被工作进程占用的订单不会被清理队列触碰。
5. 删除订单遇到清理失败会明确保留订单并返回提示。旧版留下的孤立租约会进入后台重试；共享值只有最后一份归属释放后才清理。
6. 既有旧行号任务仍需按 1065 文档重新选择记录；1066 的 ID 同步用于之后通过本程序成功确认的修改。外部直接修改 DNS 后仍需要重新核对任务。
7. Windows SSH 使用系统自带的 Windows PowerShell 执行退出码包装；用户配置的普通 Windows 命令继续由 `cmd.exe` 解释。IIS 查询无旧绑定时不会先执行无意义的删除。

历史日志在读取时再次脱敏，磁盘上的旧日志不由升级程序批量改写。已有日志副本和过去已经导出的内容需要按实际情况自行处理。新 SSH 日志不会显示命令正文与原始输出，排障时可在受信任服务器上检查对应命令。

## 回归与复核

- `dns_safety_test.php`：真实 PowerDNS 适配器配合隔离 HTTP 状态，验证手动/API 编辑、关联任务继续执行、预览编辑引用同步、TXT 批量继续，以及真实差异仍被拒绝。
- `ssh_command_test.php`：Linux 和 Windows CI 使用真实本机 shell 模拟 SSH 通道，检查错误退出、零退出警告、输入输出不入日志、参数引用和缺失状态拒绝；不连接远程 SSH。
- `recheck_orm_test.php`：真实 ThinkORM、持久字段缓存和 SQLite，验证新增列可写、新连接缓存已刷新、无关缓存保留，以及部署日志 API 的脱敏与归属限制。
- `cert_cleanup_test.php`：真实 ORM / SQLite 和虚构 DNS，验证手动重试、删除失败保留订单、后台重试、历史孤立与共享租约、正在签发、工作进程占用和域名重绑等场景；SQLite 使用 WAL 支持多连接，锁接口模拟 MySQL，真实 MySQL 锁另由原有集成测试验证。
- 原有 PHP 8.2 / 8.3 / 8.4、MySQL 5.6 / 8.0 / 8.4、全部模板桌面/手机浏览器检查和镜像双架构运行检查继续保留；新增 Windows SSH 命令检查。

本轮通过的本地检查、CI 和镜像结果以对应提交的 [GitHub Actions](https://github.com/paijishult/dnsmgr/actions) 为准。PHP WASM 中提示跳过的原生扩展和数据库测试不算通过；带 Composer 的本地隔离运行另行执行 ORM 测试。全部 DNS、证书和 SSH 业务对象使用虚构数据，不把真实凭证、私钥、日志、数据库或个人路径提交到仓库。
