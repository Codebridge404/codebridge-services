<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * [BD-0110] Operations Digital Asset Uploader & Product Image CDN Service (MinIO S3 Default / Cloudinary Fallback)
 *
 * @ticket BD-0110
 * @component operations-service
 * @file codebridge-services/operations-service/app/Services/CloudinaryService.php
 * @related-model Storage::disk('s3'), Cloudinary\Cloudinary API, App\Models\Product
 * @entry-point CloudinaryService::upload(), isConfigured()
 * @routes
 *  - Service Layer: App\Services\CloudinaryService
 * @date-time 2026-10-27T08:50:00+07:00
 *
 * Process Capabilities:
 * Step 1 (Storage Detection): Inspects MinIO S3 bucket configuration and environment credentials.
 * Step 2 (Direct MinIO S3 Upload): Stores product photos, category icons, and brand logos to MinIO S3 bucket.
 * Step 3 (Cloudinary Fallback): If S3 is unavailable and Cloudinary is configured, uploads via Cloudinary API.
 * Step 4 (URL Resolution): Returns direct accessible public HTTPS / HTTP CDN URL for POS and catalog display.
 */
class CloudinaryService
{
    protected ?string $cloudName;
    protected ?string $apiKey;
    protected ?string $apiSecret;
    protected ?string $uploadPreset;
    protected bool $s3Configured;

    public function __construct()
    {
        // 1. MinIO S3 Object Storage Configuration Check
        $s3Bucket = config('filesystems.disks.s3.bucket');
        $this->s3Configured = !empty($s3Bucket);

        // 2. Try parsing CLOUDINARY_URL if provided (cloudinary://API_KEY:API_SECRET@CLOUD_NAME)
        $cloudinaryUrl = config('services.cloudinary.url');
        if (!empty($cloudinaryUrl)) {
            $parsed = parse_url($cloudinaryUrl);
            $this->apiKey = $parsed['user'] ?? null;
            $this->apiSecret = $parsed['pass'] ?? null;
            $this->cloudName = $parsed['host'] ?? null;
            $this->uploadPreset = config('services.cloudinary.upload_preset');
        } else {
            $this->cloudName = config('services.cloudinary.cloud_name');
            $this->apiKey = config('services.cloudinary.api_key');
            $this->apiSecret = config('services.cloudinary.api_secret');
            $this->uploadPreset = config('services.cloudinary.upload_preset');
        }
    }

    /**
     * Check if MinIO S3 storage is configured.
     */
    public function isS3Configured(): bool
    {
        return $this->s3Configured;
    }

    /**
     * Check if Cloudinary credentials are configured.
     */
    public function isCloudinaryConfigured(): bool
    {
        if (empty($this->cloudName)) {
            return false;
        }

        return (!empty($this->apiKey) && !empty($this->apiSecret)) || !empty($this->uploadPreset);
    }

    /**
     * Check if either MinIO S3 or Cloudinary storage is configured.
     */
    public function isConfigured(): bool
    {
        return $this->isS3Configured() || $this->isCloudinaryConfigured();
    }

    public const ALLOWED_RASTER_EXTENSIONS = ['jpeg', 'jpg', 'png', 'gif', 'webp'];

    /**
     * Validate that an upload target (UploadedFile, base64 data URI, or URL) is a safe raster image.
     *
     * @throws \InvalidArgumentException
     */
    public function assertSafeRasterImage(mixed $file): void
    {
        if ($file instanceof UploadedFile) {
            $ext = strtolower((string) $file->getClientOriginalExtension());
            $guessed = strtolower((string) $file->guessExtension());
            $mime = strtolower((string) $file->getMimeType());
            $clientMime = strtolower((string) $file->getClientMimeType());

            foreach ([$mime, $clientMime] as $detectedMime) {
                if ($detectedMime !== '' && (str_contains($detectedMime, 'svg') || str_contains($detectedMime, 'xml') || str_contains($detectedMime, 'html') || str_contains($detectedMime, 'php'))) {
                    throw new \InvalidArgumentException('SVG and markup uploads are not permitted.');
                }
            }

            if ($ext !== '' && !in_array($ext, self::ALLOWED_RASTER_EXTENSIONS, true)) {
                throw new \InvalidArgumentException('Only raster image formats (jpeg, jpg, png, gif, webp) are permitted.');
            }

            if ($guessed !== '' && $guessed !== 'bin' && !in_array($guessed, self::ALLOWED_RASTER_EXTENSIONS, true)) {
                throw new \InvalidArgumentException('Uploaded file content is not a supported raster image format.');
            }

            $realPath = $file->getRealPath();
            if ($realPath && is_readable($realPath)) {
                $head = @file_get_contents($realPath, false, null, 0, 8192);
                if (is_string($head) && preg_match('/(?:<!DOCTYPE\s+svg|<svg\b|<script\b|<\?php)/i', $head)) {
                    throw new \InvalidArgumentException('SVG or script markup inside uploaded file is not permitted.');
                }
            }

            return;
        }

        if (is_string($file)) {
            $trimmed = trim($file);
            if ($trimmed === '') {
                throw new \InvalidArgumentException('Image payload cannot be empty.');
            }

            if (preg_match('/^(?:javascript|vbscript):/i', $trimmed)) {
                throw new \InvalidArgumentException('Unsafe image URI scheme is not permitted.');
            }

            if (stripos($trimmed, 'data:') === 0) {
                if (!preg_match('/^data:image\/(jpeg|jpg|png|gif|webp);base64,(.+)$/is', $trimmed, $matches)) {
                    throw new \InvalidArgumentException('Unsupported or unsafe image data URI format (SVGs are not permitted).');
                }
                $decoded = base64_decode($matches[2], true);
                if ($decoded === false || $decoded === '') {
                    throw new \InvalidArgumentException('Invalid base64 image payload.');
                }
                if (preg_match('/(?:<!DOCTYPE\s+svg|<svg\b|<script\b|<\?php)/i', $decoded)) {
                    throw new \InvalidArgumentException('SVG or script markup inside image data URI is not permitted.');
                }
                return;
            }

            if (preg_match('#^https?://#i', $trimmed)) {
                $path = parse_url($trimmed, PHP_URL_PATH) ?: $trimmed;
                if (preg_match('/\.svgz?(?:$|[?#])/i', $path)) {
                    throw new \InvalidArgumentException('SVG image URLs are not permitted.');
                }
                return;
            }

            throw new \InvalidArgumentException('Unsupported image payload.');
        }

        throw new \InvalidArgumentException('Invalid image payload type.');
    }

