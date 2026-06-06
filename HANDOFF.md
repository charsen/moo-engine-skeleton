# HANDOFF — moo-engine-skeleton

交接说明：当前状态、怎么跑起来、关键决策与待办。详细背景见 [`CLAUDE.md`](./CLAUDE.md)，
从 0 教程见 [`docs/`](./docs/README.md)。

## 当前状态：✅ README 的 5 步全部完成并真机验证

| 步骤 | 状态 |
|---|---|
| 1 安装 Laravel 12 + MariaDB（`moo_skeleton`） | ✅ engine/ 跑 12.61.1 |
| 2 装 moo-scaffold + 生成 `foods` 表 | ✅ curl + `/scaffold` 调试器均 200 |
| 3 装 moo-system（10 张 `system_*` 表） | ✅ `moo-system check` 6/6 |
| 4 在 scaffold 调试器里测 moo-system 接口 | ✅ 带 Bearer token 调通 200 |
| 5 JWT 登录认证（仿 wisdomcity） | ✅ 无 token 401 / 有 token 200 |

最后一次自检：`moo-system check` 全绿；`food` 200、`positions`（带 token）200、`departments` 无 token 401。

## 怎么跑起来

```bash
cd engine

# 首次 clone 后需要做的（vendor / 发布资源 / 账号 都不在 git 里）：
composer install                                  # 装依赖（含 path 仓库的 moo-* 包）
cp .env.example .env && php artisan key:generate  # 配 .env（DB: root/7777, 库 moo_skeleton）
php artisan jwt:secret                             # 生成 JWT_SECRET
php artisan vendor:publish --provider="Mooeen\Scaffold\ScaffoldProvider" --tag=public --force
php artisan migrate --seed                         # 迁移 + seed（角色 / 部门树 / 岗位 / 管理员）
php artisan moo:account:add charsen --password=skeleton2026 --role=admin   # 建 scaffold 调试台账号

# 启动（必须多 worker + --no-reload，否则 scaffold 调试器代理会死锁）
PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=127.0.0.1 --port=8088 --no-reload
```

- 应用 <http://127.0.0.1:8088> · 调试台 <http://127.0.0.1:8088/scaffold>
- 后台管理员（Personnel）：`13800000000` / `admin888`（由 `PersonnelSeeder` 建，见下方「Seeder」）

## Seeder（`database/seeders/`）

`php artisan migrate --seed` 或 `db:seed` 会按 角色 → 部门 → 岗位 → 人员 的顺序建初始数据：

| Seeder | 内容 |
|---|---|
| `RoleSeeder` | 系统管理员 / 开发 / 编辑员 |
| `DepartmentSeeder` | 猫途科技（根）→ 技术部[后端组/前端组] / 市场部（嵌套集树） |
| `PositionSeeder` | 后端工程师 / 前端工程师 / 市场专员（挂部门，`department_ids` 是 JSON 数组） |
| `PersonnelSeeder` | 管理员 `13800000000`/`admin888`，挂技术部·后端工程师·系统管理员角色 |

都幂等；单独跑某个用 `php artisan db:seed --class=PositionSeeder`。
注意：`DatabaseSeeder` **不用** `WithoutModelEvents`——否则嵌套集树事件被静默、`_lft/_rgt` 建坏。

## 关键决策（为什么这么做）

- **Laravel 放在 `engine/` 子目录**：对齐 wisdomcity / light-language-engine，私有包相对路径统一为 `../../moo-*`。
- **数据库密码是 `7777`**（README 的“777”是笔误，据 `moo-scaffold-cloud/.env` 核实）。
- **端口 8088**：本机 8000 被别的项目占了。
- **JWT 中间件别名 + 路由组写在 `AppServiceProvider::boot()`**（不是 `bootstrap/app.php`）：
  否则 `moo-system check` 在命令行看不到中间件组。`iResource` 宏在 `register()`。
- **host 契约**自己实现（没用包里的空壳 stub，因为会和 scaffold 生成的 `BaseActionTrait` 冲突）：
  见 `app/Admin/Controllers/Traits/`、`app/Models/Traits/MediaSynchronous.php`、
  `app/Models/Notification.php`、`app/Notifications/SendBlessMessage.php`、`app/Helpers/helpers.php`。
- **`foods` 演示路由故意保持公开**（不加 JWT），方便第 2 章无 token 直接调试。

## 不入 git 的东西（`.gitignore` 已配）

`engine/vendor`、`engine/.env`、`engine/public/vendor/scaffold`（`vendor:publish` 重生成）、
`engine/scaffold/{accounts.yaml,.local,runtimes,sql-slows}`（账号 / 运行时数据）。

## 待办 / 可继续的点

- **部门根节点**：moo-system 的「总公司」根部门有专门的创建流程（cascader 的 `parent_id` 必填），
  目前没建根部门（岗位 CRUD 已证明集成 OK）。可加一个根部门 seeder 让组织结构更完整。
- **保护 `foods`**：真实项目里可把 `foods` 路由移进 `routes/admin.php` 的登录 group。
- **可选**：接 `moo-scaffold-cloud`（运行时异常 / 慢 SQL 上报，`SCAFFOLD_CLOUD_*` env + `moo:cloud:push`）。
- **生产部署**：把 composer 的 path 仓库换成 vcs（见 docs 第 2 章），雪花 ID 设 `CACHE_STORE=redis`。
