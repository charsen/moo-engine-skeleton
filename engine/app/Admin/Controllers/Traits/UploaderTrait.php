<?php

declare(strict_types=1);
/*
 * @Author: Charsen
 * @Description: 上传工具（host 契约：moo-system 的 Personnel/Admin 控制器依赖它）
 *
 * 骨架版：只实现 moo-system 实际调用到的方法（getUploadImageControl / saveUploadFile），
 * 并配套最小 UploadController；不引入完整版上传体系特有的 Job / Attachment 依赖。
 * 真正接入对象存储/七牛时，按业务替换 saveUploadFile 的实现即可。
 */

namespace App\Admin\Controllers\Traits;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

trait UploaderTrait
{
    /**
     * 图片上传表单控件
     */
    protected function getUploadImageControl(string $field, int $width = 320, int $height = 320): array
    {
        return [
            'type'   => 'upload-image',
            'width'  => $width,
            'height' => $height,
            'upload' => $this->uploadUrl($field),
        ];
    }

    /**
     * 文件上传表单控件
     */
    protected function getUploadFileControl(string $upload_field = 'file_path', string $field = 'file_path', string $label = '附件文件', bool $drag = true): array
    {
        return [
            'field'  => $field,
            'label'  => $label,
            'type'   => 'upload-file',
            'drag'   => $drag,
            'upload' => $this->uploadUrl($upload_field, 'file'),
        ];
    }

    /**
     * 上传地址（骨架配套最小 UploadController；接入对象存储后可改这里）
     */
    protected function uploadUrl(string $field, string $action = 'image'): string
    {
        return 'api/admin/upload/' . $action . '?field=' . $field;
    }

    /**
     * 保存上传的文件
     *
     * 约定：$validated[$field] 是「已上传到临时目录的相对路径」。骨架做法是把它从临时目录
     * 移动到正式目录并回写模型；字段为空则直接跳过。生产可换成队列/对象存储实现。
     */
    protected function saveUploadFile($model, $validated, string $field, string $folder): void
    {
        if (! isset($validated[$field]) || empty($validated[$field])) {
            return;
        }

        $temp = (string) $validated[$field];
        $disk = Storage::disk('public');

        // 已经在正式目录下，无需移动
        if (str_starts_with($temp, $folder . '/')) {
            return;
        }

        if ($disk->exists($temp)) {
            $target = $folder . '/' . basename($temp);
            $disk->makeDirectory($folder);
            $disk->move($temp, $target);
            $model->forceFill([$field => $target])->saveQuietly();
        }
    }

    /**
     * 保存附件（骨架版与 saveUploadFile 同义）
     */
    protected function saveAttachmentFile($model, $validated, string $field, string $folder): bool
    {
        if (! isset($validated[$field]) || empty($validated[$field])) {
            return false;
        }

        $this->saveUploadFile($model, $validated, $field, $folder);

        return true;
    }

    /**
     * 文件信息
     */
    protected function getUploadFileInfo(string $file_path): array
    {
        $path      = Storage::disk('public')->path($file_path);
        $extension = File::extension($path);

        return [
            'file_size'   => File::size($path),
            'mime_type'   => File::mimeType($path),
            'format_name' => $extension,
        ];
    }
}
