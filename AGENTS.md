# AGENTS.md

## 通用规则
- 默认使用中文沟通与写文档
- 不得提交密码、密钥、R2 凭据、备份包、settings.php、.env 到 Git
- 危险操作（覆盖数据库、删除目录）前先备份
- 提交信息清晰，每完成一个阶段先 git commit

## 备份恢复工具
- 位置：xedc-recovery/
- 完整需求与验收标准见 xedc-recovery/SPEC.md，修改该工具前必须先完整阅读
- 以 SPEC.md 为准，不得擅自删减需求；有冲突时以 SPEC.md 为准

## 环境
- Drupal 11，目标 PHP 8.5
- 用户仅通过 iPhone SSH 操作，命令必须单行可复制、输出简洁
