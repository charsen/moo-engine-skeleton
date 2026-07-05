<?php

declare(strict_types=1);

namespace App\Admin\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UploadController
{
    public function image(Request $request): JsonResponse
    {
        return $this->store($request, 'images', ['image', 'max:5120']);
    }

    public function file(Request $request): JsonResponse
    {
        return $this->store($request, 'files', ['file', 'max:10240']);
    }

    /**
     * Minimal host upload endpoint used by moo-system form widgets.
     *
     * The returned path is intentionally temporary; UploaderTrait::saveUploadFile()
     * moves it into the target business folder when the form is submitted.
     *
     * @param  array<int, string>  $rules
     */
    private function store(Request $request, string $folder, array $rules): JsonResponse
    {
        $request->validate([
            'file' => ['required', ...$rules],
        ]);

        $field = (string) $request->query('field', 'file');
        if (! preg_match('/^[A-Za-z0-9_.-]+$/', $field)) {
            $field = 'file';
        }

        $file = $request->file('file');
        $extension = $file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin';
        $path = $file->storeAs('tmp/'.$folder, (string) Str::uuid().'.'.$extension, 'public');

        return response()->json([
            'data' => [
                'field' => $field,
                'path' => $path,
                'value' => $path,
                'url' => Storage::disk('public')->url($path),
                'original_name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
            ],
        ]);
    }
}
