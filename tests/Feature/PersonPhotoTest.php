<?php

namespace Tests\Feature;

use App\Models\Association;
use App\Models\Dojo;
use App\Models\DojoRoleAssignment;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PersonPhotoTest extends TestCase
{
    use RefreshDatabase;

    private Association $association;

    private Dojo $dojo;

    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('Extensão GD ausente.');
        }
        Storage::fake('local');
        $this->association = Association::query()->create(['legal_name' => 'Associação', 'trade_name' => 'Associação', 'status' => 'active']);
        $this->dojo = Dojo::query()->create(['association_id' => $this->association->id, 'code' => 'N', 'name' => 'Dojo Norte']);
    }

    public function test_manager_uploads_replaces_and_removes_photo_shown_in_lists(): void
    {
        $student = $this->student('A-1', 'Aluna Bia');
        $manager = $this->associationUser(['person.view', 'person.manage', 'student.view', 'teacher.view']);
        $data = ['full_name' => 'Aluna Bia', 'country' => 'BR', 'status' => 'active'];

        $this->actingAs($manager)->get(route('associations.students.index', $this->association))->assertOk()->assertSee('>AB</span>', false);
        $this->put(route('associations.people.update', [$this->association, $student->person]), [...$data, 'photo' => UploadedFile::fake()->image('retrato.jpg', 300, 600)])
            ->assertSessionHasNoErrors();
        $person = $student->person->fresh();
        $first = $person->photo_path;
        $this->assertNotNull($first);
        $this->assertSame([96, 96], array_slice(getimagesizefromstring(Storage::disk('local')->get($first.'-thumb.jpg')), 0, 2));
        $this->assertSame([320, 320], array_slice(getimagesizefromstring(Storage::disk('local')->get($first.'-medium.jpg')), 0, 2));
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'person.photo_updated', 'association_id' => $this->association->id]);

        $url = $person->photoUrl();
        $this->get(route('associations.students.index', $this->association))->assertOk()->assertSee(e($url), false);
        $this->get(route('associations.people.index', $this->association))->assertOk()->assertSee(e($url), false);
        $this->get(route('associations.people.show', [$this->association, $person]))->assertOk()->assertSee('alt="Foto de Aluna Bia"', false);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        $this->put(route('associations.people.update', [$this->association, $person]), [...$data, 'photo' => UploadedFile::fake()->image('nova.png', 500, 500)])
            ->assertSessionHasNoErrors();
        $this->get($url)->assertNotFound();
        Storage::disk('local')->assertMissing($first.'-thumb.jpg');
        $person->refresh();
        $this->assertNotSame($first, $person->photo_path);

        $second = $person->photo_path;
        $this->put(route('associations.people.update', [$this->association, $person]), [...$data, 'remove_photo' => '1'])->assertSessionHasNoErrors();
        $this->assertNull($person->fresh()->photo_path);
        Storage::disk('local')->assertMissing($second.'-medium.jpg');
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'person.photo_removed']);
    }

    public function test_invalid_photo_is_rejected_before_saving_other_data(): void
    {
        $student = $this->student('A-1', 'Aluna Bia');
        $manager = $this->associationUser(['person.view', 'person.manage']);

        $this->actingAs($manager)->put(route('associations.people.update', [$this->association, $student->person]), [
            'full_name' => 'Outro Nome', 'country' => 'BR', 'status' => 'active',
            'photo' => UploadedFile::fake()->createWithContent('foto.jpg', 'não é imagem'),
        ])->assertSessionHasErrors('photo');
        $this->put(route('associations.people.update', [$this->association, $student->person]), [
            'full_name' => 'Outro Nome', 'country' => 'BR', 'status' => 'active',
            'photo' => UploadedFile::fake()->image('grande.jpg', 50, 50)->size(6000),
        ])->assertSessionHasErrors(['photo' => 'A foto deve ter até 5 MB.']);

        $this->assertSame('Aluna Bia', $student->person->fresh()->full_name);
        $this->assertNull($student->person->fresh()->photo_path);
    }

    public function test_photo_link_requires_signature_login_and_same_association(): void
    {
        $student = $this->student('A-1', 'Aluna Bia');
        $manager = $this->associationUser(['person.view', 'person.manage']);
        $this->actingAs($manager)->put(route('associations.people.update', [$this->association, $student->person]), [
            'full_name' => 'Aluna Bia', 'country' => 'BR', 'status' => 'active', 'photo' => UploadedFile::fake()->image('foto.jpg', 200, 200),
        ])->assertSessionHasNoErrors();
        $person = $student->person->fresh();
        $url = $person->photoUrl('medium');

        $this->get(route('people.photo', ['person' => $person, 'size' => 'medium', 'v' => 'x'], false))->assertForbidden();
        $this->get(str_replace('medium', 'large', $url))->assertForbidden();

        $other = Association::query()->create(['legal_name' => 'Outra', 'trade_name' => 'Outra', 'status' => 'active']);
        $this->actingAs(User::factory()->create(['association_id' => $other->id]))->get($url)->assertNotFound();

        auth()->logout();
        $this->get($url)->assertRedirect(route('login'));
    }

    public function test_dojo_manager_sets_photo_of_own_student_and_dojo_list_shows_it(): void
    {
        $student = $this->student('A-1', 'Aluna Bia');
        $manager = $this->dojoUser(['dojo.access', 'student.view', 'student.manage']);

        $this->actingAs($manager)->get(route('operation.students.person.edit', [$this->dojo, $student]))->assertOk()->assertSee('name="photo"', false)
            ->assertSee('enctype="multipart/form-data"', false);
        $this->put(route('operation.students.person.update', [$this->dojo, $student]), [
            'full_name' => 'Aluna Bia', 'country' => 'BR', 'photo' => UploadedFile::fake()->image('foto.webp', 640, 480),
        ])->assertSessionHasNoErrors()->assertRedirect(route('operation.students', $this->dojo));

        $person = $student->person->fresh();
        $this->assertNotNull($person->photo_path);
        $this->get(route('operation.students', $this->dojo))->assertOk()->assertSee(e($person->photoUrl()), false);
        $this->get($person->photoUrl())->assertOk();
    }

    private function associationUser(array $codes): User
    {
        $user = User::factory()->create(['association_id' => $this->association->id]);
        $user->roles()->attach($this->role('association', $codes), ['association_id' => $this->association->id]);

        return $user;
    }

    private function dojoUser(array $codes): User
    {
        $user = User::factory()->create(['association_id' => $this->association->id]);
        DojoRoleAssignment::query()->create(['association_id' => $this->association->id, 'dojo_id' => $this->dojo->id, 'user_id' => $user->id, 'role_id' => $this->role('dojo', $codes)->id, 'start_date' => today()->subDay(), 'created_by_user_id' => $user->id]);

        return $user;
    }

    private function role(string $scope, array $codes): Role
    {
        $role = Role::query()->create(['association_id' => $this->association->id, 'name' => $scope, 'code' => $scope.'_'.Str::random(8), 'scope_type' => $scope]);
        $role->permissions()->sync(collect($codes)->map(fn ($code) => Permission::query()->firstOrCreate(['code' => $code], ['description' => $code])->id));

        return $role;
    }

    private function student(string $registration, string $name): Student
    {
        $person = Person::query()->create(['full_name' => $name, 'birth_date' => '2000-01-01']);
        $this->association->people()->attach($person, ['role_type' => 'registered_person', 'start_date' => today()->subYear(), 'status' => 'active']);
        $student = Student::query()->create(['association_id' => $this->association->id, 'person_id' => $person->id, 'registration_number' => $registration, 'entry_date' => today()->subYear(), 'primary_dojo_id' => $this->dojo->id, 'status' => 'active']);
        $student->dojoHistory()->create(['dojo_id' => $this->dojo->id, 'start_date' => today()->subYear(), 'is_primary' => true]);

        return $student;
    }
}