    /**
     * Upload an image file, base64 data string, or URL to MinIO S3 (default) or Cloudinary (fallback).
     *
     * @param UploadedFile|string $file
     * @param string $folder
     * @return string|null The public access URL of the uploaded image, or null on failure.
     */
    public function upload($file, string $folder = 'products'): ?string
    {
        $this->assertSafeRasterImage($file);

        // 1. Prioritize MinIO S3 Object Storage (Default)
        if ($this->isS3Configured()) {
            try {
                if ($file instanceof UploadedFile) {
                    $ext = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'png');
                    if (!in_array($ext, self::ALLOWED_RASTER_EXTENSIONS, true)) {
                        $ext = 'png';
                    }
                    $filename = uniqid('prod_') . '_' . time() . '.' . $ext;
                    $path = Storage::disk('s3')->putFileAs($folder, $file, $filename, 'public');
                    if ($path) {
                        return Storage::disk('s3')->url($path);
                    }
                } elseif (is_string($file)) {
                    // Direct URL pass-through if already a remote web URL
                    if (preg_match('#^https?://#i', $file)) {
                        return $file;
                    }
                    // Handle Base64 Data URL (e.g. data:image/png;base64,...)
                    if (preg_match('/^data:image\/(jpeg|jpg|png|gif|webp);base64,(.+)$/is', trim($file), $matches)) {
                        $ext = strtolower($matches[1]) === 'jpeg' ? 'jpg' : strtolower($matches[1]);
                        $decoded = base64_decode($matches[2], true);
                        if ($decoded !== false) {
                            $filename = $folder . '/' . uniqid('prod_') . '_' . time() . '.' . $ext;
                            Storage::disk('s3')->put($filename, $decoded, 'public');
                            return Storage::disk('s3')->url($filename);
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('[MinIO S3 Storage] Upload failed, falling back to Cloudinary: ' . $e->getMessage());
            }
        }

        // 2. Fallback to Cloudinary CDN if configured
        if ($this->isCloudinaryConfigured()) {
            try {
                $endpoint = "https://api.cloudinary.com/v1_1/{$this->cloudName}/image/upload";
                $timestamp = time();

                $request = Http::timeout(30);

                // Unsigned Upload Preset Mode
                if (!empty($this->uploadPreset) && (empty($this->apiKey) || empty($this->apiSecret))) {
                    $params = [
                        'upload_preset' => $this->uploadPreset,
                        'folder'        => $folder,
                    ];

                    if ($file instanceof UploadedFile) {
                        $response = $request->attach('file', file_get_contents($file->getRealPath()), $file->getClientOriginalName())
                            ->post($endpoint, $params);
                    } else {
                        $params['file'] = $file;
                        $response = $request->asForm()->post($endpoint, $params);
                    }
                } else {
                    // Signed Upload Mode (API Key + API Secret)
                    $signParams = [
                        'folder'    => $folder,
                        'timestamp' => $timestamp,
                    ];
                    ksort($signParams);

                    $signString = '';
                    foreach ($signParams as $k => $v) {
                        $signString .= ($signString ? '&' : '') . "{$k}={$v}";
                    }
                    $signature = sha1($signString . $this->apiSecret);

                    $params = [
                        'api_key'   => $this->apiKey,
                        'timestamp' => $timestamp,
                        'folder'    => $folder,
                        'signature' => $signature,
                    ];

                    if ($file instanceof UploadedFile) {
                        $response = $request->attach('file', file_get_contents($file->getRealPath()), $file->getClientOriginalName())
                            ->post($endpoint, $params);
                    } else {
                        $params['file'] = $file;
                        $response = $request->asForm()->post($endpoint, $params);
                    }
                }

                if ($response->successful()) {
                    $data = $response->json();
                    return $data['secure_url'] ?? $data['url'] ?? null;
                }

                Log::warning('[Cloudinary] Upload failed: ' . $response->body());
            } catch (\Throwable $e) {
                Log::error('[Cloudinary] Upload exception: ' . $e->getMessage());
            }
        }

        return null;
    }
}
