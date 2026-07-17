<?php

declare(strict_types=1);
/*
 * @Author: charsen
 * @Description: 自建的最简 JWT 认证主体（docs 第 3 章）。
 *
 * 这是不依赖任何付费包、跑通整套 JWT 的最小用户模型：
 * - implements JWTSubject：getJWTIdentifier 返回主键；getJWTCustomClaims 注入 guard 声明
 *   （动态跟随当前指派的守卫——JWTAssignGuard 已在路由层 Auth::shouldUse()）；
 * - actions 列 + getActions()/isRoot()：ACL Gate（acl_authentication）契约的最小实现
 *   （docs 第 5 章）；第 7 章接入 moo-system 后，admin 守卫的主体换成 Personnel
 *   （角色制授权），本模型继续作为移动端 user 守卫的主体。
 */

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Auth;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'actions',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'actions'           => 'array',
        ];
    }

    /**
     * JWT 主体标识（写进 token 的 sub 声明）
     */
    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    /**
     * 写进 token 的自定义声明。
     *
     * guard 跟随当前请求指派的守卫（JWTAssignGuard 已 shouldUse），
     * JWTGuardAuth 中间件靠它做守卫隔离；续签时由 config/jwt.php 的
     * persistent_claims=['guard'] 保住。
     */
    public function getJWTCustomClaims(): array
    {
        return ['guard' => Auth::getDefaultDriver()];
    }

    /**
     * 此用户被授权的 ACL 动作 key 列表（含 'is_root' 字面量 = 超级权限）。
     *
     * 这是 Gate 'acl_authentication' 契约的最小实现：直接存一列 JSON。
     * moo-system（第 7 章）的 Personnel 用「角色 → 动作」的完整授权体系实现同一契约。
     */
    public function getActions(): array
    {
        return $this->actions ?? [];
    }

    /**
     * 是否天然 root（自增主键体系下不启用，超级权限走 actions 里的 is_root 字面量）
     */
    public function isRoot(): bool
    {
        return false;
    }
}
