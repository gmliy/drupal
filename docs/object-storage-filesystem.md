# 文件系统审计说明

日期：2026-11-02。

这份说明解释 `docs/object-storage-filesystem-architecture-audit.md`。审计只读取了 Drupal 文件系统、S3 File System 和 `xedc_core` 的现有配置，没有改配置、代码、文件 URI 或数据库，也没有打开任何生产开关。

## 现在谁在管文件

管文件的是 Drupal 自己的本地文件系统。

公开文件使用 `public://`，类是 `PublicStream`，目录是 `/var/www/drupal/web/sites/default/files`。私有文件使用 `private://`，类是 `PrivateStream`，目录是 `/var/www/drupal/private`。临时文件使用 `temporary://`，类是 `TemporaryStream`，目录是 `/tmp`。默认下载 scheme 是 `public`。

文件记录一共 137 条。128 条公开文件都在本地磁盘上。9 条私有记录在磁盘上已经没有文件。没有任何文件使用 `s3://`。

新上传、图片样式、视频和 ZIP 解压现在都落在这两个本地目录里。

## 三个组件各干什么

| 组件 | 现在的职责 |
| --- | --- |
| Drupal 原生文件系统 | 实际保存文件，并提供 `public://`、`private://`、`temporary://` |
| S3 File System 8.x-3.12 | 已安装的 S3 兼容能力。桶和前缀已填写，但没有接管任何一个 Drupal 文件流 |
| `xedc_core` | 保存供应商、目录策略、CDN 规则和迁移工具。运行时关闭，所以还没有管理线上文件 |

S3FS 的桶是 `dclili`，公共前缀 `s3fs-public`，私有前缀 `s3fs-private`。它的 region 是 `us-east-1`，endpoint 是空的，Key 模块里的凭证名称也是空的。`s3fs.use_s3_for_public` 和 `s3fs.use_s3_for_private` 都是关闭。模块另外注册了 `s3://`，业务没有使用它。

`xedc_core` 的活动配置是 `silly`，标签 Cloudflare R2。桶同样是 `dclili`，region 是 `auto`，endpoint 主机是 `409b0f32d01cbb14237646ecda9bf3e8.r2.cloudflarestorage.com`，公开基址是 `https://cdn.gitxx.xyz`。凭证在后台文件库里，状态是已配置。`XEDC_STORAGE_RUNTIME` 未启用，`BootGuard` 因此不会把这套连接写进当前请求，并会把两个 S3FS 接管开关保持为关闭。

## 已经完成和尚未上线

已经写好、但没有接到线上文件流的部分：

- 供应商配置和后台凭证。
- 运行时启动检查。运行时本身未应用。
- CDN 地址规则和分发地址。改写开关关闭，页面仍使用本地 URL。
- 缓存策略。规则存在，没有写到生产对象。
- 会员私有文件的权限检查和预签名入口。运行时关闭时，这个入口拒绝生成地址，也不读取本地私有文件。
- 迁移中心的预检查和复制代码。复制没有执行。

线上仍走本地、只在隔离测试里验证过远程路径的部分：

- 普通文件上传和图片导入。
- 图片样式。15 个样式目录和衍生文件在 `public://styles`。
- 视频上传。策略写成 private，现有视频文件仍在本地。
- ZIP 导入、解压和打包。工作目录在本地 `private://`。

## 配置为什么不能各改各的

桶名和两个前缀在 S3FS 与 xedc 里各存了一份。region、endpoint 和凭证并不相同：S3FS 是 `us-east-1`、没有 endpoint、没有凭证名称；xedc 是 `auto`、有 R2 endpoint、凭证在后台文件库。CDN 主机只写在 xedc 的 `public_base_url`，xedc 的 `cdn.base_url` 和 S3FS 的 domain 都是空的。

这些差异现在没有影响站点，因为没有任何组件接管 `public://` 或 `private://`。如果绕过 xedc，直接在 `settings.php` 打开 S3FS 接管，S3FS 会按自己的空 endpoint 和空凭证工作，公开文件会离开本地目录。

现有 URI 继续使用 `public://` 和 `private://` 是兼容的。把记录改成 `s3://`，或把 `cdn.gitxx.xyz` 填进 S3FS 的 domain，会和 xedc 的分发规则冲突。9 条已经丢失本地文件的私有记录，不能当成可复制对象。

## 应该留着不动的配置

- `settings.php` 里的私有文件目录 `/var/www/drupal/private`。
- 未单独设置的 public path 和 temporary path。
- 默认 scheme `public`。
- S3FS 的两个接管开关、CNAME、domain 和 hostname。
- 五个生产开关：`XEDC_STORAGE_RUNTIME`、`STORAGE_I_UNDERSTAND_TAKEOVER`、`STORAGE_PUBLIC_TAKEOVER`、`STORAGE_PRIVATE_TAKEOVER`、`STORAGE_CDN_ENABLED`。
- 全部 137 条 File Entity URI。

## 以后怎么切换存储

切换本地、Cloudflare R2 或其他 S3 兼容服务时，不修改 Drupal 的三个文件路径和默认 scheme。这三个路径是未接管时的落点，也是回滚位置。

切换只更换 xedc 的活动供应商。确认复制完成之后，由环境变量打开运行时，`BootGuard` 覆盖 S3FS 的连接，再让 S3FS 替换 `public://` 和 `private://` 的流包装器。文件 URI 保持原样。公共前缀走 CDN，私有前缀在 Drupal 权限检查之后使用短时预签名地址。

S3FS 数据库里的 region、endpoint、域名和凭证不再作为第二套配置去手工维护。
