<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsProgressionContext;
use Tests\TestCase;

class AttendancePhotoTest extends TestCase
{
    use BuildsProgressionContext, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProgression();
    }

    public function test_roll_call_shows_photo_or_initials_of_each_student(): void
    {
        [$user, $teacher] = $this->teacher('Professora Ana');
        $group = $this->group($teacher);
        $session = ClassSession::query()->create(['class_group_id' => $group->id, 'session_date' => today()->toDateString(), 'start_time' => '18:00', 'end_time' => '19:00', 'status' => 'planned', 'attendance_state' => 'open']);
        $withPhoto = $this->student('A-1');
        $withPhoto->person->forceFill(['photo_path' => 'photos/'.$withPhoto->person_id.'/teste'])->save();
        $this->enroll($group, $withPhoto);
        $this->enroll($group, $this->student('A-2'));

        $this->actingAs($user)->get(route('operation.session', [$this->dojo, $session]))->assertOk()
            ->assertSee(e($withPhoto->person->fresh()->photoUrl()), false)
            ->assertSee('class="person-photo person-photo-thumb"  aria-hidden="true"', false);
    }
}
