<?php

namespace Tests\Feature;

use App\Models\Auth\User;
use App\Services\ImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AvatarUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! env('REAL_MINIO_TEST', false)) {
            Storage::fake('minio');
            Storage::fake('s3');
            Storage::fake('public');
        }
    }

    public function test_image_service_converts_png_and_jpeg_to_webp(): void
    {
        $service = new ImageService;

        // 1. Create truecolor test PNG with transparency
        $im = imagecreatetruecolor(50, 50);
        imagesavealpha($im, true);
        $transparent = imagecolorallocatealpha($im, 0, 0, 0, 127);
        imagefill($im, 0, 0, $transparent);
        $blue = imagecolorallocate($im, 0, 100, 255);
        imagefilledrectangle($im, 10, 10, 40, 40, $blue);

        ob_start();
        imagepng($im);
        $pngBytes = ob_get_clean();
        imagedestroy($im);

        $convertedWebp = $service->convertToWebp($pngBytes);
        $this->assertNotNull($convertedWebp);
        $this->assertStringStartsWith('RIFF', $convertedWebp);
        $this->assertStringContainsString('WEBP', substr($convertedWebp, 0, 16));

        // 2. Test JPEG to WebP
        $jpgIm = imagecreatetruecolor(40, 40);
        $red = imagecolorallocate($jpgIm, 255, 0, 0);
        imagefill($jpgIm, 0, 0, $red);
        ob_start();
        imagejpeg($jpgIm);
        $jpgBytes = ob_get_clean();
        imagedestroy($jpgIm);

        $convertedFromJpg = $service->convertToWebp($jpgBytes);
        $this->assertNotNull($convertedFromJpg);
        $this->assertStringStartsWith('RIFF', $convertedFromJpg);
    }

    public function test_user_can_upload_avatar_and_it_is_converted_to_webp(): void
    {
        $user = User::factory()->create([
            'name' => 'Avatar Tester',
            'email' => 'avatar.tester@codebridge.dev',
        ]);

        Sanctum::actingAs($user);

        // Create a PNG image file
        $im = imagecreatetruecolor(64, 64);
        $green = imagecolorallocate($im, 0, 255, 100);
        imagefill($im, 0, 0, $green);
        $tempPath = tempnam(sys_get_temp_dir(), 'test_png_');
        imagepng($im, $tempPath);
        imagedestroy($im);

        $uploadedFile = new UploadedFile($tempPath, 'my_photo.png', 'image/png', null, true);

        $response = $this->putJson('/api/v1/auth/profile', [
            'name' => 'Updated Tester Name',
            'avatar' => $uploadedFile,
        ]);

        if (file_exists($tempPath)) {
            unlink($tempPath);
        }

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Profile updated successfully.',
            ]);

        $avatarUrl = $response->json('user.avatar_url');
        $this->assertNotNull($avatarUrl);
        $this->assertStringEndsWith('.webp', $avatarUrl);

        $user->refresh();
        $this->assertStringEndsWith('.webp', $user->avatar_url);
        $this->assertEquals('Updated Tester Name', $user->name);
    }

    public function test_user_can_upload_only_avatar_without_any_other_fields(): void
    {
        $user = User::factory()->create([
            'name' => 'Original Name Unchanged',
            'email' => 'only.avatar@codebridge.dev',
        ]);

        Sanctum::actingAs($user);

        $im = imagecreatetruecolor(48, 48);
        $tempPath = tempnam(sys_get_temp_dir(), 'only_avatar_');
        imagepng($im, $tempPath);
        imagedestroy($im);

        $uploadedFile = new UploadedFile($tempPath, 'avatar_only.png', 'image/png', null, true);

        // Send ONLY avatar, completely omitting name, phone, pin_code, avatar_url, remove_avatar
        $response = $this->putJson('/api/v1/auth/profile', [
            'avatar' => $uploadedFile,
        ]);

        if (file_exists($tempPath)) {
            unlink($tempPath);
        }

        $response->assertStatus(200);
        $this->assertStringEndsWith('.webp', $response->json('user.avatar_url'));

        $user->refresh();
        $this->assertEquals('Original Name Unchanged', $user->name);
        $this->assertStringEndsWith('.webp', $user->avatar_url);
    }

    public function test_user_can_upload_file_using_avatar_url_field_converted_to_webp(): void
    {
        $user = User::factory()->create([
            'name' => 'Avatar Url Field Tester',
            'email' => 'avatar.url.field@codebridge.dev',
        ]);

        Sanctum::actingAs($user);

        $im = imagecreatetruecolor(48, 48);
        $tempPath = tempnam(sys_get_temp_dir(), 'avatar_url_file_');
        imagepng($im, $tempPath);
        imagedestroy($im);

        $uploadedFile = new UploadedFile($tempPath, 'avatar_file.png', 'image/png', null, true);

        // Upload using avatar_url as the file key
        $response = $this->putJson('/api/v1/auth/profile', [
            'avatar_url' => $uploadedFile,
        ]);

        if (file_exists($tempPath)) {
            unlink($tempPath);
        }

        $response->assertStatus(200);
        $this->assertStringEndsWith('.webp', $response->json('user.avatar_url'));

        $user->refresh();
        $this->assertStringEndsWith('.webp', $user->avatar_url);
    }

    public function test_user_can_upload_base64_avatar_converted_to_webp(): void
    {
        $user = User::factory()->create([
            'name' => 'Base64 Tester',
            'email' => 'base64.tester@codebridge.dev',
        ]);

        Sanctum::actingAs($user);

        // Generate base64 JPEG
        $im = imagecreatetruecolor(32, 32);
        $purple = imagecolorallocate($im, 150, 50, 200);
        imagefill($im, 0, 0, $purple);
        ob_start();
        imagejpeg($im);
        $jpgBytes = ob_get_clean();
        imagedestroy($im);

        $base64 = 'data:image/jpeg;base64,'.base64_encode($jpgBytes);

        $response = $this->putJson('/api/v1/auth/profile', [
            'avatar_url' => $base64,
        ]);

        $response->assertStatus(200);
        $avatarUrl = $response->json('user.avatar_url');
        $this->assertNotNull($avatarUrl);
        $this->assertStringEndsWith('.webp', $avatarUrl);

        $user->refresh();
        $this->assertStringEndsWith('.webp', $user->avatar_url);
    }

    public function test_user_can_remove_avatar(): void
    {
        $user = User::factory()->create([
            'name' => 'Remove Avatar Tester',
            'email' => 'remove.avatar@codebridge.dev',
            'avatar_url' => 'http://localhost:9000/codebridge-auth/avatars/avatar_1_test.webp',
        ]);

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/v1/auth/profile', [
            'remove_avatar' => true,
        ]);

        $response->assertStatus(200);
        $this->assertNull($response->json('user.avatar_url'));

        $user->refresh();
        $this->assertNull($user->avatar_url);
    }

    public function test_user_can_use_dedicated_upload_profile_endpoint(): void
    {
        $user = User::factory()->create([
            'name' => 'Isolated Upload Tester',
            'email' => 'isolated.upload@codebridge.dev',
        ]);

        Sanctum::actingAs($user);

        $im = imagecreatetruecolor(50, 50);
        $tempPath = tempnam(sys_get_temp_dir(), 'dedicated_upload_');
        imagepng($im, $tempPath);
        imagedestroy($im);

        $uploadedFile = new UploadedFile($tempPath, 'my_avatar.png', 'image/png', null, true);

        // Test POST /api/v1/auth/upload-profile with required avatar field
        $response = $this->post('/api/v1/auth/upload-profile', [
            'avatar' => $uploadedFile,
        ], ['Accept' => 'application/json']);

        if (file_exists($tempPath)) {
            unlink($tempPath);
        }

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Profile image uploaded successfully.',
            ]);

        $this->assertNotNull($response->json('avatar_url'));
        $this->assertStringEndsWith('.webp', $response->json('avatar_url'));

        $user->refresh();
        $this->assertStringEndsWith('.webp', $user->avatar_url);
    }

    /**
     * Real MinIO S3 media upload and WebP storage integration test.
     */
    public function test_real_minio_s3_avatar_upload_end_to_end(): void
    {
        $endpoint = config('filesystems.disks.s3.endpoint');
        if (! $endpoint || ! env('REAL_MINIO_TEST', false)) {
            $this->markTestSkipped('Real MinIO infrastructure not configured or REAL_MINIO_TEST not enabled.');
        }

        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $file = UploadedFile::fake()->image('real_avatar.png', 100, 100);
        $response = $this->postJson('/api/v1/auth/profile/avatar', [
            'avatar' => $file,
        ]);

        $response->assertStatus(200);
        $url = $response->json('data.avatar_url') ?? $response->json('avatar_url');
        $this->assertNotEmpty($url);

        $path = parse_url($url, PHP_URL_PATH);
        $s3Path = ltrim(preg_replace('#^/'.config('filesystems.disks.s3.bucket').'/#', '', $path), '/');
        $this->assertTrue(Storage::disk('s3')->exists($s3Path));

        Storage::disk('s3')->delete($s3Path);
    }
}
