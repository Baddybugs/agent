<?php

namespace BaddyBugs\Agent\Collectors;

use BaddyBugs\Agent\BaddyBugs;
use Illuminate\Support\Facades\Event;
use Illuminate\Http\UploadedFile;

/**
 * File Upload Collector
 * 
 * Tracks file uploads:
 * - Upload sizes and counts
 * - File types and validation
 * - Processing times
 * - Storage paths
 * - Upload errors
 */
class FileUploadCollector implements CollectorInterface
{
    protected BaddyBugs $baddybugs;
    protected array $uploads = [];
    protected float $requestStart;

    public function __construct(BaddyBugs $baddybugs)
    {
        $this->baddybugs = $baddybugs;
        $this->requestStart = microtime(true);
    }

    public function boot(): void
    {
        if (!config('baddybugs.file_upload_tracking_enabled', true)) {
            return;
        }

        // Track via request terminating
        app()->terminating(function () {
            $this->collectUploads();
        });
    }

    protected function collectUploads(): void
    {
        $request = request();
        
        if (!$request->hasFile(array_keys($request->allFiles()))) {
            return;
        }

        $files = $request->allFiles();
        $uploads = [];
        $totalSize = 0;
        $totalCount = 0;

        foreach ($this->flattenFiles($files) as $fieldName => $file) {
            if ($file instanceof UploadedFile && $file->isValid()) {
                $size = $file->getSize();
                $totalSize += $size;
                $totalCount++;

                $uploadData = [
                    'field_name' => $fieldName,
                    'original_name' => $file->getClientOriginalName(),
                    'size_bytes' => $size,
                    'mime_type' => $file->getMimeType(),
                    'extension' => $file->getClientOriginalExtension(),
                    'is_image' => str_starts_with($file->getMimeType(), 'image/'),
                    'is_valid' => true,
                    'error' => null,
                ];

                // Image dimensions if image
                if ($uploadData['is_image']) {
                    try {
                        [$width, $height] = getimagesize($file->getPathname());
                        $uploadData['image_width'] = $width;
                        $uploadData['image_height'] = $height;
                        $uploadData['image_megapixels'] = round(($width * $height) / 1000000, 2);
                    } catch (\Throwable $e) {
                        // Ignore dimension errors
                    }
                }

                $uploads[] = $uploadData;
            } elseif ($file instanceof UploadedFile) {
                // Invalid upload
                $totalCount++;
                $uploads[] = [
                    'field_name' => $fieldName,
                    'original_name' => $file->getClientOriginalName(),
                    'is_valid' => false,
                    'error' => $file->getErrorMessage(),
                ];
            }
        }

        if ($totalCount > 0) {
            $this->baddybugs->record('file_upload', 'upload_batch', [
                'upload_count' => $totalCount,
                'total_size_bytes' => $totalSize,
                'total_size_mb' => round($totalSize / 1024 / 1024, 2),
                'files' => $uploads,
                'processing_time_ms' => round((microtime(true) - $this->requestStart) * 1000, 2),
                'route' => $request->route() ? $request->route()->getName() : null,
                'url' => $request->path(),
            ]);
        }
    }

    /**
     * Flatten nested file arrays
     */
    protected function flattenFiles(array $files, string $prefix = ''): array
    {
        $flattened = [];

        foreach ($files as $key => $value) {
            $fieldName = $prefix ? "{$prefix}.{$key}" : $key;

            if ($value instanceof UploadedFile) {
                $flattened[$fieldName] = $value;
            } elseif (is_array($value)) {
                $flattened = array_merge($flattened, $this->flattenFiles($value, $fieldName));
            }
        }

        return $flattened;
    }
}
