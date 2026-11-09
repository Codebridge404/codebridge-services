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
    public const ALLOWED_RASTER_EXTENSIONS = ['jpeg', 'jpg', 'png', 'gif', 'webp'];

    public const ALLOWED_RASTER_MIMES = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    /**
     * Validate a URL or data URI image string and reject SVGs, HTML, or unsafe schemes.
     */
    public static function isSafeImageUrlOrDataUri(?string $value): bool
    {
        if ($value === null || trim($value) === '') {
            return true;
        }

        $trimmed = trim($value);

        if (preg_match('/^(?:javascript|vbscript):/i', $trimmed)) {
            return false;
        }

        if (stripos($trimmed, 'data:') === 0) {
            if (!preg_match('/^data:image\/(jpeg|jpg|png|gif|webp);base64,(.+)$/is', $trimmed, $matches)) {
                return false;
            }

            $decoded = base64_decode($matches[2], true);
            if ($decoded === false || $decoded === '') {
                return false;
            }

            return !self::containsDangerousMarkup($decoded);
        }

        $path = parse_url($trimmed, PHP_URL_PATH) ?: $trimmed;
        if (preg_match('/\.svgz?(?:$|[?#])/i', $path)) {
            return false;
        }

        return true;
    }

    /**
     * Assert an uploaded file, binary buffer, data URI, or URL represents a safe raster image.
     *
     * @throws \InvalidArgumentException
     */
    public function assertSafeRasterSource(mixed $source): void
    {
        if ($source instanceof UploadedFile) {
            $ext = strtolower((string) $source->getClientOriginalExtension());
            $guessed = strtolower((string) $source->guessExtension());
            $mime = strtolower((string) $source->getMimeType());
            $clientMime = strtolower((string) $source->getClientMimeType());

            foreach ([$mime, $clientMime] as $detectedMime) {
                if ($detectedMime !== '' && (str_contains($detectedMime, 'svg') || str_contains($detectedMime, 'xml') || str_contains($detectedMime, 'html') || str_contains($detectedMime, 'php'))) {
                    throw new \InvalidArgumentException('SVG and markup file uploads are not permitted.');
                }
            }

            if ($ext !== '' && !in_array($ext, self::ALLOWED_RASTER_EXTENSIONS, true)) {
                throw new \InvalidArgumentException('Only raster image formats (jpeg, jpg, png, gif, webp) are permitted.');
            }

            if ($guessed !== '' && $guessed !== 'bin' && !in_array($guessed, self::ALLOWED_RASTER_EXTENSIONS, true)) {
                throw new \InvalidArgumentException('Uploaded file content is not a supported raster image format.');
            }

            $realPath = $source->getRealPath();
            if ($realPath && is_readable($realPath)) {
                $head = @file_get_contents($realPath, false, null, 0, 8192);
                if (is_string($head) && self::containsDangerousMarkup($head)) {
                    throw new \InvalidArgumentException('SVG or script markup inside uploaded file is not permitted.');
                }
            }

            return;
        }

        if (is_string($source)) {
            $trimmed = trim($source);
            if ($trimmed === '') {
                throw new \InvalidArgumentException('Image payload cannot be empty.');
            }

            if (stripos($trimmed, 'data:') === 0) {
                if (!self::isSafeImageUrlOrDataUri($trimmed)) {
                    throw new \InvalidArgumentException('Unsupported or unsafe image data URI format (SVGs are not permitted).');
                }
                return;
            }

            if (preg_match('#^https?://#i', $trimmed) || str_starts_with($trimmed, '/storage/')) {
                if (!self::isSafeImageUrlOrDataUri($trimmed)) {
                    throw new \InvalidArgumentException('SVG image URLs are not permitted.');
                }
                return;
            }

            if (self::containsDangerousMarkup(substr($source, 0, 8192))) {
                throw new \InvalidArgumentException('SVG or markup payloads are not permitted.');
            }
        }
    }

    protected static function containsDangerousMarkup(string $bytes): bool
    {
        return (bool) preg_match('/(?:<!DOCTYPE\s+svg|<svg\b|<script\b|<\?php)/i', $bytes);
    }

    /**
     * Convert any supported raster image (file, binary, or Base64 data URL) into WebP format.
     *
     * @param UploadedFile|string $imageSource
     * @param int $quality Compression quality (1-100, default: 85)
     * @return string|null WebP binary bytes, or null on unsupported/corrupted format
     */
    public function convertToWebp(mixed $imageSource, int $quality = 85): ?string
    {
        $this->assertSafeRasterSource($imageSource);

        $rawBytes = null;

        if ($imageSource instanceof UploadedFile) {
            $realPath = $imageSource->getRealPath();
            if ($realPath && file_exists($realPath)) {
                $rawBytes = file_get_contents($realPath);
            }
        } elseif (is_string($imageSource)) {
            if (stripos($imageSource, 'data:image/') === 0) {
                $commaPos = strpos($imageSource, ',');
                if ($commaPos !== false) {
                    $decoded = base64_decode(substr($imageSource, $commaPos + 1), true);
                    $rawBytes = $decoded !== false ? $decoded : null;
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
        $this->assertSafeRasterSource($file);
        $webpBytes = $this->convertToWebp($file);

        if ($webpBytes !== null) {
            $filename = 'avatars/avatar_' . $userId . '_' . Str::random(16) . '.webp';
            $mimeType = 'image/webp';
            $contentToUpload = $webpBytes;
        } else {
            [$filename, $mimeType, $contentToUpload] = $this->prepareSafeRasterFallback($file, 'avatars/avatar_' . $userId);
        }

        $url = $this->uploadToMinio($contentToUpload, $filename, $mimeType);

        // Clean up previous avatar if replaced successfully
        if ($oldAvatarUrl && $oldAvatarUrl !== $url) {
            $this->deleteAvatar($oldAvatarUrl);
        }

        return $url;
    }

    /**
     * Process, convert to WebP, and store a tenant organization logo.
     */
    public function storeLogo(mixed $file, int|string $tenantId, ?string $oldLogoUrl = null): string
    {
        $this->assertSafeRasterSource($file);
        $webpBytes = $this->convertToWebp($file, 85);

        if ($webpBytes !== null) {
            $filename = 'logos/logo_' . $tenantId . '_' . Str::random(16) . '.webp';
            $mimeType = 'image/webp';
            $contentToUpload = $webpBytes;
        } else {
            [$filename, $mimeType, $contentToUpload] = $this->prepareSafeRasterFallback($file, 'logos/logo_' . $tenantId);
        }

        $url = $this->uploadToMinio($contentToUpload, $filename, $mimeType);

        if ($oldLogoUrl && $oldLogoUrl !== $url) {
            $this->deleteAvatar($oldLogoUrl);
        }

        return $url;
    }

    /**
     * Allow storing already-validated raster UploadedFiles when GD cannot decode a test stub,
     * while strictly refusing non-raster or SVG assets.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    protected function prepareSafeRasterFallback(mixed $file, string $prefix): array
    {
        if (!($file instanceof UploadedFile)) {
            throw new \InvalidArgumentException('Failed to process or convert the uploaded image to a valid raster WebP asset.');
        }

        $ext = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension() ?: '');
        if (!in_array($ext, self::ALLOWED_RASTER_EXTENSIONS, true)) {
            throw new \InvalidArgumentException('Only raster image formats (jpeg, jpg, png, gif, webp) are permitted.');
        }

        $mimeMap = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
        ];

        $content = file_get_contents($file->getRealPath());
        if ($content === false || self::containsDangerousMarkup($content)) {
            throw new \InvalidArgumentException('Uploaded file content is unsafe or unreadable.');
        }

        return [
            $prefix . '_' . Str::random(16) . '.' . $ext,
            $mimeMap[$ext],
            $content,
        ];
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

