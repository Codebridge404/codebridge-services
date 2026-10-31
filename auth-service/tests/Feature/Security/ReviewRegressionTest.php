<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\ApiKeyMiddleware;
use App\Models\Customer;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\JwtService;
use App\Services\StoreCreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ReviewRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $slug): Tenant
    {
        return Tenant::create(['name' => $slug, 'slug' => $slug, 'status' => 'active']);
    }

    private function user(Tenant $tenant, string $role = 'admin'): User
    {
        return User::factory()->create(['role' => $role, 'tenant_id' => $tenant->id, 'is_active' => true]);
    }

    private function room(Tenant $tenant): int
    {
        return DB::table('meeting_rooms')->insertGetId(['tenant_id' => $tenant->id, 'name' => 'Room', 'code' => 'ROOM-' . bin2hex(random_bytes(4)), 'capacity' => 10]);
    }

    private function meeting(Tenant $tenant, User $owner): int
    {
        return DB::table('meetings')->insertGetId([
            'tenant_id' => $tenant->id, 'room_id' => $this->room($tenant), 'organizer_id' => $owner->id,
            'title' => 'Review', 'status' => 'scheduled', 'start_time' => now()->subMinutes(1), 'end_time' => now()->addHour(),
        ]);
    }

    private function bearer(string $token): static
    {
        Auth::forgetGuards();
        $this->app->forgetInstance('auth.driver');
        return $this->withHeader('Authorization', 'Bearer ' . $token);
    }

    public function test_role_id_cannot_smuggle_super_admin_or_another_tenants_role(): void
    {
        $tenant = $this->tenant('one');
        $admin = $this->user($tenant);
        $target = $this->user($tenant, 'employee');
        $super = Role::create(['name' => 'Super', 'slug' => 'super_admin', 'is_system' => true]);
        $other = Role::create(['name' => 'Other role', 'slug' => 'other-role', 'tenant_id' => $this->tenant('two')->id]);
        $this->actingAs($admin, 'sanctum')->putJson('/api/v1/users/' . $target->id, ['role' => 'employee', 'role_id' => $super->id])->assertForbidden();
        $this->putJson('/api/v1/users/' . $target->id, ['role' => 'employee', 'role_id' => $other->id])->assertForbidden();
        $this->assertSame('employee', $target->fresh()->role);
    }

    public function test_downgrade_and_inactive_roles_remove_administrative_access(): void
    {
        $tenant = $this->tenant('one');
        $admin = $this->user($tenant);
        $target = $this->user($tenant);
        $this->user($tenant, 'employee');
        $this->actingAs($admin, 'sanctum')->putJson('/api/v1/users/' . $target->id, ['role' => 'employee'])->assertOk();
        $this->actingAs($target->fresh(), 'sanctum')->getJson('/api/v1/users')->assertForbidden();
        $this->assertFalse($target->fresh()->hasRole('admin'));
    }

    public function test_tenantless_and_suspended_memberships_cannot_read_workspace_data(): void
    {
        $tenant = $this->tenant('one');
        $member = $this->user($tenant, 'employee');
        DB::table('tenant_users')->where('user_id', $member->id)->update(['status' => 'suspended']);
        $this->actingAs($member->fresh(), 'sanctum')->getJson('/api/v1/meetings')->assertForbidden();
        $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/v1/crm/deals')->assertForbidden();
        $this->getJson('/api/v1/customers')->assertForbidden();
    }

    public function test_profile_and_receipt_configuration_are_tenant_scoped(): void
    {
        $one = $this->tenant('one');
        $two = $this->tenant('two');
        $outlet = DB::table('outlets')->insertGetId(['tenant_id' => $two->id, 'name' => 'Private', 'code' => 'PRIVATE']);
        $this->actingAs($this->user($one), 'sanctum')->putJson('/api/v1/tenants/profile', ['tenant_id' => $two->id, 'name' => 'Hijacked'])->assertForbidden();
        $this->postJson('/api/v1/outlets/' . $outlet . '/receipt-branding', ['receipt_footer' => 'Hijacked'])->assertNotFound();
        $this->getJson('/api/v1/outlets/' . $outlet . '/terminal-settings')->assertNotFound();
        $this->getJson('/api/v1/outlets/' . $outlet . '/business-profile')->assertNotFound();
        $this->assertSame('two', $two->fresh()->name);
    }

    public function test_members_cannot_change_licenses_and_admins_need_plan_entitlements(): void
    {
        $tenant = $this->tenant('one');
        DB::table('platform_modules')->insert(['id' => 'premium', 'name' => 'Premium', 'category' => 'business', 'description' => 'Premium feature', 'icon' => 'Store', 'href' => '/premium', 'is_active' => true]);
        $this->actingAs($this->user($tenant, 'employee'), 'sanctum')->putJson('/api/v1/tenants/modules', ['modules' => ['premium']])->assertForbidden();
        $this->actingAs($this->user($tenant), 'sanctum')->putJson('/api/v1/tenants/modules', ['modules' => ['premium']])->assertForbidden();
        $this->assertDatabaseMissing('tenant_modules', ['tenant_id' => $tenant->id, 'module_id' => 'premium']);
    }

    public function test_api_key_is_hashed_and_the_returned_key_authenticates(): void
    {
        $tenant = $this->tenant('one');
        $this->actingAs($this->user($tenant), 'sanctum');
        $response = $this->postJson('/api/v1/api-keys', ['name' => 'Integration', 'permissions' => ['sales.read']])->assertCreated();
        $raw = $response->json('plain_text_key');
        $row = DB::table('api_keys')->find($response->json('data.id'));
        $this->assertSame(hash('sha256', $raw), $row->secret);
        $this->assertNotSame($raw, $row->key);
        $request = Request::create('/integration');
        $request->headers->set('X-API-Key', $raw);
        $middleware = new ApiKeyMiddleware();
        $this->assertSame(200, $middleware->handle($request, fn () => response()->json(['ok' => true]), 'sales.read')->getStatusCode());
        $this->assertSame(403, $middleware->handle($request, fn () => response()->json(['ok' => true]), 'sales.write')->getStatusCode());
    }

    public function test_financial_objects_and_crm_responses_cannot_cross_tenants(): void
    {
        $one = $this->tenant('one');
        $two = $this->tenant('two');
        $customer = Customer::create(['tenant_id' => $two->id, 'name' => 'Private customer']);
        $deal = DB::table('deals')->insertGetId(['tenant_id' => $two->id, 'title' => 'Secret', 'value' => 5]);
        $this->actingAs($this->user($one), 'sanctum');
        $this->postJson('/api/v1/customers/' . $customer->id . '/credit/topup-invoice', ['amount_paid' => 5])->assertNotFound();
        $this->getJson('/api/v1/customers/' . $customer->id . '/credit/statement')->assertNotFound();
        $this->getJson('/api/v1/customers/' . $customer->id . '/history')->assertNotFound();
        $this->putJson('/api/v1/crm/deals/' . $deal, ['title' => 'Changed'])->assertNotFound()->assertJsonMissing(['title' => 'Secret']);
    }

    public function test_meeting_authorization_covers_parent_and_child_resources(): void
    {
        $one = $this->tenant('one');
        $two = $this->tenant('two');
        $owner = $this->user($two);
        $meeting = $this->meeting($two, $owner);
        $room = DB::table('meetings')->find($meeting)->room_id;
        $this->actingAs($this->user($one), 'sanctum')->getJson('/api/v1/meetings/' . $meeting)->assertNotFound();
        $this->postJson('/api/v1/meetings/' . $meeting . '/minutes', ['content' => 'Changed'])->assertNotFound();
        $this->deleteJson('/api/v1/meeting-rooms/' . $room)->assertNotFound();
        $this->actingAs($this->user($two, 'employee'), 'sanctum')->deleteJson('/api/v1/meetings/' . $meeting)->assertForbidden();
        $this->postJson('/api/v1/meeting-rooms', ['name' => 'Unauthorized', 'capacity' => 5])->assertForbidden();
    }

    public function test_attendance_can_only_be_changed_for_authorized_workspace_staff(): void
    {
        $one = $this->tenant('one');
        $two = $this->tenant('two');
        $employee = $this->user($one, 'employee');
        $other = $this->user($two, 'employee');
        $id = DB::table('attendances')->insertGetId(['tenant_id' => $two->id, 'user_id' => $other->id, 'date' => now()->toDateString(), 'clock_in' => now()]);
        $this->actingAs($employee, 'sanctum')->postJson('/api/v1/attendance/clock-out', ['attendance_id' => $id])->assertNotFound();
        $this->postJson('/api/v1/attendance/clock-in', ['user_id' => $other->id])->assertForbidden();
        $this->assertNull(DB::table('attendances')->find($id)->clock_out);
    }

    public function test_profile_pin_is_saved_and_bcrypt_supervisor_pins_verify(): void
    {
        $tenant = $this->tenant('one');
        $admin = $this->user($tenant);
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/auth/profile', ['pin_code' => '1234'])->assertOk();
        $pin = DB::table('user_pos_pins')->where('user_id', $admin->id)->value('pin_hash');
        $this->assertTrue(Hash::check('1234', $pin));
        $this->postJson('/api/v1/auth/verify-pin', ['pin_code' => '1234'])->assertOk();
    }

    public function test_profile_upload_aliases_reject_non_images_before_storage(): void
    {
        Storage::fake('minio');
        Storage::fake('public');
        $this->actingAs(User::factory()->create(), 'sanctum');
        foreach (['avatar', 'avatar_url'] as $field) {
            $this->postJson('/api/v1/auth/profile', [$field => UploadedFile::fake()->create('payload.html', 1, 'text/html')])->assertUnprocessable();
        }
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame([], Storage::disk('minio')->allFiles());
    }

    public function test_query_and_body_secrets_are_redacted_from_audit_storage(): void
    {
        $this->getJson('/api/v1/auth/verify-invite?token=INVITE_SECRET&code=123456&signature=SIGNED_SECRET');
        $row = DB::table('api_request_logs')->where('path', 'api/v1/auth/verify-invite')->first();
        $serialized = json_encode($row);
        $this->assertStringNotContainsString('INVITE_SECRET', $serialized);
        $this->assertStringNotContainsString('SIGNED_SECRET', $serialized);
        $this->assertStringNotContainsString('123456', $serialized);
    }

    public function test_logout_revokes_access_and_refresh_for_the_same_session(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Password123!')]);
        $login = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Password123!'])->assertOk();
        $access = $login->json('access_token');
        $refresh = $login->json('refresh_token');
        $this->bearer($access)->postJson('/api/v1/auth/logout')->assertOk();
        $this->bearer($access)->getJson('/api/v1/me')->assertUnauthorized();
        $this->bearer($refresh)->postJson('/api/v1/auth/refresh')->assertUnauthorized();
    }

    public function test_remote_revocation_ends_only_the_selected_jwt_session(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Password123!')]);
        $first = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Password123!', 'device_name' => 'First'])->assertOk();
        $second = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Password123!', 'device_name' => 'Second'])->assertOk();
        $jwt = app(JwtService::class);
        $sid = $jwt->verify($first->json('access_token'))['sid'];
        $this->bearer($second->json('access_token'))->deleteJson('/api/v1/auth/sessions/' . $sid)->assertOk();
        $this->bearer($first->json('access_token'))->getJson('/api/v1/me')->assertUnauthorized();
        $this->bearer($first->json('refresh_token'))->postJson('/api/v1/auth/refresh')->assertUnauthorized();
        $this->bearer($second->json('access_token'))->getJson('/api/v1/me')->assertOk();
    }

    public function test_pos_refresh_preserves_scope_and_administrative_apis_reject_pos_sessions(): void
    {
        $user = $this->user($this->tenant('one'));
        $jwt = app(JwtService::class);
        $claims = ['scope' => 'pos', 'is_pos_session' => true, 'permissions' => ['pos:access']];
        $access = $jwt->generateTokenForUser($user, $claims);
        $refresh = $jwt->generateRefreshTokenForUser($user, $claims);
        $this->bearer($access)->getJson('/api/v1/users')->assertForbidden();
        $response = $this->bearer($refresh)->postJson('/api/v1/auth/refresh')->assertOk();
        $payload = $jwt->verify($response->json('access_token'));
        $this->assertSame('pos', $payload['scope']);
        $this->assertTrue($payload['is_pos_session']);
        $this->bearer($response->json('access_token'))->putJson('/api/v1/auth/password', [])->assertForbidden();
    }

    public function test_administrative_password_reset_revokes_both_token_types(): void
    {
        $tenant = $this->tenant('one');
        $target = $this->user($tenant, 'employee');
        $jwt = app(JwtService::class);
        $access = $jwt->generateTokenForUser($target);
        $refresh = $jwt->generateRefreshTokenForUser($target);
        $this->actingAs($this->user($tenant), 'sanctum')->postJson('/api/v1/users/' . $target->id . '/reset-password', ['new_password' => 'NewPassword123!'])->assertOk();
        $this->bearer($access)->getJson('/api/v1/me')->assertUnauthorized();
        $this->bearer($refresh)->postJson('/api/v1/auth/refresh')->assertUnauthorized();
        $this->assertNotNull($jwt->verifyRefreshToken($jwt->generateRefreshTokenForUser($target)));
    }

    public function test_normal_jwt_contains_only_active_scoped_permissions(): void
    {
        $tenant = $this->tenant('one');
        $user = $this->user($tenant, 'employee');
        $permission = DB::table('permissions')->insertGetId(['name' => 'meetings.view', 'slug' => 'meetings.view', 'group' => 'meetings']);
        DB::table('role_permissions')->insert(['role_id' => $user->role_id, 'permission_id' => $permission]);
        $this->assertSame(['meetings.view'], app(JwtService::class)->verify(app(JwtService::class)->generateTokenForUser($user))['permissions']);
    }

    public function test_wallet_reloads_stale_models_and_expiration_preserves_new_credit(): void
    {
        $customer = Customer::create(['name' => 'Wallet', 'store_credit' => 0]);
        $stale = Customer::find($customer->id);
        $service = app(StoreCreditService::class);
        $first = $service->createTopUpInvoice($customer, 50, validityDays: 1);
        $service->createTopUpInvoice($stale, 20);
        $this->assertSame('70.00', $customer->fresh()->store_credit);
        $this->assertEquals(70, $customer->fresh()->store_credit_balance);
        // A debit consumes the oldest 50; the newer 20 must survive expiration.
        DB::table('customer_credit_ledgers')->insert(['customer_id' => $customer->id, 'amount_change' => -50, 'debit_amount' => 50, 'balance_before' => 70, 'balance_after' => 20]);
        $customer->update(['store_credit' => 20, 'store_credit_balance' => 20]);
        DB::table('customer_credit_ledgers')->where('id', $first['ledger_id'])->update(['expires_at' => now()->subDay()]);
        $service->processExpirations();
        $service->processExpirations();
        $this->assertSame('20.00', $customer->fresh()->store_credit);
        $this->assertEquals(20, $customer->fresh()->store_credit_balance);
    }

    public function test_meeting_materials_staff_and_details_work_on_fresh_schema(): void
    {
        $tenant = $this->tenant('one');
        $owner = $this->user($tenant);
        $this->actingAs($owner, 'sanctum');
        $material = $this->postJson('/api/v1/meetings/materials', ['name' => 'Projector', 'quantity_total' => 3])->assertCreated()->json('data.id');
        $staff = $this->postJson('/api/v1/meetings/staff', ['name' => 'Technician', 'role' => 'technician'])->assertCreated()->json('data.id');
        $payload = ['room_id' => $this->room($tenant), 'title' => 'Review', 'start_time' => now()->addHour()->toDateTimeString(), 'end_time' => now()->addHours(2)->toDateTimeString(), 'materials' => [['material_id' => $material, 'quantity' => 2]], 'staff_ids' => [$staff]];
        $meeting = $this->postJson('/api/v1/meetings', $payload)->assertCreated()->json('data.id');
        $this->getJson('/api/v1/meetings/' . $meeting)->assertOk();
        $this->postJson('/api/v1/meetings', $payload)->assertUnprocessable();
        $this->assertDatabaseHas('meeting_materials', ['id' => $material, 'quantity_available' => 1]);
        $this->patchJson('/api/v1/meetings/' . $meeting . '/cancel', ['cancellation_reason' => 'Cancelled'])->assertOk();
        $this->patchJson('/api/v1/meetings/' . $meeting . '/cancel', ['cancellation_reason' => 'Again'])->assertOk();
        $this->assertDatabaseHas('meeting_materials', ['id' => $material, 'quantity_available' => 3]);
    }

    public function test_public_check_in_requires_signature_and_a_valid_state(): void
    {
        $tenant = $this->tenant('one');
        $meeting = $this->meeting($tenant, $this->user($tenant));
        $this->postJson('/api/v1/meetings/' . $meeting . '/check-in/public')->assertForbidden();
        $url = URL::temporarySignedRoute('meetings.public-check-in', now()->addMinutes(5), ['id' => $meeting]);
        $this->postJson($url)->assertOk();
        DB::table('meetings')->where('id', $meeting)->update(['status' => 'cancelled']);
        $this->postJson($url)->assertStatus(409);
    }

    public function test_receipt_logo_column_and_settings_routes_are_usable(): void
    {
        $tenant = $this->tenant('one');
        $outlet = DB::table('outlets')->insertGetId(['tenant_id' => $tenant->id, 'name' => 'Store', 'code' => 'STORE']);
        $this->actingAs($this->user($tenant), 'sanctum')->postJson('/api/v1/outlets/' . $outlet . '/receipt-branding', ['receipt_logo_url' => 'https://example.test/logo.png'])->assertOk();
        $this->assertDatabaseHas('outlets', ['id' => $outlet, 'receipt_logo_url' => 'https://example.test/logo.png']);
        $this->putJson('/api/v1/meetings/system-settings/batch', ['settings' => [['setting_key' => 'booking.max_advance_days', 'setting_value' => '30']]])->assertOk();
        $this->postJson('/api/v1/meetings/system-settings/reset')->assertOk();
    }
}
