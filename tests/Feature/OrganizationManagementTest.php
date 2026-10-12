<?php

namespace Tests\Feature;

use App\Models\Association;
use App\Models\Dojo;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_administrator_can_create_an_association_with_normalized_data(): void
    {
        $user = $this->userWithPermissions(null, ['association.view', 'association.manage']);

        $response = $this->actingAs($user)->post('/associacoes', [
            'legal_name' => 'Associação Caminho Suave',
            'trade_name' => 'Caminho Suave',
            'cnpj' => '11.222.333/0001-81',
            'email' => 'CONTATO@EXEMPLO.COM',
            'phone' => '(11) 3333-4444',
            'country' => 'BR',
            'status' => 'active',
        ]);

        $association = Association::query()->firstOrFail();
        $response->assertRedirect(route('associations.show', $association));
        $this->assertSame('11222333000181', $association->cnpj);
        $this->assertSame('contato@exemplo.com', $association->email);
        $this->assertDatabaseHas('audit_logs', ['association_id' => $association->id, 'event_type' => 'association.created']);
    }

    public function test_association_manager_cannot_open_another_association(): void
    {
        $associationA = Association::query()->create(['legal_name' => 'A', 'trade_name' => 'A']);
        $associationB = Association::query()->create(['legal_name' => 'B', 'trade_name' => 'B']);
        $user = $this->userWithPermissions($associationA, ['association.view']);

        $this->actingAs($user)->get(route('associations.show', $associationB))->assertForbidden();
    }

    public function test_dojo_is_created_only_inside_authorized_association(): void
    {
        $association = Association::query()->create(['legal_name' => 'A', 'trade_name' => 'A']);
        $user = $this->userWithPermissions($association, ['dojo.view', 'dojo.manage']);

        $response = $this->actingAs($user)->post(route('associations.dojos.store', $association), [
            'code' => ' centro ',
            'name' => 'Dojo Centro',
            'country' => 'BR',
            'status' => 'active',
        ]);

        $dojo = Dojo::query()->firstOrFail();
        $response->assertRedirect(route('associations.dojos.show', [$association, $dojo]));
        $this->assertSame('CENTRO', $dojo->code);
        $this->assertSame($association->id, $dojo->association_id);
    }

    public function test_nested_dojo_from_another_association_is_not_exposed(): void
    {
        $associationA = Association::query()->create(['legal_name' => 'A', 'trade_name' => 'A']);
        $associationB = Association::query()->create(['legal_name' => 'B', 'trade_name' => 'B']);
        $dojoB = Dojo::query()->create(['association_id' => $associationB->id, 'code' => 'B1', 'name' => 'Dojo B']);
        $user = $this->userWithPermissions($associationA, ['dojo.view']);

        $this->actingAs($user)->get(route('associations.dojos.show', [$associationA, $dojoB]))->assertNotFound();
    }

    public function test_inactive_association_rejects_new_dojo(): void
    {
        $association = Association::query()->create(['legal_name' => 'A', 'trade_name' => 'A', 'status' => 'inactive']);
        $user = $this->userWithPermissions(null, ['dojo.manage']);

        $this->actingAs($user)->post(route('associations.dojos.store', $association), [
            'code' => 'NOVO', 'name' => 'Novo Dojo', 'country' => 'BR', 'status' => 'active',
        ])->assertSessionHasErrors('association');

        $this->assertDatabaseCount('dojos', 0);
    }

    public function test_authorized_user_can_navigate_organization_pages(): void
    {
        $association = Association::query()->create(['legal_name' => 'Associação A', 'trade_name' => 'A']);
        $dojo = Dojo::query()->create(['association_id' => $association->id, 'code' => 'A1', 'name' => 'Dojo A']);
        $person = Person::query()->create(['full_name' => 'Maria Silva']);
        $association->people()->attach($person, ['role_type' => 'registered_person', 'start_date' => now()->toDateString(), 'status' => 'active']);
        $user = $this->userWithPermissions($association, ['association.view', 'dojo.view', 'person.view']);

        $this->actingAs($user)->get(route('associations.show', $association))->assertOk()->assertSee('Dojos');
        $this->get(route('associations.dojos.index', $association))->assertOk()->assertSee('Dojo A');
        $this->get(route('associations.people.index', $association))->assertOk()->assertSee('Maria Silva');
        $this->get(route('associations.people.export', $association))->assertOk()->assertDownload();
    }

    private function userWithPermissions(?Association $association, array $codes): User
    {
        $permissions = collect($codes)->map(fn (string $code) => Permission::query()->create(['code' => $code, 'description' => $code]));
        $role = Role::query()->create([
            'association_id' => $association?->id,
            'name' => 'Gestor de teste',
            'code' => 'manager_'.uniqid(),
            'scope_type' => $association ? 'association' : 'system',
        ]);
        $role->permissions()->sync($permissions->pluck('id'));
        $user = User::factory()->create(['association_id' => $association?->id]);
        $user->roles()->attach($role, ['association_id' => $association?->id]);

        return $user;
    }
}
