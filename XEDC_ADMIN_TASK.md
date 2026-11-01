# XEDC Admin：基于 Radix 创建独立 Drupal 11 后台主题——完整实施任务

你现在负责我的 Drupal 11 XEDC 项目的后台主题改造。

本次任务不是只做代码审查、方案设计或输出开发报告，而是要求你在充分保护现有项目的前提下，实际创建、开发、测试一个独立的 Radix 后台主题 `xedc_admin`，逐步替换现有 Gin 后台主题。

请直接开始执行，不要只给我实施计划。

---

## 零、执行机制（最先阅读）

本任务很长，不能靠记忆完成，必须靠文件追踪进度。

1. 本文件是任务的唯一依据：`/var/www/drupal/XEDC_ADMIN_TASK.md`
2. 进度清单：`/var/www/drupal/XEDC_ADMIN_PROGRESS.md`（已存在则读取，不存在则按本文件第十二节各阶段创建）
3. 最终报告：`/var/www/drupal/XEDC_ADMIN_REPORT.md`（见第十八节）

进度状态只能使用以下标记：

- `[ ]` 未做
- `[x]` 已完成并实际验证
- `[-]` 已做但未验证
- `[!]` 失败或被阻塞

规则：

1. 按清单从上到下逐项执行，不得跳项、合并项、提前结束。
2. 每完成一项，立刻更新清单，并在后面写一句实际结果。
3. 没有实际运行验证的项目只能标 `[-]`，不得标 `[x]`。
4. 每个阶段结束前，重新读取进度清单，确认该阶段没有遗留 `[ ]`，再进入下一阶段。
5. 每进入新阶段前，重新阅读第三节（保护规则）和第十四节（命令风险）。
6. 全部完成后，重新读取第十五节验收标准，逐条核对，发现遗漏立即补做。
7. 会话中断后恢复时，先读取本文件和进度清单，从第一个 `[ ]` 继续，不要重做已完成项。
8. 本节提到的三个 md 文件均为新增文件，不要提交 Git。

---

## 一、项目环境

项目目录：`/var/www/drupal`

Web 根目录：`/var/www/drupal/web`

技术栈：

- Drupal 11
- PHP 8.5
- Radix 6.x
- Bootstrap 5
- Nginx
- MariaDB
- Twig
- Drupal 原生管理系统

命令约定：

- Drush 使用 `vendor/bin/drush`，先确认可用：`vendor/bin/drush status`
- PHP 使用系统 `php`，先确认版本：`php -v`
- 新建文件的属主和权限必须与 `web/themes/custom/xedc` 保持一致，确保 Web 服务用户可读

现有主题：

- 前台主题：`web/themes/custom/xedc`
- 前台主题基于 Radix
- 当前后台使用 Gin
- 计划创建新的独立后台主题：`web/themes/custom/xedc_admin`

现有自定义模块：

- `web/modules/custom/xedc_core`
- `web/modules/custom/xedc_admin_ui`
- `web/modules/custom/xedc_quick`
- `web/modules/custom/xedc_home`

项目中还包含图片批量导入、快速发布、内容管理、会员管理、SEO 等现有功能。

这些功能已经投入使用，不允许因为后台主题改造而重写。

---

## 二、本次任务的最终目标

创建一个独立的 Drupal 后台主题：`xedc_admin`

要求：

1. 基于当前项目实际安装的 Radix 版本创建子主题。
2. 使用 Radix 和 Bootstrap 5 现有技术体系。
3. 不影响前台 xedc 主题。
4. 保留 Gin，作为回退方案。
5. 新主题具有独立的 CSS、Twig 模板、JavaScript 和布局。
6. 将现有 XEDC 后台 Dashboard 迁移到新主题。
7. 逐步适配 Drupal 原生后台页面。
8. 适配现有图片导入、快速发布、内容审核、会员管理等功能。
9. 建立统一的现代化后台设计系统。
10. 最终使新主题能够承担日常后台管理工作。

注意：主题只负责 UI、布局、样式和交互。业务逻辑仍然放在现有 Drupal 模块中。

不要把业务逻辑迁移到主题里。

---

## 三、最高优先级：保护现有代码

