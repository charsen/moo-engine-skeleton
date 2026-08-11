# 第 13 章　moo-feedback 扩展包用例

本章用一个真实可运行的意见反馈场景，演示通用扩展包怎样接进 host：访客可以匿名提交，后台人员登录后受理；包保留通用机制，分类和认证边界由 host 决定。

## 13.1 这个示例提供什么

- `POST /api/feedbacks`：匿名提交，成功只返回 `{"submitted": true}`，不暴露反馈 ID。
- `GET /api/feedbacks/meta`：返回 host 定义的分类、必填联系方式、蜜罐字段和内容长度。
- `/api/admin/feedbacks*`：后台受理接口，只允许通过 `moo-feedback` 独立认证组访问。
- 默认分类在 `app/Moo/Feedback/AppFeedbackTypes.php`，真实项目直接替换成自己的业务口径。
- 教学默认不保存访客 IP、设备、浏览器和页面地址；需要采集时先完成隐私评审，再按字段开启。

包已同时写入 `engine/composer.json` 与 `engine/composer.production.json`，从 Packagist 使用正式版本约束安装，不需要额外 VCS 仓库。

如果你走的是“从零跟教程搭”而不是直接使用本骨架，先在自己的 Laravel 根目录执行：

```bash
composer require charsen/moo-feedback:^0.1
php artisan vendor:publish --tag=moo-feedback-config
php artisan migrate
```

然后完成三项 host 接线：把 `FeedbackServiceProvider` 加入 `bootstrap/providers.php`；把 `Feedback` 控制器命名空间加入 `config/scaffold.php` 的 `controller.admin.extra_modules`；按本章配置独立安全组。最后从真实路由生成 ACL：

```bash
php artisan moo:auth admin
```

`config/actions.php` 是再生成区。骨架里的 moo-system 个人中心白名单属于 host 手动策略，重生 ACL 后要按第 7 章恢复并运行 `FoodAclTest`，不能为了加入 Feedback 而让原有个人中心变成 403。

## 13.2 为什么不能直接挂 `admin`

骨架的 `admin` 组需要承载 `/api/admin/authenticate` 等登录前接口，因此只指定 admin 守卫，不强制已经登录。扩展包若把管理路由直接挂到这个组，匿名请求可能到达控制器，安全边界取决于每个 action 是否恰好又做了授权检查。

正确做法是每个扩展包拥有自己的组。骨架在 `bootstrap/app.php` 中为反馈包配置了完整链：

```php
$middleware->group('moo-feedback', [
    'jwt.assign.guard:admin',
    'jwt.guard.auth:admin',
    'jwt.auth.refresh',
    'throttle:admin',
    'set.locale',
    SubstituteBindings::class,
    OperationLog::class,
]);
```

`config/moo-feedback.php` 再明确指向这个组：

```php
'admin' => [
    'prefix' => 'api/admin',
    'name' => 'admin.',
    'middleware' => 'moo-feedback',
],
```

不要为了省一段配置而指向 `moo-system`。两者即使当前中间件数组相同，也代表两个独立包的边界；后续某个包增加审计、租户或限流策略时，不会误伤另一个包。

## 13.3 host 分类目录

通用包不知道每个项目有哪些反馈类型，所以分类不写死在包内。`AppFeedbackTypes` 实现 `FeedbackTypeResolver`，再由 `FeedbackServiceProvider` 绑定：

```php
return [
    'BUG'        => ['label' => '问题反馈', 'sort' => 10],
    'SUGGESTION' => ['label' => '功能建议', 'sort' => 20],
    'OTHER'      => ['label' => '其他', 'sort' => 99],
];
```

更改分类后不需要修改包。删除已使用的历史分类前，应先决定旧数据的显示和迁移口径。

## 13.4 手工验证

在 `engine/` 运行：

```bash
php artisan migrate
PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=127.0.0.1 --port=8088 --no-reload
```

先看表单元信息：

```bash
curl -i http://127.0.0.1:8088/api/feedbacks/meta
```

再提交一条不含真实个人信息的测试数据：

```bash
curl -i -X POST http://127.0.0.1:8088/api/feedbacks \
  -H 'Content-Type: application/json' \
  -d '{"feedback_type":"SUGGESTION","feedback_content":"希望后续增加批量导出功能。","feedback_contact_name":"示例用户","feedback_email":"demo@example.test","nickname_confirm":""}'
```

后台匿名请求必须被认证层挡住：

```bash
curl -i -H 'Accept: application/json' http://127.0.0.1:8088/api/admin/feedbacks
# 预期：HTTP 401
```

完整验收不是只看 401：使用后台 token 再验证“无 Feedback ACL 返回 403、授予对应 ACL 后成功”。401 证明认证边界存在，403 才证明动作授权也生效。

## 13.5 自动验证

```bash
php artisan test --filter=FeedbackExampleTest
```

该测试同时钉住四件事：配置指向 `moo-feedback`、组内含完整 JWT 链、匿名后台请求返回 401、公开提交真实落库但不回传 ID。关闭公开入口时可把 `.env` 的 `MOO_FEEDBACK_PUBLIC=false`；这不会关闭后台管理路由。
