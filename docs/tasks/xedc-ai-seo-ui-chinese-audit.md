# SEO 分析详情页 UI 与中文化

日期：2026-11-05。没有改生产库，没有改 `config/sync`，没有调用模型，没有做 Git 提交。

## 1. 原来的问题

详情页把内容编号、分析修订、当前修订、创建时间、发布状态、工作流机器名和工作流状态挤在一起。已发布会出现两次，工作流显示 `xedc_editorial`。版本号和评分放在同一视线里。部分页面还残留 Site Audit、Meta Title、Meta Description、HTTP，以及未翻译的状态码。

## 2. 内容状态

普通区域现在只有三项：

- 发布状态：已发布或未发布。已发布为绿色，未发布为灰色。未发布不会写成已公开。
- 发布时间：已发布时显示 `Y-m-d H:i`。文章优先用 `field_published_at`，没有该字段时用创建时间。未发布一律显示「尚未发布」。
- 审核状态：使用工作流配置里的中文名称。没有审核时显示「未启用审核」。草稿为灰色，待审核为提醒色，已撤下、已拒绝、退回修改为警示色。

发布状态和审核状态回答的是两件事，所以已发布内容仍会各显示一次「已发布」。不再另外重复一行「状态」和「工作流状态」。

内容编号和工作流机器名不出现在这个区域。机器名仍只在程序内部使用。

## 3. 分析版本

版本号放在可折叠的「分析版本信息」里，默认收起。展开后显示分析时版本、当前内容版本，以及：

- 一致：分析版本与当前内容一致。
- 不一致：内容已更新，分析结果可能过期。不一致时，折叠区外面还有一条警告。

版本号不会被当成 SEO 评分。折叠用的是 `details`，点击不会提交表单。采纳前的修订比对、拒绝覆盖和重新分析读取最新内容都没有改。没有快照的旧分析仍显示「这次分析没有保存读取快照，不能把当前内容当成当时的依据。」

## 4. 英文排查范围

查了 `xedc_ai` 的详情、列表、审核详情、建议表单、查找预览的 JavaScript、状态标签和错误码展示。配置结构文件里的英文标签没有改，那些不是页面上的句子，批量替换会碰到配置键。

保留的英文是 SEO、AI、API、Token，以及模型名称。路由名、权限名、字段名、状态值和 JSON 键没有改。

## 5. 对照

| 原来 | 现在 |
| --- | --- |
| 内容 ID、工作流 xedc_editorial | 从详情状态区去掉 |
| 状态 / 工作流状态 | 发布状态、审核状态 |
| 分析修订、当前修订 | 分析版本信息里的分析时版本、当前内容版本 |
| Meta Title / Meta Description | SEO 标题 / 页面描述 |
| Site Audit / SEO Audit | 站点检查 / SEO 检查 |
| HTTP | 响应码 |
| success / error / pending | 成功 / 失败 / 待处理 |
| high / medium / low | 高 / 中 / 低 |
| zh-hans | 简体中文 |
| field_images 等字段名 | 图片集、封面、视频封面 |
| invalid_json 等错误码 | 结果无法读取等中文说明 |
| seo_apply 等日志动作 | 采纳建议、忽略建议、恢复原值、版本冲突 |

查找预览不再显示工作流机器名，改为发布状态、发布时间和审核状态。

## 6. 修改文件

- `web/modules/custom/xedc_ai/src/Labels.php`
- `web/modules/custom/xedc_ai/src/Controller/SeoController.php`
- `web/modules/custom/xedc_ai/src/Controller/ReviewController.php`
- `web/modules/custom/xedc_ai/src/Seo/ContentLookup.php`
- `web/modules/custom/xedc_ai/src/Seo/SeoResult.php`
- `web/modules/custom/xedc_ai/src/Review/TextExtractor.php`
- `web/modules/custom/xedc_ai/templates/xedc-ai-seo-detail.html.twig`
- `web/modules/custom/xedc_ai/templates/xedc-ai-seo.html.twig`
- `web/modules/custom/xedc_ai/templates/xedc-ai-review.html.twig`
- `web/modules/custom/xedc_ai/css/ai.css`
- `web/modules/custom/xedc_ai/js/ai.js`
- `web/modules/custom/xedc_ai/xedc_ai.libraries.yml`
- `docs/tasks/xedc-ai-seo-ui-chinese-audit.md`

