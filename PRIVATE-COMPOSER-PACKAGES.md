# 私有 Composer 包接入 SOP

> 适用：项目依赖**不在公共 Packagist**的 Composer 包（商业闭源，deploy key 授权分发）。
>
> 骨架接入的私有包由授权源分发：
>
> | 包 | 定位 | 当前来源 | 目标来源 |
> | --- | --- | --- | --- |
> | `charsen/moo-scaffold` | 开源（MIT）代码生成器 | 按 manifest 私包接线（本地 `path` / 测试生产 Gitee VCS） | **保持** manifest 私包接线 |
> | `charsen/moo-monitor-laravel` | 开源（MIT）运行时/慢SQL 监控 | 按 manifest 私包接线（本地 `path` / 测试生产 Gitee VCS） | **保持** manifest 私包接线 |
> | `charsen/moo-system` | **私有业务包**（proprietary） | 私有 Gitee VCS + deploy key | **保持** VCS 授权分发 |
> | `charsen/moo-upload` | **私有基础包**（proprietary） | 私有 Gitee VCS + deploy key | **保持** VCS 授权分发 |
>
> `charsen/moo-scaffold` 与 `charsen/moo-monitor-laravel` 的源码虽然是 MIT 开源，但本仓与其它 host 一致，
> 把它们按 manifest 私包接入三份 `repositories` 与 `extra.moo-private-packages`；本地 `path` symlink 仍可在 App 内联联调。
> 4 个包中 `moo-system` 与 `moo-upload` 是闭源授权包，长期走 deploy key VCS——本文档 §4 的 deploy key 流程对它长期有效。

## 1. 解决什么问题

1. **闭源包不能进公共 Packagist**（`moo-system` / `moo-upload` 授权）——生产 `composer install` 必须能凭 deploy key 从私仓拉到。
2. **本地开发想改包源码即时生效**——本地使用 path profile。
3. **测试服想验证多个私包的最新开发态**——测试 profile 统一追每个私包的 `dev`，无需反复打 tag 发版。

目标：**本地即时联调 + 测试服统一追 dev + 生产按稳定约束安装 + 闭源包不外泄**。

## 2. 三套 Composer profile（本地 path / 测试 dev / 生产稳定版）

一个包的「怎么解析」按环境分开，但业务配置保持一致：

| 文件 | 谁用 | `repositories` 段 | 效果 |
| --- | --- | --- | --- |
| `composer.json` | 本地开发（默认） | 开源包走 Packagist；私有包用 sibling `path`（`../../moo-*`，`symlink`） | 改包源码即时可见 |
| `composer.test.json` | 测试服务器 | `vcs`；manifest 私包直接使用包侧 branch alias 支撑的 `dev-dev` | Host 与私包统一验证 `dev` 最新内容 |
| `composer.production.json` | 生产部署 | `vcs`（按稳定版本约束解析） | 装成实体目录、可显式更新私包 |

**本地用 `path` 仓库的团队**（把包 clone 到 host 同级目录）：

```jsonc
// composer.json —— 本地
"repositories": {
  "system": { "type": "path", "url": "../moo-system" },
  "upload": { "type": "path", "url": "../moo-upload" }
},
"require": {
  "charsen/moo-system": "^1.6.28",
  "charsen/moo-upload": "^0.1.3"
}
```

```jsonc
// composer.production.json —— 生产
"repositories": {
  "system": { "type": "vcs", "url": "git@gitee.com:charsen/moo-system.git" },
  "upload": { "type": "vcs", "url": "git@gitee.com:charsen/moo-upload.git" }
},
"require": {
  "charsen/moo-system": "^1.6.28",
  "charsen/moo-upload": "^0.1.3"
}
```

