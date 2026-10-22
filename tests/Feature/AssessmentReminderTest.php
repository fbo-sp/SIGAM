<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Models\ProgressionSetting;
use App\Models\TechniqueAssessment;
use App\Services\Notifications\NotificationGenerator;
use App\Support\Notifications\NotificationCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsProgressionContext;
use Tests\TestCase;

class AssessmentReminderTest extends TestCase
{
    use BuildsProgressionContext, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProgression();
        [$subject, $body] = NotificationCatalog::templates()['assessment.due'];
        NotificationTemplate::query()->create(['association_id' => null, 'event_type' => 'assessment.due', 'channel' => 'internal', 'subject_template' => $subject, 'body_template' => $body, 'status' => 'active']);
    }

    public function test_teacher_is_reminded_once_per_period_about_students_without_recent_assessment(): void
    {
        [$anaUser, $ana] = $this->teacher('Professora Ana');
        [$outsiderUser] = $this->teacher('Professor Bruno');
        $group = $this->group($ana);
        $stale = $this->student('A-1');
        $fresh = $this->student('A-2');
        $newcomer = $this->student('A-3');
        $this->enroll($group, $stale);
        $this->enroll($group, $fresh);
        $this->enroll($group, $newcomer)->update(['start_date' => '2026-10-01']);
        TechniqueAssessment::query()->create(['association_id' => $this->association->id, 'student_id' => $fresh->id, 'martial_art_id' => $this->art->id, 'technique_item_id' => $this->items['kano']->id, 'level' => 2, 'teacher_profile_id' => $ana->id, 'dojo_id' => $this->dojo->id, 'assessed_by_user_id' => $anaUser->id, 'assessed_on' => '2026-10-01']);
        ProgressionSetting::query()->create(['association_id' => $this->association->id, 'assessment_reminder_weeks' => 4]);

        $generator = app(NotificationGenerator::class);
        $generator->generate($this->association->id);
        $generator->generate($this->association->id);

        $notification = Notification::query()->where('event_type', 'assessment.due')->sole();
        $this->assertSame($anaUser->id, $notification->user_id);
        $this->assertStringContainsString('Na turma “Turma A”, 1 aluno(s) estão sem avaliação técnica há mais de 4 semanas', $notification->body);
        $this->assertSame(0, Notification::query()->where('user_id', $outsiderUser->id)->count());

        $this->travelTo('2026-11-20 08:00');
        $generator->generate($this->association->id);
        $this->assertSame(2, Notification::query()->where('event_type', 'assessment.due')->count());
    }
}
