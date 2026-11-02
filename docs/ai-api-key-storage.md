# AI API 密钥保存

本文说明 `xedc_ai` 的 API 密钥保存在哪里、2026-11-02 无法保存的原因，以及修复后的使用方式。运营中心的其他功能见 `docs/ai-operations-center.md`。

后台入口：管理 → AI 运营中心 → AI 模型管理 → 供应商，地址 `/admin/xedc/ai/models/providers`。

## 1. 保存位置

密钥不进入供应商表、Drupal 配置同步、Git 或公开文件目录。

读取顺序：

1. 环境变量 `XEDC_AI_KEY_{供应商标识大写}`。例如供应商 `deepseek` 对应 `XEDC_AI_KEY_DEEPSEEK`。环境变量优先于文件。
2. Key 模块 `8.x-1.22` 的 File Provider。Key 实体编号是 `xedc_ai_{供应商标识}`，配置里只记录文件路径。
3. 密钥文件位于私有目录 `private://xedc-ai-keys/{供应商标识}.key`。

当前私有目录配置：

```php
$settings['file_private_path'] = '/var/www/drupal/private';
```

实际路径是 `/var/www/drupal/private/xedc-ai-keys/`。这个目录在 Web 根目录 `web/` 之外。文件权限 `0600`，目录权限 `0700`。

页面和供应商表只保存末四位提示，例如 `****a1b2`。密码框留空表示不修改已有密钥。只有勾选「清除已保存的密钥」才会删除文件和对应的 Key 实体。

密码框最多接受 512 个字符。更长的密钥会被拒绝，并提示「密钥超过 512 个字符，没有保存。」

## 2. 无法保存的原因

2026-11-02 的故障不是 Key 模块缺失，也不是 `private://` 未配置。

当时的状态：

| 项目 | 实际情况 |
| --- | --- |
| Key 模块 | 已启用，版本 `8.x-1.22`，兼容 Drupal 11 |
| File Provider | 可用 |
| `private://` | 解析到 `/var/www/drupal/private` |
| 私有目录 | 存在，所有者 `www-data:www-data`，权限 `drwxr-x---` |
| PHP-FPM | 用户 `www-data`，套接字 `/run/php/php8.5-fpm.sock` |
| 密钥目录 | `/var/www/drupal/private/xedc-ai-keys` 由 root 创建，权限 `0700`，所有者 `root:root` |

`www-data` 可以看见这个目录，但不能在里面创建文件。以 PHP-FPM 用户保存时，写入失败：

```text
file_put_contents(/var/www/drupal/private/xedc-ai-keys/ai_probe.key): Failed to open stream: Permission denied
```

保存在创建 Key 实体之前就返回了。因此没有把密钥写入配置或公开目录。旧代码只把失败变成一个笼统提示，没有说明是目录权限问题。

这次检查时还没有已保存的 AI 供应商密钥。对象存储使用的 Key 实体没有被修改。

## 3. 已做的修复

密钥目录已改为：

```text
drwx------ www-data www-data /var/www/drupal/private/xedc-ai-keys
```

只调整了这一个目录，没有对整个项目递归修改权限。修改后重新加载了 `php8.5-fpm`。

代码改动：

- `web/modules/custom/xedc_ai/src/Provider/SecretStore.php`
- `web/modules/custom/xedc_ai/src/Form/ProviderForm.php`

保存失败时，页面会显示具体原因，例如私有目录不可用、目录不可写、当前用户与目录所有者不一致、Key 模块未启用、机器名不正确、密钥为空或超过 512 字符。日志只记录供应商编号和系统用户，不记录密钥正文。

如果 root 之后再次创建密钥目录或密钥文件，代码会把所有权改回私有目录的所有者。当前私有目录所有者是 `www-data`。其他系统用户如果用 `0700` 重建该目录，`www-data` 仍然写不进去，但页面会说明所有者和当前用户。

更新已有文件型密钥时，直接更新 Key 实体中的文件路径，不再先删除实体再重建。

## 4. 管理员操作

1. 打开 `/admin/xedc/ai/models/providers/add`，填写机器名、供应商类型、名称和 HTTPS Base URL。
2. 在 API Key 中粘贴密钥并保存。页面随后只显示末四位。
3. 需要更换密钥时，再次打开编辑页，输入新密钥后保存。留空不会覆盖原密钥。
4. 需要停用密钥时，勾选「清除已保存的密钥」后保存。
5. 在供应商页面使用「测试连接」。测试会发送一次短文本请求，结果里不显示密钥。

也可以不把密钥写入文件，而在 PHP-FPM 和命令行都能读到的环境中设置 `XEDC_AI_KEY_{供应商标识大写}`。设置了环境变量以后，读取会使用环境变量，页面上的文件提示不再代表实际使用的密钥。

## 5. 验证结果

2026-11-02 用虚拟密钥、以 `www-data` 执行了检查，没有调用外部 AI 接口。测试数据已经删除。

通过的项目：

- `private://` 解析到 `/var/www/drupal/private`。
- `xedc-ai-keys` 可以由 `www-data` 写入。
- 探测文件可以写入、读回并删除。
- Key 模块可以创建、读取并删除测试 Key 实体。
- 供应商表单可以保存测试密钥。
- 新进程可以再次读回同一密钥。
- 编辑页只显示末四位，密码框不包含完整密钥。
- 再次提交空密钥后，原密钥仍在。
- watchdog、操作日志、active config、`config` 目录和供应商表都不包含这枚测试密钥。
- root 写入的测试文件所有者会变成 `www-data`，权限 `0600`，删除后文件和 Key 实体都不存在。

验证使用的是 Drupal 表单提交和页面渲染，不是在浏览器里手工点击。
