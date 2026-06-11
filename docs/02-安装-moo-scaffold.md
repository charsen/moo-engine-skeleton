# 第 2 章　安装 moo-scaffold，生成 foods 表的业务代码

目标：把 `charsen/moo-scaffold`（代码生成器 + 可视化调试工具）接入项目，
设计一张 `foods` 表，一键生成全套 CRUD 业务代码，并用两种方式真机调试接口。

> 📌 **仓库现状 vs 本章时点**：本仓库只保留全书完成后的**终态代码**，没有按章打 tag / 分支。
> 后面章节会不断改写本章的产出（路由、宏、YAML……），所以拿仓库文件核对时会发现
> 「比文中多了东西」——凡是这种地方，文中都有「来自第 X 章的更新」之类的回注说明。
> **跟做时以文中代码为准**，回注只用来解释差异。

---

## 2.1 接入 moo-scaffold：开发用 path、生产用 vcs

先把这个包的身份说清楚：`moo-scaffold` 采用 **MIT 协议**，但**尚未公开发布**——
没上 Packagist，Gitee 仓库目前也是私有的（所以下面生产环境才需要配 SSH 部署公钥；
等仓库公开后这一步就不需要了）。规划发布到 Packagist 后，普通使用一行
`composer require charsen/moo-scaffold` 即可。

> 注意将来也**不是** `--dev`：在这套架构里 scaffold 是**运行时依赖**，不是纯开发工具——
> 生成的控制器直接继承 / 返回包里的类（`Mooeen\Scaffold\Foundation\{Controller, BaseResource, ...}`），
> `bootstrap/app.php` 也引用了包里的 `BaseException` / `ExceptionDispatcher`。
> 装进 `require-dev` 的话，本节自己推荐的 `composer install --no-dev` 部署会直接炸。
> 本仓库 `engine/composer.json` 也是把它放在 `require` 里的。

本教程用的是**源码模式**（便于跟着改包源码、也是作者的开发模式）：

**前置条件**：`moo-scaffold/` 的源码已经克隆在与本仓库**同级**的目录。
包发布前没有公开下载渠道，源码需**找作者获取**；拿不到源码的话，本章起只能「读通」、
没法「跑通」——下面第一步就会报 "path repository ... does not exist"。

接入前要先在 `engine/composer.json` 里声明 `repositories`。两种模式按环境选：

**开发环境（path 相对路径，改源码实时生效）** —— 本教程用这种：

```json
"require": {
    "charsen/moo-scaffold": "dev-master as 3.999.0"
},
"repositories": {
    "scaffold": { "type": "path", "url": "../../moo-scaffold" }
},
"minimum-stability": "stable",
"prefer-stable": true
```

> ⚠️ 上面是**片段**，不是完整的 composer.json，要**合并**进已有文件：
> `"require"` 里**追加**这一行（整块照抄会顶掉 `laravel/framework` 等核心依赖）；
> `"repositories"` 是**新增**的键；`minimum-stability` / `prefer-stable` 两行
> Laravel 默认 composer.json 里**本来就有**，列出来只是为了说明原理，不用动。

> 为什么是 `../../moo-scaffold`？因为 Laravel 应用在 `moo-engine-skeleton/engine/` 下，
> 而 `moo-scaffold/` 与 `moo-engine-skeleton/` 同级，从 `engine/` 往上两级正好到 `wwwroot/`。
>
> 为什么是 `dev-master as 3.999.0`？path/dev 分支没有版本号，用 `as 3.999.0`
> 把 dev 分支「假装」成一个很高的稳定版本号，这样 `minimum-stability: stable` 不会拒绝它，
> 其它包对它的 `"charsen/moo-scaffold": "^3.0"` 这类版本约束也能满足。

**生产环境（vcs 私有仓库，锁 tag）** —— 部署时换成：

