<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * ImageService: High-Performance Image Optimization & MinIO S3 Asset Management.
 *
 * Automatically converts incoming raster image uploads (PNG, JPEG, GIF, BMP)
 * to next-generation .webp format with alpha transparency preservation,
 * and persists to MinIO S3 bucket (with transparent local storage fallback).
 */
class ImageService
{
    /**
     * Convert any supported raster image (file, binary, or Base64 data URL) into WebP format.
     *
     * @param UploadedFile|string $imageSource
     * @param int $quality Compression quality (1-100, default: 85)
     * @return string|null WebP binary bytes, or null on unsupported/corrupted format
     */
    public function convertToWebp(mixed $imageSource, int $quality = 85): ?string
    {
        $rawBytes = null;

        if ($imageSource instanceof UploadedFile) {
            $realPath = $imageSource->getRealPath();
            if ($realPath && file_exists($realPath)) {
                $rawBytes = file_get_contents($realPath);
            }
        } elseif (is_string($imageSource)) {
            if (str_starts_with($imageSource, 'data:image/')) {
                $commaPos = strpos($imageSource, ',');
                if ($commaPos !== false) {
                    $rawBytes = base64_decode(substr($imageSource, $commaPos + 1));
                }
            } else {
                $rawBytes = $imageSource;
            }
        }

        if (empty($rawBytes)) {
            return null;
        }

        // If GD is unavailable, check if already valid WebP binary
        if (!function_exists('imagecreatefromstring')) {
            if ($this->isWebpBinary($rawBytes)) {
                return $rawBytes;
            }
            return null;
        }

        // Suppress GD warnings on invalid/corrupted binary
        $image = @imagecreatefromstring($rawBytes);
        if ($image === false) {
            // Check if already a valid WebP file that GD couldn't decode
            if ($this->isWebpBinary($rawBytes)) {
                return $rawBytes;
            }
            return null;
        }

        // Ensure palette images (e.g. 8-bit PNG / GIF) are promoted to truecolor
        if (!imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        // Preserve full alpha transparency channel for PNG and WebP
        imagealphablending($image, false);
        imagesavealpha($image, true);

        // Render to WebP memory stream
        ob_start();
        $success = imagewebp($image, null, max(1, min(100, $quality)));
        $webpBytes = ob_get_clean();
        imagedestroy($image);

        return $success && !empty($webpBytes) ? $webpBytes : null;
    }

    /**
     * Store user avatar: Converts image to .webp and uploads to MinIO S3 object storage.
     *
     * @param UploadedFile|string $file Uploaded file or base64 data URL
     * @param int|string $userId User identifier for namespacing
     * @param string|null $oldAvatarUrl Optional previous avatar URL to garbage-collect
     * @return string Publicly accessible URL of the uploaded .webp avatar
     * @throws \RuntimeException If storage fails completely
     */
    public function storeAvatar(mixed $file, int|string $userId, ?string $oldAvatarUrl = null): string
    {
        $webpBytes = $this->convertToWebp($file);

        if ($webpBytes !== null) {
            $filename = 'avatars/avatar_' . $userId . '_' . Str::random(16) . '.webp';
            $mimeType = 'image/webp';
            $contentToUpload = $webpBytes;
        } else {
            // Fallback for non-raster assets (e.g. SVG) or if conversion returned null
            if ($file instanceof UploadedFile) {
                $ext = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'png');
                $filename = 'avatars/avatar_' . $userId . '_' . Str::random(16) . '.' . $ext;
                $mimeType = $file->getMimeType() ?: 'application/octet-stream';
                $contentToUpload = file_get_contents($file->getRealPath());
            } elseif (is_string($file) && str_starts_with($file, 'data:image/svg+xml')) {
                $filename = 'avatars/avatar_' . $userId . '_' . Str::random(16) . '.svg';
                $mimeType = 'image/svg+xml';
                $commaPos = strpos($file, ',');
                $contentToUpload = $commaPos !== false ? base64_decode(substr($file, $commaPos + 1)) : $file;
            } else {
                throw new \RuntimeException('Failed to process or convert the uploaded image to WebP.');
            }
        }

        $url = $this->uploadToMinio($contentToUpload, $filename, $mimeType);

        // Clean up previous avatar if replaced successfully
        if ($oldAvatarUrl && $oldAvatarUrl !== $url) {
            $this->deleteAvatar($oldAvatarUrl);
        }

        return $url;
    }

    /**
     * Upload binary asset to MinIO S3 with graceful local storage fallback.
     */
    public function uploadToMinio(string $binaryContent, string $path, string $mimeType = 'image/webp'): string
    {
        // 1. Try MinIO S3 Storage
        try {
            $disk = Storage::disk('minio');
            $stored = $disk->put($path, $binaryContent, [
                'visibility'  => 'public',
                'mimetype'    => $mimeType,
                'ContentType' => $mimeType,
            ]);

            if ($stored) {
                return $disk->url($path);
            }
        } catch (\Throwable $e) {
            Log::warning('[ImageService] MinIO S3 upload failed, falling back to local disk: ' . $e->getMessage());
        }

        // 2. Local Public Disk Fallback
        try {
            $diskLocal = Storage::disk('public');
            $diskLocal->put($path, $binaryContent);
            return '/storage/' . ltrim($path, '/');
        } catch (\Throwable $e) {
            Log::error('[ImageService] Failed to store image locally: ' . $e->getMessage());
            throw new \RuntimeException('Failed to store image in object storage.');
        }
    }

    /**
     * Delete an avatar from MinIO or local storage.
     */
    public function deleteAvatar(?string $avatarUrl): bool
    {
        if (empty($avatarUrl)) {
            return false;
        }

        try {
            // MinIO S3 path extraction: e.g. http://localhost:9000/codebridge-auth/avatars/...
            $bucketName = config('filesystems.disks.minio.bucket', 'codebridge-auth');
            if (str_contains($avatarUrl, '/' . $bucketName . '/')) {
                $parts = explode('/' . $bucketName . '/', $avatarUrl);
                $relativeKey = end($parts);
                if (!empty($relativeKey)) {
                    return Storage::disk('minio')->delete($relativeKey);
                }
            }

            // Local storage path extraction: e.g. /storage/avatars/...
            if (str_starts_with($avatarUrl, '/storage/')) {
                $relativeKey = substr($avatarUrl, strlen('/storage/'));
                return Storage::disk('public')->delete($relativeKey);
            }
        } catch (\Throwable $e) {
            Log::warning('[ImageService] Failed to delete old avatar: ' . $e->getMessage());
        }

        return false;
    }

    /**
     * Helper to verify if raw bytes constitute a WebP image.
     */
    protected function isWebpBinary(string $bytes): bool
    {
        return strlen($bytes) >= 12
            && str_starts_with($bytes, 'RIFF')
            && substr($bytes, 8, 4) === 'WEBP';
    }
}

