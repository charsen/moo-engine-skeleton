<?php

declare(strict_types=1);
/*
 * @Author: Charsen
 * @Description: Notification（host 契约：Personnel::notifications() 的 morph 关联模型）
 */

namespace App\Models;

use App\Models\Traits\MediaSynchronous;
use App\Models\Traits\UsingSnowFlakePrimaryKey;
use Illuminate\Notifications\DatabaseNotification;
use Mooeen\Scaffold\Concerns\GetSerializeDate;
use Mooeen\Scaffold\Concerns\GetUpdatedAtHumanTime;

class Notification extends DatabaseNotification
{
    use GetSerializeDate;
    use GetUpdatedAtHumanTime;
    use MediaSynchronous;
    use UsingSnowFlakePrimaryKey;

    /**
     * 属性转换
     *
     * @var array
     */
    protected $casts = [
        'id'      => 'string',
        'data'    => 'json',
        'read_at' => 'datetime',
    ];

    protected $appends = ['avatar_url'];

    public function getAvatarUrlAttribute(): ?string
    {
        return isset($this->data['causer_avatar']) ? $this->getMediaUrl($this->data['causer_avatar']) : null;
    }
}
