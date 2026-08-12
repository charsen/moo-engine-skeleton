<?php

declare(strict_types=1);

namespace App\Admin\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UploadController
{
    public function image(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:5120'],
        ]);

        $file      = $request->file('file');
        $mimeType  = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getPathname());
        $extension = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            default      => throw ValidationException::withMessages([
                'file' => '仅支持 JPEG、PNG 或 WebP 图片。',
            ]),
        };

        return $this->store($request, 'images', $extension, $mimeType);
    }

    /**
     * Minimal host upload endpoint used by the default moo-system avatar manager.
     *
     * The returned path is intentionally temporary; PersonnelAvatarManager moves
     * it into the Personnel-scoped directory when the form is submitted.
     */
    private function store(Request $request, string $folder, string $extension, string $mimeType): JsonResponse
    {
        $field = (string) $request->query('field', 'file');
        if (! preg_match('/^[A-Za-z0-9_.-]+$/', $field)) {
            $field = 'file';
        }

        $file = $request->file('file');
        $path = $file->storeAs('temp/' . $folder, (string) Str::uuid() . '.' . $extension, 'public');

        return response()->json([
            'data' => [
                'field'         => $field,
                'path'          => $path,
                'value'         => $path,
                'url'           => Storage::disk('public')->url($path),
                'original_name' => $file->getClientOriginalName(),
                'size'          => $file->getSize(),
                'mime_type'     => $mimeType,
            ],
        ]);
    }
}
