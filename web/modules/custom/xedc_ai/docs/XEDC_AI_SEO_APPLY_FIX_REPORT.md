# XEDC AI SEO 建议采纳修复报告

日期：2026-11-05

范围：`web/modules/custom/xedc_ai/`。没有调用真实 AI，没有改生产内容和生产库，没有执行生产 `drush updb`，没有改 `config/sync`，没有改 `xedc_auto_translate`，没有 Git 提交。

## 1. 问题根因

勾选建议后点「确认采纳所选」，页面提示「没有选择要处理的建议。」

文件：`src/Form/SeoApplyForm.php`，`buildForm()`。
处理函数：`act()` 读取 `$form_state->getValue('items')`，再交给 `SeoAdvisor::applySelected()`。空数组时 `applySelected()` 返回「没有选择要处理的建议。」

复选框写在 `$form['items'][$safe]` 下面，但 `items` 没有 `#tree => TRUE`。Drupal `FormBuilder` 在父级 `#tree` 为 false 时，把子元素的 `#parents` 设成它自己的键，不带上 `items`：

```php
$element[$key]['#parents'] = $element[$key]['#tree'] && $element['#tree']
  ? array_merge($element['#parents'], [$key])
  : [$key];
```

因此浏览器提交的是 `kaaf2320646108059=title` 这种顶层字段，不是 `items[kaaf2320646108059]=title`。`getValue('items')` 读到空值，后端认为没有选择。建议 key 本身没有错位，历史记录也能读。断的是表单值的层级。

修复后，同一条生产库里的分析（只渲染、不提交）输出的字段名是：

- `items[kaaf2320646108059]`
- `items[k761b7ad8ad439b28]`
- `items[k978c2f8941354cf5]`

实验室浏览器实际 POST：

- 空选择：`op=确认采纳所选`，没有 `items[...]`。响应：「请至少选择一条要处理的建议。」
- 两条：`items[kaaf2320646108059]=title` 和 `items[k761b7ad8ad439b28]=summary`。
- 拒绝：只有 `items[k978c2f8941354cf5]=tags`。
- 恢复：只有 `items[kaaf2320646108059]=title`。

未勾选的复选框不会出现在 POST 里。后端只处理这些 key，并且只用服务端 `#return_value`，不接受 POST 里改出来的其他 key。

## 2. 修复内容

| 文件 | 原因 |
| --- | --- |
| `src/Form/SeoApplyForm.php` | `items` 设为 `#tree` 容器。空选择提示改为「请至少选择一条要处理的建议。」按本条分析的 key 白名单读取勾选。已忽略、版本冲突、不适用的建议不再出现可勾选框。结束时分别统计成功采纳、已忽略、版本冲突、采纳失败、已恢复。 |
| `src/Seo/SeoResult.php` | 新增 `chosenKeys()`，丢掉未勾选的 `0`、空值和不属于这条分析的 key。不重算历史分数。 |
| `src/Seo/SeoAdvisor.php` | 已采纳的建议不再写第二次。已忽略的建议不能再采纳。标签或简介失败时，同一批里已经能写的字段仍保存。一条失败只标记这一条。版本冲突、翻译保护和人工确认门闩保持原样。 |
| `js/ai.js` | 「全选待处理建议」只勾选待确认和采纳失败。再次点击取消这些勾选。已采纳的框留给恢复，不跟着全选。重复提交时拦截第二次 submit，不禁用按钮，避免丢掉 `op`。 |
| `css/ai.css` | 建议表格和表单说明允许换行。窄屏下三个按钮各占一行。复选框至少 20px。 |
| `xedc_ai.libraries.yml` | 样式和脚本版本改为 1.2，避免浏览器继续用旧脚本。 |
| `tests/manual/seo-ops-test.php` | 增加空选择、单选、多选、越权 key 和历史分数不被读档改写的检查。 |

没有改 AI 客户端、预算入口、DeepSeek 配置、评分权重和 Metatag token。没有新的数据库列。

## 3. 功能验收

实验室库 `127.0.0.1:3307`。测试文章由脚本新建，没有使用内容 13，没有调用模型。

