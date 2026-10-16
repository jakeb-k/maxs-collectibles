<?php

namespace App\Services;

use App\Models\Image;
use App\Models\OrderProduct;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductImageService
{
    /** Fully decode before associating files. Writes are staged at unguessable final paths. */
    public function stage(array $files, array $metadata = []): array
    {
        $staged = [];
        $written = [];
        $disk = config('commerce.public_disk');
        try {
            foreach ($files as $index => $file) {
                $staged[] = $this->stageOne($file, $metadata[$index] ?? [], $disk, $index, $written);
            }
            return $staged;
        } catch (\Throwable $error) {
            $this->deletePaths($disk, $written);
            throw $error;
        }
    }

    private function stageOne(UploadedFile $file, array $metadata, string $disk, int $index, array &$written): array
    {
        $info = @getimagesize($file->getRealPath());
        $types = [IMAGETYPE_JPEG => ['jpg', 'image/jpeg'], IMAGETYPE_PNG => ['png', 'image/png'], IMAGETYPE_WEBP => ['webp', 'image/webp']];
        if (!$info || !isset($types[$info[2]]) || $info[0] < 1 || $info[1] < 1 || max($info[0], $info[1]) > 12000 || $file->getSize() > 20 * 1024 * 1024) {
            throw ValidationException::withMessages(["images.$index" => 'Use a valid JPEG, PNG or WebP up to 20MB and 12000px.']);
        }
        // Avoid fatal decoder OOM on constrained hosts; reject before any allocation/storage.
        $limit = ini_parse_quantity(ini_get('memory_limit'));
        $estimated = $info[0] * $info[1] * 8 + $file->getSize() * 2 + 32 * 1024 * 1024;
        if ($limit > 0 && memory_get_usage(true) + $estimated > $limit) {
            throw ValidationException::withMessages(["images.$index" => 'This image exceeds the available decoder memory. Upload a smaller image.']);
        }
        $bytes = file_get_contents($file->getRealPath());
        set_error_handler(fn ($severity, $message) => throw new \ErrorException($message, 0, $severity));
        try {
            $source = imagecreatefromstring($bytes);
            if (!$source) throw new \RuntimeException('Image decode failed.');
        } catch (\Throwable $error) {
            throw ValidationException::withMessages(["images.$index" => 'The image could not be fully decoded.']);
        } finally {
            restore_error_handler();
        }
        try {
            [$extension, $mime] = $types[$info[2]];
            $directory = 'products/'.Str::uuid();
            $path = $directory.'/original.'.$extension;
            $this->put($disk, $path, $bytes, $written);
            $derivatives = [];
            foreach ([480, 960, 1600] as $bound) {
                $ratio = min(1, $bound / imagesx($source), $bound / imagesy($source));
                $width = max(1, (int) round(imagesx($source) * $ratio));
                $height = max(1, (int) round(imagesy($source) * $ratio));
                // No cropping, tinting or aesthetic filters; preserve alpha and actual aspect ratio.
                $target = imagecreatetruecolor($width, $height);
                try {
                    imagealphablending($target, false); imagesavealpha($target, true);
                    imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));
                    ob_start();
                    try { $encoded = imagewebp($target, null, 90); $contents = ob_get_contents(); }
                    finally { ob_end_clean(); }
                    if (!$encoded || !$contents) throw new \RuntimeException('Photo derivative encoding failed.');
                    $variant = $directory.'/'.$bound.'.webp';
                    $this->put($disk, $variant, $contents, $written);
                    $derivatives[] = ['file_path' => $variant, 'width' => $width, 'height' => $height, 'mime_type' => 'image/webp'];
                } finally { unset($target); }
                if ($ratio === 1) break;
            }
            return ['file_name' => 'original.'.$extension, 'file_path' => $path, 'disk' => $disk,
                'file_size' => strlen($bytes), 'mime_type' => $mime, 'width' => imagesx($source), 'height' => imagesy($source),
                'alt_text' => $metadata['alt_text'] ?? null, 'caption' => $metadata['caption'] ?? null,
                'derivatives' => $derivatives];
        } finally { unset($source); }
    }

    private function put(string $disk, string $path, string $bytes, array &$written): void
    {
        $written[] = $path;
        if (!Storage::disk($disk)->put($path, $bytes)) throw new \RuntimeException('Photo storage failed.');
    }

    public function cleanStaged(array $staged): void
    {
        foreach ($staged as $image) {
            $this->deletePaths($image['disk'], array_merge([$image['file_path']], array_column($image['derivatives'], 'file_path')));
        }
    }

    /** Runs only after association commit, preserving anything referenced by a purchase. */
    public function removeUnreferenced(Image $image): void
    {
        if (DB::table('imageables')->where('image_id', $image->id)->exists()) return;
        $paths = array_merge([$image->file_path], array_column($image->derivatives ?? [], 'file_path'));
        $referenced = OrderProduct::withTrashed()->where('image_disk', $image->disk)->whereIn('image_path', $paths)->exists();
        if (!$referenced) $this->deletePaths($image->disk, $paths);
        $image->delete();
    }

    private function deletePaths(string $disk, array $paths): void
    {
        // Never attempt deletion of inherited remote URLs or unrelated files.
        $paths = array_values(array_filter($paths, fn ($path) => preg_match('~^products/[0-9a-f-]{36}/[^/]+$~D', $path)));
        if ($paths && !Storage::disk($disk)->delete($paths)) {
            Log::error('Photo cleanup failed', ['disk' => $disk, 'paths' => $paths]);
            throw new \RuntimeException('Photo cleanup failed; inspect storage logs.');
        }
    }
}