> **骨架当前口径**：4 个 manifest 私包（`moo-scaffold` / `moo-monitor-laravel` / `moo-system` / `moo-upload`）都进三份 `repositories` 与 `extra.moo-private-packages`；`charsen/moo-feedback`（`^0.1`）仍是 MIT 公开包，直接走 Packagist 正式版本。
> 骨架本地 `composer.json` 即用 sibling `path`（`url` 为 `../../moo-scaffold` / `../../moo-monitor-laravel` / `../../moo-system` / `../../moo-upload`，`options.symlink: true`）联调；测试与生产 profile 均保持 VCS。
> `composer.test.json` 只允许 manifest 私包版本约束与 production 分流，其余 require、repositories、scripts、extra 等配置保持一致。

**pull.sh 的私包 manifest** 从当前选择的 VCS profile 的 `extra."moo-private-packages"` 读（字段
`name` / `repo-key` / `provider-rel` / `publish-tag`），URL 从 `repositories.<repo-key>.url` 关联。
加/减私包时同时维护三份 manifest（本地 `composer.json`、测试 `composer.test.json`、生产 `composer.production.json`）；一致性测试会阻止漂移。

**本仓 manifest 私包清单**（`extra.moo-private-packages` 三份一致；`repositories` 按环境只差 `type` 与 URL）：

| 包 | `repo-key` | `provider-rel` | `publish-tag` | 本地约束 / `versions` | 测试约束 | 生产约束 | 仓库 URL（本地 / 测试 = 生产） |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `charsen/moo-scaffold` | `scaffold` | `src/ScaffoldProvider.php` | `"public"` | `^2.1@dev` / `2.x-dev` | `dev-dev` | `^2.1.7` | `../../moo-scaffold` / `git@gitee.com:charsen/moo-scaffold.git` |
| `charsen/moo-monitor-laravel` | `monitor` | `src/MonitorProvider.php` | `null` | `^0.1@dev` / `0.1.x-dev` | `dev-dev` | `^0.1` | `../../moo-monitor-laravel` / `git@gitee.com:charsen/moo-monitor-laravel.git` |
| `charsen/moo-system` | `system` | `src/MooeenSystemServiceProvider.php` | `null` | `^1.6@dev` / `1.6.x-dev` | `dev-dev` | `^1.6.38` | `../../moo-system` / `git@gitee.com:charsen/moo-system.git` |
| `charsen/moo-upload` | `upload` | `src/MooeenUploadServiceProvider.php` | `null` | `^0.1@dev` / `0.1.x-dev` | `dev-dev` | `^0.1.3` | `../../moo-upload` / `git@gitee.com:charsen/moo-upload.git` |

`charsen/moo-scaffold` 的 `publish-tag` 是 `"public"`：`pull.sh` Step 5.5 会对它执行 `vendor:publish --tag=public --force`，
刷新 `engine/public/vendor/scaffold`。`charsen/moo-feedback`（`^0.1`）是 MIT 公开包，走 Packagist，不在本清单内。

## 3. deploy 流程：用 `pull.sh` 而非 `cache.sh`

**两个脚本职责严格分离**（详 [`SCRIPTS.md`](./SCRIPTS.md)）：

| 脚本 | 职责 |
| --- | --- |
| `pull.sh` | 网络层：git pull + 验证私包权限（ssh + ls-remote）+ 临时切换生产 Composer 配置 + `composer install/update` 私包 + `vendor:publish` + 调 cache.sh |
| `test-pull.sh` | 测试服薄入口：选择 Host `dev` + `composer.test.json` 后复用 pull.sh |
| `cache.sh` | 本地层：清缓存 + dumpautoload + 权限修复（chown / setgid） |

**生产 deploy 入口固定 `pull.sh`**（不要直接跑 cache.sh，它不验证私包权限）：

```bash
cd /opt/<你的仓库>
sudo sh pull.sh                 # 日常
sudo sh pull.sh --production     # 首次部署（.env 未建，显式声明生产）
```

测试服固定执行：

```bash
sudo sh test-pull.sh
```