### 3.1 开始前必须执行

```bash
cd /var/www/drupal
pwd
git status --short
git branch --show-current
git diff --stat
git diff --cached --stat
git status
```

必须先识别：

- 已修改但未提交的文件
- 已暂存但未提交的文件
- 新增但未跟踪的文件
- 当前分支
- 是否存在其他 Agent 正在修改的文件

把 `git status --short` 的完整输出保存到报告中，作为"开始前状态"，用于最终对比。

### 3.2 开始前必须备份与记录

1. 记录当前主题配置（只读命令）：

```bash
vendor/bin/drush config:get system.theme
vendor/bin/drush config:get core.extension --include-overridden
```

   输出写入报告的"开始前主题状态"。

2. 备份数据库到项目目录之外（例如 `/root/backup/` 或 `/var/backups/`），文件名带时间戳：

```bash
vendor/bin/drush sql:dump --gzip --result-file=/var/backups/xedc_before_admin_theme_$(date +%Y%m%d_%H%M%S).sql
```

   如果该命令不可用或会产生风险，改用 `mysqldump`。备份失败则暂停并报告，不得继续切换主题。

3. 记录前台基线（用于验收"未影响前台"）：对前台首页和一个内容页执行 `curl -s -o /dev/null -w "%{http_code}"` 记录状态码，并保存 HTML 的 `sha256sum`。

### 3.3 严格禁止

1. 禁止执行 `git reset --hard`。
2. 禁止执行 `git checkout --`。
3. 禁止执行 `git restore`。
4. 禁止执行 `git clean`。
5. 禁止执行 `git stash`。
6. 禁止覆盖已有未提交修改。
7. 禁止删除现有文件。
8. 禁止使用 `git add -A`。
9. 禁止擅自提交 Git。
10. 禁止擅自推送 GitHub。
11. 禁止修改 `config/sync`。
12. 禁止执行 `drush config:import`。
13. 禁止重装 Drupal。
14. 禁止重建数据库。
15. 禁止卸载 Gin。
16. 禁止修改前台 xedc 主题。
17. 禁止重写已经完成的图片导入模块。
18. 禁止重写快速发布模块。
19. 禁止为了实现新主题而修改无关业务代码。

### 3.4 修改现有文件的原则

如果某个目标必须修改现有文件：

先阅读完整文件，确认原有功能，再做最小化修改。

如果发现目标文件已经有其他未提交修改，不得直接覆盖。优先采用新增独立文件、独立 library 或兼容性扩展的方式实现。

如果无法安全修改，暂停该部分并说明原因。

---

## 四、第一阶段：审查现有项目

### 1. Radix 技术结构

检查：

- `web/themes/contrib/`
- `web/themes/custom/xedc/`

确认：

- 实际安装的 Radix 版本
- Radix 的真实 machine name
- 子主题正确的 base theme 写法
- Radix 的 libraries
- Bootstrap 版本
- 主题推荐的模板覆盖方式
- 主题的 Twig 结构
- 主题的 JavaScript 初始化方式
- 是否存在可复用的前台设计令牌

不要凭经验猜测 Radix 的 machine name 或 YAML 写法，必须以当前安装源码为准。

不要复制整个 Radix 源码。

不要直接复制前台 xedc 主题作为后台主题。

### 2. Gin 现状

检查：

- Gin 主题配置
- Gin 相关模块（包括 `gin_toolbar`、`gin_login` 等配套模块是否启用）
- 当前后台主题实际使用情况
- Gin 的 CSS 和 JavaScript 加载方式
- Gin 的工具栏、导航和页面布局
- `system.theme` 当前配置
- `config/sync/system.theme.yml` 当前状态

注意：

之前发现线上实际 admin 主题与 `config/sync/system.theme.yml` 中的配置可能存在漂移。

因此：

禁止通过 config import 解决主题配置问题。

先记录现有状态，后续采用最小化、安全的主题启用方式。

关于 Gin 配套模块（如 `gin_toolbar`）：它们可能输出自己的侧边栏或工具栏，与新主题自带的侧边栏冲突。必须审查其在新主题下的行为，只允许通过新主题的 libraries 或模板方式避免冲突，不得卸载这些模块。

