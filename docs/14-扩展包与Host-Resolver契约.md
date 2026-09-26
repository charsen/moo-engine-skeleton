---
title: 第 14 章 扩展包与 Host 的 Resolver 契约
group: 后端骨架教程
order: 150
---
# 第 14 章　扩展包与 Host 的 Resolver 契约

目标：共享人员与组织读取能力，Host 只实现自身差异。以下说明对应当前源码的统一契约接线。

> **版本前提**：本轮契约升级尚未发布，不能把当前生产 manifest 的最低版本视为已支持以下全部行为。正式安装须等完整 moo-contract、配套 moo-system 与消费包稳定版本发布，再统一提高 Host 依赖下限。没有授权私包仓访问权时，继续使用前六章；本章不要求读者靠本机 sibling 源码完成安装。

## 14.1 职责

| 层 | 职责 |
| --- | --- |
| moo-scaffold | 当前操作人身份、HasOperator 自动写入；不查询组织业务表 |
| moo-contract | 公共 PersonnelNameResolver、OrgDirectory；零框架依赖，无 Provider、默认实现 |
| moo-system | 组织数据所有者，实现公共契约并提供可覆盖的默认绑定 |
| 业务扩展包 | 直接消费公共契约，维护自己的业务规则和响应形状 |
| Host | 身份、ACL、业务类型与项目范围；外部目录需要时显式替换公共实现 |

姓名与组织能力使用原有公共接口直接升级，不再为每个包建立同义子接口、转发适配器或 Null 姓名实现。带有实际领域差异的契约继续保留，例如骨架的 FeedbackTypeResolver。

## 14.2 批量姓名解析

```php
use Mooeen\Contract\PersonnelNameResolver;

$names = app(PersonnelNameResolver::class)->resolveNames($ids);
```

System 默认实现批量读取人员展示名，包含离职及软删历史人员；不存在的 ID 不进入返回 map。缺少契约绑定属于接入错误，应明确失败，不能用空实现掩盖。已绑定但查不到人员时，由具体响应契约决定空值或 ID 的展示方式。

列表先收集整页 ID，一次解析，再注入瞬态 `_txt` 字段；不要逐行调用，也不要给没有 accessor 的字段使用 `append()`。骨架的反馈详情和话题串直接消费这一公共姓名能力。

## 14.3 组织事实与表单选项

业务包和 Host 组织读取使用 `Mooeen\Contract\OrgDirectory`：

```php
use Mooeen\Contract\OrgDirectory;

$directory = app(OrgDirectory::class);
$postings = $directory->onJobPersonnelPostings($personnelIds);
$departmentIds = $directory->departmentDescendantIds($departmentId);
```

- `onJobPersonnelPostings(null)` 读取所有在职、未软删人员；`[]` 不查询。任职包含多部门、多岗位，无任职者保留空 postings。
- `onJobPersonnelNamesByKeyword()` 用于在职搜索候选。组织事实接口本身不排除 root。
- 可指派资格仍有独立的在册＋在职口径，不能作为候选列表的替代。
- 主部门用于归属、展示或审计快照；授权范围按实时任职关系计算。

需要 scaffold 级联 widget 的 System 消费方，直接使用 `Mooeen\System\Contracts\OrgOptions`。默认人员候选按多岗位挂人、仅在职并排除 root；显式部门模型集合保持历史回显。System 的子目录接口保留 Laravel Collection 等框架专属能力，业务包无需为此依赖 System。

## 14.4 Host 接线

安装配套 System 后，默认绑定关系为：

```text
公共 OrgDirectory → System OrgDirectory → EloquentOrgDirectory
公共 PersonnelNameResolver → 公共 OrgDirectory
System OrgOptions → System 的表单选项实现
```

这些绑定使用 `bindIf`，Host 无需复制姓名查询或另建只做转发的 Provider。骨架现有 FeedbackServiceProvider 只绑定反馈分类；操作人身份仍由 ScaffoldServiceProvider 负责。

不用 System 或需要外部目录时，Host 实现并显式绑定现有公共契约。只替换姓名时实现 PersonnelNameResolver 即可；替换组织目录时须实现其全部方法，同时升级所有实现方与消费者。不要创建第二套版本接口或用方法存在性探测回退。

Composer 只采用根项目 repositories；私包的传递依赖也须在 Host 声明可解析来源。三份 manifest 与私包元数据同步维护，见 [私包接入说明](../PRIVATE-COMPOSER-PACKAGES.md)。

## 14.5 定向验证与交付

在 `engine/` 运行：

```bash
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync php artisan test tests/Unit/Services/SharedOrganizationBindingTest.php
```

该测试使用隔离内存库，验证真实 Host 默认绑定、批量历史姓名以及显式覆盖；失败时不应通过增加 Null 实现绕过。反馈消费者的姓名断言位于 `FeedbackExampleTest::test_feedback_detail_resolves_historical_names_through_system_default_binding`，可用 `--filter` 单独执行。

公共契约升级核查四层：包源码、Host 调用、实现与绑定、测试与文档。发布顺序为完整契约 → 配套 System/消费包 → Host 依赖与接线；源码联调、稳定包安装和目标环境验收分别记录。
