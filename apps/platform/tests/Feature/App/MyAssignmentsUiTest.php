<?php

namespace Tests\Feature\App;

use App\Domain\LMS\Infrastructure\Assignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesLmsOwnershipFixtures;
use Tests\Concerns\CreatesTeacherDeliveryFixtures;
use Tests\Concerns\CreatesTeacherLearningContentFixtures;
use Tests\Concerns\CreatesTeachingAssignmentFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * TCH.5D -- "My Assignments": the owned teacher page shows only the classes
 * the teacher teaches today and only the Assignments they may read; it
 * writes through the teacher guard; the dashboard link follows
 * `lms.assignments.teacher`, never the role key. No Submission surface.
 */
class MyAssignmentsUiTest extends TestCase
{
    use CreatesLmsOwnershipFixtures, CreatesTeacherDeliveryFixtures, CreatesTeacherLearningContentFixtures, CreatesTeachingAssignmentFixtures, CreatesTenancyFixtures;

    private function actor(array $w, User $user): static
    {
        return $this->actingAs($user)->withHeader('X-School-Id', $w['school']->id);
    }

    #[Test]
    public function a_teacher_sees_their_classes_and_only_the_rows_they_may_read(): void
    {
        $w = $this->contentWorld();
        [$user] = $this->contentTeacher($w);
        [$colleague] = $this->contentTeacher($w);
        $mine = $this->teacherAssignment($w, $user);
        $shared = $this->adminAssignment($w);
        $this->teacherAssignment($w, $colleague);   // a colleague's draft
        $this->adminAssignment($w, 'draft');

        $this->actor($w, $user)->get('/app/my-assignments?subject_offering_id='.$w['offering']->id)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('App/LMS/Assignments/Mine')
                ->where('canAuthor', true)
                ->has('contexts', 1)
                ->where('contexts.0.sections', [['id' => $w['sectionA']->id, 'code' => 'A', 'name' => 'A']])
                ->where('filters.subjectOfferingId', $w['offering']->id)
                ->where('assignments', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all() === collect([$mine->id, $shared->id])->sort()->values()->all())
                ->where('assignments', fn ($rows) => collect($rows)->firstWhere('id', $shared->id)['canEdit'] === false));
    }

    #[Test]
    public function a_teacher_creates_edits_publishes_and_closes_through_the_page(): void
    {
        $w = $this->contentWorld();
        [$user, $employee] = $this->contentTeacher($w, ['sectionA', 'sectionB']);

        $this->actor($w, $user)->post('/app/my-assignments', [
            'subject_offering_id' => $w['offering']->id,
            'title' => 'Worksheet', 'due_on' => '2026-10-30',
            'audience_section_ids' => [$w['sectionA']->id, $w['sectionB']->id],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $assignment = $this->withinSchool($w['school'], fn () => Assignment::query()->firstOrFail());
        $this->assertSame($employee->id, $assignment->getAttribute('owner_employee_id'));

        $this->actor($w, $user)->patch("/app/my-assignments/{$assignment->id}", ['title' => 'Worksheet, revised'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actor($w, $user)->post("/app/my-assignments/{$assignment->id}/publish")->assertRedirect()->assertSessionHasNoErrors();
        $this->actor($w, $user)->post("/app/my-assignments/{$assignment->id}/close")->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('closed', $this->withinSchool($w['school'], fn () => $assignment->fresh()->status));

        // An untaught Section is a form error, and a row they do not own is refused.
        $this->actor($w, $user)->post('/app/my-assignments', [
            'subject_offering_id' => $w['offering']->id, 'title' => 'x', 'audience_section_ids' => [$w['otherGrade']->id],
        ])->assertSessionHasErrors('audience_section_ids');
        $shared = $this->adminAssignment($w);
        $this->actor($w, $user)->post("/app/my-assignments/{$shared->id}/close")->assertForbidden();
        $this->assertSame(2, $this->withinSchool($w['school'], fn () => DB::table('assignment_section_audiences')->count()));
    }

    #[Test]
    public function the_navigation_follows_the_capability_and_admin_pages_stay_closed(): void
    {
        $w = $this->contentWorld();
        [$user] = $this->contentTeacher($w);
        [$noCapability] = $this->contentTeacher($w, roleKey: 'principal');

        $this->actor($w, $user)->get('/app')->assertInertia(fn (AssertableInertia $page) => $page->where('nav.canUseMyAssignments', true));
        $this->actor($w, $noCapability)->get('/app')->assertInertia(fn (AssertableInertia $page) => $page->where('nav.canUseMyAssignments', false));

        $this->actor($w, $noCapability)->get('/app/my-assignments')->assertForbidden();
        foreach (['/app/learning-content', '/app/assignments', '/app/teaching-assignments', '/app/students', '/app/timetable-schedule'] as $page) {
            $this->actor($w, $user)->get($page)->assertForbidden();
        }
    }

    #[Test]
    public function a_capability_holder_who_is_not_an_eligible_employee_sees_an_empty_page_and_cannot_write(): void
    {
        $w = $this->contentWorld();
        [$user] = $this->teacher($w, 'teacher', linked: false);

        $this->actor($w, $user)->get('/app/my-assignments')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canAuthor', false)->has('contexts', 0)->has('assignments', 0));
        $this->actor($w, $user)->post('/app/my-assignments', [
            'subject_offering_id' => $w['offering']->id, 'title' => 'x', 'audience_section_ids' => [$w['sectionA']->id],
        ])->assertForbidden();
    }
}