### 3. XEDC 后台模块

完整检查：`web/modules/custom/xedc_admin_ui/`

重点关注：

- `xedc_admin_ui.module`
- `xedc_admin_ui.info.yml`
- `xedc_admin_ui.libraries.yml`
- `xedc_admin_ui.routing.yml`
- `xedc_admin_ui.permissions.yml`
- `xedc_admin_ui.links.menu.yml`
- `src/Controller/DashboardController.php`
- `src/DashboardStats.php`
- `templates/xedc-admin-dashboard.html.twig`
- `css/tokens.css`
- `css/dashboard.css`

以上是预期结构，实际以项目文件为准。

确认：

- Dashboard 当前实现
- 路由和权限
- 菜单链接
- CSS 加载方式
- 是否依赖 Gin 专属 DOM 或 CSS 选择器
- 是否有硬编码 Gin 类名
- 是否存在只在 Gin 下工作的功能

现有 Dashboard 业务逻辑尽可能保留，只调整视觉表现和必要的模板结构。

### 4. 其他现有模块

检查以下模块与后台页面的关联：

- `xedc_core`
- `xedc_quick`
- 图片批量导入相关模块
- 内容管理相关模块
- SEO Audit
- Site Audit
- 会员管理相关模块

重点确认路由、权限、表单、Views 和页面模板。

审查结束后不要停止任务。没有重大风险就直接开始开发。

---

## 五、后台设计方向

本项目后台采用：**白色简约、现代 SaaS Dashboard 风格。**

设计参考：Shadcn UI、Linear、Vercel Dashboard、GitHub 新版管理界面。

这些仅作为视觉参考，不允许直接复制其代码、商标或完整页面。

### 1. 整体视觉

要求：

- 白色简约
- 清晰的视觉层级
- 现代化 SaaS 管理后台
- 页面整洁，不拥挤，适当留白
- 统一的间距系统
- 统一的边框和圆角
- 克制的阴影
- 避免过度装饰
- 不要做成传统 Drupal 后台的老式视觉
- 不要做成过于复杂的企业 ERP 系统
- 不要使用大面积渐变
- 不要滥用彩色卡片
- 不要让每个模块拥有完全不同的 UI

整体应该像一款成熟的现代内容管理 SaaS 产品。

### 2. 配色系统

统一使用以下设计令牌：

```css
:root {
  --xedc-admin-background: #F8F9FB;
  --xedc-admin-surface: #FFFFFF;
  --xedc-admin-surface-secondary: #F4F4F5;
  --xedc-admin-foreground: #18181B;
  --xedc-admin-muted: #71717A;
  --xedc-admin-border: #E4E4E7;
  --xedc-admin-primary: #2563EB;
  --xedc-admin-primary-hover: #1D4ED8;
  --xedc-admin-primary-active: #1E40AF;
  --xedc-admin-success: #16A34A;
  --xedc-admin-warning: #D97706;
  --xedc-admin-danger: #DC2626;
  --xedc-admin-radius-sm: 6px;
  --xedc-admin-radius-md: 8px;
  --xedc-admin-radius-lg: 12px;
}
```

要求：

- 页面背景使用浅灰色
- 内容卡片使用白色
- 主色使用蓝色
- 普通文字使用深灰黑色
- 辅助文字使用中性灰
- 边框使用浅灰色
- 成功、警告、错误颜色只用于状态提示
- 不要让整个后台充满蓝色
- 第一阶段只制作浅色主题
- 为以后增加深色模式预留 CSS 变量，但不要现在实现完整深色模式

### 3. 字体和图标

字体：

- 优先使用系统无衬线字体
- 中文和英文都必须有良好显示效果
- 不要为了字体引入不必要的外部服务

图标：

- 优先使用项目已有图标资源
- 可以采用 Lucide 风格的线性图标
- 如果项目没有 Lucide，不要直接假定已经安装
- 优先采用可维护的 SVG 图标方案
- 保持图标尺寸、线宽和视觉风格一致
- 不要混用大量不同风格的图标

### 4. 桌面端整体布局

采用经典现代 SaaS 后台布局：

