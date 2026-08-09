<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('failed_jobs') && ! Schema::hasTable('job_failed')) {
            Schema::rename('failed_jobs', 'job_failed');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('job_failed') && ! Schema::hasTable('failed_jobs')) {
            Schema::rename('job_failed', 'failed_jobs');
        }
    }
};
