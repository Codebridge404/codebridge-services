<?php

namespace Tests\Unit\Policies;

use App\Models\Outlet;
use App\Models\Role;
use App\Models\SecurityEvent;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class ModelPoliciesTest extends TestCase
{
    public function test_super_admin_bypasses_all_policies_via_before_hook(): void
    {
        $superAdmin = (new User())->forceFill(['id' => 1, 'email' => 'super@test.kh', 'role' => 'super_admin']);
        $targetUser = (new User())->forceFill(['id' => 99, 'tenant_id' => 50, 'role' => 'admin']);
        $tenant = (new Tenant())->forceFill(['id' => 50, 'name' => 'Target Tenant']);

        $this->assertTrue(Gate::forUser($superAdmin)->allows('update', $targetUser));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('delete', $targetUser));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('manageSettings', $tenant));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('manageModules', $tenant));
    }

    public function test_user_policy_enforces_tenant_isolation_and_role_protection(): void
    {
        $tenantAdminA = (new User())->forceFill(['id' => 10, 'tenant_id' => 1, 'role' => 'admin']);
        $tenantStaffA = (new User())->forceFill(['id' => 11, 'tenant_id' => 1, 'role' => 'employee']);
        $tenantStaffB = (new User())->forceFill(['id' => 20, 'tenant_id' => 2, 'role' => 'employee']);
        $superAdmin = (new User())->forceFill(['id' => 1, 'tenant_id' => null, 'role' => 'super_admin']);

        // Can view / update own tenant staff
        $this->assertTrue(Gate::forUser($tenantAdminA)->allows('view', $tenantStaffA));
        $this->assertTrue(Gate::forUser($tenantAdminA)->allows('update', $tenantStaffA));

        // CANNOT view or update cross-tenant staff
        $this->assertFalse(Gate::forUser($tenantAdminA)->allows('view', $tenantStaffB));
        $this->assertFalse(Gate::forUser($tenantAdminA)->allows('update', $tenantStaffB));

        // CANNOT update or delete a platform super admin
        $this->assertFalse(Gate::forUser($tenantAdminA)->allows('update', $superAdmin));
        $this->assertFalse(Gate::forUser($tenantAdminA)->allows('delete', $superAdmin));

        // CANNOT delete yourself
        $this->assertFalse(Gate::forUser($tenantAdminA)->allows('delete', $tenantAdminA));
    }

    public function test_tenant_policy_enforces_scoped_workspace_access(): void
    {
        $tenantAdmin = (new User())->forceFill(['id' => 10, 'tenant_id' => 5, 'role' => 'admin']);
        $ownTenant = (new Tenant())->forceFill(['id' => 5, 'name' => 'Own Workspace']);
        $otherTenant = (new Tenant())->forceFill(['id' => 8, 'name' => 'Foreign Workspace']);

        // Can view and update own workspace
        $this->assertTrue(Gate::forUser($tenantAdmin)->allows('view', $ownTenant));
        $this->assertTrue(Gate::forUser($tenantAdmin)->allows('update', $ownTenant));

        // CANNOT view or update foreign workspace
        $this->assertFalse(Gate::forUser($tenantAdmin)->allows('view', $otherTenant));
        $this->assertFalse(Gate::forUser($tenantAdmin)->allows('update', $otherTenant));

        // CANNOT manage platform modules (super-admin only)
        $this->assertFalse(Gate::forUser($tenantAdmin)->allows('manageModules', $ownTenant));
    }

    public function test_outlet_policy_enforces_tenant_and_manager_scoping(): void
    {
        $tenantAdmin = (new User())->forceFill(['id' => 10, 'tenant_id' => 5, 'role' => 'admin']);
        $outletA = (new Outlet())->forceFill(['id' => 1, 'uuid' => 'out-a', 'tenant_id' => 5]);
        $outletB = (new Outlet())->forceFill(['id' => 2, 'uuid' => 'out-b', 'tenant_id' => 9]);

        $this->assertTrue(Gate::forUser($tenantAdmin)->allows('view', $outletA));
        $this->assertTrue(Gate::forUser($tenantAdmin)->allows('update', $outletA));
        $this->assertTrue(Gate::forUser($tenantAdmin)->allows('delete', $outletA));

        // Cross-tenant outlet rejected
        $this->assertFalse(Gate::forUser($tenantAdmin)->allows('view', $outletB));
        $this->assertFalse(Gate::forUser($tenantAdmin)->allows('update', $outletB));
        $this->assertFalse(Gate::forUser($tenantAdmin)->allows('delete', $outletB));
    }

    public function test_role_policy_protects_system_roles(): void
    {
        $tenantAdmin = (new User())->forceFill(['id' => 10, 'tenant_id' => 5, 'role' => 'admin']);
        $systemRole = (new Role())->forceFill(['id' => 24, 'slug' => 'super_admin', 'tenant_id' => null, 'company_id' => null]);
        $customRole = (new Role())->forceFill(['id' => 99, 'slug' => 'barista_lead', 'tenant_id' => 5]);
        $foreignRole = (new Role())->forceFill(['id' => 100, 'slug' => 'other_role', 'tenant_id' => 8]);

        // System roles cannot be updated or deleted by tenant admins
        $this->assertFalse(Gate::forUser($tenantAdmin)->allows('update', $systemRole));
        $this->assertFalse(Gate::forUser($tenantAdmin)->allows('delete', $systemRole));

        // Custom tenant role can be managed
        $this->assertTrue(Gate::forUser($tenantAdmin)->allows('update', $customRole));
        $this->assertTrue(Gate::forUser($tenantAdmin)->allows('delete', $customRole));

        // Foreign tenant role cannot be managed
        $this->assertFalse(Gate::forUser($tenantAdmin)->allows('update', $foreignRole));
        $this->assertFalse(Gate::forUser($tenantAdmin)->allows('delete', $foreignRole));
    }

    public function test_security_event_policy_scoping(): void
    {
        $tenantAdmin = (new User())->forceFill(['id' => 10, 'tenant_id' => 5, 'role' => 'admin']);
        $ownEvent = (new SecurityEvent())->forceFill(['id' => 1, 'tenant_id' => 5]);
        $foreignEvent = (new SecurityEvent())->forceFill(['id' => 2, 'tenant_id' => 9]);

        $this->assertTrue(Gate::forUser($tenantAdmin)->allows('view', $ownEvent));
        $this->assertFalse(Gate::forUser($tenantAdmin)->allows('view', $foreignEvent));
        $this->assertFalse(Gate::forUser($tenantAdmin)->allows('manageSettings', SecurityEvent::class));
    }
}
