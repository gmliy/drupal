# 对象存储接管检查与容量单位

日期：2026-11-03  
站点：https://gitxx.xyz  
页面：`/admin/config/media/object-storage/runtime`  
判定：接管检查逻辑 **PASS**。容量显示 **PASS**。生产接管 **未执行**。没有提交 Git。

## 1. 原始接管失败原因

提示「运行时、接管或 CDN rewrite 已经打开。本检查预期它们是关闭的。」只来自 `TakeoverReadiness::report()`。它把四个来源用一次「或」合成一条，并要求全部关闭。

这条规则是首次启用对象存储之前的检查。当前生产已经由运行状态文件处于 Cloudflare R2，公开接管和 CDN 改写打开是这条架构的正常结果，不是读错配置后的假开。

真正触发这条提示的是后两项，不是 `xedc_storage.runtime_applied`：

| 开关 | 旧检查读取的值 | 本次读到的生产值 | 说明 |
| --- | --- | --- | --- |
| 运行时 | `Settings xedc_storage.runtime_applied` | false | `BootGuard` 在接受配置后仍把这个值写成 false。它不能表示运行时已关闭，也不能表示已打开。 |
| 公开接管 | `Settings s3fs.use_s3_for_public` | true | 运行模式为 `cloudflare_r2` 时，`BootGuard` 会把它写成 true。 |
| 私有接管 | `Settings s3fs.use_s3_for_private` | false | `BootGuard` 固定写成 false。 |
| CDN Rewrite | `StorageUriResolver::rewriteEnabled()`，即 `xedc_storage.cdn_enabled` | true | 运行模式为 R2 时，用状态文件里的 `cdn_enabled` 覆盖环境变量。 |

状态文件 `private/xedc-storage-runtime/active.json` 才是运行模式的来源：`public_mode=cloudflare_r2`，`public_provider=silly`，`cdn_enabled=true`，版本 1，状态 active。请求启动时由 `BootGuard::apply()` 读入。环境变量 `XEDC_STORAGE_RUNTIME`、`STORAGE_PUBLIC_TAKEOVER`、`STORAGE_CDN_ENABLED` 在本进程里也是 1，`STORAGE_PRIVATE_TAKEOVER` 是 0。公开包装器是 `XedcPublicS3fsStream`，私有包装器是 `PrivateStream`。

因此这不是「检查读错了文件所以误报打开」，而是「把已经生效的生产阶段，拿首次启用前的关闭条件来判失败」。

## 2. 三个开关的真实来源和当前状态

页面现在分成四行，因为公开接管和私有接管不是同一个开关。

| 开关 | 当前状态 | 来源 | 何时生效 | 范围 | 是否参与请求 | 关闭会怎样 |
| --- | --- | --- | --- | --- | --- | --- |
| 运行时 | 已启用，检查通过 | `active.json` 的 `public_mode`。启动时写入 `xedc_storage.public_mode`。`runtime_applied` 固定为 false，不参与判断。 | 每个请求启动时读取状态文件 | 公开原件的运行模式 | 是。决定公开包装器走 R2 还是本地 | 会让新的公开原件回到本地。本次没有关。 |
| 公开接管 | 已启用，检查通过 | `s3fs.use_s3_for_public` 与 `xedc_storage.public_takeover`，由 `BootGuard` 在 R2 模式下写成 true | 请求启动 | 公开原件 | 是 | 会和 R2 运行模式矛盾。本次没有关。 |
| 私有接管 | 未启用，检查通过 | 同上两个私有 Settings，`BootGuard` 固定 false | 请求启动 | 私有文件 | 设置不参与公开 CDN。私有文件仍走本地 `PrivateStream` | 当前本来就是关。打开会把私有文件送进对象存储，检查会失败并继续禁止执行。 |
| CDN Rewrite | 已启用，检查通过 | `xedc_storage.cdn_enabled`。R2 模式下以状态文件的 `cdn_enabled` 为准 | 请求启动 | 公开原件 URL。图片样式不走 CDN | 是。公开原件需要它才能用 `https://cdn.gitxx.xyz` | 公开原件会留在本站地址。本次没有关。 |

