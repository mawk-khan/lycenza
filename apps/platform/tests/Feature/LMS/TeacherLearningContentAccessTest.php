<?php

namespace Tests\Feature\LMS;

use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Application\EmploymentService;
use App\Domain\Identity\Application\Staff\StaffAccessService;
use App\Domain\LMS\Infrastructure\LearningContent;
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
 * TCH.5C (ADR 0063 sections 34, 36) -- the owned teacher Learning Content
 * API, end to end:
 *
 *   lms.content.teacher AND ActingEmployee (today) AND owner/audience rule
 *   AND today's TeachingAssignment coverage
 *
 * - create: owner = ActingEmployee (never input), every audience Section taught;
 * - writes: owner-only AND every audience Section taught;
 * - reads: own rows while teaching every Section; published teacher rows for
 *   any taught Section; published Offering-wide rows of a taught Offering;
 * - co-teaching, hand-over, partial multi-Section ownership, non-disclosure,
 *   off-boarding, Timetable independence, Tier 1 unchanged.
 */
class TeacherLearningContentAccessTest extends TestCase
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
        return "/api/v1/schools/{$w['school']->id}/my/learning-content{$path}";
    }

    /** @param  list<string>  $sections */
    private function create(array $w, User $user, array $sections, array $extra = []): TestResponse
    {
        return $this->as($user, $w)->postJson($this->my($w), array_merge([
            'subject_offering_id' => $w['offering']->id,
            'title' => 'Chapter 3 reading',
            'audience_section_ids' => array_map(fn (string $s) => $w[$s]->id, $sections),
        ], $extra));
    }

    private function ownerOf(array $w, string $id): ?string
    {
        return $this->withinSchool($w['school'], fn () => DB::table('learning_content')->where('id', $id)->value('owner_employee_id'));
    }

    /** @return list<string> */
    private function listed(array $w, User $user, string $query = ''): array
    {
        return collect($this->as($user, $w)->getJson($this->my($w).$query)->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
    }

    #[Test]
    public function a_teacher_creates_section_targeted_content_owned_by_their_acting_employee(): void
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

        $audit = $this->withinSchool($w['school'], fn () => SchoolAuditEvent::query()->where('event_type', 'lms.learning_content.created')->firstOrFail());
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
        $this->assertSame(2, $this->withinSchool($w['school'], fn () => DB::table('learning_content_section_audiences')->where('learning_content_id', $id)->count()));

        $this->create($w, $onlyA, ['sectionA', 'sectionB'])->assertStatus(422)->assertJsonPath('error.code', 'LMS_AUDIENCE_SECTION_NOT_TAUGHT');
        $this->assertSame(1, $this->withinSchool($w['school'], fn () => LearningContent::query()->count()), 'Nothing written for a partially taught audience.');
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
        $this->assertSame(0, $this->withinSchool($w['school'], fn () => LearningContent::query()->count()));
    }

    #[Test]
    public function the_owner_runs_the_ordinary_lifecycle(): void
    {
        $w = $this->contentWorld();
        [$user] = $this->contentTeacher($w);
        $id = $this->create($w, $user, ['sectionA'])->json('data.id');

        $this->as($user, $w)->patchJson($this->my($w, "/{$id}"), ['title' => 'Renamed', 'sequence' => 3])->assertOk()->assertJsonPath('data.title', 'Renamed');
        $this->as($user, $w)->postJson($this->my($w, "/{$id}/archive"))->assertStatus(422)->assertJsonPath('error.code', 'LEARNING_CONTENT_ILLEGAL_TRANSITION');
        $this->as($user, $w)->postJson($this->my($w, "/{$id}/publish"))->assertOk()->assertJsonPath('data.status', 'published');
        $this->as($user, $w)->postJson($this->my($w, "/{$id}/archive"))->assertOk()->assertJsonPath('data.status', 'archived');
        $this->as($user, $w)->postJson($this->my($w, "/{$id}/publish"))->assertOk()->assertJsonPath('data.status', 'published');
        $this->as($user, $w)->getJson($this->my($w, "/{$id}"))->assertOk()->assertJsonPath('data.canEdit', true);
    }

    #[Test]
    public function ownership_alone_or_teaching_alone_never_writes(): void
    {
        $w = $this->contentWorld();
        [$owner, , , [$assignment]] = $this->contentTeacher($w);
        [$coTeacher] = $this->contentTeacher($w);
        $draft = $this->teacherContent($w, $owner);
        $published = $this->teacherContent($w, $owner, status: 'published');

        // Teaching the Section without owning the row: read the published
        // row, never write it; the draft is not even visible.
        $this->as($coTeacher, $w)->getJson($this->my($w, "/{$published->id}"))->assertOk()->assertJsonPath('data.mine', false)->assertJsonPath('data.canEdit', false);
        $this->as($coTeacher, $w)->patchJson($this->my($w, "/{$published->id}"), ['title' => 'x'])->assertForbidden()->assertJsonPath('error.code', 'LEARNING_CONTENT_NOT_OWNED');
        $this->as($coTeacher, $w)->postJson($this->my($w, "/{$published->id}/archive"))->assertForbidden();
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
        $row = $this->teacherContent($w, $owner, ['sectionA', 'sectionB'], 'published');

        $this->endTeaching($w, $b);

        // Still readable (published, Section A taught) -- never writable, never shrunk.
        $this->as($owner, $w)->getJson($this->my($w, "/{$row->id}"))->assertOk()->assertJsonPath('data.canEdit', false);
        $this->as($owner, $w)->patchJson($this->my($w, "/{$row->id}"), ['title' => 'x'])->assertStatus(422)->assertJsonPath('error.code', 'LEARNING_CONTENT_OUTSIDE_TEACHING_ASSIGNMENT');
        $this->as($owner, $w)->postJson($this->my($w, "/{$row->id}/archive"))->assertStatus(422);
        $this->assertSame(2, $this->withinSchool($w['school'], fn () => DB::table('learning_content_section_audiences')->where('learning_content_id', $row->id)->count()));

        // A teacher of only one audience Section reads the published row.
        $this->as($readerA, $w)->getJson($this->my($w, "/{$row->id}"))->assertOk();
    }

    #[Test]
    public function a_hand_over_never_transfers_ownership(): void
    {
        $w = $this->contentWorld();
        [$before, , , [$assignment]] = $this->contentTeacher($w);
        $published = $this->teacherContent($w, $before, status: 'published');
        $draft = $this->teacherContent($w, $before);
        $this->endTeaching($w, $assignment);
        [$after, $afterEmployee] = $this->teacher($w);
        $this->own($w, $afterEmployee, '2026-09-01', null, $w['sectionA']);

        $this->as($after, $w)->getJson($this->my($w, "/{$published->id}"))->assertOk()->assertJsonPath('data.mine', false);
        $this->as($after, $w)->patchJson($this->my($w, "/{$published->id}"), ['title' => 'x'])->assertForbidden();
        $this->as($after, $w)->postJson($this->my($w, "/{$published->id}/archive"))->assertForbidden();
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
        $published = $this->adminContent($w);
        $draft = $this->adminContent($w, 'draft');
        $archived = $this->adminContent($w, 'archived');
        $sibling = $this->adminContent($w, 'published', $w['sibling']);

        $this->as($user, $w)->getJson($this->my($w, "/{$published->id}"))->assertOk()->assertJsonPath('data.offeringWide', true);
        $this->as($user, $w)->patchJson($this->my($w, "/{$published->id}"), ['title' => 'x'])->assertForbidden();
        $this->as($user, $w)->postJson($this->my($w, "/{$published->id}/archive"))->assertForbidden();
        foreach ([$draft, $archived, $sibling] as $hidden) {
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
            $this->teacherContent($w, $me)->id,
            $this->teacherContent($w, $me, status: 'archived')->id,
            $this->teacherContent($w, $colleague, status: 'published')->id,
            $this->adminContent($w)->id,
        ];
        $this->teacherContent($w, $colleague);                       // a colleague's draft
        $this->teacherContent($w, $colleague, status: 'archived');   // a colleague's archived row
        $this->teacherContent($w, $teacherB, ['sectionB'], 'published'); // an untaught Section
        $this->adminContent($w, 'draft');
        $this->adminContent($w, 'published', $w['sibling']);         // an untaught Offering
        $this->adminContent($other);                                 // another School

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

        $contexts = $this->as($user, $w)->getJson("/api/v1/schools/{$w['school']->id}/my/learning-content-contexts")->assertOk()->json('data');
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
        $foreign = $this->adminContent($other);

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
        $role = Role::query()->create(['key' => 'test.content_lead.'.Str::uuid(), 'name' => 'Content lead', 'scope' => 'school', 'is_system' => false]);
        $role->capabilities()->sync(['lms.content.teacher']);
        [$custom] = $this->contentTeacher($w, roleKey: $role->key);
        $this->create($w, $custom, ['sectionA'])->assertCreated();
    }

    #[Test]
    public function an_ineligible_acting_employee_is_refused(): void
    {
        foreach (['unlinked', 'suspend', 'disable', 'archive', 'end_employment'] as $case) {
            $w = $this->contentWorld();
            [$user, $employee, $membership] = $this->contentTeacher($w);
            $row = $this->teacherContent($w, $user, status: 'published');
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
            $this->assertSame(1, $this->withinSchool($w['school'], fn () => LearningContent::query()->count()), $case);
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
        $row = $this->teacherContent($w, $user);

        app(StaffAccessService::class)->revokeRole($w['school'], $admin, $membership->id, 'teacher');

        $this->as($user, $w)->getJson($this->my($w, "/{$row->id}"))->assertForbidden();
        $this->assertNotNull($this->ownerOf($w, $row->id), 'Revocation deletes no ownership.');
    }

    #[Test]
    public function tier_one_is_unchanged_and_teachers_get_no_administrative_surface(): void
    {
        $w = $this->contentWorld();
        [$user] = $this->contentTeacher($w);
        $row = $this->teacherContent($w, $user);
        $base = "/api/v1/schools/{$w['school']->id}";

        // The administrator manages the teacher's row School-wide, needing no Employee.
        $this->as($w['admin'], $w)->patchJson("{$base}/learning-content/{$row->id}", ['title' => 'Edited by admin'])->assertOk();
        $this->as($w['admin'], $w)->getJson("{$base}/subject-offerings/{$w['offering']->id}/learning-content")->assertOk()->assertJsonCount(1, 'data');

        foreach (["{$base}/learning-content/{$row->id}", "{$base}/subject-offerings/{$w['offering']->id}/learning-content",
            "{$base}/subject-offerings/{$w['offering']->id}/assignments", "{$base}/teaching-assignments", "{$base}/students"] as $url) {
            $this->as($user, $w)->getJson($url)->assertForbidden();
        }
    }
}
