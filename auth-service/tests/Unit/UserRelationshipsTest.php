<?php

namespace Tests\Unit;

use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Meeting;
use App\Models\Outlet;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Tests\TestCase;

class UserRelationshipsTest extends TestCase
{
    public function test_user_relationships_are_properly_configured(): void
    {
        $user = new User();

        $this->assertInstanceOf(BelongsTo::class, $user->tenant());
        $this->assertInstanceOf(BelongsTo::class, $user->roleModel());
        $this->assertInstanceOf(BelongsTo::class, $user->outlet());
        $this->assertInstanceOf(HasMany::class, $user->userRoles());
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsToMany::class, $user->roles());
        $this->assertInstanceOf(HasMany::class, $user->apiKeys());
        $this->assertInstanceOf(HasMany::class, $user->auditLogs());
        $this->assertInstanceOf(HasOne::class, $user->employee());
        $this->assertInstanceOf(HasMany::class, $user->organizedMeetings());
        $this->assertInstanceOf(HasMany::class, $user->sentInvitations());
    }

    public function test_tenant_relationships_are_properly_configured(): void
    {
        $tenant = new Tenant();

        $this->assertInstanceOf(HasMany::class, $tenant->users());
        $this->assertInstanceOf(HasMany::class, $tenant->outlets());
        $this->assertInstanceOf(HasMany::class, $tenant->roles());
        $this->assertInstanceOf(HasMany::class, $tenant->subscriptions());
        $this->assertInstanceOf(HasOne::class, $tenant->activeSubscription());
        $this->assertInstanceOf(HasMany::class, $tenant->apiKeys());
        $this->assertInstanceOf(HasMany::class, $tenant->invitations());
        $this->assertInstanceOf(HasMany::class, $tenant->employees());
        $this->assertInstanceOf(HasMany::class, $tenant->departments());
        $this->assertInstanceOf(BelongsTo::class, $tenant->owner());
        $this->assertInstanceOf(HasMany::class, $tenant->tenantUsers());
        $this->assertInstanceOf(HasMany::class, $tenant->tenantModules());
    }

    public function test_role_relationships_are_properly_configured(): void
    {
        $role = new Role();

        $this->assertInstanceOf(HasMany::class, $role->userRoles());
        $this->assertInstanceOf(HasMany::class, $role->users());
        $this->assertInstanceOf(BelongsTo::class, $role->tenant());
        $this->assertInstanceOf(BelongsTo::class, $role->parentRole());
        $this->assertInstanceOf(HasMany::class, $role->childRoles());
    }

    public function test_related_models_link_to_user_and_tenant(): void
    {
        $apiKey = new ApiKey();
        $this->assertInstanceOf(BelongsTo::class, $apiKey->user());
        $this->assertInstanceOf(BelongsTo::class, $apiKey->tenant());

        $auditLog = new AuditLog();
        $this->assertInstanceOf(BelongsTo::class, $auditLog->user());
        $this->assertInstanceOf(BelongsTo::class, $auditLog->outlet());

        $invitation = new UserInvitation();
        $this->assertInstanceOf(BelongsTo::class, $invitation->invitedBy());
        $this->assertInstanceOf(BelongsTo::class, $invitation->tenant());
        $this->assertInstanceOf(BelongsTo::class, $invitation->outlet());

        $subscription = new TenantSubscription();
        $this->assertInstanceOf(BelongsTo::class, $subscription->tenant());

        $outlet = new Outlet();
        $this->assertInstanceOf(BelongsTo::class, $outlet->tenant());
        $this->assertInstanceOf(HasMany::class, $outlet->users());

        $employee = new Employee();
        $this->assertInstanceOf(BelongsTo::class, $employee->user());
        $this->assertInstanceOf(BelongsTo::class, $employee->tenant());

        $meeting = new Meeting();
        $this->assertInstanceOf(BelongsTo::class, $meeting->organizer());
        $this->assertInstanceOf(BelongsTo::class, $meeting->tenant());
    }
}