这四行都通过，只说明开关和当前生产模式一致。它不等于接管可以执行，也不等于本轮执行了接管。

## 3. 修复前后的检查逻辑

修复前：任一开关为真，就输出一条组合错误，并预期它们全部关闭。`public_ready` 和 `private_ready` 本来也固定是 false。

修复后：

- 运行模式来自状态文件，不来自永远为 false 的 `runtime_applied`。
- `cloudflare_r2` 阶段：公开接管和 CDN 改写应当打开，私有接管必须关闭，公开包装器必须按对象存储工作。一致则接管检查通过。
- `local` 或还没有状态文件：这四个有效开关应当关闭。某一个打开，只让那一行失败。
- Settings 读不到、状态文件无效、两个 Settings 互相不一致，都显示「无法判断」或「不通过」，不把未知当成关闭。
- 适配器 HeadBucket 成功单独显示，不能把接管检查改成通过，也不能把「可以执行」改成允许。
- `execution_allowed` 固定为 false。本页没有执行入口。
- `executed_this_round` 固定为 false。观察到的 R2 是现有生产状态。
- 图片样式、GD、CDN 私有前缀这些原有阻断仍留在「其他阻断」。没有为了页面变绿而删掉。

启用检查页目前仍有两类其他阻断：GD 不能写 AVIF；CDN 私有前缀还没有被匿名访问测试证明已拒绝。它们不改变上面四行的结论，也继续不允许从本页执行接管。

## 4. 当前生产存储架构

只读核对，没有改：

| 项 | 值 |
| --- | --- |
| 运行模式 | `cloudflare_r2` |
| Provider | `silly` |
| Bucket | `dclili` |
| Region | `auto` |
| 公开前缀 | `public/` |
| 私有前缀 | `private/` |
| CDN | `https://cdn.gitxx.xyz` |
| 公开包装器 | `XedcPublicS3fsStream` |
| 私有包装器 | `PrivateStream` |
| 状态文件 | `private/xedc-storage-runtime/active.json` |
| 状态文件 SHA-256 | `d03cc9eaa125d934c65c3c57463abceb67094f60cdc30f7783df37ddf2ef3aa6` |

公开原件 CDN 示例仍是 `https://cdn.gitxx.xyz/public/example.jpg`。没有 `public/public/`。`private://` 不生成公开 CDN 地址。图片样式地址留在本站。本地和 R2 适配器的只读检查都是 ok。R2 只做了 HeadBucket 和既有抽样对象 `public/2026-10/IMG_3009.jpeg` 的 Head，没有写入。

## 5. 格式化服务

新增 `StorageSizeFormatter`，并注册为 `xedc_core.storage_size`。`OpsFormat::bytes()` 改为调用它，对象存储、文件统计、重复文件、未引用文件、清理任务和磁盘容量卡片因此使用同一套显示。

规则是 1024 进制，单位为 B、KiB、MiB、GiB、TiB、PiB。大于等于 1024 字节时保留两位小数。空值、负数、非整数和非法字符串返回「暂无数据」，不抛 TypeError。

`163198` 显示为 `159.37 KiB`。`39380312064 / 1024^3 = 36.67578125`，两位小数四舍五入是 **36.68 GiB**。任务示例里的 36.67 是把第三位直接截掉。实现和 159.37 KiB 使用同一套四舍五入。检查期间磁盘剩余大约是 36.66–36.67 GiB，对应的原始字节和 39380312064 不是同一个数，页面括号里保留了当次的原始字节。

底层比较、复制、哈希和数据库仍使用原始整数。格式化结果放在单独的显示字段。费用估算仍用原始字节除以 1024^3，旁边另给人类可读容量。

`TrafficReport::bytes()` 仍是原来的 KB/MB/GB，流量测试没有改。图片优化和导入日志里的 `ByteSizeMarkup` 也不在这次对象存储页面范围内。

## 6. 涉及的页面