```json
"repositories": {
    "scaffold": { "type": "vcs", "url": "git@gitee.com:charsen/moo-scaffold.git" }
},
"require": { "charsen/moo-scaffold": "^3.0" }
```

因为仓库尚未公开，生产机需要配 Gitee **部署公钥（只读 SSH key）**才拉得到代码。
常见做法是维护两份文件：开发用
`composer.json`(path)，生产用 `composer.production.json`(vcs)，部署脚本里
`cp composer.production.json composer.json && composer install --no-dev`。

声明好之后安装：

```bash
cd engine
composer update charsen/moo-scaffold --with-all-dependencies
php artisan list | grep moo     # 看到 moo:init / moo:free / moo:api 等命令即成功
```

## 2.2 初始化 + 发布资源

```bash
php artisan moo:init "charsen"          # 写 SCAFFOLD_AUTHOR 到 .env，建 scaffold/ 目录
php artisan vendor:publish --provider="Mooeen\Scaffold\ScaffoldProvider" --tag=config
php artisan vendor:publish --provider="Mooeen\Scaffold\ScaffoldProvider" --tag=public --force
```

得到 `config/scaffold.php`（可改 route 前缀 / hosts / 各种开关）和
`public/vendor/scaffold/*`（调试工具的前端静态资源）。

> ⚠️ 用 path 模式时，改了包里的 JS/CSS，每个项目都要重新 `--tag=public --force`，
> 否则浏览器看到的还是旧资源。

## 2.3 给生成器留路由插入口 + 注册 iResource 宏

生成器会把新路由插到 `routes/admin.php` / `routes/api.php` 的标记位置，标记不能删。
新建这两个文件（Laravel 12 默认没有它们）。

先记住一个容易绕晕的对应关系——**文件名说的是「给谁用」，URL 前缀是另一回事**：

| 路由文件 | 给谁用 | 挂载的 URL 前缀 |
| --- | --- | --- |
| `routes/admin.php` | 后台管理接口 | `/api/admin` |
| `routes/api.php` | 客户端（App / 移动端）接口 | `/app` |

也就是说，名叫 `api.php` 的文件反而**不在** `/api` 前缀下；2.7 那条
`curl http://127.0.0.1:8088/api/admin/food`，落在的是 `routes/admin.php`。

> 📌 **来自第 3 / 5 章的更新**：仓库现状的这两个文件已经长大了——多了第 3 章的
> `authenticate` / `logout` / `refresh` / `me` 等登录路由，food 业务组也已包上第 5 章的
> JWT 中间件。本章时点只需要下面的最简骨架。

`routes/admin.php`：
```php
<?php declare(strict_types=1);
use Illuminate\Support\Facades\Route;

Route::get('/', static fn () => 'Hello admin api ~');

// 第 5 章会给这个 group 加上 JWT + ACL 中间件，标记行不能删
Route::group([], function () {
    // :insert_code_here:do_not_delete
});
```

`routes/api.php`：
```php
<?php declare(strict_types=1);
use Illuminate\Support\Facades\Route;

Route::get('/', static fn () => 'Hello app api ~');

// :insert_code_here:do_not_delete
```

在 `bootstrap/app.php` 的 `withRouting()` 里用 `then:` 挂载它们
（**第 3 章**会把这段换成 `using:` 并给挂载点指定中间件组，这里先用最简形式。
提前点名一个 `using:` 的副作用：换成 `using:` 后框架的 `health: '/up'` 参数会**失效**，
要手动补一条 `/up` 路由——仓库 `engine/bootstrap/app.php` 里就是这么做的）：
```php
->withRouting(
    web: __DIR__.'/../routes/web.php',
    commands: __DIR__.'/../routes/console.php',
    health: '/up',
    then: function (): void {
        Route::prefix('api/admin')->name('admin.')->group(base_path('routes/admin.php'));
        Route::prefix('app')->name('app.')->group(base_path('routes/api.php'));
    },
)
```

