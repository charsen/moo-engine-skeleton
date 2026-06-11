# HANDOFF — 换机/新人上手交接

> 本文件随仓库分发，已按脱敏守则编写（不含生产项目名与真实凭据）。
> 机器私有信息在 `CLAUDE.local.md`（gitignored，见第 4 节模板自行重建）。

## 0. 一句话现状（2026-06-11）

生态内各仓库**全部干净、全部已推送**，无任何未提交工作。

| 仓库 | 状态 | 备注 |
|---|---|---|
| moo-engine-skeleton | tag `0.1.0` / `0.2.0`，之后有少量提交 | 八章教程 + 网页引导器 + CI workflow，21 测试全绿 |
| moo-scaffold | tag 至 `3.8.x`，LICENSE(MIT) 已补 | **已定开源**，待发布 Packagist |
| moo-system | tag 至 `1.6.12` | **商业包**（proprietary）；1.6.12 含 guard claim 动态化 |

## 1. 新机环境

```bash
# PHP 必须 8.3（composer.lock 按 8.3 解析，jwt-auth 2.9.2 要求 ^8.3）
brew install php@8.3 composer node mariadb git-lfs
brew services start mariadb
# ⚠ 若机器同时装了 php@8.2 且默认 php 指向它，每次先：
export PATH=/opt/homebrew/opt/php@8.3/bin:$PATH

# 数据库：自设 root 密码（教程示例值 7777），建库：
mysql -uroot -p -e "CREATE DATABASE moo_skeleton CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 配好 Gitee SSH key
```

## 2. 克隆（目录结构必须同级——path 仓库依赖 `../../` 相对路径）

```bash
mkdir wwwroot && cd wwwroot
git clone git@gitee.com:charsen/moo-scaffold.git
git clone git@gitee.com:charsen/moo-system.git        # 商业包，需授权
git clone git@gitee.com:charsen/moo-engine-skeleton.git
# 按需：moo-scaffold-cloud
```

## 3. skeleton 初始化（同 README「方式 A」）

```bash
cd moo-engine-skeleton/engine
composer install
cp .env.example .env && php artisan key:generate     # .env 预填示例凭据，按本机改
php artisan jwt:secret --force
php artisan vendor:publish --provider="Mooeen\Scaffold\ScaffoldProvider" --tag=public --force
php artisan migrate --seed       # admin@example.com/password + 13800000000/admin888
php artisan moo:account:add charsen --password=skeleton2026 --role=admin
PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=127.0.0.1 --port=8088 --no-reload
php artisan test                 # ✅ 应 21 passed —— 环境一切正常的硬指标
```

**不随 git 走、需重建的**：`engine/.env`、`vendor/`、`scaffold/accounts.yaml`、
`public/vendor/scaffold/`、数据库数据、`CLAUDE.local.md`——以上命令 + 第 4 节全覆盖。

## 4. 重建 `CLAUDE.local.md`（机器私有注记，gitignored）

```markdown
# CLAUDE.local.md —— 本机私有注记（不随仓库分发）
- php 多版本：命令前 export PATH=/opt/homebrew/opt/php@8.3/bin:$PATH（若有 8.2 共存）
- 数据库真实凭据：root / <你的密码>
- Git 远程：git@gitee.com:charsen/moo-engine-skeleton.git（当前私有）
- 同级目录：moo-scaffold（开源 MIT）、moo-system（商业）、moo-scaffold-cloud
```

## 5. 关键决策与守则（新会话必读，CLAUDE.md 有完整版）

1. **包定位**：moo-scaffold 开源（MIT，规划发 Packagist）；moo-system 商业（proprietary）。
   教程第 1~6 章零付费依赖是核心卖点。
2. **脱敏守则**：本仓库一切资料不得出现作者具体生产项目名称（统一"作者生产项目"指代）。
   工作树已全量脱敏；**历史提交信息未清**（见待办 #2）。
3. **架构要点**：移动端 user 守卫永久用自建 User（email 登录）；admin 守卫第 7 章起用
   Personnel；Gate `acl_authentication` 是 host 契约且多态；教程中间态代码内联在文档里，
   仓库只存第 7 章后的最终态。

## 6. 待办清单（按优先级）

| # | 事项 | 等什么 |
|---|---|---|
| 1 | moo-scaffold 公开：Gitee 设公开/GitHub 镜像 → 提交 Packagist → 教程第 2 章切 `composer require` 主线（文档已预留措辞） | 作者操作 |
| 2 | 本仓库公开时的历史脱敏：历史压缩 vs 推新公开仓库，二选一 | 作者决策 |
| 3 | moo-system 商业化：LICENSE 授权条款、`--tag=moo-system-stubs` 修复（降集成摩擦，可委托 AI 直接做）、分发凭证机制 | 作者决策 |
| 4 | CI 首跑：GitHub 镜像后配 secret `MOO_PACKAGES_DEPLOY_KEY`，按报错微调 `.github/workflows/tests.yml`（未实测） | 镜像后 |
| 5 | Gitee Pages：仓库公开后，服务 → Pages → 部署目录 `docs/` | 公开后 |
| 6 | 版本：`0.2.0` 后已积若干提交（问题修复/脱敏/包定位/部署章），可打 `0.3.0` | 作者决策 |

## 7. 已知局限备忘

- jwt-auth：过期但仍在续期窗口内的 token 调 logout 返回 200 但未真正拉黑
  （库设计局限，docs 第 4 章已记录）；
- 坑 #22：生产 `cache:clear` 会清空 JWT 黑名单 → 已注销 token 复活（docs 第 8 章）。