| 页面 | 结果 |
| --- | --- |
| `/admin/config/media/object-storage/runtime` | PASS。四行开关、适配器、GiB/KiB、刷新后单位一致。桌面和 390 宽都没有整页横向溢出。 |
| 运行模式页「检查文件一致性」 | PASS。只读检查完成，复制清单用 MiB。出现「确认并执行切换」，没有点击。随后点了「取消操作」，页面回到未检查。 |
| `/admin/config/media/object-storage/readiness` | PASS。不再出现组合错误。其他阻断仍在。 |
| `/admin/reports/xedc-storage-health` | PASS。剩余空间和抽样对象都带单位和原始字节。 |
| `/admin/config/media/object-storage` | PASS。文件容量为 GiB。 |
| `/admin/config/media/object-storage/monitor` | PASS。容量为 GiB/MiB/KiB。 |
| `/admin/config/media/object-storage/migration` | PASS。页面打开，没有原始字节堆砌，也没有旧提示。 |
| `/admin/reports/xedc-files` | PASS。磁盘总容量、已用、剩余为 GiB。 |
| 重复文件列表和组 110 | PASS。大小为 GiB/MiB/KiB。 |
| 未引用列表和 fid 686 | PASS。 |
| 清理任务 70 | PASS。大小已格式化，没有点击确认、清理或恢复。 |
| 引用详情 fid 686 | PASS 打开。该页不展示文件大小。fid 894 不在分析批次里，返回 404，这是原有查找规则。 |
| 清理任务列表、日志列表 | PASS 打开。列表本身没有容量数字。 |
| 上传下载记录、会员下载大小 | NOT TESTED。会员下载代码改走同一格式化，但没有打开对应页面。 |
| 服务器监控内存和磁盘 | NOT TESTED。它们共用 `OpsFormat::bytes()`，显示单位会一起变成 GiB，没有单独打开监控页。 |
| 全量 R2 对象清单页 | 没有这个页面。切换检查里的待复制清单已用 MiB 显示。 |

运行模式页没有单独的异步容量接口。刷新页面后单位仍是 GiB/KiB。文件扫描进度接口刷新的是数量，不是容量。

## 7. 修改文件

- `web/modules/custom/xedc_core/src/Ops/Storage/StorageSizeFormatter.php`
- `web/modules/custom/xedc_core/src/Ops/Storage/TakeoverReadiness.php`
- `web/modules/custom/xedc_core/src/Ops/OpsFormat.php`
- `web/modules/custom/xedc_core/src/Ops/Storage/Provider/LocalStorageProvider.php`
- `web/modules/custom/xedc_core/src/Ops/Storage/Provider/CloudflareR2Provider.php`
- `web/modules/custom/xedc_core/src/Ops/Storage/StorageSwitchManager.php`
- `web/modules/custom/xedc_core/src/Ops/Storage/CostEstimator.php`
- `web/modules/custom/xedc_core/src/Form/StorageSwitchForm.php`
- `web/modules/custom/xedc_core/src/Controller/StorageController.php`
- `web/modules/custom/xedc_core/src/Controller/FileCleanupController.php`
- `web/modules/custom/xedc_core/templates/storage-takeover-status.html.twig`
- `web/modules/custom/xedc_core/templates/xedc-core-storage.html.twig`
- `web/modules/custom/xedc_core/templates/xedc-core-cleanup-task.html.twig`
- `web/modules/custom/xedc_core/css/ops.css`
- `web/modules/custom/xedc_core/xedc_core.services.yml`
- `web/modules/custom/xedc_core/tests/src/Unit/StorageSizeFormatterTest.php`
- `web/modules/custom/xedc_core/tests/src/Unit/TakeoverReadinessTest.php`
- `web/modules/custom/xedc_core/tests/manual/storage-takeover-size-self-test.php`

其中表单、切换管理器、存储模板、服务定义和 `ops.css` 在本轮之前已有未提交修改。本轮是在那些修改上继续改的。

## 8. PHP 语法

PASS。上述 PHP 文件 `php -l` 均无语法错误。

## 9. 自动化测试

环境没有 PHPUnit 可执行文件，`composer.json` 也没有 phpunit。单元测试文件已放在 `tests/src/Unit/`。同一组断言由 Drush 隔离脚本执行：