## 7. PHP 语法

上述 PHP 文件 `php -l` 均无语法错误。没有新增服务参数。模板在实验室页面中正常渲染，详情路由可打开。

## 8. Docker

单元测试：

```bash
docker compose -f web/modules/custom/xedc_ai/tests/manual/docker/docker-compose.yml run --rm seo-test
```

结果：`OK 0 failed`。

实验室使用 `docker-compose.lab.yml`，项目名 `xedc-seo-apply`，只在 127.0.0.1:3307 导入只读 dump。自动翻译在实验室配置里关闭。结束后容器已删除。

## 9. 桌面 1280×900

实验室浏览器实际打开两页：

- 未发布基础页：发布状态「未发布」，发布时间「尚未发布」，审核状态「未启用审核」。没有内容编号，没有 `xedc_editorial`。版本 2384 与 2384 一致。
- 已有分析：发布状态「已发布」，发布时间「2026-11-02 13:35」，审核状态「已发布」。分析时版本 802，当前版本 2382，警告「内容已更新，分析结果可能过期。」旧分析仍提示没有读取快照。

展开版本信息时没有新增 POST。全选一条待处理建议后再取消，勾选数回到 0。点击「确认采纳所选」后出现中文结果，页面没有变成对公众公开。再点击「恢复所选原值」，实验室页面标题回到「中文化回归原文」，仍然未发布。控制台没有留下需要处理的脚本错误。

截图：

- `docs/tasks/screenshots/xedc-ai-seo-zh/detail-match-1280.png`
- `docs/tasks/screenshots/xedc-ai-seo-zh/detail-stale-1280.png`

## 10. 移动端 390×844

同一未发布详情页没有横向溢出。发布状态、发布时间和审核状态改成上下排列。版本信息保持折叠。

截图：`docs/tasks/screenshots/xedc-ai-seo-zh/detail-390.png`。

## 11. 版本冲突

实验室里对另一篇未发布基础页调用采纳，故意传入过期修订号。返回未覆盖，标题保持「版本冲突原文」，发布状态仍是未发布。详情页只改了展示，没有放宽这条判断。

## 12. 采纳回归

浏览器对实验室新建的未发布基础页做了单条采纳和恢复。恢复后标题和未发布状态与操作前一致。已撤下节点的重新发布路径仍返回不允许，因为工作流没有从已撤下到已发布的转换。节点 13 在测试前后都是 vid 2383、标题「海边风景手机壁纸高清下载」、未发布、已撤下。

没有在这次点击里对已发布内容批量采纳。全选和取消全选已在浏览器确认。

## 13. 数据库结构

没有变化。生产 `xedc_ai` schema 仍是 10002。没有执行 `updb`。

## 14. 模型

没有调用。实验室用量行数在测试前后相同。页面打开不会发起分析。

## 15. config/sync

没有修改。

## 16. 生产数据库

没有修改。测试结束后端口仍是 3306。

## 17. 尚未解决

- 列表和「新建分析」仍保留内容编号输入，否则无法指定要分析的内容。详情状态区不再显示它。
- 已保存的旧建议理由里，如果当初写入了英文说明，不会自动改写；只有新分析会使用现在的中文理由。
- 发给模型的提示词仍使用原有字段说明，这次没有改提示词，避免影响已验收的分析逻辑。
- 配置结构文件中的英文标签未改。
- 已撤下内容的成功重新发布仍然不可用，工作流没有对应转换。
- 深色模式这次没有单独截图；颜色继续使用管理主题变量。