测试 profile 首次没有 `composer.test.lock` 时完整解析一次并生成独立 lock；后续只更新 manifest 私包。
不会每次删除 lock，也不会每次重新安装全部公共依赖。该 lock 只属于测试服务器运行态，不提交 Git。

### 3.1 生产配置切换与本地 lock

`pull.sh` 在生产环境先备份 `engine/composer.json`，再把 `engine/composer.production.json` 覆盖过去。
Composer 使用部署环境本地生成、被 Git 忽略的 `engine/composer.lock`；依赖步骤失败时脚本会恢复备份，
成功后删除备份。下次拉代码前，脚本会先把受控的 `composer.json` 还原到 HEAD，再重新切换。

手工复现生产安装时使用同一方式：

```bash
cd engine
cp composer.json composer.json.local-backup
cp composer.production.json composer.json
composer install --no-dev --optimize-autoloader
```

骨架不提交 `composer.lock`，也不创建 `composer.production.lock`。部署版本由生产 manifest 的稳定约束、
私包已发布 tag 和部署当时生成的本地 lock 共同决定；需要逐字节复现时应保存构建产物，而不是在骨架仓库
额外维护第二把 lock。

## 4. deploy key 生成与配置（私有包长期需要，一次性配）

`moo-system` 与 `moo-upload` 从私有 Gitee 仓库分发，生产 box 必须有能读取这两个仓库的 SSH deploy key；
`moo-scaffold` 与 `moo-monitor-laravel` 同样走 Gitee VCS，其仓库可读性要求以实际仓库权限为准（仓库若为公开则不额外需要 deploy key）。

### 4.1 生产 box 生成 deploy key

```bash
# SSH 进生产 box（pull.sh 的 chown 段需 root，通常切 root）
ssh user@your-prod-box && sudo -i

# 生成专用 deploy key（命名跟机器关联便于追踪）
ssh-keygen -t ed25519 -f ~/.ssh/gitee_deploy -N "" -C "prod-$(hostname)"
cat ~/.ssh/gitee_deploy.pub          # 复制全部输出
```

### 4.2 Gitee 侧加部署公钥（只读）

浏览器分别打开两个私有包仓库 → 管理 → 部署公钥 → 添加：
- 标题：`prod-<hostname>`（标识哪台机器）
- 公钥：粘贴上一步 `cat` 的输出
- **不勾「启用推送权限」**（只读够用，最小权限）

> 单账户多仓多机：也可以把一把 key 加到 Gitee **账户公钥**（对该账户所有 repo 有权限），
> 省去逐仓配置。安全上更推荐 per-repo 只读 deploy key。

### 4.3 生产 box 配 `~/.ssh/config`

```bash
cat >> ~/.ssh/config <<'EOF'

Host gitee.com
    IdentityFile ~/.ssh/gitee_deploy
    IdentitiesOnly yes
EOF
chmod 600 ~/.ssh/config

# 测试（首次会要 yes 接受 host key）
ssh -T git@gitee.com
# 期望：Hi <账号>! You've successfully authenticated ...
```

✅ SSH 通了 → pull.sh Step 3 的私包权限验证会通过，`composer install` 走 SSH 自动用这把 key。

> 骨架 `composer.production.json` 的 4 条 manifest 私包 URL 里，`moo-system` 与 `moo-upload` 指向私有仓库。
> 生产建议统一使用 SSH URL，这样 deploy key 才会生效；
> 此时 pull.sh Step 3 的 `ssh -T git@gitee.com` 联通检查即前置门禁。

## 5. 日常迭代

- **改包源码**：在包自己的 repo 改 → commit → `git push`（推到 Gitee = 更新分发源）。本地 `path` 仓库
  的 host 项目 symlink 即时可见；本地 `vcs`（dev-master）的下次 `composer update` 拉到。
