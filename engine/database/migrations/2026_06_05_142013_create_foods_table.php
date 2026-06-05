<?php declare(strict_types=1);
/*
 * @Author:      charsen
 * @Date:        2026-06-05 14:20:13
 * @Description: Create 食品 (foods)
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foods', static function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('food_name', 128)->comment('名称');
            $table->tinyInteger('food_category')->unsigned()->default(1)->comment('分类');
            $table->integer('price')->unsigned()->default(0)->comment('价格');
            $table->integer('calories')->unsigned()->nullable()->default(0)->comment('热量');
            $table->tinyInteger('food_status')->unsigned()->default(1)->comment('状态');
            $table->string('description', 255)->nullable()->comment('描述');
            $table->softDeletes();
            $table->timestamps();
            $table->index('food_name', 'food_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foods');
    }
};