生成的后台控制器路由用了 `Route::iResource(...)` 宏，先注册在
`app/Providers/AppServiceProvider.php` 的 `boot()` 里（比 `Route::resource`
多了回收站 / 永久删除 / 批量删除 / 恢复四条路由）。
**预告**：第 7 章装 moo-system 时它会报错，要挪到 `register()`——那是个值得亲手踩的坑，
这里先照写：
```php
Route::macro('iResource', function (string $name, string $controller, array $options = []) {
    Route::get($name.'/trashed', $controller.'@trashed')->name($name.'.trashed');
    Route::delete($name.'/forever/{id}', $controller.'@forceDestroy')->name($name.'.forceDestroy');
    Route::delete($name.'/batch', $controller.'@destroyBatch')->name($name.'.destroyBatch');
    Route::patch($name.'/restore', $controller.'@restore')->name($name.'.restore');
    Route::resource($name, $controller, $options);
});
```

> 📌 **这个宏体后来被整个重写了**——别拿仓库代码来核对本节。最终版（见仓库
> `engine/app/Providers/AppServiceProvider.php`，commit `c5acdd1`）改成了**用反射
> 「按控制器方法真实存在且 public」逐条注册**，去掉了无条件的 `Route::resource` 和
> `$options` 参数：这套生态的控制器普遍没有 `destroy`（统一走 `destroyBatch`）、
> 部分没有 `show`/`index`，无脑 resource 会产出「幻影路由」——调用即
> `Call to undefined method`（500）。上面的简化版**足够跑通本章全部内容**；
> 第 7 章只负责把它挪进 `register()`，宏体的反射版重写是后来的复审修复、
> 不在任何章节正文里——想要终态直接抄仓库即可。

## 2.4 建调试工具的登录账号

`/scaffold` 调试后台需要登录，账号在 `scaffold/accounts.yaml`：

```bash
php artisan moo:account:add charsen --password=skeleton2026 --role=admin
```

加完**立刻验证**：起服务（`php artisan serve --host=127.0.0.1 --port=8088`，
此时还用不到调试器代理，单 worker 就行），浏览器打开
`http://127.0.0.1:8088/scaffold` 用上面的账号密码登录一次——
密码敲错现在就能发现，不用等到 2.7 才暴露。

## 2.5 设计 foods 表

```bash
php artisan moo:schema Food     # 生成 scaffold/database/Food.yaml 模板，然后编辑它
```

`scaffold/database/Food.yaml`（本章时点的形态；仓库版是**第 9 章演进后**的样子——
多了 `stock` 字段、`controller.app` 变成 `['admin', 'api']`、还新增了
`resource: ['admin']` 和 `attrs.remark`，对不上是正常的）：
```yaml
module:
    name: 食品管理
    folder: Food
tables:
    foods:
        model: { class: Food }
        controller: { app: ['admin'], class: FoodController }
        attrs: { name: 食品, desc: 存储食品基础信息（新手教程示例表） }
        index:
            id: { type: primary, fields: id }
            food_name: { type: index, fields: food_name }
        fields:
            id: { }
            food_name: { name: 名称, type: varchar, size: '2,128', unique: true }
            food_category: { name: 分类, type: tinyint, default: 1 }
            price: { name: 价格, type: int, default: 0, desc: '单位：分' }
            calories: { required: false, name: 热量, type: int, default: 0 }
            food_status: { name: 状态, type: tinyint, default: 1 }
            description: { required: false, name: 描述, type: varchar, size: 255 }
            deleted_at: { }
            created_at: { }
            updated_at: { }
        enums:
            food_category: { fruit: [1, fruit, 水果], vegetable: [2, vegetable, 蔬菜], meat: [3, meat, 肉类], staple: [4, staple, 主食] }
            food_status:   { on_shelf: [1, on shelf, 上架], off_shelf: [2, off shelf, 下架] }
```

两个新手必问的点：