- **生产拉新版**：下次 `sudo sh pull.sh`，Step 5 `composer update <私包>` 自动拉 caret 范围内最新。
- **打 tag 发版**：包侧 `git tag 2.1.4 && git push origin 2.1.4`，下游 `^2.1` 自动接受新 `2.x.x`，
  不用改 production.json；主版本升级（`^3.0`）才手动改 require + commit + deploy。

## 6. 排错 FAQ

**Q1：生产 `composer install` 报私有包 `Failed to clone`**
- `ssh -T git@gitee.com` 通不通？deploy key 加进**那个 repo**没（不是随便一把全局 key）？仓库确是私有 + key 有读权限？

**Q2：`Package charsen/moo-system` 或 `charsen/moo-upload could not be resolved`**
- `composer.json` 是否已由 `composer.production.json` 正确覆盖？先 `diff composer.json composer.production.json`。
- `composer.production.json` 的仓库 URL、版本约束和 deploy key 权限是否匹配？
- 当前环境的本地 `composer.lock` 是否与生产约束冲突？必要时显式更新对应私包。

**Q3：本地改了包但 host 看不到新代码**
- vendor 是 symlink 吗？`readlink engine/vendor/charsen/moo-scaffold` 应指向你的 path 源
- 不是 → `rm -rf engine/vendor/charsen/moo-scaffold && composer update charsen/moo-scaffold --no-scripts`

**Q4：composer.lock 锁了 dev-master 跟新 require caret 不一致**
- 手动 `composer update <私包> --no-dev --optimize-autoloader --no-scripts` 一次，之后 pull.sh 走正常 update

## 7. 首次生产部署踩坑归纳

pull.sh 已把大部分坑**内化自动处理**，剩下几个是运维侧手动项：

**已内化（pull.sh 自动防）**：

| 坑 | pull.sh 已防 |
| --- | --- |
| 生产配置切换到一半失败 | Step 4 覆盖前备份 `composer.json`，Composer 失败时自动恢复 |
| 前端 build / log 等 untracked 产物 block deploy | Step 1 `--untracked-files=no` |
| sudo 重置 PATH 走 `/usr/bin/php` 老版 | `tools/_common.sh` 顶部 PATH 守卫补常见 PHP 路径 |
| vendor 缺 ServiceProvider（chicken-egg） | Step 5.0 检测到自动 `--no-scripts` 救援 install |
| 私包历史被 force-push 换根 → `no merge base` 崩 | 命中该签名时删私包 vendor 全新克隆重试 |
| jq / flock 缺失提示模糊 | `require_command` 按 cmd 名给装包指令 |
| SSH 成功消息含 ANSI 色码致匹配失败 | 用 `*authenticated*GITEE*` 两 anchor 跨过 |
| `set -u` 下未初始化变量崩 | 关键变量都 init `""` + `${var:-fallback}` |

**仍需运维侧手动**：

| # | 坑 | 处理 |
| --- | --- | --- |
| R-1 | `~/.ssh/config` 漏配 deploy key | 按 §4.3 补 config + `chmod 600` |
| R-2 | 首次 `.env` 未建、is_production 误判 | 首次部署显式 `sudo sh pull.sh --production` |
| R-3 | composer.lock 锁旧版跟新 require 不一致 | 手动 `composer update <私包>` 一次（见 FAQ Q4） |
| R-4 | apt 装 jq 弹 needrestart 默认勾 php-fpm | Tab → `<Cancel>` 跳过，待 pull.sh 跑完再手动 restart php-fpm |
| R-5 | 系统缺 `git`/`ssh`/`jq`/`flock` | 按 [`DEPLOY-CHECKLIST.md`](./DEPLOY-CHECKLIST.md) O-0 装齐 |

---

**相关**：脚本索引 [`SCRIPTS.md`](./SCRIPTS.md) ·  部署核对单 [`DEPLOY-CHECKLIST.md`](./DEPLOY-CHECKLIST.md) ·  教程 `docs/08-部署上线.md` / `docs/07-安装-moo-system.md`。
