# EngagementBuilder 500 修复报告

这个问题与 R2 接管是否成功无关。

## 原因

`https://gitxx.xyz/cosplay/cos/315` 返回 500。日志是 `EngagementBuilder::__construct()` 收到 5 个参数，当前构造函数需要 6 个。

当前源码里的服务定义已经是 6 个参数，最后一个是 `xedc_core.guest_identity`：

`xedc_core.services.yml` 中 `xedc_core.engagement_builder`。

已编译的生产容器仍按旧定义传入 5 个参数。类文件已经更新，容器没有重建。Drupal 不会因为构造函数参数变化自动丢弃容器缓存。

点赞、收藏、下载、分享的业务代码没有被拿掉。`GuestIdentity` 仍负责游客点赞的 cookie 哈希。

## 修改

文件：`web/modules/custom/xedc_core/src/Engagement/EngagementBuilder.php`。

第 6 个参数改为可选。容器若已传入 `GuestIdentity`，就使用注入的实例。若仍按旧容器传入 5 个参数，则使用已注册的 `xedc_core.guest_identity`；该服务还不在旧容器里时，用现有的 `request_stack` 和 `Drupal::time()` 构造同一个类。没有删除依赖，也没有改互动记录规则。

没有执行 `drush cr`，没有重载 PHP-FPM。PHP 按文件时间重新加载了这个类，所以旧容器的 5 个参数现在可以实例化。

## 验证

| 检查 | 结果 |
| --- | --- |
| 服务能从当前容器实例化 | PASS |
| 必填构造参数数量 | PASS，为 5 |
| `https://gitxx.xyz/cosplay/cos/315` | PASS，HTTP 200，约 34 KB，不再是“网站遇到意外错误” |
| 页面文件地址 | 仍是本地 `/sites/default/files/`，24 处 |
| 首页 | HTTP 200 |

以后某次正常的容器重建会改为注入 6 个参数。两种调用方式都保留 `GuestIdentity`。本轮不需要为了这个修复单独重建生产缓存。
