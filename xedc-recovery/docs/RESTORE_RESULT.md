# 本轮恢复结果

说明范围是 2026-11-04 这一轮对 `xedc-recovery/` 的恢复验证、回滚校验和仓库首页重写。依据是代码、单元测试、隔离容器日志和已推送提交。没有写入密钥、邮箱、内网地址或对象存储 endpoint。

`main` 与 `origin/main` 一致，提交 `ca925a3`。没有创建 `/var/www/drupal/.MIGRATION_DONE`。

## 提交

| 提交 | 内容 |
| --- | --- |
| `df0e912` | 在干净 Ubuntu 24.04 容器里恢复不含生产数据的夹具 |
| `3c61d3c` | 回滚必须能核对已保存的文件，空快照标为 FAIL |
| `ca925a3` | 重写仓库首页，并归档旧迁移脚本和原来的首页说明 |

标签 `backup-before-migration-v2` 仍指向 `3f56c01`。没有改写历史，也没有强制推送。

## 夹具恢复

夹具备份在仓库外：

`/var/lib/xedc-recovery/fixture-backups/20261104T070508Z`

构建时设置了 `XEDC_SQL_DUMP_FILE` 和 `XEDC_SKIP_DOCKER=1`，没有连接生产数据库，也没有导出生产 Docker 卷。生产快照 `/var/backups/xedc/20261104T061746Z` 没有被拿来恢复。

恢复命令：

```bash
bash xedc-recovery/tests/integration/restore-docker.sh 快照目录 age私钥
```

容器镜像是 `ubuntu:24.04`，限制为 4G 内存和 2 个 CPU。挂载只有夹具快照、夹具 age 私钥和 `xedc-recovery/` 代码。

后一次完整运行退出码为 0，日志结束于 `FIXTURE_RESTORE_OK`。

| 检查 | 结果 |
| --- | --- |
| PHP | 容器日志为 `8.3.6 → 8.5 → 8.5.12` |
| 数据库 | MariaDB 导入夹具的 2 张表 |
| 域名 | `old.example.test` 替换为 `newer.example.test`，序列化字段可以 unserialize |
| 文件 | 公共文件 `hello.txt` 和 `settings.local.php` 存在 |
| HTTPS | 首页 200，`/admin` 302 |
| 重复恢复 | 第二次跑完后，对账要求 `异常差异: 0` |
| ffmpeg | 安装 `6.1.1-3ubuntu5`，冒烟步骤没有告警 |

同一次日志里的警告仍然成立，不能改记成通过：

- 容器里没有 Docker 守护进程，Matomo 只恢复了 compose 模板。
- 夹具没有 Drush，配置导入、数据库更新、管理员重置和一次性登录没有执行。
- 证书走的是自签名等待模式，没有向证书机构申请。
- 容器没有 systemd，SSL 等待 timer 没有启用。

前面还有一次同样退出码为 0 的运行。那次在重复恢复时把表数报成 0，对账出现了异常差异。原因是已完成的步骤被跳过之后没有重新读取数据库名。修复后的运行不再出现这个差异。

## 回滚

`bash xedc-recovery/tests/unit/shell_functions.sh` 通过。其中两项是：

- 临时目录里的站点文件写回后，SHA256 与覆盖前一致，状态为 PASS。
- 没有可核对内容的空快照状态为 FAIL。

运行中的 Nginx 配置和数据库回滚还没有执行。

## 文档

仓库根目录 `README.md` 已改成 v2 的说明，命令和状态以当前代码为准。详细说明在 `xedc-recovery/README.md`。

`site-migrate.sh` 移到 `legacy/site-migrate.sh`。归档时的根 README 实际是 `tools/xedc-recover` 的首页，保存在 `legacy/README-xedc-recover.md`，没有标成旧迁移脚本的手册。`docs/migration/` 里的相对链接已改到新路径。

## 还没有完成

- 真实 Drupal 站点的 Composer、Drush、配置导入和文件权限验收。
- HTTP-01、DNS-01 和证书续期。测试只允许使用 staging 或本地 Pebble，本轮没有调用。
- Matomo、Netdata 和 Docker 数据卷的实际拉起。
- Ubuntu 22.04、26.04、26.10 和 Debian 12。
- Docker A 级 24 个场景、B 级子集，以及连续两轮。
- 完整对账。当前只比较 Drupal 版本、PHP、公共文件数、表数、ffmpeg 和对象数量。

公开 Git 历史里仍然有数据库转储、`settings.php` 中的数据库密码和 hash salt，以及 SMTP 密码。这些凭据还没有轮换。当前文件已经脱敏，不表示历史泄露已经消失。
