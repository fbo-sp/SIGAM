<?php

namespace Tests\Feature\Concerns;

use App\Models\Association;
use App\Models\ClassEnrollment;
use App\Models\ClassGroup;
use App\Models\Dojo;
use App\Models\DojoRoleAssignment;
use App\Models\DojoTeacherAssignment;
use App\Models\MartialArt;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Rank;
use App\Models\RankCurriculumEntry;
use App\Models\Role;
use App\Models\Student;
use App\Models\TeacherAssociationMembership;
use App\Models\TeacherProfile;
use App\Models\TechniqueItem;
use App\Models\User;
use Illuminate\Support\Str;

/** Associação, dojo, judô com três faixas, catálogo pequeno e currículo da faixa amarela para os testes do acompanhamento técnico. */
trait BuildsProgressionContext
{
    protected Association $association;

    protected Dojo $dojo;

    protected MartialArt $art;

    protected Rank $white;

    protected Rank $yellow;

    /** @var array<string, TechniqueItem> */
    protected array $items = [];

    protected function setUpProgression(): void
    {
        $this->travelTo('2026-10-15 08:00');
        $this->association = Association::query()->create(['legal_name' => 'Associação', 'trade_name' => 'Associação', 'status' => 'active']);
        $this->dojo = Dojo::query()->create(['association_id' => $this->association->id, 'code' => 'N', 'name' => 'Dojo Norte']);
        $this->art = MartialArt::query()->create(['association_id' => $this->association->id, 'name' => 'Judô']);
        $this->white = Rank::query()->create(['martial_art_id' => $this->art->id, 'name' => 'branca', 'display_name' => 'Faixa branca', 'sort_order' => 1, 'status' => 'active']);
        $this->yellow = Rank::query()->create(['martial_art_id' => $this->art->id, 'name' => 'amarela', 'display_name' => 'Faixa amarela', 'sort_order' => 2, 'minimum_age' => 6, 'minimum_months' => 6, 'status' => 'active']);
        Rank::query()->create(['martial_art_id' => $this->art->id, 'name' => 'laranja', 'display_name' => 'Faixa laranja', 'sort_order' => 3, 'status' => 'active']);

        $theory = $this->item('Teoria', 'theoretical');
        $history = $this->item('História', 'theoretical', $theory, 'group');
        $rules = $this->item('Regras', 'theoretical', $theory, 'group');
        $this->items['kano'] = $this->item('Jigoro Kano', 'theoretical', $history);
        $this->items['shido'] = $this->item('Shido', 'theoretical', $rules);
        foreach (['Nage-waza' => 'O-goshi', 'Katame-waza' => 'Kesa-gatame', 'Kata' => 'Nage-no-kata'] as $root => $name) {
            $this->items[$name] = $this->item($name, 'practical', $this->item($root, 'practical'));
        }
        foreach (['kano' => 2, 'shido' => 2, 'O-goshi' => 3, 'Kesa-gatame' => 2, 'Nage-no-kata' => 2] as $key => $level) {
            RankCurriculumEntry::query()->create(['association_id' => $this->association->id, 'rank_id' => $this->yellow->id, 'technique_item_id' => $this->items[$key]->id, 'required_level' => $level]);
        }
    }

    protected function item(string $name, string $knowledge, ?TechniqueItem $parent = null, string $kind = 'item'): TechniqueItem
    {
        return TechniqueItem::query()->create([
            'association_id' => $this->association->id, 'martial_art_id' => $this->art->id, 'parent_id' => $parent?->id,
            'kind' => $parent === null ? 'group' : $kind, 'knowledge_type' => $knowledge, 'name' => $name, 'status' => 'active',
        ]);
    }

    /** @return array{User, TeacherProfile} */
    protected function teacher(string $name): array
    {
        $person = $this->person($name);
        $teacher = TeacherProfile::query()->create(['person_id' => $person->id, 'status' => 'active']);
        TeacherAssociationMembership::query()->create(['association_id' => $this->association->id, 'teacher_profile_id' => $teacher->id, 'status' => 'active']);
        $user = User::factory()->create(['association_id' => $this->association->id, 'person_id' => $person->id]);
        $this->grant($user, $this->role('teacher', ['dojo.access', 'class.view', 'session.view', 'attendance.manage', 'assessment.view', 'assessment.manage', 'progression.report']));
        DojoTeacherAssignment::query()->create(['association_id' => $this->association->id, 'dojo_id' => $this->dojo->id, 'teacher_profile_id' => $teacher->id, 'start_date' => '2026-09-01', 'created_by_user_id' => $user->id]);

        return [$user, $teacher];
    }

    protected function role(string $scope, array $codes): Role
    {
        $role = Role::query()->create(['association_id' => $this->association->id, 'name' => $scope, 'code' => $scope.'_'.Str::random(8), 'scope_type' => $scope]);
        $role->permissions()->sync(collect($codes)->map(fn ($code) => Permission::query()->firstOrCreate(['code' => $code], ['description' => $code])->id));

        return $role;
    }

    protected function grant(User $user, Role $role): void
    {
        DojoRoleAssignment::query()->create(['association_id' => $this->association->id, 'dojo_id' => $this->dojo->id, 'user_id' => $user->id, 'role_id' => $role->id, 'start_date' => '2026-09-01', 'created_by_user_id' => $user->id]);
    }

    protected function associationUser(array $codes): User
    {
        $user = User::factory()->create(['association_id' => $this->association->id]);
        $user->roles()->attach($this->role('association', $codes), ['association_id' => $this->association->id]);

        return $user;
    }

    protected function portalUser(Person $person): User
    {
        $user = User::factory()->create(['association_id' => $this->association->id, 'person_id' => $person->id]);
        $user->roles()->attach($this->role('association', ['portal.access']), ['association_id' => $this->association->id]);

        return $user;
    }

    protected function person(string $name, ?string $birthDate = '2000-01-01'): Person
    {
        $person = Person::query()->create(['full_name' => $name, 'birth_date' => $birthDate]);
        $this->association->people()->attach($person, ['role_type' => 'registered_person', 'start_date' => '2026-01-01', 'status' => 'active']);

        return $person;
    }

    protected function student(string $registration, ?string $birthDate = '2000-01-01', string $rankSince = '2026-01-01'): Student
    {
        $student = Student::query()->create(['association_id' => $this->association->id, 'person_id' => $this->person($registration, $birthDate)->id, 'registration_number' => $registration, 'entry_date' => '2026-01-01', 'primary_dojo_id' => $this->dojo->id, 'status' => 'active']);
        $student->dojoHistory()->create(['dojo_id' => $this->dojo->id, 'start_date' => '2026-01-01', 'is_primary' => true]);
        $link = $student->martialArts()->create(['martial_art_id' => $this->art->id, 'start_date' => '2026-01-01', 'status' => 'active', 'current_rank_id' => $this->white->id]);
        $link->rankHistory()->create(['rank_id' => $this->white->id, 'achieved_at' => $rankSince]);

        return $student;
    }

    protected function group(TeacherProfile $teacher): ClassGroup
    {
        return ClassGroup::query()->create(['association_id' => $this->association->id, 'dojo_id' => $this->dojo->id, 'martial_art_id' => $this->art->id, 'name' => 'Turma A', 'main_teacher_id' => $teacher->id, 'start_date' => '2026-09-01', 'status' => 'active']);
    }

    protected function enroll(ClassGroup $group, Student $student): ClassEnrollment
    {
        return ClassEnrollment::query()->create(['class_group_id' => $group->id, 'student_id' => $student->id, 'start_date' => '2026-09-01', 'status' => 'active']);
    }
}
