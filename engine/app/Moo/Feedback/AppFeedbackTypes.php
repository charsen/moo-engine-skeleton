<?php

declare(strict_types=1);

namespace App\Moo\Feedback;

use Mooeen\Feedback\Contracts\FeedbackTypeResolver;

/** 骨架示例的反馈分类目录；真实项目应替换成自己的业务口径。 */
final class AppFeedbackTypes implements FeedbackTypeResolver
{
    public function types(): array
    {
        return [
            'BUG'        => ['label' => '问题反馈', 'sort' => 10],
            'SUGGESTION' => ['label' => '功能建议', 'sort' => 20],
            'OTHER'      => ['label' => '其他', 'sort' => 99],
        ];
    }
}
