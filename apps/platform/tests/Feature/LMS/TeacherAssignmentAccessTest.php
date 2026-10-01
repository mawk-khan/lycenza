<?php

namespace Tests\Feature\LMS;

use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Application\EmploymentService;
use App\Domain\Identity\Application\Staff\StaffAccessService;
use App\Domain\LMS\Infrastructure\Assignment;
use App\Models\Role;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesLmsOwnershipFixtures;
use Tests\Concerns\CreatesTeacherDeliveryFixtures;
use Tests\Concerns\CreatesTeacherLearningContentFixtures;
use Tests\Concerns\CreatesTeachingAssignmentFixtures;
use Tests\Feature\Timetable\Concerns\CreatesTimetableFixtures;
use Tests\TestCase;

/**
 * TCH.5D (ADR 0063 sections 34, 37) -- the owned teacher Assignment API, end
 * to end (the TCH.5C Learning Content rule for staff-authored Assignments):
 *
 *   lms.assignments.teacher AND ActingEmployee (today) AND owner/audience
 *   rule AND today's TeachingAssignment coverage -- never `due_on`
 *
 * - create: owner = ActingEmployee (never input), every audience Section taught;
 * - writes: owner-only AND every audience Section taught;
 * - reads: own rows (draft/closed included) while teaching every Section;
 *   published teacher rows for
 *   any taught Section; published Offering-wide rows of a taught Offering;
 * - co-teaching, hand-over, partial multi-Section ownership, non-disclosure,
 *   off-boarding, Timetable independence, Tier 1 unchanged.
 */
class TeacherAssignmentAccessTest extends TestCase
{
    // CreatesTimetableFixtures brings CreatesTenancyFixtures.
    use CreatesLmsOwnershipFixtures, CreatesTeacherDeliveryFixtures, CreatesTeacherLearningContentFixtures, CreatesTeachingAssignmentFixtures, CreatesTimetableFixtures;

