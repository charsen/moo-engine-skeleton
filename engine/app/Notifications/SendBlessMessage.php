<?php

declare(strict_types=1);
/*
 * @Author: Charsen
 * @Description: 发送祝福消息（host 契约：moo-system NotifyRobotController::bless 用）
 *
 * 骨架版：不绑定具体的钉钉/企微广播通道，via() 返回空数组（不实际推送），
 * 保证 notify() 调用链不报错。接入真实机器人时补 via() 与对应 Channel 即可。
 */

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Mooeen\System\Models\NotifyRobot;

class SendBlessMessage extends Notification
{
    public function __construct(public NotifyRobot $robot) {}

    /**
     * 投递通道（骨架里留空，不实际推送）
     */
    public function via(mixed $notifiable): array
    {
        return [];
    }

    public function getRobot(): NotifyRobot
    {
        return $this->robot;
    }

    /**
     * 渠道契约：DingTalkRobotChannel / WeWorkRobotChannel 的 send() 取的是这两个方法，
     * 不是 toArray()。骨架里 via() 为空、调不到，接入真实 Channel 后就是投递入口。
     */
    public function toDingTalk(mixed $notifiable = null): array
    {
        return $this->toArray($notifiable);
    }

    public function toWeWork(mixed $notifiable = null): array
    {
        return $this->toArray($notifiable);
    }

    public function toArray(mixed $notifiable): array
    {
        return [
            'type'    => 'text',
            'content' => '愿你被这个世界温柔以待。',
        ];
    }
}
