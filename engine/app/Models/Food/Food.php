<?php

declare(strict_types=1);
/*
 * @Author: charsen
 * @Date: 2026-06-05 14:18
 * @LastEditors: charsen
 * @LastEditTime: 2026-06-05 14:18
 * @Description: Food Model
 */

namespace App\Models\Food;

use App\Models\Food\Filters\FoodFilter;
use App\Models\Food\Traits\FoodTrait;
use App\Models\Traits\UsingSnowFlakePrimaryKey;
use EloquentFilter\Filterable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Mooeen\Scaffold\Concerns\GetSerializeDate;
use Mooeen\Scaffold\Concerns\GetUpdatedAtHumanTime;
use Mooeen\Scaffold\Concerns\Optional;

/**
 * Food Model
 *
 * @property int $id 编号
 * @property string $food_name 名称
 * @property int $food_category 分类
 * @property int $price 价格
 * @property int $stock 库存
 * @property int $calories 热量
 * @property int $food_status 状态
 * @property string $description 描述
 * @property Carbon|null $deleted_at 删除于
 * @property Carbon|null $created_at 创建于
 * @property Carbon|null $updated_at 更新于
 *
 * @method select(array $fields)
 * @method query()
 */
class Food extends Model
{
    use Filterable;
    use FoodTrait;
    use GetSerializeDate;
    use GetUpdatedAtHumanTime;
    use Optional;
    use SoftDeletes;
    use UsingSnowFlakePrimaryKey;

    /**
     * 表格名称
     *
     * @var string
     */
    protected $table = 'foods';

    /**
     * 指定字段默认值
     *
     * @var array
     */
    protected $attributes = [
        'food_category' => 1,
        'price' => 0,
        'stock' => 0,
        'calories' => 0,
        'food_status' => 1,
    ];

    /**
     * 属性转换
     *
     * @var array
     */
    protected $casts = [
        'id' => 'string',
    ];

    /**
     * 可以被批量赋值的属性
     *
     * @var array
     */
    protected $fillable = ['food_name', 'food_category', 'price', 'stock', 'calories', 'food_status', 'description'];

    /**
     * 数组中的属性会被隐藏
     *
     * @var array
     */
    protected $hidden = [];

    /**
     * 追加到模型数组表单的访问器
     *
     * @var array
     */
    protected $appends = ['food_category_txt', 'food_status_txt'];

    /**
     * 指定 Filter
     */
    public function modelFilter(): string
    {
        return FoodFilter::class;
    }
}
