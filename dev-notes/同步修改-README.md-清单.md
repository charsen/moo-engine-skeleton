# docs/README.md 同步修改清单

**基于第 1-3 章修订方案**，需要同步修改 `docs/README.md` 的地方：

---

## 修改 1：环境要求表 - 删除 Node 说明（对应第 1 章 P2-1）

### 当前内容（第 39 行）
```markdown
| Node / npm | Node 26 / npm 11 | **整行可选**：只有 `engine/` 的前端资源构建（vite/tailwind）用到，只跑后端接口教程可以完全不装 |
```

### 修改为
```markdown
| Node / npm | Node 26 / npm 11 | **整行可选**：教程第 1-10 章均不涉及前端资源构建，可完全不装；保留此行是为可能的前端分离架构预留 |
```

---

## 修改 2：环境要求表 - 强化私有包说明（对应第 2 章 P0-1）

### 当前内容（第 42 行）
```markdown
| moo-scaffold 源码 | dev-master | **第 2 章起必需**。开源包（MIT，规划发 Packagist），但**尚未正式发布——目前唯一获取途径是联系作者要源码**，缺它 `composer install` 直接失败。克隆到与本仓库同级目录，第 2 章用相对路径引用；moo-system 为第 7 章的**商业包**（可选，同样联系作者获取） |
```

### 修改为
```markdown
| moo-scaffold 源码 | dev-master | **第 2 章起必需**。开源包（MIT，规划发 Packagist），但**尚未正式发布**。**获取方式**：`git clone https://gitee.com/charsen/moo-scaffold.git`（当前私有，需联系作者申请协作者权限）。**克隆位置**：与本仓库同级目录（`wwwroot/moo-scaffold/`），第 2 章用相对路径 `../../moo-scaffold` 引用。缺它 `composer install` 直接失败。moo-system 为第 7 章的**商业包**（可选，同样联系作者获取，克隆到同级 `wwwroot/moo-system/`） |
```

---

## 修改 3：第 1 章描述 - 监控改为可选（对应第 1 章 P0-1）

### 当前内容（第 77 行）
```markdown
| [第 1 章 安装 Laravel 12](./01-安装-laravel.md) | 创建项目、连接 MariaDB、建库、真机访问、**1.7 接入监控（本骨架标准件）** | 基础 |
```

### 修改为
```markdown
| [第 1 章 安装 Laravel 12](./01-安装-laravel.md) | 创建项目、连接 MariaDB、建库、真机访问、**1.7【可选】接入监控（需私有仓库访问权限）** | 基础 |
```

---

## 修改 4：踩坑速查表 - 删除"坑 1、2"（已前置为正常步骤）

### 当前内容（第 97-98 行）
```markdown
| 1 | 生成 Model 报 `EloquentFilter\Filterable not found` | 装 `tucker-eric/eloquentfilter` + `godruoyi/php-snowflake` | 2 |
| 2 | 报 `BaseActionTrait not found` | `moo:free` 不建它，跑一次 `php artisan moo:controller Food -f` | 2 |
```

### 修改为（删除这两行，后续坑序号前移）
原因：第 2 章修订方案（P1-1）已将 eloquentfilter 和 php-snowflake 前置为 2.1 节的正常安装步骤，不再是"坑"。"坑 2"的 `moo:controller -f` 也已在修订方案中删除。

---

## 修改 5：踩坑速查表 - 坑 3 改序号为坑 1

### 当前内容（第 99 行）
```markdown
| 3 | `moo:free` 里 `moo:api` 提示 No routes matched | 路由刚插入、当前进程没刷新，单独补 `moo:api admin Food` | 2 |
```

### 修改为
```markdown
| 1 | `moo:free` 里 `moo:api` 提示 No routes matched | 路由刚插入、当前进程没刷新，单独补 `moo:api admin Food` | 2 |
```

---

## 修改 6：踩坑速查表 - 坑 4 改为坑 2（多 worker 已前置）

### 当前内容（第 100 行）
```markdown
| 4 | 调试器代理一直转圈 | 单线程 serve 自我代理死锁，用 `PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=127.0.0.1 --port=8088 --no-reload` 启动 | 2 |
```

### 修改为
```markdown
| 2 | 调试器代理一直转圈（虽然 2.1 已说明多 worker 启动，但还是列出来） | 单线程 serve 自我代理死锁，确认用 `PHP_CLI_SERVER_WORKERS=4 ... --no-reload` 启动了吗？ | 2 |
```

或者干脆**删除这一行**（因为第 2 章修订方案 P2-7 已将多 worker 前置到 2.1 节，不再是"坑"）。

---

## 修改 7：后续坑序号全部前移 2 位

从原坑 5 到坑 27，序号全部减 2（如果保留坑 4 则减 1）。

示例：
```markdown
| 3 | 装 moo-system 后 artisan 报 `Attribute [iResource] does not exist` | `iResource` 宏要注册在 `AppServiceProvider::register()` | 7 |
| 4 | 调部门列表报 `undefined function toLabelValue()` | 补 `app/Helpers/helpers.php` 并 `composer` files 自动加载 | 7 |
...
```

---

## 修改汇总表

| 行号 | 类型 | 修改内容 | 对应章节修订 |
|-----|------|---------|-------------|
| 39 | 表述优化 | Node 说明改为"教程不涉及" | 第 1 章 P2-1 |
| 42 | 补充说明 | 私有包克隆命令和位置 | 第 2 章 P0-1 |
| 77 | 定位调整 | 监控改为"可选" | 第 1 章 P0-1 |
| 97-98 | 删除 | 坑 1、2 已前置为正常步骤 | 第 2 章 P1-1 |
| 100 | 删除或改写 | 坑 4 已前置 | 第 2 章 P2-7 |
| 99-124 | 序号调整 | 所有后续坑序号前移 | - |

---

## 下一步：检查 docs/index.html

生成 `docs/index.html` 的同步修改清单。
