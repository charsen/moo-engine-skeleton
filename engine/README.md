# engine/

本目录是教程产物 —— 放在子目录的 Laravel 12 应用(`moo-engine-skeleton` 的实际代码)。

- 项目说明与两种使用方式:见 [../README.md](../README.md)
- step-by-step 教程(01–13):见 [../docs/](../docs/)
- `scaffold/` 是 moo-scaffold 代码生成器的配置与 YAML 真值源；`composer.json` 是当前可运行配置（过渡期显式声明 moo-* VCS），`composer.production.json` 是目标生产配置样例（开源包走 Packagist，`moo-system` 走 VCS；差异见教程第 2、8 章）。
- `moo-feedback` 是扩展包接入示例：公开提交配置在 `config/moo-feedback.php`，后台管理路由固定使用独立 `moo-feedback` 强制认证组。