- **`id: { }` / `deleted_at: { }` / `created_at: { }` 为什么是空的？** 这些是**约定字段**，
  留空即可——生成器会自动补全类型（`id` 是雪花算法主键，见 2.6 末尾的表结构）。
- **同目录还会出现一个 `scaffold/database/_fields.yaml`**——它是生成命令自动创建、
  维护的**字段翻译润色文件**（`table_fields` 随库表字段自动增、减；你手改的翻译
  不会被重新生成覆盖），**不用手写**，看到它别慌。

## 2.6 一键生成业务代码

先确认数据库连接没问题——`moo:free` 末尾要执行迁移，连不上库会失败：

```bash
php artisan migrate:status    # 能列出迁移即说明 .env 的 DB_* 配置可用（第 1 章配的）
```

然后一键生成：

```bash
php artisan moo:fresh                 # 解析 yaml 到 storage/scaffold 缓存（改完 yaml 必跑）
php artisan moo:free admin Food -a    # 生成 Model/Controller/Request/路由/i18n/ACL/迁移/API 文档
```

> `moo:free` 末尾会问「是否执行迁移」——选 **yes**。手滑选了 no 也没关系，
> 事后补一句 `php artisan migrate` 即可，否则下面 `DESCRIBE foods` 会查不到表。

生成的目录结构：
```
app/Models/Food/{Food.php, Filters/FoodFilter.php, Traits/FoodTrait.php, Enums/{FoodCategory,FoodStatus}.php}
app/Models/Traits/{UsingSnowFlakePrimaryKey.php, HasOperator.php}   # 雪花 ID 等约定 trait（自动生成）
app/Admin/Controllers/Food/{FoodController.php, Traits/FoodTrait.php}
app/Admin/Requests/Food/Food/{Index,Store,Update,Create,Edit,DestroyBatch}Request.php
app/Admin/Requests/Food/Food/FoodRequestTrait.php   # 各 Request 共用的表名/枚举值 trait（也是生成的）
# 没有单独的 FoodResource —— 控制器直接用包里的 BaseResource，这是这套架构的常态
database/migrations/*_create_foods_table.php
```

- **`Requests/Food/Food/` 双层 Food 不是生成错了**：第一层是模块目录
  （YAML 里的 `module.folder: Food`），第二层是表模型名（`Food`）——
  本例俩恰好同名才显得重复；模块目录换个名（比如 `Catalog`）就是 `Catalog/Food/`。
- 📌 **对照仓库时的两处差异**：① `app/Models/Traits/` 里仓库还有一个
  `MediaSynchronous.php`——那是**第 7 章** host 契约抄进去的，本章不会生成；
  ② 「没有单独的 FoodResource」是本章时点的事实，**第 9.9 章**为定制列表字段
  新增了 `app/Admin/Resources/Food/FoodResource.php`，`FoodController` 的
  index/show 改用了它（store/update 等仍用 BaseResource）。本章生成完没有这两样，正常。

### ⚠️ 新手会遇到的 4 个坑（本教程实测）

1. **生成的 Model 依赖两个约定包**，全新项目里没装会报
   `Trait "EloquentFilter\Filterable" not found` / 找不到 `Godruoyi\Snowflake`。装上即可：
   ```bash
   composer require "tucker-eric/eloquentfilter:^3.0" "godruoyi/php-snowflake:^3.2"
   ```
   （这俩也是 `moo-system` 的依赖，提前装好后面不冲突。）

2. **`moo:free` 不会创建共享的 `BaseActionTrait`**（它只在独立命令 `moo:controller` 里创建）。
   报 `Trait "App\Admin\Controllers\Traits\BaseActionTrait" not found` 时，跑一次：
   ```bash
   php artisan moo:controller Food -f
   ```

