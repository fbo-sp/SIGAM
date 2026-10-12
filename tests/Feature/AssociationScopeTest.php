<?php

namespace Tests\Feature;

use App\Models\Association;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\AssociationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssociationScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_resolves_users_current_association(): void
    {
        $association = Association::query()->create([
            'legal_name' => 'Associação Exemplo',
            'trade_name' => 'Dojo Central',
        ]);
        $user = User::factory()->create(['association_id' => $association->id]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Dojo Central');

        $this->assertSame($association->id, app(AssociationContext::class)->id());
    }

    public function test_user_cannot_operate_with_an_inactive_association(): void
    {
        $association = Association::query()->create([
            'legal_name' => 'Associação Inativa',
            'trade_name' => 'Associação Inativa',
            'status' => 'inactive',
        ]);
        $user = User::factory()->create(['association_id' => $association->id]);

        $this->actingAs($user)->get('/dashboard')->assertForbidden();
    }

    public function test_permission_must_match_association_scope(): void
    {
        $associationA = Association::query()->create(['legal_name' => 'A', 'trade_name' => 'A']);
        $associationB = Association::query()->create(['legal_name' => 'B', 'trade_name' => 'B']);
        $permission = Permission::query()->create([
            'code' => 'person.view',
            'description' => 'Visualizar pessoas',
        ]);
        $role = Role::query()->create([
            'association_id' => $associationA->id,
            'name' => 'Gestor',
            'code' => 'manager',
            'scope_type' => 'association',
        ]);
        $role->permissions()->attach($permission);
        $user = User::factory()->create(['association_id' => $associationA->id]);
        $user->roles()->attach($role, ['association_id' => $associationA->id]);

        $this->assertTrue($user->hasPermission('person.view', $associationA->id));
        $this->assertFalse($user->hasPermission('person.view', $associationB->id));
        $this->assertFalse($user->hasPermission('person.manage', $associationA->id));
    }
}
