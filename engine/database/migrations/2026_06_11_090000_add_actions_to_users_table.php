<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * users 表加 actions 列：ACL Gate 契约的最小授权存储（docs 第 5 章）。
 * JSON 数组，存被授权的 acl key；'is_root' 字面量 = 超级权限。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('actions')->nullable()->after('password')->comment('ACL 授权动作（JSON 数组，is_root=超级权限）');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('actions');
        });
    }
};
