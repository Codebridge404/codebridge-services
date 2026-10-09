<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CloudinaryService
{
    protected ?string $cloudName;
    protected ?string $apiKey;
    protected ?string $apiSecret;
    protected ?string $uploadPreset;

    public function __construct()
    {
        // 1. Try parsing CLOUDINARY_URL if provided (cloudinary://API_KEY:API_SECRET@CLOUD_NAME)
        $cloudinaryUrl = env('CLOUDINARY_URL');
        if (!empty($cloudinaryUrl)) {
            $parsed = parse_url($cloudinaryUrl);
            $this->apiKey = $parsed['user'] ?? null;
            $this->apiSecret = $parsed['pass'] ?? null;
            $this->cloudName = $parsed['host'] ?? null;
            $this->uploadPreset = env('CLOUDINARY_UPLOAD_PRESET');
        } else {
            $this->cloudName = env('CLOUDINARY_CLOUD_NAME');
            $this->apiKey = env('CLOUDINARY_API_KEY');
            $this->apiSecret = env('CLOUDINARY_API_SECRET');
            $this->uploadPreset = env('CLOUDINARY_UPLOAD_PRESET');
        }
    }

    /**
     * Check if Cloudinary credentials are configured.
     */
    public function isConfigured(): bool
    {
        if (empty($this->cloudName)) {
            return false;
        }

        // Configured either with API Key & Secret, or with an Unsigned Upload Preset
        return (!empty($this->apiKey) && !empty($this->apiSecret)) || !empty($this->uploadPreset);
    }

    /**
     * Upload an image file or base64 data string to Cloudinary.
     *
     * @param UploadedFile|string $file
     * @param string $folder
     * @return string|null The secure HTTPS CDN URL of the uploaded image, or null on failure.
     */
    public function upload($file, string $folder = 'codebridges/products'): ?string
    {
        if (!$this->isConfigured()) {
            return null;
        }

        try {
            $endpoint = "https://api.cloudinary.com/v1_1/{$this->cloudName}/image/upload";
            $timestamp = time();

            $request = Http::timeout(30);

            // 1. Unsigned Upload Preset Mode
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
                // 2. Signed Upload Mode (API Key + API Secret)
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
            return null;
        } catch (\Throwable $e) {
            Log::error('[Cloudinary] Upload exception: ' . $e->getMessage());
            return null;
        }
    }
}
