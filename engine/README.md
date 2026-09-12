# engine/

本目录是教程产物 —— 放在子目录的 Laravel 12 应用(`moo-engine-skeleton` 的实际代码)。

- 项目说明与两种使用方式:见 [../README.md](../README.md)
- step-by-step 教程(01–13):见 [../docs/](../docs/)
- `scaffold/` 是 moo-scaffold 代码生成器的配置与 YAML 真值源；三份 Composer 清单按环境分流：`composer.json`（本地，4 个 manifest 私包走 sibling `path`）、`composer.test.json`（测试，Gitee `vcs` + `dev-dev`）、`composer.production.json`（生产，Gitee `vcs` + 稳定 semver）；`charsen/moo-feedback` 走 Packagist（差异见教程第 2、8 章与 [../PRIVATE-COMPOSER-PACKAGES.md](../PRIVATE-COMPOSER-PACKAGES.md)）。
- `moo-feedback` 是扩展包接入示例：公开提交配置在 `config/moo-feedback.php`，后台管理路由固定使用独立 `moo-feedback` 强制认证组。
