<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test organization endpoints require authentication.
     */
    public function test_organization_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/outlets')->assertStatus(401);
        $this->getJson('/api/v1/departments')->assertStatus(401);
        $this->getJson('/api/v1/suppliers')->assertStatus(401);
        $this->getJson('/api/v1/customers')->assertStatus(401);
    }

    /**
     * Test authenticated staff can view organization entities.
     */
    public function test_authenticated_user_can_view_organization_entities(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/v1/outlets')->assertStatus(200);
        $this->getJson('/api/v1/departments')->assertStatus(200);
        $this->getJson('/api/v1/suppliers')->assertStatus(200);
        $this->getJson('/api/v1/customers')->assertStatus(200);
        $this->getJson('/api/v1/employees')->assertStatus(200);
    }

    /**
     * Test creating and managing departments, suppliers, and customers conforming to ERD.
     */
    public function test_organization_crud_conforms_to_erd_schemas(): void
    {
        $tenantId = \Illuminate\Support\Facades\DB::table('tenants')->insertGetId([
            'name' => 'Demo Org',
            'slug' => 'demo-org',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'tenant_id' => $tenantId,
        ]);

        Sanctum::actingAs($user, ['*']);

        // 1. Department Store
        $depRes = $this->postJson('/api/v1/departments', [
            'name' => 'Kitchen & Service',
            'code' => 'KITCHEN_01',
            'description' => 'Culinary operations and floor staff',
        ])->assertStatus(201);
        $depId = $depRes->json('data.id');
        $this->assertDatabaseHas('departments', ['id' => $depId, 'code' => 'KITCHEN_01']);

        // 2. Supplier Store
        $supRes = $this->postJson('/api/v1/suppliers', [
            'name' => 'Premium Coffee Roasters',
            'company_name' => 'PCR International LLC',
            'email' => 'orders@pcroasters.com',
            'phone' => '+85512345678',
            'address' => 'Phnom Penh, Cambodia',
        ])->assertStatus(201);
        $supId = $supRes->json('data.id');
        $this->assertDatabaseHas('suppliers', ['id' => $supId, 'company_name' => 'PCR International LLC']);

        // 3. Customer Store
        $custRes = $this->postJson('/api/v1/customers', [
            'name' => 'Alice Johnson',
            'email' => 'alice@example.com',
            'phone' => '+85599887766',
            'address' => 'Street 240, BKK1',
        ])->assertStatus(201);
        $custId = $custRes->json('data.id');
        $this->assertDatabaseHas('customers', ['id' => $custId, 'name' => 'Alice Johnson', 'store_credit' => 0.00]);

        // 4. Employee Store
        $empRes = $this->postJson('/api/v1/employees', [
            'first_name' => 'Bob',
            'last_name' => 'Smith',
            'email' => 'bob@demo.com',
            'phone' => '+85511223344',
            'designation' => 'Barista Lead',
            'employment_type' => 'full_time',
            'department_id' => $depId,
            'salary' => 450.00,
        ])->assertStatus(201);
        $empId = $empRes->json('data.id');
        $this->assertDatabaseHas('employees', ['id' => $empId, 'first_name' => 'Bob', 'designation' => 'Barista Lead']);
    }
}
