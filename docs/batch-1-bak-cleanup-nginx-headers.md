# 第一批改动说明：清理 `.bak` 与 Nginx 安全头

**执行日期：** 2026-11-10  
**依据：** `AUDIT_REPORT.md` 摘要 D1、S1（Permissions-Policy 缺失）  
**范围：** 仅删除主题遗留 `.bak`；仅调整 `/etc/nginx/conf.d/drupal.conf`（未改 Drupal 代码/配置）

---

## 1. 删除 `web/themes/custom/xedc` 下的 `.bak` 文件

### 1.1 删除的文件（13 个）

| # | 相对路径 |
|---|----------|
| 1 | `css/legal.css.bak` |
| 2 | `css/xedc-navbar.css.v1.bak` |
| 3 | `css/xedc-navbar.css.v2.bak` |
| 4 | `js/xedc-navbar.js.v1.bak` |
| 5 | `src/js/main.script.js.bak` |
| 6 | `templates/comment/comment.html.twig.bak` |
| 7 | `templates/content/node--photo--full.html.twig.bak` |
| 8 | `templates/includes/xedc-header.html.twig.v1.bak` |
| 9 | `templates/node/xedc-meta.html.twig.bak` |
| 10 | `templates/page/page--front.html.twig.bak` |
| 11 | `templates/page/page.html.twig.bak` |
| 12 | `xedc.libraries.yml.bak` |
| 13 | `xedc.theme.bak` |

以上路径均相对于 `web/themes/custom/xedc/`。

### 1.2 操作步骤

1. 列出 `find web/themes/custom/xedc -name '*.bak'` 确认清单。  
2. 打包备份：`tar czf /root/bak-backup.tgz`（仅含上述 13 个文件）。  
3. 在仓库内检索 PHP/Twig/YAML/JS 等对 `.bak` 文件名的引用：**无匹配**。  
4. 删除全部 `*.bak`，不改动其他文件。  
5. Git 提交：仅暂存 `web/themes/custom/xedc` 下的删除记录（未 `git add -A`，避免带上 `xedc-recovery` 等待提交改动）。

### 1.3 Git

- **提交：** `5b4c385`  
- **说明：** `chore: remove .bak files`

### 1.4 回滚

```bash
# 从服务器备份恢复（不经 Git）
tar xzf /root/bak-backup.tgz -C /var/www/drupal/web/themes/custom/xedc

# 或 Git 回滚该提交
cd /var/www/drupal && git revert 5b4c385 --no-edit
```

---

## 2. Nginx 安全响应头

### 2.1 修改前首页响应头（`curl -sI https://gitxx.xyz`）

| 头 | 修改前 |
|----|--------|
| `Strict-Transport-Security` | 有（`max-age=15552000`，由 Drupal 输出） |
| `X-Content-Type-Options` | 有 `nosniff` |
| `X-Frame-Options` | 有 `SAMEORIGIN` |
| `Referrer-Policy` | 有 `strict-origin-when-cross-origin` |
| `Permissions-Policy` | **无** |
| `Content-Security-Policy` | 有（Drupal；本次**未**在 Nginx 增加 CSP） |

### 2.2 策略说明

按「**只补缺失的头**」：

- **未**在 Nginx 重复添加 HSTS / `nosniff` / `X-Frame-Options` / `Referrer-Policy`（避免与 Drupal 双发）。  
- **仅**在 Nginx 增加 `Permissions-Policy`。  
- **未**添加 CSP。

### 2.3 配置变更

**文件：** `/etc/nginx/conf.d/drupal.conf`  

**备份：** `/root/drupal.conf.bak-2026-11-10`（备份在 `/root/`，不在 `conf.d/`）

**新增内容（`always`）：**

```nginx
add_header Permissions-Policy "camera=(), microphone=(), geolocation=()" always;
```

写入位置：

1. HTTPS `server` 块（`client_body_timeout` 之后），作用于大部分响应。  
2. 下列两个 `location` 内（因有自有 `add_header Cache-Control`，Nginx 不继承外层 `add_header`，需重复）：
   - `^/sites/default/files/.+\.(avif|webp|jpe?g|png|svg|woff2?)$`
   - `^/themes/.+\.(avif|webp|jpe?g|png|svg|woff2?)$`

### 2.4 发布

```bash
nginx -t && systemctl reload nginx
```

测试失败时用备份还原：

```bash
cp /root/drupal.conf.bak-2026-11-10 /etc/nginx/conf.d/drupal.conf
nginx -t && systemctl reload nginx
```

### 2.5 修改后验证

**头：**

```text
permissions-policy: camera=(), microphone=(), geolocation=()
```

**状态码（curl）：**

| URL | 码 |
|-----|-----|
| `https://gitxx.xyz` | 200 |
| `https://gitxx.xyz/user/login` | 200 |
| `https://gitxx.xyz/video` | 200 |

---

## 3. 后续可选（未做，待确认）

| 项 | 说明 |
|----|------|
| HSTS `max-age=31536000` | 首页当前仍为 Drupal 的 `15552000`；若在 Nginx 覆盖需协调 Drupal/CSP 模块，避免双 HSTS。 |
| 静态 `.css/.js` 安全头 | 经 Cloudflare 缓存的资源可能不带头；可评估源站或 CF「Transform Rules」补头。 |
| 品牌 403/404 | 见 `AUDIT_REPORT.md` U1，属下一批任务。 |

---

## 4. 相关路径速查

| 说明 | 路径 |
|------|------|
| 审计报告 | `/var/www/drupal/AUDIT_REPORT.md` |
| `.bak` tar 备份 | `/root/bak-backup.tgz` |
| Nginx 配置 | `/etc/nginx/conf.d/drupal.conf` |
| Nginx 配置备份 | `/root/drupal.conf.bak-2026-11-10` |