    private function as(User $user, array $w): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user)->withHeader('X-School-Id', $w['school']->id);
    }

    private function my(array $w, string $path = ''): string
    {
        return "/api/v1/schools/{$w['school']->id}/my/assignments{$path}";
    }

    /** @param  list<string>  $sections */
    private function create(array $w, User $user, array $sections, array $extra = []): TestResponse
    {
        return $this->as($user, $w)->postJson($this->my($w), array_merge([
            'subject_offering_id' => $w['offering']->id,
            'title' => 'Worksheet 3', 'due_on' => '2026-10-30',
            'audience_section_ids' => array_map(fn (string $s) => $w[$s]->id, $sections),
        ], $extra));
    }

    private function ownerOf(array $w, string $id): ?string
    {
        return $this->withinSchool($w['school'], fn () => DB::table('assignments')->where('id', $id)->value('owner_employee_id'));
    }

    /** @return list<string> */
    private function listed(array $w, User $user, string $query = ''): array
    {
        return collect($this->as($user, $w)->getJson($this->my($w).$query)->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
    }

    #[Test]
    public function a_teacher_creates_a_section_targeted_assignment_owned_by_their_acting_employee(): void
    {
        $w = $this->contentWorld();
        [$user, $employee] = $this->contentTeacher($w);
        $other = $this->createEmployee($w['school']);

        $data = $this->create($w, $user, ['sectionA'], ['owner_employee_id' => $other->id, 'owner' => $other->id])
            ->assertCreated()->assertHeader('Cache-Control', 'no-store, private')->json('data');

        $this->assertSame($employee->id, $this->ownerOf($w, $data['id']), 'The owner is the ActingEmployee, whatever the client sends.');
        $this->assertTrue($data['mine'] && $data['canEdit'] && ! $data['offeringWide']);
        $this->assertSame([['sectionId' => $w['sectionA']->id, 'sectionCode' => 'A']], $data['audience']);
        $this->assertArrayNotHasKey('ownerEmployeeId', $data);

        $audit = $this->withinSchool($w['school'], fn () => SchoolAuditEvent::query()->where('event_type', 'lms.assignment.created')->firstOrFail());
        $this->assertSame($user->id, $audit->actor_user_id, 'The audit actor is the User, never the owner Employee.');
        $this->assertSame($employee->id, $audit->metadata['ownerEmployeeId']);
    }

    #[Test]
    public function one_resource_may_target_several_sections_only_if_every_one_is_taught(): void
    {
        $w = $this->contentWorld();
        [$both] = $this->contentTeacher($w, ['sectionA', 'sectionB']);
        [$onlyA] = $this->contentTeacher($w, ['sectionA']);

        $id = $this->create($w, $both, ['sectionB', 'sectionA'])->assertCreated()->json('data.id');
        $this->assertSame(2, $this->withinSchool($w['school'], fn () => DB::table('assignment_section_audiences')->where('assignment_id', $id)->count()));

        $this->create($w, $onlyA, ['sectionA', 'sectionB'])->assertStatus(422)->assertJsonPath('error.code', 'LMS_AUDIENCE_SECTION_NOT_TAUGHT');
        $this->assertSame(1, $this->withinSchool($w['school'], fn () => Assignment::query()->count()), 'Nothing written for a partially taught audience.');
    }

    #[Test]
    public function an_untaught_or_foreign_audience_is_refused(): void
    {
        $w = $this->contentWorld();
        $other = $this->contentWorld();
        [$user] = $this->contentTeacher($w);

        foreach (['otherGrade', 'otherCampus', 'otherYear', 'sectionB'] as $section) {
            $this->create($w, $user, ['sectionA', $section])->assertStatus(422)->assertJsonPath('error.code', 'LMS_AUDIENCE_SECTION_NOT_TAUGHT');
        }
        $this->create($w, $user, [], ['audience_section_ids' => [$w['sectionA']->id, $other['sectionA']->id]])->assertStatus(422);
        $this->create($w, $user, [])->assertStatus(422);
        // An Offering the teacher teaches no Section of, and another School's Offering: not found.
        $this->create($w, $user, ['sectionA'], ['subject_offering_id' => $w['sibling']->id])->assertNotFound();
        $this->create($w, $user, ['sectionA'], ['subject_offering_id' => $other['offering']->id])->assertNotFound();
        $this->assertSame(0, $this->withinSchool($w['school'], fn () => Assignment::query()->count()));
    }

    #[Test]
    public function the_owner_runs_the_ordinary_lifecycle(): void
    {
        $w = $this->contentWorld();
        [$user] = $this->contentTeacher($w);
        $id = $this->create($w, $user, ['sectionA'])->json('data.id');

        $this->as($user, $w)->patchJson($this->my($w, "/{$id}"), ['title' => 'Renamed', 'due_on' => '2026-11-15'])->assertOk()->assertJsonPath('data.title', 'Renamed')->assertJsonPath('data.dueOn', '2026-11-15');
        $this->as($user, $w)->postJson($this->my($w, "/{$id}/close"))->assertStatus(422)->assertJsonPath('error.code', 'ASSIGNMENT_ILLEGAL_TRANSITION');
        $this->as($user, $w)->postJson($this->my($w, "/{$id}/publish"))->assertOk()->assertJsonPath('data.status', 'published');
        $this->as($user, $w)->postJson($this->my($w, "/{$id}/close"))->assertOk()->assertJsonPath('data.status', 'closed');
        $this->as($user, $w)->postJson($this->my($w, "/{$id}/publish"))->assertOk()->assertJsonPath('data.status', 'published');
        $this->as($user, $w)->getJson($this->my($w, "/{$id}"))->assertOk()->assertJsonPath('data.canEdit', true);
    }

    #[Test]
    public function ownership_alone_or_teaching_alone_never_writes(): void
    {
        $w = $this->contentWorld();
        [$owner, , , [$assignment]] = $this->contentTeacher($w);
        [$coTeacher] = $this->contentTeacher($w);
        $draft = $this->teacherAssignment($w, $owner);
        $published = $this->teacherAssignment($w, $owner, status: 'published');

        // Teaching the Section without owning the row: read the published
        // row, never write it; the draft is not even visible.
        $this->as($coTeacher, $w)->getJson($this->my($w, "/{$published->id}"))->assertOk()->assertJsonPath('data.mine', false)->assertJsonPath('data.canEdit', false);
        $this->as($coTeacher, $w)->patchJson($this->my($w, "/{$published->id}"), ['title' => 'x'])->assertForbidden()->assertJsonPath('error.code', 'ASSIGNMENT_NOT_OWNED');
        $this->as($coTeacher, $w)->postJson($this->my($w, "/{$published->id}/close"))->assertForbidden();
        $this->as($coTeacher, $w)->getJson($this->my($w, "/{$draft->id}"))->assertNotFound();
        $this->as($coTeacher, $w)->patchJson($this->my($w, "/{$draft->id}"), ['title' => 'x'])->assertNotFound();

        // Owning the row without teaching the Section any more: nothing.
        $this->endTeaching($w, $assignment);
        foreach ([$draft, $published] as $row) {
            $this->as($owner, $w)->getJson($this->my($w, "/{$row->id}"))->assertNotFound();
            $this->as($owner, $w)->patchJson($this->my($w, "/{$row->id}"), ['title' => 'x'])->assertNotFound();
        }
        $this->assertSame([$published->id], $this->listed($w, $coTeacher), 'The co-teacher still reads the published row after the owner leaves.');
        $this->assertSame([], $this->listed($w, $owner));

        // Ending teaching changes no ownership or audience.
        $this->assertSame($this->ownerOf($w, $draft->id), $this->withinSchool($w['school'], fn () => DB::table('employees')->where('user_id', $owner->id)->value('id')));
    }

    #[Test]
    public function a_multi_section_row_is_writable_only_while_every_section_is_taught(): void
    {
        $w = $this->contentWorld();
        [$owner, , , [$a, $b]] = $this->contentTeacher($w, ['sectionA', 'sectionB']);
        [$readerA] = $this->contentTeacher($w, ['sectionA']);
        $row = $this->teacherAssignment($w, $owner, ['sectionA', 'sectionB'], 'published');

        $this->endTeaching($w, $b);

        // Still readable (published, Section A taught) -- never writable, never shrunk.
        $this->as($owner, $w)->getJson($this->my($w, "/{$row->id}"))->assertOk()->assertJsonPath('data.canEdit', false);
        $this->as($owner, $w)->patchJson($this->my($w, "/{$row->id}"), ['title' => 'x'])->assertStatus(422)->assertJsonPath('error.code', 'ASSIGNMENT_OUTSIDE_TEACHING_ASSIGNMENT');
        $this->as($owner, $w)->postJson($this->my($w, "/{$row->id}/close"))->assertStatus(422);
        $this->assertSame(2, $this->withinSchool($w['school'], fn () => DB::table('assignment_section_audiences')->where('assignment_id', $row->id)->count()));

        // A teacher of only one audience Section reads the published row.
        $this->as($readerA, $w)->getJson($this->my($w, "/{$row->id}"))->assertOk();
    }

    #[Test]
    public function a_hand_over_never_transfers_ownership(): void
    {
        $w = $this->contentWorld();
        [$before, , , [$assignment]] = $this->contentTeacher($w);
        $published = $this->teacherAssignment($w, $before, status: 'published');
        $draft = $this->teacherAssignment($w, $before);
        $this->endTeaching($w, $assignment);
        [$after, $afterEmployee] = $this->teacher($w);
        $this->own($w, $afterEmployee, '2026-09-01', null, $w['sectionA']);

        $this->as($after, $w)->getJson($this->my($w, "/{$published->id}"))->assertOk()->assertJsonPath('data.mine', false);
        $this->as($after, $w)->patchJson($this->my($w, "/{$published->id}"), ['title' => 'x'])->assertForbidden();
        $this->as($after, $w)->postJson($this->my($w, "/{$published->id}/close"))->assertForbidden();
        $this->as($after, $w)->getJson($this->my($w, "/{$draft->id}"))->assertNotFound();

        $this->as($before, $w)->getJson($this->my($w, "/{$published->id}"))->assertNotFound();
        $this->as($before, $w)->getJson($this->my($w, "/{$draft->id}"))->assertNotFound();
        $this->assertNotSame($afterEmployee->id, $this->ownerOf($w, $published->id));
    }

    #[Test]
    public function offering_wide_rows_are_readable_when_published_and_never_writable(): void
    {
        $w = $this->contentWorld();
        [$user] = $this->contentTeacher($w);
        $published = $this->adminAssignment($w);
        $draft = $this->adminAssignment($w, 'draft');
        $closed = $this->adminAssignment($w, 'closed');
        $sibling = $this->adminAssignment($w, 'published', $w['sibling']);

        $this->as($user, $w)->getJson($this->my($w, "/{$published->id}"))->assertOk()->assertJsonPath('data.offeringWide', true);
        $this->as($user, $w)->patchJson($this->my($w, "/{$published->id}"), ['title' => 'x'])->assertForbidden();
        $this->as($user, $w)->postJson($this->my($w, "/{$published->id}/close"))->assertForbidden();
        foreach ([$draft, $closed, $sibling] as $hidden) {
            $this->as($user, $w)->getJson($this->my($w, "/{$hidden->id}"))->assertNotFound();
        }
    }

    #[Test]
    public function the_list_is_filtered_on_the_server(): void
    {
        $w = $this->contentWorld();
        $other = $this->contentWorld();
        [$me] = $this->contentTeacher($w);
        [$colleague] = $this->contentTeacher($w);
        [$teacherB] = $this->contentTeacher($w, ['sectionB']);
        [$foreign] = $this->contentTeacher($other);

        $visible = [
            $this->teacherAssignment($w, $me)->id,
            $this->teacherAssignment($w, $me, status: 'closed')->id,
            $this->teacherAssignment($w, $colleague, status: 'published')->id,
            $this->adminAssignment($w)->id,
        ];
        $this->teacherAssignment($w, $colleague);                       // a colleague's draft
        $this->teacherAssignment($w, $colleague, status: 'closed');   // a colleague's closed row
        $this->teacherAssignment($w, $teacherB, ['sectionB'], 'published'); // an untaught Section
        $this->adminAssignment($w, 'draft');
        $this->adminAssignment($w, 'published', $w['sibling']);         // an untaught Offering
        $this->adminAssignment($other);                                 // another School

        sort($visible);
        $this->assertSame($visible, $this->listed($w, $me));
        $this->assertSame($visible, $this->listed($w, $me, '?subject_offering_id='.$w['offering']->id));
        $this->assertSame([], $this->listed($w, $me, '?subject_offering_id='.$w['sibling']->id));
    }

    #[Test]
    public function the_contexts_list_only_taught_sections_and_the_timetable_grants_nothing(): void
    {
        $w = $this->contentWorld();
        [$user, $employee] = $this->contentTeacher($w, ['sectionB']);
        // The Timetable names this teacher for Section A; no TeachingAssignment does.
        $this->createTimetableEntry($w['offering'], $w['sectionA'], $employee, $this->createTimetablePeriod($w['school']));

        $contexts = $this->as($user, $w)->getJson("/api/v1/schools/{$w['school']->id}/my/assignment-contexts")->assertOk()->json('data');
        $this->assertCount(1, $contexts);
        $this->assertSame($w['offering']->id, $contexts[0]['subjectOfferingId']);
        $this->assertSame([['id' => $w['sectionB']->id, 'code' => 'B', 'name' => 'B']], $contexts[0]['sections']);

        $this->create($w, $user, ['sectionA'])->assertStatus(422)->assertJsonPath('error.code', 'LMS_AUDIENCE_SECTION_NOT_TAUGHT');
    }

    #[Test]
    public function unknown_malformed_and_foreign_ids_are_the_same_not_found(): void
    {
        $w = $this->contentWorld();
        $other = $this->contentWorld();
        [$user] = $this->contentTeacher($w);
        $foreign = $this->adminAssignment($other);

        foreach (['01a0f3f1-0000-7000-8000-000000000000', 'not-a-uuid', $foreign->id] as $id) {
            $this->as($user, $w)->getJson($this->my($w, "/{$id}"))->assertNotFound();
            $this->as($user, $w)->patchJson($this->my($w, "/{$id}"), ['title' => 'x'])->assertNotFound();
            $this->as($user, $w)->postJson($this->my($w, "/{$id}/publish"))->assertNotFound();
        }
    }

    #[Test]
    public function the_capability_is_required_and_no_role_key_is_read(): void
    {
        $w = $this->contentWorld();
        [$noCapability] = $this->contentTeacher($w, roleKey: 'principal');
        $this->create($w, $noCapability, ['sectionA'])->assertForbidden();
        $this->as($noCapability, $w)->getJson($this->my($w))->assertForbidden();

        // Any role carrying the capability satisfies the same formula.
        $role = Role::query()->create(['key' => 'test.assignment_lead.'.Str::uuid(), 'name' => 'Assignment lead', 'scope' => 'school', 'is_system' => false]);
        $role->capabilities()->sync(['lms.assignments.teacher']);
        [$custom] = $this->contentTeacher($w, roleKey: $role->key);
        $this->create($w, $custom, ['sectionA'])->assertCreated();
    }

    #[Test]
    public function an_ineligible_acting_employee_is_refused(): void
    {
        foreach (['unlinked', 'suspend', 'disable', 'archive', 'end_employment'] as $case) {
            $w = $this->contentWorld();
            [$user, $employee, $membership] = $this->contentTeacher($w);
            $row = $this->teacherAssignment($w, $user, status: 'published');
            $hr = $this->fullHrActor($w['school']);

            match ($case) {
                'unlinked' => app(EmployeeService::class)->unlinkUser($employee, $hr),
                'suspend' => DB::table('school_memberships')->where('id', $membership->id)->update(['status' => 'suspended']),
                'disable' => DB::table('users')->where('id', $user->id)->update(['is_disabled' => true, 'disabled_at' => now()]),
                'archive' => app(EmployeeService::class)->archive($employee, $hr),
                'end_employment' => app(EmploymentService::class)->end($this->withinSchool($w['school'], fn () => $employee->employmentRecords()->firstOrFail()), '2026-08-31', $this->createUserWithCapabilities($w['school'], ['hr.employees.assignments.manage'])),
            };

            foreach ([$this->create($w, $user, ['sectionA']), $this->as($user, $w)->patchJson($this->my($w, "/{$row->id}"), ['title' => 'x']), $this->as($user, $w)->getJson($this->my($w, "/{$row->id}"))] as $response) {
                $this->assertContains($response->getStatusCode(), [401, 403, 404], "{$case}: refused");
            }
            $this->assertSame(1, $this->withinSchool($w['school'], fn () => Assignment::query()->count()), $case);
        }
    }

    #[Test]
    public function revoking_the_role_removes_access_and_keeps_the_rows(): void
    {
        $w = $this->contentWorld();
        $admin = $this->createUser();
        $this->assignSchoolRole($this->createMembership($admin, $w['school']), 'school_admin');
        $this->assignSchoolRole($this->createMembership($this->createUser(), $w['school']), 'school_admin');
        [$user, , $membership] = $this->contentTeacher($w);
        $row = $this->teacherAssignment($w, $user);

        app(StaffAccessService::class)->revokeRole($w['school'], $admin, $membership->id, 'teacher');

        $this->as($user, $w)->getJson($this->my($w, "/{$row->id}"))->assertForbidden();
        $this->assertNotNull($this->ownerOf($w, $row->id), 'Revocation deletes no ownership.');
    }

    #[Test]
    public function tier_one_is_unchanged_and_teachers_get_no_administrative_surface(): void
    {
        $w = $this->contentWorld();
        [$user] = $this->contentTeacher($w);
        $row = $this->teacherAssignment($w, $user);
        $base = "/api/v1/schools/{$w['school']->id}";

        // The administrator manages the teacher's row School-wide, needing no Employee.
        $this->as($w['admin'], $w)->patchJson("{$base}/assignments/{$row->id}", ['title' => 'Edited by admin'])->assertOk();
        $this->as($w['admin'], $w)->getJson("{$base}/subject-offerings/{$w['offering']->id}/assignments")->assertOk()->assertJsonCount(1, 'data');

        foreach (["{$base}/assignments/{$row->id}", "{$base}/subject-offerings/{$w['offering']->id}/assignments",
            "{$base}/subject-offerings/{$w['offering']->id}/assignments", "{$base}/teaching-assignments", "{$base}/students"] as $url) {
            $this->as($user, $w)->getJson($url)->assertForbidden();
        }
    }

    #[Test]
    public function closed_assignments_are_their_owners_alone(): void
    {
        // `published` is the only shared status: a closed Assignment (the
        // retired state, ADR 0039 section 9) is private to its owner, like a
        // draft, and is re-published through the same publish action.
        $w = $this->contentWorld();
        [$owner] = $this->contentTeacher($w);
        [$coTeacher] = $this->contentTeacher($w);
        $closed = $this->teacherAssignment($w, $owner, status: 'closed');

        $this->as($owner, $w)->getJson($this->my($w, "/{$closed->id}"))->assertOk()->assertJsonPath('data.status', 'closed');
        $this->as($coTeacher, $w)->getJson($this->my($w, "/{$closed->id}"))->assertNotFound();
        $this->as($owner, $w)->postJson($this->my($w, "/{$closed->id}/publish"))->assertOk();
        $this->as($coTeacher, $w)->getJson($this->my($w, "/{$closed->id}"))->assertOk();
    }

    #[Test]
    public function the_due_date_is_never_an_authorization_date(): void
    {
        $w = $this->contentWorld();
        [$owner, , , [$assignment]] = $this->contentTeacher($w);
        $row = $this->teacherAssignment($w, $owner);

        // A due date long after the assignment ends changes nothing: today decides.
        $this->as($owner, $w)->patchJson($this->my($w, "/{$row->id}"), ['due_on' => '2027-03-01'])->assertOk();
        $this->endTeaching($w, $assignment);
        $this->as($owner, $w)->patchJson($this->my($w, "/{$row->id}"), ['due_on' => '2026-07-01'])->assertNotFound();

        // Publishing still needs a due date (the existing lifecycle rule).
        [$other] = $this->contentTeacher($w);
        $undated = $this->create($w, $other, ['sectionA'], ['due_on' => null])->assertCreated()->json('data.id');
        $this->as($other, $w)->postJson($this->my($w, "/{$undated}/publish"))->assertStatus(422)->assertJsonPath('error.code', 'ASSIGNMENT_DUE_DATE_REQUIRED');
        $this->create($w, $other, ['sectionA'], ['due_on' => '2030-01-01'])->assertStatus(422);
    }

    #[Test]
    public function an_elective_offering_has_no_teacher_path(): void
    {
        // TeachingAssignments cover required Offerings only, so nobody teaches
        // an elective in TCH terms: it stays Tier 1.
        $w = $this->contentWorld();
        [$user] = $this->contentTeacher($w);
        $elective = $this->createSubjectOffering($w['year'], $w['campus'], $w['grade'], $this->createSubject($w['school']), ['is_required' => false, 'status' => 'active']);

        $this->create($w, $user, ['sectionA'], ['subject_offering_id' => $elective->id])->assertNotFound();
        $this->as($w['admin'], $w)->postJson("/api/v1/schools/{$w['school']->id}/subject-offerings/{$elective->id}/assignments", ['title' => 'Elective work'])->assertCreated();
    }

    #[Test]
    public function a_row_the_teacher_may_not_read_is_not_found_before_any_validation(): void
    {
        $w = $this->contentWorld();
        [$user] = $this->contentTeacher($w);
        [$colleague] = $this->contentTeacher($w);
        $theirs = $this->teacherAssignment($w, $colleague);

        // An invalid due date on a row they cannot see is still the same 404.
        $this->as($user, $w)->patchJson($this->my($w, "/{$theirs->id}"), ['due_on' => '2099-01-01'])->assertNotFound();
        $this->as($user, $w)->patchJson($this->my($w, "/{$theirs->id}"), ['due_on' => 'not-a-date'])->assertNotFound();
    }
}