3. **`moo:free` 里的 `moo:auth` / `moo:api` 可能提示 “No routes matched”**，
   因为路由是同一个进程里刚插进文件的、当前路由表还没刷新。生成完单独补一次即可：
   ```bash
   php artisan moo:api admin Food
   ```

4. **接口调试器的代理会和单线程 `php artisan serve` 死锁**：调试器发请求时，后端要再向
   「自己」发一次 HTTP 代理请求，单进程服务器处理不了并发会一直转圈。解决办法是开多 worker：
   ```bash
   PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=127.0.0.1 --port=8088 --no-reload
   ```
   （必须带 `--no-reload`，否则 Laravel 只起单 worker。）

生成的 `foods` 表（注意 `id` 是非自增 bigint，留给雪花算法赋值。
`-p7777` / `moo_skeleton` 来自**第 1 章** `.env` 里的 `DB_PASSWORD` / `DB_DATABASE`，
按你自己的配置替换）：
```bash
mysql -uroot -p7777 -h127.0.0.1 moo_skeleton -e "DESCRIBE foods;"
```

## 2.7 真机调试接口（两种方式）

> **来自第 5 章的更新**：food 路由后来上了 JWT + ACL（见第 5 章），本节的无 token 玩法
> 只在「刚做完本章、还没做第 5 章」的状态下成立。已做完第 5 章的话，先按第 3 章登录
> 拿 token，下面的 curl 加上 `-H "Authorization: Bearer $TOKEN"` 即可，其余照旧。

开打之前确认服务起着，而且必须是**多 worker** 方式（坑 4，调试器代理会和单线程死锁）：

```bash
PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=127.0.0.1 --port=8088 --no-reload
```

### 方式一：命令行 curl 直接打

> **URL 为什么是单数 `food` 不是 `foods`？** 表名（复数 `foods`）只决定数据库表；
> 资源路由名取的是**模型名（单数）的小写**——`moo:free admin Food` 生成的是
> `Route::iResource('food', FoodController::class)`，所以 URL 是 `/api/admin/food`。

```bash
# 新增
curl -s -X POST http://127.0.0.1:8088/api/admin/food \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"food_name":"红富士苹果","food_category":1,"price":350,"calories":52,"food_status":1,"description":"脆甜多汁"}'
# 列表
curl -s "http://127.0.0.1:8088/api/admin/food?page=1&page_limit=10" -H "Accept: application/json"
```

返回是 moo 体系的响应约定：成功直接返回 `{data: ...}`（列表还带 `meta` 分页和 `columns` 表头），
`id` 是雪花字符串，枚举自动带 `food_category_txt` / `food_status_txt` 文案。

### 方式二：用 moo-scaffold 内置的接口调试器（浏览器真机）

先把 `config/scaffold.php` 的 hosts 开发环境指向本机：
```php
'hosts' => [
    '开发环境' => 'http://127.0.0.1:8088',
    '正式环境' => 'https://example.com',
],
```

浏览器打开 `http://127.0.0.1:8088/scaffold` 用 charsen 登录，首页能看到刚生成的 Food 模块：

![scaffold 首页](./images/02-scaffold-dashboard.png)

进「接口调试」→ 选「后台管理」→ 展开「食品管理」→ 点「食品列表」，自动按接口文档回填参数并发请求，
右侧拿到 `200` 实时响应：

![接口调试器](./images/02-api-debugger.png)

---

## 本章产出

- `moo-scaffold` 以 path 模式接入，20 个 `moo:*` 命令可用（`php artisan list | grep moo` 可数；
  「20」是**刚做完本章**、只装了 moo-scaffold 时的数——第 7 章装上 moo-system 后会更多，
  在仓库终态执行这条命令数出超过 20 是正常的）；
- 一张 `foods` 表从 YAML 设计到全套业务代码、迁移落库；
- 接口用 curl 和内置调试器两种方式真机验证通过（HTTP 200）。

下一章：**不依赖任何付费包**，用自建的最简用户把 JWT 登录认证跑通。
