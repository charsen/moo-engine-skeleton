<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Facades\Storage;

class TemporaryUploadPruner
{
    public function prune(
        string $diskName,
        string $directory,
        DateTimeInterface $olderThan,
        int $limit = 1000,
    ): int {
        $disk    = Storage::disk($diskName);
        $deleted = 0;

        if ($limit <= 0) {
            return 0;
        }

        foreach ($disk->files($directory) as $path) {
            if ($disk->lastModified($path) <= $olderThan->getTimestamp() && $disk->delete($path)) {
                $deleted++;
                if ($deleted >= $limit) {
                    break;
                }
            }
        }

        return $deleted;
    }
}