```
┌────────────────────────────────────────────────────┐
│                    顶部工具栏                        │
├──────────────┬─────────────────────────────────────┤
│              │                                     │
│   左侧导航    │              页面内容                │
│              │                                     │
└──────────────┴─────────────────────────────────────┘
```

左侧导航：

- 默认宽度约 240px
- 支持折叠到约 72px
- 固定在页面左侧
- 白色背景，右侧浅灰边框
- 导航图标与文字垂直居中
- 当前选中菜单使用浅蓝色背景或清晰的选中指示
- 导航分组之间有适当间距
- 支持当前路由高亮
- 支持键盘访问
- 不要出现横向滚动条

顶部工具栏：

- 高度约 64px
- 白色背景，底部浅灰边框
- 左侧可以放置面包屑或页面上下文
- 右侧放置必要的操作入口
- 用户头像和账户菜单
- 可以预留通知入口
- 不要堆积无用图标

主内容区域：

- 浅灰色背景
- 合理的内容宽度
- 页面标题与说明文字层级清晰
- 页面内容之间保持一致的间距
- 卡片和表格使用统一设计规范

### 5. 手机端布局

手机端必须单独设计，不能简单缩小桌面布局。

要求：

- 隐藏桌面固定侧边栏
- 使用左侧抽屉导航
- 点击顶部菜单按钮后，从左侧滑出导航
- 点击遮罩可以关闭
- 支持 ESC 关闭
- 支持合理的键盘焦点管理
- 页面顶部工具栏适配手机
- 内容卡片自适应
- 表格必要时采用横向滚动容器或移动端卡片布局
- 表单字段宽度自适应
- 操作按钮不能溢出屏幕
- 不增加底部固定导航栏
- 不允许页面出现整体横向溢出

测试宽度：

- 手机：390px（重点）
- 平板：768px
- 桌面：1366px 和 1920px

---

## 六、后台导航设计

建立统一的后台导航结构。

建议分组：

**工作台**
- XEDC 控制台

**内容管理**
- 全部内容
- 美图
- 视频
- 文章
- 图片导入
- 快速发布

**内容组织**
- 分类管理
- 标签管理
- 人物管理

**投稿审核**
- 会员投稿
- 审核中心

**会员管理**
- 会员列表

**SEO 与网站健康**
- SEO Audit
- Site Audit
- 系统状态

**系统管理**
- Drupal 原生管理入口
- 配置管理
- 日志与报告

注意：以上是导航规划，不代表所有路由都已经存在。

必须先检查真实路由和权限。

只显示：

1. 实际存在的路由
2. 当前用户有权访问的路由
3. 已经实现的功能入口

不得创建没有实际功能的假菜单。

优先使用 Drupal 菜单系统和权限控制，不要在 Twig 中硬编码所有链接。

保留 Drupal 原生管理入口。

不要破坏现有 Drupal 菜单。

因路由不存在而未创建的菜单项，必须记录到最终报告。

---

## 七、统一 UI 组件

新主题应建立统一组件体系，至少包括：

1. Sidebar
2. Header
3. Breadcrumb
4. PageHeader
5. StatCard
6. DataTable
7. SearchInput
8. FilterBar
9. Pagination
10. StatusBadge
11. DropdownMenu
12. Dialog
13. FormField
14. EmptyState
15. LoadingState
16. Toast
17. Button
18. Card

要求：

- 统一边框、圆角、间距、字体
- 统一按钮尺寸
- 统一状态颜色
- 统一表格风格
- 统一表单风格
- 统一交互反馈

不要为每个页面重复编写完全不同的组件。

优先复用 Drupal、Radix、Bootstrap 5 已有能力。

不要为了实现这些组件引入 React、Vue、Tailwind 或其他大型前端框架。

---

## 八、XEDC Dashboard 迁移

现有 Dashboard 路由：`/admin/xedc`，必须保留。

现有 Dashboard 统计逻辑不能随意修改。

现有页面包括：欢迎区域、内容统计、美图统计、视频统计、文章统计、会员统计、审核状态、最近发布内容、快捷操作、SEO 和网站健康入口。

要求：