| 项 | 结果 |
| --- | --- |
| 空选择 | 已验证。标题未改。提示「请至少选择一条要处理的建议。」 |
| 单条采纳 | 已验证。只改标题，简介保持原文。状态 `adopted`，新修订，发布状态仍是已发布。 |
| 批量 | 已验证。简介写入成功；不存在的标签失败，并且没有新建术语。标题保持已写入的值。提示「成功采纳 1 条。采纳失败 1 条。」 |
| 浏览器两条一起采纳 | 已验证。POST 只有 title 和 summary。刷新后两条都是「已采纳 · 用户 1」。 |
| 重复采纳 | 已验证。第二次提示「所选建议已经采纳，不会重复写入。」修订号不变。 |
| 取消勾选 | 已验证。未出现在 POST 里的建议保持原状态。 |
| 拒绝 | 已验证。只把标签标为已忽略，标题和简介不变。 |
| 恢复 | 已验证。标题回到采纳前的原文，简介保持已采纳的新值，发布状态未改成另一种。 |
| 版本冲突 | 已验证。人工新修订后，旧简介建议被拒绝。摘要仍是原文。状态 `conflict`。提示「版本冲突 1 条。」以及修订号变化。 |
| 刷新 | 已验证。重新加载详情页后，已采纳、已忽略、待确认仍在。 |
| 翻译保护 | 已验证。未保存的已发布文章、缺少目标语言时，`writeBlocked()` 仍返回原来的拦截句。探针节点 id 为 0，没有写入。 |
| Metatag HTML | 已验证。文章 1019 的数据库标题和简介写入后，新进程页面为 `<title>表单验收新标题 \| DcLili</title>`，description 为「表单验收新简介，用来检查描述令牌。 表单验收新标题 -」。这是文章默认 token，不是节点上的 Metatag 字段。 |
| 历史分析 | 已验证。实验室里的分析 202 分数仍是 46，没有按新规则重算。 |
| 移动端 | 已验证 390×844。页面宽 390，没有横向溢出。三个按钮都在。复选框约 20×16。截图：`docs/tasks/screenshots/xedc-ai-seo-apply/seo-apply-mobile.png`。 |

文章保存仍会走到现有自动翻译，测试文章创建后带有中文、英文、俄文、日文，并变成已发布。这次没有放宽翻译拦截，也没有改翻译模块。基础页和视频的 description token 不读简介；这次没有改这两类的 token，也没有假装改简介会改变它们的 meta description。

全选按钮已出现在页面上。浏览器脚本没有点击它。脚本逻辑只勾选待确认和采纳失败。

管理主题把同一条消息渲染了两次。每次操作只有一条 POST，数据库只变一次。

## 4. 测试结果

Docker 隔离 PHP（`php:8.3-cli`，不连数据库）：`OK 0 failed`。覆盖原有评分、标签、Token 测试，以及这次的选择解析和历史分数。

Drupal Form API：在实验室用 `FormBuilder::submitForm()` 提交真实表单。空选择、单条、批量、重复采纳、拒绝、恢复、版本冲突都有消息和数据库结果。这不是浏览器点击。

真实浏览器：本机 Chromium 远程调试，登录实验室站点，打开 `/admin/xedc/ai/seo/213`，勾选复选框并点击按钮。上面的 POST 正文来自这次点击，不是手写的 curl。

数据库：实验室节点 1023 在恢复标题后，标题是「浏览器验收原文」，简介仍是采纳后的新简介，状态为已发布。内容 13 在实验室和生产库都仍是 vid 215、未发布、原标题。

尚未在浏览器里点击「全选待处理建议」。没有制造一次真实的节点保存异常来点出「采纳失败」以外的数据库异常；标签不存在这条失败已经记成采纳失败。没有在真实手机上聚焦文本框。这个表单的输入是复选框，详情页没有会触发缩放的文本框。列表页文本框的 16px 规则还在。

## 5. 修改范围

改了的代码和测试：

- `web/modules/custom/xedc_ai/src/Form/SeoApplyForm.php`
- `web/modules/custom/xedc_ai/src/Seo/SeoAdvisor.php`
- `web/modules/custom/xedc_ai/src/Seo/SeoResult.php`
- `web/modules/custom/xedc_ai/js/ai.js`
- `web/modules/custom/xedc_ai/css/ai.css`
- `web/modules/custom/xedc_ai/xedc_ai.libraries.yml`
- `web/modules/custom/xedc_ai/tests/manual/seo-ops-test.php`
- `web/modules/custom/xedc_ai/docs/XEDC_AI_SEO_APPLY_FIX_REPORT.md`
- `docs/tasks/screenshots/xedc-ai-seo-apply/seo-apply-mobile.png`

没有修改数据库结构。没有修改生产数据。生产 `xedc_ai` schema 仍是 `10002`，端口 3306。没有修改 `config/sync`。没有调用真实 AI。没有执行 Git commit、push 或 reset。实验室库在验收后已关闭。
