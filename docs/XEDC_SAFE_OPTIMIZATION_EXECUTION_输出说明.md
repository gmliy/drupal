# 安全优化执行说明

日期：2026-11-03  
站点：https://gitxx.xyz  
项目：/var/www/drupal

本文件说明第二阶段做了什么、为什么停在这里。逐项证据、哈希和测试表在 `docs/XEDC_SAFE_OPTIMIZATION_EXECUTION_REPORT.md`。依据是上一阶段的五份审计：

- `docs/XEDC_FULL_CODE_ASSET_AND_PERFORMANCE_AUDIT_REPORT.md`
- `docs/XEDC_FULL_CODE_ASSET_INVENTORY.md`
- `docs/XEDC_FULL_DEPENDENCY_MAP.md`
- `docs/XEDC_FULL_PERFORMANCE_AUDIT.md`
- `docs/XEDC_SAFE_OPTIMIZATION_PLAN.md`

没有提交 Git，没有推送。HEAD 仍是 `e72409eba38874aaaba4c48e16d085951d222f10`。开始前工作区里已有的未提交修改没有被重置。

## 这次要做什么

在不改页面、权限、路由和数据的前提下，处理审计里已经证实、而且只动自定义代码就能完成的项：

- 修掉会变成 HTTP 500 的导入失败路径。
- 修掉会拼出 `/undefined/retry` 的重试链接。
- 降低监控总览对 Netdata 的轮询频率。
- 删除三份确认没有引用的 PHP 类。

证据不足、需要改核心、配置、数据库、Nginx、PHP-FPM、Cloudflare 或 R2 的项全部停住，记为 BLOCKED。没有为了减少文件数量去合并行为不完全相同的代码。

## 实际改了什么

| 文件 | 结果 |
| --- | --- |
| `web/modules/custom/xedc_core/src/ImageBatchIntake.php` | 工作目录为空或复制失败时返回原来的失败结构，控制器仍给出 422，不再把 `DirectoryNotReadyException` 抛成 500。目录可用时的复制、校验和入库路径没变 |
| `web/modules/custom/xedc_core/js/asset-upload.js` | 只有任务 ID 是大于 0 的整数才生成重试链接 |
| `web/modules/custom/xedc_core/src/Ops/ServerMonitor.php` | 总览刷新 3 秒改为 10 秒，成功快照缓存 3 秒改为 8 秒。CPU、内存、网络仍是 3 秒。失败快照仍是 15 秒 |
| `web/modules/custom/xedc_core/src/Controller/MonitorController.php` | 页面设置缺少刷新秒数时，回退值从 3 改为 10。监控 JSON 仍是 `no-store, private` |
| `web/modules/custom/xedc_admin_ui/src/ModerationGuard.php` | 删除。类名只在自身和审计文档里出现，配置和服务都没有引用 |
| `web/modules/custom/xedc_core/src/Ops/Storage/StorageManager.php` | 删除。正在使用的是 `StorageSwitchManager` |
| `web/modules/custom/xedc_core/src/Ops/Storage/StorageSwitchboard.php` | 删除。正在使用的是 `xedc_core.storage_switch` 和 `xedc_core.storage_delivery` |

`ImageBatchIntake.php` 和 `asset-upload.js` 本来就是未跟踪文件，这次只加了上述判断。`ServerMonitor.php` 在这次之前就有未提交改动（缓存键带时区等）。`git diff HEAD` 会把那些旧改动和这次的两个数字一起显示出来。

删除后 Drupal 仍能启动。管理模块仍启用，三个在用的存储服务仍能从容器取出。`file_managed` 322 行、`node` 84 行、队列 142 行，与删除前相同。没有删用户文件、R2 对象或数据库记录。

## 测到什么程度

PHP 语法检查通过。相关服务能从容器创建。重试链接的 10 个边界值在 Node 里复跑正确。登录页修改前后都是 200、18845 字节；83ms 和 67ms 是单次采样，不记成性能提升。首页两次都是维护模式的 503。

没有登录后台，所以导入上传、监控轮询次数、审核页和存储页都是 NOT TESTED。维护模式开着，前台内容页没有打开，UI 不能标 PASS。没有清缓存，没有跑会写数据的手工测试，也没有新装测试工具。

## 为什么很多审计项没动

| 停住的项 | 原因 |
| --- | --- |
| `settings.php.bak` 仍可下载 | 要改 Nginx 并轮换口令。没有先删备份，避免口令还在线上时只从工作区拿走副本 |
| 翻译密钥在 State 里 | 要改密钥存放位置并轮换 |
| 维护模式、503 的公共缓存头 | 关闭维护模式会公开站点。缓存头没有落在自定义代码里 |
| Nginx 上传临时目录权限、PHP-FPM 进程数、Redis 上限 | 禁止改这些服务配置 |
| 684MB 聚合 CSS/JS、数据库旧 cache 表 | 需要在生产清缓存或截断表 |
| 残留 batch 队列行 | 是数据库记录，没有删 |
| 拆 Bootstrap、删图标、合并重复 CSS | 没有证明页面覆盖和层叠结果不变 |
| 两套模特站点地图 URL、多套文件引用判断 | 失败行为和数据源不同，不能合成一个函数 |
| 卸载 Gin、workspaces，导出配置 | 禁止卸载模块和改 `config/sync` |
| 66 个 `.bak` 文件 | 禁止批量删除 |

## 回滚

三个删除的类和 `MonitorController.php` 可以用 Git 单独恢复：

```bash
git checkout HEAD -- \
  web/modules/custom/xedc_admin_ui/src/ModerationGuard.php \
  web/modules/custom/xedc_core/src/Ops/Storage/StorageManager.php \
  web/modules/custom/xedc_core/src/Ops/Storage/StorageSwitchboard.php \
  web/modules/custom/xedc_core/src/Controller/MonitorController.php
```

`ServerMonitor.php` 只把总览的 10 改回 3、成功快照的 8 改回 3。不要整文件退回 HEAD，否则会清掉这次之前已有的修改。

`ImageBatchIntake.php` 和 `asset-upload.js` 不在 HEAD 里。要撤这次改动，就去掉 `workReady()` 和 `copy()` 的异常捕获，并把重试链接的条件改回原来的 `if (job.taskId && ...)`。

## 还留着的问题

设置备份仍可被未登录下载，这是当前最大的未处理风险。维护模式仍开着。导入失败路径已经改成返回失败结构，但没有用一张图片打过上传接口。监控是否少打请求，要登录后看访问日志。上传临时目录权限、聚合文件体积、残留 batch 和数据库旧 cache 表都还在。
