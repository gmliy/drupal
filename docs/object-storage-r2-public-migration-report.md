# 公开文件复制报告

时间：2026-11-02 19:06 UTC 复测结束后停止。没有调用复制。

## 结果

| 项 | 数量 |
| --- | --- |
| 复制前 File Entity | 137 |
| 公开文件 | 128 |
| 公开文件数据库字节 | 75,177,632 |
| 本地存在且可读 | 128 |
| 已计算 SHA-256 | 128 |
| 重复目标键 | 0 |
| 复制前 `s3fs-public/` 对象 | 0 |
| 复制前 `s3fs-private/` 对象 | 0 |
| 来源不明的公开前缀对象 | 0 |
| 已存在且 SHA 一致 | 0 |
| 本轮实际复制 | 0 |
| 失败 | 0 |
| 内容冲突 | 0 |
| SHA-256 远程校验 | 未执行 |

停止原因：PNG 和 MP4 的 CDN GET 返回 `public, max-age=3600`，不是上传时的 `public, max-age=60`。按门禁，缓存不通过就不复制。

## 复制前已经确认的状态

- 活动供应商 `silly`，桶 `dclili`，region `auto`，R2 API 主机正确，连接可用。
- 五个生产开关关闭。`XEDC_STORAGE_RUNTIME`、`STORAGE_I_UNDERSTAND_TAKEOVER`、`STORAGE_PUBLIC_TAKEOVER`、`STORAGE_PRIVATE_TAKEOVER`、`STORAGE_CDN_ENABLED` 都未设为 `1`。
- `public://` 是 `PublicStream`。S3FS 的 public/private takeover 关闭。
- 文件实体指纹：137 行，fid 合计 28272，URI CRC32 合计 300810938969。本轮没有写 `file_managed`。
- 本地 128 个公开文件都还在。
- 首页 `https://gitxx.xyz/` 返回 200，HTML 不含 `cdn.gitxx.xyz`。
- `https://gitxx.xyz/cosplay/cos/315` 返回 200，HTML 不含 `cdn.gitxx.xyz`。
- 原图 `IMG_3009.jpeg` 返回 200，长度 163198，与文件记录一致。
- 已有的 `thumbnail` 和 `photo_gallery_650` 衍生文件返回 200。样式目录仍是 15 个，文件仍是 766 个。没有批量复制，也没有重建。

图片样式的隔离生成测试没有做。缓存门禁失败后，没有进入复制后的阶段。

## 私有文件

9 条记录都没有本地文件，没有复制，没有创建替代文件。

| fid | 文件名 | 状态 |
| --- | --- | --- |
| 166 | member-2707556.zip | 永久记录，本地缺失 |
| 219 | member-2707995.zip | 永久记录，本地缺失 |
| 272 | member-2718523.zip | 永久记录，本地缺失 |
| 326 | member-2726783.zip | 永久记录，本地缺失 |
| 379 | member-2727113.zip | 永久记录，本地缺失 |
| 481 | member-2806776.zip | 永久记录，本地缺失 |
| 491 | member-2807293.zip | 永久记录，本地缺失 |
| 505 | member-2807605.zip | 永久记录，本地缺失 |
| 520 | member-2807749.zip | 永久记录，本地缺失 |

私有路径不写入本报告。私有迁移仍要单独做文件恢复和安全验收。

## 生产接管

不具备接管条件。公开文件还没有复制到 R2。缓存头仍被边缘改成 3600 秒。`r2.dev` 只有管理员确认，程序没有独立验证。五个开关保持关闭。
