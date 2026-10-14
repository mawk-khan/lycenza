<?php

namespace Tests\Feature\App;

use App\Domain\AcademicStructure\Infrastructure\ElectiveGroup;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Examinations\Concerns\CreatesTeacherStudentMarkFixtures;
use Tests\TestCase;

/**
 * SWC.2 (ADR 0073 sections 4.3, 16 X1): Elective Groups are configured from
 * the browser over ElectiveGroupService -- create a group for one (Year,
 * Campus, Grade level), assign or remove an elective Offering of that same
 * context. The service's rules stay the authority: no required Offering, no
 * other context, no change once Students participate; another School's
 * group or Offering is a 404. Afterwards the existing elective-enrollment
 * workspace runs on the configured group.
 */
class ElectiveGroupUiTest extends TestCase
{
    use CreatesTeacherStudentMarkFixtures;

    private function manager(School $school): User
    {
        $user = $this->createUserWithCapabilities($school, ['academics.subjects.view', 'academics.subjects.manage', 'students.view', 'enrollments.view']);
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");

        return $user;
    }

    private function inSchool(School $school, \Closure $fn): mixed
    {
        return app(TenantContext::class)->withSchool($school, $fn);
    }

    /** @param  array<string, mixed>  $w */
    private function createGroup(array $w, User $user, string $code = 'lang'): ElectiveGroup
    {
        $this->actingAs($user)->post('/app/elective-groups', [
            'academic_year_id' => $w['year']->id, 'campus_id' => $w['campus']->id, 'grade_level_id' => $w['grade']->id,
            'name' => 'Third language', 'code' => $code,
        ])->assertSessionHasNoErrors();

        return $this->inSchool($w['school'], fn () => ElectiveGroup::query()->where('code', strtoupper($code))->firstOrFail());
    }

    #[Test]
    public function a_group_is_created_listed_and_shown_with_its_candidates(): void
    {
        $w = $this->marksWorld();
        $user = $this->manager($w['school']);
        $free = $this->createSubjectOffering($w['year'], $w['campus'], $w['grade'], $this->createSubject($w['school'], ['name' => 'Sanskrit']), ['is_required' => false, 'status' => 'active']);

        $group = $this->createGroup($w, $user);
        $this->assertSame('LANG', $group->code);
        $this->actingAs($user)->post('/app/elective-groups', [
            'academic_year_id' => $w['year']->id, 'campus_id' => $w['campus']->id, 'grade_level_id' => $w['grade']->id, 'name' => 'Again', 'code' => 'Lang',
        ])->assertSessionHasErrors('code');

        $this->actingAs($user)->get('/app/elective-groups')->assertOk()->assertInertia(fn ($page) => $page
            ->component('App/ElectiveGroups/Index')->where('groups.0.code', 'LANG')->where('groups.0.offeringCount', 0)->where('canManage', true));
        $this->actingAs($user)->get("/app/elective-groups/{$group->id}")->assertOk()->assertInertia(fn ($page) => $page
            ->component('App/ElectiveGroups/Show')
            ->has('assigned', 0)
            // Elective Offerings of the same context only -- never the required one.
            ->where('candidates', fn ($c) => collect($c)->pluck('id')->sort()->values()->all() === collect([$free->id, $w['elective']->id])->sort()->values()->all()));
    }

    #[Test]
    public function an_offering_is_assigned_and_removed_and_repeats_converge(): void
    {
        $w = $this->marksWorld();
        $user = $this->manager($w['school']);
        $free = $this->createSubjectOffering($w['year'], $w['campus'], $w['grade'], $this->createSubject($w['school']), ['is_required' => false, 'status' => 'active']);
        $group = $this->createGroup($w, $user);
        $url = "/app/elective-groups/{$group->id}/offerings";

        $this->actingAs($user)->post($url, ['subject_offering_id' => $free->id])->assertRedirect(route('app.elective-groups.show', $group->id))->assertSessionHasNoErrors();
        $this->actingAs($user)->post($url, ['subject_offering_id' => $free->id])->assertSessionHasNoErrors(); // a double submit is a no-op
        $this->assertSame($group->id, $this->inSchool($w['school'], fn () => SubjectOffering::query()->findOrFail($free->id)->elective_group_id));

        // The existing elective-enrollment workspace now shows the group.
        $this->actingAs($user)->get("/app/subject-offerings/{$free->id}")->assertOk()->assertInertia(fn ($page) => $page->where('offering.electiveGroup.id', $group->id));

        $this->actingAs($user)->delete("{$url}/{$free->id}")->assertSessionHasNoErrors();
        $this->assertNull($this->inSchool($w['school'], fn () => SubjectOffering::query()->findOrFail($free->id)->elective_group_id));
        $this->assertSame(404, $this->actingAs($user)->delete("{$url}/{$free->id}")->getStatusCode(), 'only an Offering in the group can be removed through it');
    }

