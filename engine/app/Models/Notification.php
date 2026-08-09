<?php

declare(strict_types=1);
/*
 * @Author: Charsen
 * @Description: Notification（host 契约：Personnel::notifications() 的 morph 关联模型）
 */

namespace App\Models;

use App\Models\Traits\MediaSynchronous;
use Illuminate\Notifications\DatabaseNotification;
use Mooeen\Scaffold\Concerns\GetSerializeDate;
use Mooeen\Scaffold\Concerns\GetUpdatedAtHumanTime;
use Mooeen\Scaffold\Concerns\UsingSnowFlakePrimaryKey;

/**
 * $notifications = \Auth::user()->notifications()->paginate($request->size);
 * $user->notifications()->get() // 获取所有的通知
 * $user->readNotifications()->get() // 获取已读
 * $user->unreadNotifications()->get() // 获取未读
 * $user->unreadNotifications->markAsRead() // 将未读标记为已读
 * $user->readNotifications->markAsUnread() // 将已读标记为未读
 */
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