1. 保留原有统计口径
2. 保留原有权限判断
3. 保留缓存逻辑
4. 保留上海时区相关逻辑
5. 保留现有路由
6. 保留 Dashboard 数据服务
7. 主要调整 Twig 结构和视觉样式
8. 使用新主题的统一卡片组件
9. 手机端正常显示
10. 不要在 Dashboard 普通页面请求中触发 SEO Audit 或 Site Audit 的实时扫描

如果需要调整 Dashboard 模板，优先采用 Drupal 推荐的主题模板覆盖方式。

不要将统计 SQL 或业务权限判断放进主题。

---

## 九、Drupal 原生页面兼容性

新主题不能只支持 `/admin/xedc`，必须逐步适配 Drupal 原生管理页面。

### 1. 内容管理
- `/admin/content`、内容列表、内容编辑、内容添加、内容删除确认
- 内容筛选、Views、分页、批量操作、多语言内容管理

### 2. 分类和标签
- 分类列表与编辑、标签列表与编辑、人物词汇表、词汇表管理

### 3. 会员管理
- 用户列表、用户编辑、会员状态、角色管理、用户权限

### 4. 内容审核
- Content Moderation、工作流状态、审核操作
- 内容发布、退回修改、拒绝投稿

### 5. Media Library

必须实际验证：打开媒体库、搜索媒体、浏览媒体、预览图片、选择图片、上传媒体、关闭媒体库、返回原表单、多选媒体。

不能因为修改主题 CSS 导致 Media Library 弹窗无法使用。

### 6. CKEditor 5

必须验证：编辑器正常加载、工具栏正常显示、图片功能、链接功能、表格功能、HTML 编辑能力、内容编辑和保存、编辑区域样式正常。

注意：CKEditor 5 可能拥有独立的编辑区域或 iframe 环境，不能假设后台主题 CSS 会自动影响编辑区域。

### 7. 图片批量导入

这是现有重要功能，必须保持原功能不变。只做兼容性验证和必要的视觉适配。

不得：重写导入逻辑、改变图片处理方式、改变图片转换规则、改变文件保存路径、改变导入数据库结构、重新导入已有图片、删除原有导入数据。

### 8. 快速发布

同样只做兼容性验证和视觉适配。

不得重写现有快速发布逻辑，不得改变其业务流程和数据库结构。

---

## 十、主题 CSS 和模块 CSS 的职责

主题负责：页面布局、导航、页面整体视觉、Drupal 原生页面样式、统一组件、响应式、主题级交互。

模块负责：数据、路由、权限、表单、内容处理、工作流、统计、缓存、业务操作。

关于 `xedc_admin_ui` 中的 Gin 专属选择器（例如 `body[data-gin-accent]`、`html.gin--dark-mode`）：

- **切换完成前，不得删除或修改这些 Gin 选择器**，因为线上后台目前仍在使用 Gin。
- 采用"新增"方式：为新主题新增独立、与 Gin 无关的样式（放在 `xedc_admin` 主题内，或模块内新增独立 library 且只在新主题下附加）。
- 在报告中列出所有 Gin 专属选择器的位置，作为"切换稳定后的清理清单"，由我决定何时清理。

新主题的 CSS 要求：

- 使用独立命名空间，例如 `.xedc-admin`、`.xedc-admin-sidebar`、`.xedc-admin-header`、`.xedc-admin-content`
- 不能让后台 CSS 泄漏到前台
- 不能通过全局修改 Bootstrap 样式破坏前台
- 通过主题自己的 libraries 正确加载
- 不继续依赖 Gin 专属的 CSS group 或 Gin DOM 结构

---

## 十一、Radix 主题文件结构

根据当前 Radix 版本和项目实际情况设计结构。参考：

```
web/themes/custom/xedc_admin/
├── xedc_admin.info.yml
├── xedc_admin.libraries.yml
├── xedc_admin.theme
├── css/
│   ├── base.css
│   ├── layout.css
│   ├── components.css
│   ├── dashboard.css
│   ├── content.css
│   ├── forms.css
│   └── responsive.css
├── js/
│   ├── admin.js
│   └── navigation.js
└── templates/
    ├── layout/
    ├── navigation/
    ├── content/
    ├── views/
    ├── forms/
    └── page/
```

