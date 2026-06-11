<?php

declare(strict_types=1);
/*
 * @Author:      charsen
 * @Date:        2026-06-11 14:37:50
 * @Description: Update 食品 (foods)
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foods', static function (Blueprint $table) {
            $table->integer('stock')->unsigned()->default(0)->comment('库存')->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('foods', static function (Blueprint $table) {
            $table->dropColumn('stock');
        });
    }
};
