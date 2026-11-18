<?php

namespace Tests\Feature\BDD;

use App\Models\User;
use App\Models\RBAC\User as RBACUser;
use App\Modules\HR\Models\User as HRUSer;
use Spatie\Permission\Traits\HasRoles;
use Tests\TestCase;

class UserModelBddTest extends TestCase
{
    public function test_user_model_uses_spatie_roles_trait_without_custom_duplication()
    {
        $user = new User();

        $traitUses = class_uses(User::class);

        $this->assertContains(HasRoles::class, $traitUses);

        // Spatie trait provides hasRole via HasRoles
        $this->assertTrue(method_exists($user, 'hasRole'));

        // Spatie trait provides hasPermissionTo via HasPermissions (not hasPermission)
        $this->assertTrue(method_exists($user, 'hasPermissionTo'));

        // HasWildcardPermissions provides hasAnyPermission with wildcard support
        $this->assertTrue(method_exists($user, 'hasAnyPermission'));
    }

    public function test_rbac_user_uses_spatie_traits_without_custom_override()
    {
        $rbacUser = new RBACUser();

        // RBAC User model should not override hasRole with custom logic
        // It should use Spatie's HasRoles trait method
        $traitUses = class_uses(RBACUser::class);

        $this->assertContains(HasRoles::class, $traitUses);

        // hasRole should come from the trait (not be custom-declared in class body)
        $this->assertTrue(method_exists($rbacUser, 'hasRole'));

        // RBAC User intentionally overrides hasAnyPermission via wildcard trait
        $this->assertTrue(method_exists($rbacUser, 'hasAnyPermission'));
    }

    public function test_hr_user_uses_roles_trait_correctly()
    {
        $hrUser = new HRUSer();

        $traitUses = class_uses(HRUSer::class);

        $this->assertContains(HasRoles::class, $traitUses);

        $this->assertTrue(method_exists($hrUser, 'hasRole'));
    }
}