这只是参考。不要为了满足目录结构而创建大量空文件。只创建实际需要的文件。

优先遵循 Radix 当前版本的标准结构。如果 Radix 提供更合适的子主题开发方式，采用实际源码支持的方式。

---

## 十二、开发执行顺序

### 阶段 1：只读审查

- Git 工作区审查
- 开始前备份与记录（第 3.2 节）
- Radix 版本和结构审查
- Gin 配置与配套模块审查
- `xedc_admin_ui` 审查
- 路由和权限审查
- 现有 Dashboard 审查
- 现有模块兼容性审查

完成后简要输出审查结果，写入报告，然后继续开发，不要停下来等待批准。

如果发现重大风险、数据安全风险、不可逆操作，或者需要修改数据库结构，必须暂停相关操作并向我报告。

### 阶段 2：创建独立后台主题

创建 `web/themes/custom/xedc_admin`。

- 正确继承当前 Radix
- 正确配置主题信息和 libraries
- 正确加载 CSS 和 JavaScript
- 创建独立的设计令牌
- 创建基础布局、侧边栏、顶部工具栏、响应式布局
- 不影响前台主题

先让新主题能够正常启用并显示基础布局。

### 阶段 3：开发统一设计系统

先完成基础视觉系统，再迁移具体页面。实现：设计令牌、Sidebar、Header、Breadcrumb、PageHeader、Card、Button、StatusBadge、DataTable、FormField、EmptyState、Responsive Layout，以及第七节其余组件。

### 阶段 4：迁移 Dashboard

将 `/admin/xedc` 适配到新主题，保留现有业务逻辑。优化：统计卡片、内容概览、最近发布内容、审核状态、快捷入口、页面标题、卡片间距、手机端布局。

### 阶段 5：适配 Drupal 原生管理页面

依次适配：

1. 内容列表
2. 内容编辑表单
3. 分类和标签
4. 会员管理
5. 审核中心
6. Media Library
7. CKEditor 5
8. 图片导入
9. 快速发布
10. SEO 和 Site Audit 页面

不能为了视觉统一而破坏 Drupal 原生功能。

### 阶段 6：测试

至少执行：

- PHP 语法检查
- YAML 解析检查
- Twig 模板检查
- Drupal 路由检查
- Drupal 服务容器检查
- 缓存重建
- 桌面端页面检查
- 手机端页面检查
- 权限检查
- Media Library 测试
- CKEditor 测试
- 图片导入兼容性测试
- 快速发布兼容性测试
- 前台回归检查（第 3.2 节基线对比：状态码与 HTML 哈希，如因动态内容哈希必然变化，对比结构与样式引用是否变化）

浏览器测试手段：

- 优先使用 Playwright 或 headless Chromium 实际打开页面并截图（390 / 768 / 1366 / 1920）
- 如果服务器没有浏览器且无法安装（不得引入不必要的 Composer 依赖，npm 全局工具需先告知），则对应项目标记"未验证"，不得假装测过

不要只根据代码推断页面正常。

---

## 十三、主题切换策略

禁止一开始就把 Gin 替换掉。采用并行开发策略：

1. 创建并开发 `xedc_admin`。
2. 在不影响当前线上后台的情况下验证新主题。
3. 确认 Dashboard 和关键 Drupal 管理功能正常。
4. 在完成备份和回滚准备后，才允许将新主题设为 admin 主题。
5. 验证失败立即恢复原 Gin 主题。

并行预览规则：

- 允许执行 `vendor/bin/drush theme:install xedc_admin`（只安装，不设为默认或后台主题）。
- 不得在未备份的情况下修改 `system.theme` 的 `admin` 值。
- 如果无法在不影响线上的前提下预览（例如没有测试环境或测试账号方案），先完成代码开发和静态验证，把相关项标记"未验证"，并在报告中写明启用步骤，不要为了测试而直接影响线上后台。

正式切换（仅在完整验收通过后，且备份已完成）：

```bash
vendor/bin/drush config:set system.theme admin xedc_admin -y
```

