<?php

namespace Tests\Feature;

use App\Models\RBAC\Permission;
use App\Models\RBAC\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RbacApiTest extends TestCase
{
    use RefreshDatabase;

    protected function seedRoles(): void
    {
        foreach (['users.view', 'users.delete', 'candidates.view', 'admin.access'] as $name) {
            Permission::create([
                'name' => $name,
                'display_name' => $name,
                'guard_name' => 'web',
            ]);
        }

        $admin = Role::create([
            'name' => 'admin',
            'display_name' => 'Administrator',
        ]);
        $admin->syncPermissions(
            Permission::whereIn('name', ['users.view', 'users.delete', 'admin.access'])->get()
        );

        Role::create([
            'name' => 'recruiter',
            'display_name' => 'Recruiter',
        ]);
    }

    protected function makeAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->syncRoles(['admin']);

        return $admin->fresh();
    }

    public function test_rbac_endpoints_require_authentication(): void
    {
        $this->seedRoles();

        $this->getJson('/api/v1/rbac/roles')->assertUnauthorized();
        $this->getJson('/api/v1/rbac/permissions')->assertUnauthorized();
        $this->getJson('/api/v1/rbac/users')->assertUnauthorized();
    }

    public function test_rbac_endpoints_require_an_admin_role(): void
    {
        $this->seedRoles();

        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/rbac/roles')
            ->assertForbidden();
    }

    public function test_roles_endpoint_lists_roles_with_permissions_and_user_count(): void
    {
        $this->seedRoles();

        $response = $this->actingAs($this->makeAdmin())
            ->getJson('/api/v1/rbac/roles');

        $response->assertOk();

        $data = collect($response->json('data'))->keyBy('name');

        $this->assertTrue($data->has('admin'));
        $this->assertTrue($data->has('recruiter'));
        $this->assertEqualsCanonicalizing(
            ['users.view', 'users.delete', 'admin.access'],
            $data->get('admin')['permissions']
        );
        $this->assertEquals(1, $data->get('admin')['user_count']);
    }

    public function test_a_role_can_be_created_and_assigned_to_a_user(): void
    {
        $this->seedRoles();

        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->postJson('/api/v1/rbac/roles', [
            'name' => 'auditor',
            'display_name' => 'Compliance Auditor',
            'description' => 'Read-only access',
            'permissions' => ['users.view'],
        ]);

        $response->assertCreated();

        $role = Role::where('name', 'auditor')->firstOrFail();

        $this->assertEquals(
            ['users.view'],
            $role->permissions->pluck('name')->all()
        );

        $admin->syncRoles(['auditor']);

        $this->assertTrue($admin->fresh()->hasRole('auditor'));
    }

    public function test_a_user_can_be_listed_with_their_roles(): void
    {
        $this->seedRoles();

        $user = User::factory()->create();
        $user->syncRoles(['recruiter']);

        $response = $this->actingAs($this->makeAdmin())
            ->getJson('/api/v1/rbac/users');

        $response->assertOk();

        $this->assertCount(2, $response->json('data'));
    }

    public function test_gate_wildcard_permission_matching(): void
    {
        $this->seedRoles();

        $admin = User::factory()->create();
        $admin->syncRoles(['admin']);

        $this->assertTrue($admin->hasAnyPermission(['admin.*', 'users.view']));
        $this->assertTrue($admin->can('users.view'));
        $this->assertTrue($admin->can('users.delete'));
        $this->assertFalse($admin->hasAnyPermission(['recruiter.*']));
    }
}
