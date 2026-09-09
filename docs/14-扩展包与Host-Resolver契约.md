---
title: 第 14 章 扩展包与 Host 的 Resolver 契约
group: 后端骨架教程
order: 150
---
# 第 14 章　扩展包与 Host 的 Resolver 契约

目标：让扩展包保持独立，同时由 Host 组合自己的人员、组织或业务目录能力。本文以“把 `creator_id/updater_id` 显示为姓名”为例，演示典型 resolver 接入。

## 14.1 三层职责

| 层 | 负责 | 不负责 |
|---|---|---|
| moo-scaffold | 当前操作人 ID、`HasOperator` 自动写入等通用机制 | 不读取 Personnel 或业务数据库 |
| 业务扩展包 | 保存 ID、定义窄 resolver 契约、提供 Null 默认、批量注入展示字段 | 不依赖 moo-system，不认识 Host 模型 |
| Host | 绑定 resolver，把扩展包契约适配到本项目人员体系 | 不修改 vendor 包源码 |

使用 moo-system 的 Host 还可以注入 `Mooeen\System\Contracts\OrgDirectory`。数据库查询由拥有 `Personnel` 的 moo-system 完成；未安装 moo-system 的 Host 可以从自己的用户表、LDAP 或外部目录实现同一扩展包契约。

## 14.2 扩展包自持窄契约

扩展包只声明自己需要的最小能力：

```php
namespace Mooeen\Certificate\Contracts;

interface OperatorNameResolver
{
    /** @return array<int|string, ?string> */
    public function resolveNames(array $ids): array;
}
```

包 Provider 用 `bindIf()` 注册 Null 默认实现。Null 返回空 map；展示层找不到姓名时回退原始 ID，保证未接入新契约的旧 Host 行为不变。

列表必须收集当前页全部 `creator_id/updater_id` 后只调用一次 `resolveNames()`，再通过 `setAttribute('creator_id_txt', ...)` 注入瞬态字段。不要逐行查询，也不要用 `append()`：后者会寻找并不存在的 Eloquent accessor。

## 14.3 moo-system 提供组织目录

`OrgDirectory` 是 moo-system 对外统一的组织目录只读契约，覆盖人员、部门与岗位查询：

```php
use Mooeen\System\Contracts\OrgDirectory;

$names = app(OrgDirectory::class)->resolveNames($ids);
```

默认实现批量查询 `Personnel::withTrashed()`，让离职或软删人员的历史记录仍能显示姓名。业务扩展包不能直接引用这个契约；只有 Host 胶水层负责把两边组合起来。

## 14.4 Host 合一实现

多个扩展包 resolver 签名相同时，Host 可以用一个类同时实现：

```php
namespace App\Moo\Support;

use Mooeen\Banner\Contracts\OperatorNameResolver as BannerOperatorNameResolver;
use Mooeen\Certificate\Contracts\OperatorNameResolver as CertificateOperatorNameResolver;
use Mooeen\System\Contracts\OrgDirectory;

final class PersonnelNameResolver implements BannerOperatorNameResolver, CertificateOperatorNameResolver
{
    public function __construct(private readonly OrgDirectory $org) {}

    public function resolveNames(array $ids): array
    {
        return $this->org->resolveNames($ids);
    }
}
```

契约仍属于各扩展包，只有实现合一。随后在每个包自己的 Host provider 中绑定：

```php
$this->app->bind(
    \Mooeen\Certificate\Contracts\OperatorNameResolver::class,
    \App\Moo\Support\PersonnelNameResolver::class,
);
```

Provider 放在 `App\Moo\Certificate`，并登记到 `bootstrap/providers.php`。不要把所有包的绑定堆进 `AppServiceProvider`，也不要让一个扩展包直接依赖另一个扩展包。

## 14.5 验证清单

1. 包测试：未绑定 resolver 时仍返回原始 ID；绑定 stub 后返回姓名；整页只批量解析一次。
2. 架构检查：扩展包 `src/` 与 Composer manifest 中没有 moo-system、Personnel 或 Host `App\*`。
3. Host 测试：真实创建记录后，列表/详情的 `_txt` 字段等于当前人员姓名。
4. 软删人员：历史记录仍能通过 `OrgDirectory` 解析姓名。
5. 多 Host：没有接入 moo-system 的消费者仍能以自己的目录实现 resolver，或继续使用包的 ID 回退。
6. 发版顺序：先发布提供目录能力的 moo-system，再发布扩展包，最后更新 Host 约束与绑定。

`PersonnelDirectory` 已移除且没有兼容别名，升级到提供 `OrgDirectory` 的 moo-system 版本时必须同步修改 Host 注入点。

## 14.6 常见错误

- 在扩展包中直接 `DB::table('system_personnels')`：硬编码了别的包的数据结构。
- 把 Personnel 查询放进 moo-scaffold：工具包越权读取业务数据库。
- 每行调用一次 resolver：制造列表 N+1。
- Null resolver 返回异常：未接入契约的 Host 被迫同步升级。
- 只隐藏前端 ID：接口仍没有姓名，其他消费者继续显示裸值。

这套模式不只适用于人员姓名，也适用于 Host 私有分类目录、组织树、业务对象标题和跨包事件联动：扩展包提供窄缝，Host 负责组合，真正拥有数据的一侧提供查询能力。