`drush php:script web/modules/custom/xedc_core/tests/manual/storage-takeover-size-self-test.php`

结果 **PASS 0**，退出码 0。覆盖：

1. 四个有效开关全部关闭的本地模式。
2. 只有 R2 运行模式、公开接管和 CDN 关闭。
3. 只有公开接管打开。
4. 只有 CDN Rewrite 打开。
5. 本地模式下多个开关同时打开。
6. 状态文件缺失。
7. Settings 读取失败，不把未知当成关闭。
8. 两个公开接管 Settings 不一致。
9. 当前生产 R2 形状。
10. 无效状态文件。
11. 额外传入适配器成功标记，私有接管打开时仍然不能执行。
12. 检查通过但 `executed_this_round` 为 false。

另外核对了 0、1、512、1023、1024、1 MiB、163198、39380312064、1 TiB、1 PiB、负数、null 和非法字符串。原始整数比较没有改用格式化字符串。

脚本末尾又做了一次生产只读回归：本地目录可读，R2 HeadBucket 成功，抽样对象 159.37 KiB，前缀仍是 `public/` 和 `private/`，私有 URI 没有公开 CDN，样式 URL 留在本站，状态文件哈希不变。

## 10. 真实浏览器

PASS。使用管理员一次性登录，Playwright 打开真实页面。截图在 `docs/storage-takeover-size/`。

- 运行模式页桌面和移动端都能打开。
- 本地服务器 ok，Cloudflare R2 ok。
- 四行开关分别有状态、来源、结果和说明。
- 没有旧的组合提示。
- 容量为 GiB/KiB，适配器说明里带原始字节。
- 刷新后单位不变。
- 桌面 `scrollWidth` 与视口相同，390 宽也没有整页溢出。
- 一致性检查可完成。执行按钮出现后没有点击，并取消了这次检查。

## 11. 生产 R2 回归

PASS。HeadBucket 成功。抽样对象仍是 163,198 字节，显示 159.37 KiB。没有上传、删除、移动或覆盖对象。没有删除 `s3fs-public/`。公开前缀和私有前缀未改。运行模式仍是 `cloudflare_r2`。

## 12. 是否修改生产配置

没有。没有改 `config/sync`、数据库结构、Bucket、Endpoint、密钥、Region、CDN 域名或前缀。

## 13. 是否修改生产运行状态

没有。状态文件哈希在检查前后都是 `d03cc9eaa125d934c65c3c57463abceb67094f60cdc30f7783df37ddf2ef3aa6`。没有关闭运行时、公开接管或 CDN Rewrite，也没有打开私有接管。

## 14. 未完成的测试

- PHPUnit 进程：**NOT TESTED**。机器上没有 phpunit。同等断言已由 Drush 脚本 PASS。
- 会员下载页、服务器监控页：**NOT TESTED**。
- 生产存储切换、执行按钮点击、失败回滚：**NOT TESTED**。按要求没有做。
- 一致性检查阶段的全量 SHA-256：页面仍显示未执行。**NOT TESTED**。
- 引用详情页不显示大小，因此没有在该页核对容量单位。

## 15. 回滚方法

不要对 `StorageSwitchForm.php`、`StorageSwitchManager.php`、`xedc-core-storage.html.twig`、`xedc_core.services.yml`、`ops.css` 执行 `git checkout`。这些文件在本轮之前就有未提交修改，整文件回退会把更早的切换修复一起删掉。

回滚本轮时，只撤销第 7 节列出的接管判断和容量显示改动。新文件可以直接删除：`StorageSizeFormatter.php`、`storage-takeover-status.html.twig` 和两个单元测试、一个手动脚本。然后 `drush cr`。不需要改状态文件。

## 16. 下一步需要人工处理的事项

- 启用检查页的 CDN 私有前缀匿名拒绝，仍要在 Cloudflare 边缘用真实匿名请求证明。本轮没有把它算成开关通过以外的放行。
- GD 仍不能写 AVIF。转换失败必须继续报失败。
- 若要更换公开存储模式，仍走运行模式页的确认文字。本轮没有执行。私有接管应继续保持关闭。
