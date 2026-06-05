<?php

declare(strict_types=1);
/*
 * @Author: Charsen
 * @Description: 多媒体文件处理（host 契约：moo-system 的 Personnel 模型依赖 getMediaUrl）
 */

namespace App\Models\Traits;

use Illuminate\Support\Facades\Storage;

trait MediaSynchronous
{
    /**
     * 获取多媒体的完整 url 地址
     */
    public function getMediaUrl($field_txt = ''): ?string
    {
        if ($field_txt === null || $field_txt === '') {
            return null;
        }

        $disk = 'public';

        // 支持「disk::path」写法（如七牛云）
        if (str_contains($field_txt, '::')) {
            [$disk, $field_txt] = explode('::', $field_txt);
            $disk = config('filesystems.qiniu_to_browse') ? $disk : 'public';
        }

        // 本地开发环境直接走 public 盘
        if (app()->isLocal()) {
            $disk = 'public';
        }

        return Storage::disk($disk)->url($field_txt);
    }
}
