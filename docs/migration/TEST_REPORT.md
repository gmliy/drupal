# 测试报告

日期：2026-11-03。工具版本：2.0.0。

生产网站、生产数据库、Nginx、PHP、证书、cron、`/root/backup.sh` 和 Cloudflare 没有被修改。生产 `settings.php` 的修改时间仍是 2026-11-02 09:33:14 UTC。

## 隔离环境

恢复演练容器 `xedc-recover-drill`：

- 镜像 `ubuntu:24.04`
- 网络 `xedc-recover-lab`
- 内存 3 GiB，CPU 1.5，非特权
- 端口只发布 `127.0.0.1:18080 -> 80`
- 只读挂载密钥、工具目录和加密备份
- 容器内不存在 `/var/www/drupal/web/core`

域名迁移使用另一个容器 `xedc-recover-db`，MariaDB 只监听 `127.0.0.1:13306`。

## 单元和实验室套件

命令：

```bash
cd /opt/xedc-migration/tools/xedc-recover
python3 -m unittest tests.test_core tests.test_lab
```

最终代码完成后连续两轮：

| 轮次 | 结果 | 耗时 |
| --- | --- | --- |
| A | 17 tests, OK | 2.095s |
| B | 17 tests, OK | 1.701s |

覆盖 S1 到 S12。S12 在 `127.0.0.1:13306` 上完成改写、第二次 0 行和回滚。S11 使用进程内 S3 服务和 6 MB 分段上传。S10 的签名与 AWS 2013-05-24 `GET /test.txt` 公开向量一致。

## 生产只读备份

备份 ID：`xedc-20261103T135450Z-3cd3bf`。

`backup verify` 解密 code、database、private、public、secrets 后结果：

```json
{"id": "xedc-20261103T135450Z-3cd3bf", "status": "PASS", "checked": ["code", "database", "private", "public", "secrets"], "parent_id": ""}
```

manifest 中的计数：users 11，node 94，file_managed 273。校验输出和 manifest 没有密钥值。

## 全新服务器演练

同一容器里连续执行两次 `lab/drill.sh xedc-20261103T135450Z-3cd3bf`。中间没有改恢复逻辑。

| 轮次 | 退出码 | 耗时 | 结果 |
| --- | --- | --- | --- |
| 1 | 0 | 144.7s | `DRILL_OK` |
| 2 | 0 | 124.9s | `DRILL_OK` |

两次报告相同：

```json
{"home_bytes": 37192, "login_has_form": true, "drupal_header": true, "home_status": "HTTP/1.1 200 OK"}
```

从宿主机访问发布端口：

| 请求 | 结果 |
| --- | --- |
| `GET /`，Host `lab.xedc.test` | 200，标题 `DcLili - 精选美图、视频与优质文章`，`X-Generator: Drupal 11` |
| `GET /user/login` | 200，标题 `登录 \| DcLili`，页面含登录表单 |
| `GET /sites/default/files/comments/2026-10/IMG_2154_0.png` | 200，`image/png`，173184 字节 |
| 私有目录 URL | 404 |

导入后的数据库计数：users 11，node 94，file_managed 273。public 是指向 `/srv/xedc/public` 的符号链接。`xedc-storage-keys` 已删除。`active.json` 为 `public_mode=local`、`cdn_enabled=false`、`writes_paused=true`、`status=lab-isolated`。`settings.php` 的真实数据库名和用户是 `xedc_restore`，主机是 `127.0.0.1`，私有目录是 `/srv/xedc/private`，trusted host 是演练域名；示例注释里的占位口令没有被当成正式口令。

容器内版本：PHP 8.5.12，MariaDB 13.0.2，Nginx 1.31.8，ffmpeg 6.1.1，7-Zip 23.01，cryptography 41.0.7。Redis 是发行版的 7.0.15，生产机是 8.10.3。首页仍然返回 200。

## 演练中修复过的问题

| 问题 | 根因 | 结果 |
| --- | --- | --- |
| 解包直接失败 | 备份里的硬链接被当成不安全路径；嵌套 `node_modules` 没有排除 | FIXED。硬链接复制成普通文件，越界路径仍拒绝 |
| settings 仍指向原库 | 改写命中了文件前部的示例注释 | FIXED。改为最后一处真实配置 |
| `nginx -t` 失败 | pid 目录不存在 | FIXED |
| Nginx 无法绑定 80 | 重复启动，旧进程还占着端口 | FIXED |
| 502 | `nginx` 用户不能连接 `www-data` 的套接字，目录模式过严 | FIXED |
| 500 Access denied | 第二次导入时 `CREATE USER IF NOT EXISTS` 不更新密码 | FIXED。导入时 `ALTER USER` |
| 演练脚本找不到 cryptography | 命令在安装该包之前就导入了加密模块 | FIXED |

这些修复之后才记录上面的连续两轮 PASS。

## 未通过项

| 项目 | 状态 | 原因 |
| --- | --- | --- |
| 向生产或专用 R2 桶上传 | BLOCKED | 没有专用测试桶。MinIO 镜像无法拉取。本地 S3 和公开签名向量已通过 |
| 正式 Let's Encrypt 和 DNS-01 | BLOCKED | 只生成 staging 计划，不申请正式证书 |
| replacement 角色整站启动 | NOT TESTED | 该角色会保留对象存储密钥。演练使用 lab，避免第二份网站写回生产 R2 |
| 在生产机安装 systemd 定时任务 | NOT TESTED | 生产主机上该操作被拒绝。临时目录渲染已通过 |