这是单项配置修改，不是 config import。切换后必须核实实际生效结果，并立即验证 `/admin/xedc`、`/admin/content`、Media Library、CKEditor。

回滚命令（必须写入报告，并保证可用）：

```bash
vendor/bin/drush config:set system.theme admin gin -y
vendor/bin/drush cr
```

（回滚值以第 3.2 节记录的开始前 `system.theme.admin` 为准。）

要求：

- Gin 保留，Gin 相关模块保留
- 不卸载 Gin，不删除 Gin 配置
- 不运行 config import，不修改 config/sync
- 不擅自修改默认前台主题

缓存重建（`drush cr`）会短暂影响线上，尽量在改动集中后再执行，不要频繁重建。

---

## 十四、命令风险与性能安全要求

### 14.1 命令风险控制

任何 `drush` 命令执行前，先确认它不会修改配置或数据库。以下命令类别必须先暂停并向我报告，得到明确许可才能执行（本文件已明确授权的 `theme:install`、第十三节的 `config:set system.theme admin` 与回滚命令除外）：

- `config:*`（只读的 `config:get` 除外）
- `sql:*`（`sql:dump` 备份除外，`sql:query` 仅限 SELECT）
- `entity:delete`
- `updatedb`
- `pm:uninstall` / `pmu`
- 任何会写入数据库内容的 `php:eval` / `php:script`

### 14.2 性能和安全

1. 不引入大型前端框架
2. 不引入不必要的 Composer 依赖
3. 不加载不必要的外部 CDN
4. 不影响 Drupal 缓存系统
5. 不绕过 Drupal 权限系统
6. 不在 Twig 中直接执行数据库查询
7. 不在前端暴露敏感数据
8. 所有菜单链接遵循 Drupal 权限控制
9. 所有表单保留 Drupal CSRF 和表单 API 机制
10. 不使用不安全的原生 HTML 注入方式
11. JavaScript 必须兼容 Drupal behaviors 和 `once()` 机制
12. 避免重复绑定事件
13. 不影响 Drupal AJAX
14. 不影响 Views AJAX
15. 不影响 Media Library 对话框
16. 不影响 CKEditor 5
17. 不影响多语言
18. 不影响内容审核工作流

---

## 十五、验收标准

以下内容全部完成后，才能认定本次任务完成。未实际测试的项目必须标记为"未验证"，不得标记为通过。

### A. 主题

- [ ] xedc_admin 已创建
- [ ] 正确继承 Radix
- [ ] 主题可以正常启用
- [ ] CSS 和 JS 正常加载
- [ ] 没有影响前台 xedc
- [ ] 新主题没有 Gin 专属依赖
- [ ] 视觉符合设计规范

### B. UI

- [ ] 左侧导航正常
- [ ] 导航可以折叠
- [ ] 手机端抽屉正常
- [ ] 顶部工具栏正常
- [ ] 面包屑正常
- [ ] Dashboard 卡片正常
- [ ] 表格样式正常
- [ ] 表单样式正常
- [ ] 状态徽标正常
- [ ] 页面没有横向溢出

### C. 功能

- [ ] Dashboard 正常
- [ ] 内容管理正常
- [ ] 内容编辑正常
- [ ] 分类和标签管理正常
- [ ] 会员管理正常
- [ ] 内容审核正常
- [ ] Media Library 正常
- [ ] CKEditor 5 正常
- [ ] 图片导入正常
- [ ] 快速发布正常
- [ ] SEO Audit 正常
- [ ] Site Audit 正常
- [ ] Drupal 原生管理页面正常

### D. 权限和安全

- [ ] 管理员正常
- [ ] 内容编辑角色正常
- [ ] 普通会员无法访问无权限后台
- [ ] 菜单遵循权限
- [ ] 不泄漏未授权内容
- [ ] 多语言正常

### E. 项目保护

- [ ] 没有覆盖原有未提交修改
- [ ] 没有删除原有文件
- [ ] 没有修改 config/sync
- [ ] 没有执行 config import
- [ ] 没有卸载 Gin
- [ ] 没有重写图片导入模块
- [ ] 没有重写快速发布模块
- [ ] 没有擅自提交 Git
- [ ] 开始前数据库备份已完成
- [ ] 开始前 system.theme 配置已记录
- [ ] 回滚命令已验证可用（或已明确标注未验证）

