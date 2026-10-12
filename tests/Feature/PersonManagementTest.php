<?php

namespace Tests\Feature;

use App\Models\Association;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersonManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_creates_normalized_person_and_association_link(): void
    {
        [$association, $user] = $this->context(['person.view', 'person.manage']);

        $response = $this->actingAs($user)->post(route('associations.people.store', $association), [
            'full_name' => '  Maria da Silva  ',
            'cpf' => '529.982.247-25',
            'email' => 'MARIA@EXEMPLO.COM',
            'mobile' => '(11) 99999-8888',
            'country' => 'BR',
            'status' => 'active',
        ]);

        $person = Person::query()->firstOrFail();
        $response->assertRedirect(route('associations.people.show', [$association, $person]));
        $this->assertSame('52998224725', $person->cpf);
        $this->assertSame('maria@exemplo.com', $person->email);
        $this->assertDatabaseHas('association_person_roles', ['association_id' => $association->id, 'person_id' => $person->id, 'role_type' => 'registered_person']);
        $this->assertDatabaseHas('audit_logs', ['association_id' => $association->id, 'event_type' => 'person.created']);
    }

    public function test_duplicate_cpf_is_rejected_without_creating_another_person(): void
    {
        [$association, $user] = $this->context(['person.manage']);
        Person::query()->create(['full_name' => 'Pessoa existente', 'cpf' => '52998224725']);

        $this->actingAs($user)->from(route('associations.people.create', $association))->post(route('associations.people.store', $association), [
            'full_name' => 'Cadastro repetido',
            'cpf' => '529.982.247-25',
            'country' => 'BR',
            'status' => 'active',
        ])->assertRedirect(route('associations.people.create', $association))->assertSessionHasErrors('cpf');

        $this->assertDatabaseCount('people', 1);
    }

    public function test_person_outside_association_scope_is_not_exposed(): void
    {
        [$associationA, $user] = $this->context(['person.view']);
        $associationB = Association::query()->create(['legal_name' => 'B', 'trade_name' => 'B']);
        $personB = Person::query()->create(['full_name' => 'Pessoa B']);
        $associationB->people()->attach($personB, ['role_type' => 'registered_person', 'start_date' => now()->toDateString(), 'status' => 'active']);

        $this->actingAs($user)->get(route('associations.people.show', [$associationA, $personB]))->assertNotFound();
    }

    public function test_user_without_management_permission_cannot_create_person(): void
    {
        [$association, $user] = $this->context(['person.view']);

        $this->actingAs($user)->post(route('associations.people.store', $association), [
            'full_name' => 'Pessoa sem autorização',
            'country' => 'BR',
            'status' => 'active',
        ])->assertForbidden();
    }

    private function context(array $codes): array
    {
        $association = Association::query()->create(['legal_name' => 'Associação A', 'trade_name' => 'A']);
        $permissions = collect($codes)->map(fn (string $code) => Permission::query()->create(['code' => $code, 'description' => $code]));
        $role = Role::query()->create(['association_id' => $association->id, 'name' => 'Gestor', 'code' => 'manager_'.uniqid(), 'scope_type' => 'association']);
        $role->permissions()->sync($permissions->pluck('id'));
        $user = User::factory()->create(['association_id' => $association->id]);
        $user->roles()->attach($role, ['association_id' => $association->id]);

        return [$association, $user];
    }
}
