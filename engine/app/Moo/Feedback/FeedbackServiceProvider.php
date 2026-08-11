<?php

declare(strict_types=1);

namespace App\Moo\Feedback;

use Illuminate\Support\ServiceProvider;
use Mooeen\Feedback\Contracts\FeedbackTypeResolver;

/** moo-feedback 的 host 胶水层：业务分类留在应用，不写进通用包。 */
final class FeedbackServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FeedbackTypeResolver::class, AppFeedbackTypes::class);
    }
}