    #[Test]
    public function the_services_eligibility_rules_are_form_errors(): void
    {
        $w = $this->marksWorld();
        $user = $this->manager($w['school']);
        $group = $this->createGroup($w, $user);
        $url = "/app/elective-groups/{$group->id}/offerings";

        // Required Offering.
        $this->actingAs($user)->post($url, ['subject_offering_id' => $w['required']->id])
            ->assertSessionHasErrors(['subject_offering_id' => 'A required SubjectOffering cannot be assigned to an ElectiveGroup.']);
        // Another Grade level (a different academic context).
        $otherGrade = $this->createGradeLevel($w['school'], ['code' => 'G6', 'sequence' => 6]);
        $elsewhere = $this->createSubjectOffering($w['year'], $w['campus'], $otherGrade, $this->createSubject($w['school']), ['is_required' => false]);
        $this->actingAs($user)->post($url, ['subject_offering_id' => $elsewhere->id])->assertSessionHasErrors('subject_offering_id');
        // Students already participate: the assignment cannot change.
        $this->elect($w, $this->markStudent($w));
        $this->actingAs($user)->post($url, ['subject_offering_id' => $w['elective']->id])
            ->assertSessionHasErrors(['subject_offering_id' => 'This SubjectOffering already has Student participation history -- its ElectiveGroup assignment cannot change.']);

        $this->assertSame(0, $this->inSchool($w['school'], fn () => SubjectOffering::query()->where('elective_group_id', $group->id)->count()));
    }

    #[Test]
    public function viewers_and_other_schools_are_refused(): void
    {
        $w = $this->marksWorld();
        $group = $this->createGroup($w, $this->manager($w['school']));

        $viewer = $this->createUserWithCapabilities($w['school'], ['academics.subjects.view']);
        $this->actingAs($viewer)->post("/app/schools/{$w['school']->id}/activate");
        $this->actingAs($viewer)->get("/app/elective-groups/{$group->id}")->assertOk()->assertInertia(fn ($page) => $page->where('canManage', false));
        $this->actingAs($viewer)->post("/app/elective-groups/{$group->id}/offerings", ['subject_offering_id' => $w['elective']->id])->assertForbidden();
        $this->actingAs($viewer)->post('/app/elective-groups', ['name' => 'X'])->assertForbidden();

        $nobody = $this->createUserWithCapabilities($w['school'], []);
        $this->actingAs($nobody)->post("/app/schools/{$w['school']->id}/activate");
        $this->actingAs($nobody)->get('/app/elective-groups')->assertForbidden();

        $other = $this->createSchool();
        $foreign = $this->manager($other);
        $foreignOffering = $this->createSubjectOffering($this->createAcademicYear($other), $this->createCampus($other), $this->createGradeLevel($other), $this->createSubject($other), ['is_required' => false]);
        $this->assertSame(404, $this->actingAs($foreign)->get("/app/elective-groups/{$group->id}")->getStatusCode());
        $this->assertSame(404, $this->actingAs($foreign)->post("/app/elective-groups/{$group->id}/offerings", ['subject_offering_id' => $foreignOffering->id])->getStatusCode());
        // School A's own manager cannot pull School B's Offering into School A's group either.
        $this->actingAs($this->manager($w['school']));
        $this->assertSame(404, $this->post("/app/elective-groups/{$group->id}/offerings", ['subject_offering_id' => $foreignOffering->id])->getStatusCode());
        // Nor create a group on another School's Year/Campus/Grade.
        $this->assertSame(404, $this->post('/app/elective-groups', [
            'academic_year_id' => $w['year']->id, 'campus_id' => $this->createCampus($other)->id, 'grade_level_id' => $w['grade']->id, 'name' => 'X', 'code' => 'X',
        ])->getStatusCode());
    }
}
