<?php

namespace Tests\Feature\App;

use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Examinations\Concerns\CreatesTeacherStudentMarkFixtures;
use Tests\TestCase;

/**
 * SWC.2 (ADR 0073 sections 10, 14): Subject Offerings are created and
 * changed from the browser through SubjectOfferingService -- the code
 * `/api/v1` uses. The ADR 0069 classification freeze is enforced on a
 * direct request, not only by a disabled control: a locked change is a
 * form error and writes nothing. Deactivation is `status`, never a delete.
 */
class SubjectOfferingAdminUiTest extends TestCase
{
    use CreatesTeacherStudentMarkFixtures;

    private function manager(School $school): User
    {
        $user = $this->createUserWithCapabilities($school, ['academics.subjects.view', 'academics.subjects.manage']);
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");

        return $user;
    }

    private function offering(School $school, string $id): SubjectOffering
    {
        return app(TenantContext::class)->withSchool($school, fn () => SubjectOffering::query()->findOrFail($id));
    }

    #[Test]
    public function an_offering_is_created_from_the_index_with_its_choices(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school, ['status' => 'active']);
        $grade = $this->createGradeLevel($school);
        $subject = $this->createSubject($school, ['name' => 'French']);
        $this->createSubject($school, ['status' => 'inactive']);
        $user = $this->manager($school);

        $this->actingAs($user)->get('/app/subject-offerings')->assertOk()->assertInertia(fn ($page) => $page
            ->where('filters.academic_year_id', $year->id)
            ->has('createOptions.subjects', 1)->where('createOptions.subjects.0.name', 'French')
            ->has('createOptions.campuses', 1)->has('createOptions.gradeLevels', 1));

        $response = $this->actingAs($user)->post('/app/subject-offerings', [
            'academic_year_id' => $year->id, 'campus_id' => $campus->id, 'grade_level_id' => $grade->id,
            'subject_id' => $subject->id, 'is_required' => false, 'weekly_periods_target' => 4,
        ]);
        $created = app(TenantContext::class)->withSchool($school, fn () => SubjectOffering::query()->firstOrFail());
        $response->assertRedirect(route('app.subject-offerings.show', $created->id));
        $this->assertSame([false, 4, 'active'], [$created->is_required, $created->weekly_periods_target, $created->status]);

        // The same Subject twice in one Year/Campus/Grade is a field error, not a 500.
        $this->actingAs($user)->post('/app/subject-offerings', [
            'academic_year_id' => $year->id, 'campus_id' => $campus->id, 'grade_level_id' => $grade->id, 'subject_id' => $subject->id,
        ])->assertSessionHasErrors('subject_id');
        $this->assertSame(1, app(TenantContext::class)->withSchool($school, fn () => SubjectOffering::query()->count()));

        $this->actingAs($user)->get("/app/subject-offerings/{$created->id}")->assertOk()->assertInertia(fn ($page) => $page
            ->where('offering.weeklyPeriodsTarget', 4)->where('offering.isRequired', false)->where('canManage', true));
    }

    #[Test]
    public function the_classification_lock_holds_on_a_direct_request_and_other_settings_still_change(): void
    {
        $w = $this->marksWorld(); // both Offerings have examination papers: academic evidence
        $user = $this->manager($w['school']);
        $audits = fn () => app(TenantContext::class)->withSchool($w['school'], fn () => SchoolAuditEvent::query()->where('event_type', 'subject_offering.updated')->count());

        $this->actingAs($user)->put("/app/subject-offerings/{$w['required']->id}", ['is_required' => false])
            ->assertRedirect()
            ->assertSessionHasErrors(['is_required' => 'This Subject Offering already has academic records, so it cannot be changed between required and elective.']);
        $this->assertTrue($this->offering($w['school'], $w['required']->id)->is_required, 'the lock is the server\'s, whatever the browser sent');
        $this->assertSame(0, $audits(), 'a refused change writes no audit row');

        $this->actingAs($user)->put("/app/subject-offerings/{$w['required']->id}", ['is_required' => true, 'sequence' => 2, 'weekly_periods_target' => 6])
            ->assertSessionHasNoErrors();
        $fresh = $this->offering($w['school'], $w['required']->id);
        $this->assertSame([true, 2, 6], [$fresh->is_required, $fresh->sequence, $fresh->weekly_periods_target]);
    }

    #[Test]
    public function an_offering_is_deactivated_and_reactivated_never_deleted(): void
    {
        $w = $this->marksWorld();
        $user = $this->manager($w['school']);

        $this->actingAs($user)->put("/app/subject-offerings/{$w['elective']->id}", ['status' => 'inactive'])->assertSessionHasNoErrors();
        $this->assertSame('inactive', $this->offering($w['school'], $w['elective']->id)->status);
        $this->actingAs($user)->put("/app/subject-offerings/{$w['elective']->id}", ['status' => 'inactive'])->assertSessionHasNoErrors(); // a stale repeat converges
        $this->actingAs($user)->put("/app/subject-offerings/{$w['elective']->id}", ['status' => 'active'])->assertSessionHasNoErrors();
        $this->assertSame('active', $this->offering($w['school'], $w['elective']->id)->status);
        $this->actingAs($user)->put("/app/subject-offerings/{$w['elective']->id}", ['status' => 'archived'])->assertSessionHasErrors('status');
    }

    #[Test]
    public function viewers_and_other_schools_cannot_change_offerings(): void
    {
        $w = $this->marksWorld();
        $viewer = $this->createUserWithCapabilities($w['school'], ['academics.subjects.view']);
        $this->actingAs($viewer)->post("/app/schools/{$w['school']->id}/activate");

        $this->actingAs($viewer)->get('/app/subject-offerings')->assertOk()->assertInertia(fn ($page) => $page->where('createOptions', null));
        $this->actingAs($viewer)->put("/app/subject-offerings/{$w['elective']->id}", ['status' => 'inactive'])->assertForbidden();
        $this->actingAs($viewer)->post('/app/subject-offerings', ['academic_year_id' => $w['year']->id])->assertForbidden();

        $other = $this->createSchool();
        $foreignManager = $this->manager($other);
        $this->assertSame(404, $this->actingAs($foreignManager)->put("/app/subject-offerings/{$w['elective']->id}", ['status' => 'inactive'])->getStatusCode());
        $ownCampus = $this->createCampus($other);
        $ownGrade = $this->createGradeLevel($other);
        $ownSubject = $this->createSubject($other);
        // Another School's Year as the parent of a new Offering.
        $this->assertSame(404, $this->actingAs($foreignManager)->post('/app/subject-offerings', [
            'academic_year_id' => $w['year']->id, 'campus_id' => $ownCampus->id, 'grade_level_id' => $ownGrade->id, 'subject_id' => $ownSubject->id,
        ])->getStatusCode());
        $this->assertSame('active', $this->offering($w['school'], $w['elective']->id)->status);
    }
}
