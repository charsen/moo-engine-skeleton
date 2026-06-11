# 第 4 章　真机调试 moo-system 接口

目标：登录拿到 JWT，验证「无 token 401 / 有 token 200」，并在 **moo-scaffold 内置调试器**里
带 token 调通 moo-system 的接口（README 第 4 步）。

> 记得用多 worker 启动服务（否则调试器代理会和单线程服务死锁，见第 2 章第 4 个坑）：
> ```bash
> PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=127.0.0.1 --port=8088 --no-reload
> ```

---

## 4.1 命令行联调（curl）

### 登录拿 token

```bash
curl -s -X POST http://127.0.0.1:8088/api/admin/authenticate \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"account":"13800000000","password":"admin888"}'
```

返回：

```json
{"data":{"user":{"id":"615920788075319296","real_name":"管理员","login_times":1},
 "token":"eyJ0eXAiOiJKV1Qi...","expires_in":172800}}
```

把 token 存起来后续用：

```bash
TOKEN=$(curl -s -X POST http://127.0.0.1:8088/api/admin/authenticate \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"account":"13800000000","password":"admin888"}' \
  | sed -n 's/.*"token":"\([^"]*\)".*/\1/p')
```

### 无 token → 401

```bash
curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8088/api/admin/departments -H "Accept: application/json"
# 401
```

### 有 token → 200

```bash
# 当前登录人
curl -s http://127.0.0.1:8088/api/admin/me/info -H "Accept: application/json" -H "Authorization: Bearer $TOKEN"
# {"data":{"user":{"id":"615920788075319296","real_name":"管理员","mobile":"13800000000"}}}

# 部门列表（树状 label-value）
curl -s "http://127.0.0.1:8088/api/admin/departments?page=1&page_limit=10" \
  -H "Accept: application/json" -H "Authorization: Bearer $TOKEN"

# 新建岗位（完整 CRUD 验证）。注意：「后端工程师」第 3 章 seeder 已建过，
# position_name 有唯一校验，重名会 422——换个 seeder 里没有的名字
curl -s -X POST http://127.0.0.1:8088/api/admin/positions \
  -H "Accept: application/json" -H "Content-Type: application/json" -H "Authorization: Bearer $TOKEN" \
  -d '{"position_name":"测试工程师"}'
# 201 {"data":{"position_status":7,"position_name":"后端工程师","id":"615921207845457920",...}}
```

至此 JWT 的「签发 / 守卫校验 / 强制认证」与 moo-system 的接口都在真机跑通。

## 4.2 在 moo-scaffold 调试器里测 moo-system（README 第 4 步）

要让 moo-system 的接口出现在 scaffold 的调试器/文档里，需要把它的控制器登记进 scaffold——
改 `config/scaffold.php` 的 `controller.admin.extra_modules`：

```php
'extra_modules' => [
    'System' => 'Mooeen\\System\\Http\\Controllers\\Admin',
],
```

然后生成 moo-system 的接口文档：

```bash
php artisan moo:api admin System
```

刷新 `http://127.0.0.1:8088/scaffold/api/request?app=admin`，左侧除了「食品管理」，
现在多了「系统管理」整组（部门 / 岗位 / 人员 / 角色 / 授权 / 通知机器人 / 登录 / 操作日志 / 个人信息）：

![调试器里出现系统管理模块](./images/03-system-debugger-list.png)

点开「岗位管理 → 岗位列表」，直接发会得到 401（没带 token）。在 Header 区把
**Authorization 的值填成 `Bearer <你的 token>`**（注意一定要带 `Bearer ` 前缀，否则报
`The token could not be parsed from the request`），再点「发送」：

![带 token 调通岗位列表 200](./images/04-system-positions-200.png)

右侧拿到 `200`，响应里就是刚才用 curl 建的「后端工程师」岗位——
moo-system 的接口在 scaffold 调试器里联调成功。

> ⚠️ **第 4 个坑**：调试器 Authorization 那栏要手动加 `Bearer ` 前缀；只填裸 token 会 401。

---

## 本章产出

- 登录 / `me/info` / 部门列表 / 岗位 CRUD 全部命令行真机通过；
- 「无 token 401、有 token 200」鉴权行为符合预期；
- moo-system 接口已登记进 scaffold，并在内置调试器里带 token 调通（200）。

到这里，README 设定的从 0 到「带基础系统管理模块 + JWT 的 Laravel 12 骨架」全部完成。