---

## 十六、最终交付内容（总览）

完成后须交付：

1. 创建的文件及用途
2. 修改的文件及原因
3. 主题结构（继承关系、libraries、模板结构）
4. 视觉实现说明（侧边栏、顶部工具栏、Dashboard、表格、表单、手机端、设计令牌）
5. 功能测试表（Dashboard / 内容管理 / Media Library / CKEditor 5 / 图片导入 / 快速发布 / 手机端 / 权限，状态为 通过 / 失败 / 未验证）
6. Git 状态（修改文件、新增文件、是否存在未提交修改、是否影响原有修改）
7. 当前主题状态（当前默认后台主题、xedc_admin 是否已启用、Gin 是否保留、是否具备回滚条件）
8. 后续建议（不擅自扩大本次任务）

以上全部汇总写入第十八节规定的报告文件。

---

## 十七、执行原则（必须遵守）

1. 先审查，后开发。
2. 审查完成后直接开始制作，不要只输出方案。
3. 优先新增独立文件，减少修改现有模块。
4. 不要为了追求视觉效果破坏现有功能。
5. 不要修改与本任务无关的代码。
6. 不要重写已经完成的业务模块。
7. 不要擅自改变数据库和配置。
8. 不要擅自切换生产环境后台主题。
9. 遇到普通技术问题，优先依据当前项目源码自行解决。
10. 遇到重大风险、不可逆操作或需要数据库变更时暂停并报告。
11. 每完成一个阶段，进行必要的检查，并更新进度清单。
12. 最终交付可运行、可维护、可回滚的代码，而不是只有设计稿或演示页面。

---

## 十八、最终报告文件（必须执行）

全部任务完成，或因重大风险暂停时，在项目根目录创建：

`/var/www/drupal/XEDC_ADMIN_REPORT.md`

这是新增文件，不要提交 Git。开发过程中每完成一个阶段，先把该阶段结果追加到此文件，再进入下一阶段，保证会话中断后可以继续。

报告必须包含以下章节，缺一不可：

1. **任务完成情况**：阶段 1-6 逐项列出，状态只能是 已完成 / 部分完成 / 未完成 / 未验证
2. **开始前状态**：`git status --short`、`system.theme` 配置、数据库备份文件路径、前台基线
3. **新增文件清单**：路径 + 用途
4. **修改文件清单**：路径 + 修改原因 + 修改前后要点；没有则写"无"
5. **主题结构**：base theme、libraries、模板覆盖列表、JS 初始化方式
6. **设计令牌**：实际使用的 CSS 变量清单
7. **导航清单**：实际显示的菜单项、对应路由、权限；以及因路由不存在而未创建的菜单项
8. **验收清单**：第十五节 A-E 每一条如实填写，未实际测试的写"未验证"
9. **测试记录表**：测试项 | 执行命令或操作 | 实际结果 | 状态（通过 / 失败 / 未验证）
10. **Gin 专属选择器清单**：`xedc_admin_ui` 及其他模块中残留的 Gin 选择器位置，供切换稳定后清理
11. **Gin 配套模块处理**：`gin_toolbar` 等在新主题下的行为与冲突处理
12. **Git 状态**：结束后的 `git status --short`，与开始前对比，说明原有未提交修改是否受影响
13. **当前主题状态**：当前 admin 主题、xedc_admin 是否已启用、Gin 是否保留
14. **启用步骤与回滚步骤**：写成可直接复制的命令
15. **已知问题与风险**
16. **未完成事项与后续建议**

要求：

- 如实报告，失败和跳过的内容必须写明，不得夸大。
- 没有实际运行过的测试一律写"未验证"。
- 最后在聊天中只输出报告路径和 5 行以内摘要，不要重复全文。

---

现在开始执行。先读取并遵守第零节的执行机制，再检查工作区和项目结构，完成备份与记录，然后立即进入 xedc_admin 主题创建与实际开发阶段。不要只给我审查报告，不要等待我批准普通开发步骤。

