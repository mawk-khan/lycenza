<?php

namespace Tests\Feature\Attendance;

use App\Domain\Attendance\Infrastructure\AttendanceSession;
use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Application\EmploymentService;
use App\Domain\Identity\Application\Staff\StaffAccessService;
use App\Domain\TeachingAssignments\Application\TeachingAssignmentService;
use App\Domain\TeachingAssignments\Infrastructure\TeachingAssignment;
use App\Models\Role;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Testing\LocalCatalogueFixtures;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTeacherAttendanceFixtures;
use Tests\Concerns\CreatesTeacherDeliveryFixtures;
use Tests\Concerns\CreatesTeachingAssignmentFixtures;
use Tests\Feature\Attendance\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * TCH.4 (ADR 0063 section 11): the owned (Tier 2) Attendance path end to
 * end over the `/my/` API -- `attendance.teacher` AND a verified
 * ActingEmployee today AND a TeachingAssignment for the exact Section +
 * SubjectOffering on the register's attendance_date -- beside the unchanged
 * Tier 1 administrative path. The TimetableEntry names the class; neither
 * its teacher_id nor the session's teacher_id snapshot authorizes.
 */
class TeacherAttendanceAccessTest extends TestCase
{
    use CreatesAttendanceFixtures, CreatesTeacherAttendanceFixtures, CreatesTeacherDeliveryFixtures, CreatesTeachingAssignmentFixtures;

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withHeader('Authorization', 'Bearer '.$user->createToken('test-device')->plainTextToken);
    }

    private function base(array $w): string
    {
        return "/api/v1/schools/{$w['school']->id}/my";
    }

    private function submit(array $w, User $user, string $date, $entry = null): TestResponse
    {
        $entry ??= $w['entry'];
        $students = $entry->section_id === $w['section']->id ? $w['studentsA'] : $w['studentsB'];

        return $this->as($user)->postJson($this->base($w).'/attendance-sessions', [
            'timetable_entry_id' => $entry->id,
            'attendance_date' => $date,
            'records' => $this->allPresent($students),
        ]);
    }

    private function correctFirst(array $w, User $user, AttendanceSession $session): TestResponse
    {
        $record = $this->inSchool($w['school'], fn () => $session->records()->orderBy('id')->firstOrFail());

        return $this->as($user)->postJson($this->base($w)."/attendance-records/{$record->id}/correct", [
            'expected_status' => $record->status, 'new_status' => $record->status === 'present' ? 'absent' : 'present',
        ]);
    }

    private function sessions(array $w): int
    {
        return $this->inSchool($w['school'], fn () => AttendanceSession::query()->count());
    }

    #[Test]
    public function an_eligible_teacher_who_owns_the_class_on_the_date_reads_takes_and_corrects_it(): void
    {
        $w = $this->teacherAttendanceWorld();
        [$user, $employee] = $this->teacher($w);
        $this->own($w, $employee, '2026-06-01');

        $classes = $this->as($user)->getJson($this->base($w).'/attendance-sessions/scheduled-classes?attendance_date='.self::MONDAY)
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data');
        $this->assertSame([$w['entry']->id], array_column($classes, 'timetableEntryId'), 'Only the owned class (not B/X, not A/Y).');

        $this->as($user)->getJson($this->base($w)."/attendance-sessions/roster-preview?timetable_entry_id={$w['entry']->id}&attendance_date=".self::MONDAY)
            ->assertOk()->assertJsonCount(2, 'data');

        $id = $this->submit($w, $user, self::MONDAY)->assertCreated()->json('data.id');
        $session = $this->inSchool($w['school'], fn () => AttendanceSession::query()->findOrFail($id));

        // The session keeps the SCHEDULED teacher as provenance; it is not
        // rewritten to the acting teacher.
        $this->assertSame($w['teacher']->id, $session->teacher_id);
        $this->assertNotSame($employee->id, $session->teacher_id);

        $this->as($user)->getJson($this->base($w).'/attendance-sessions')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $id);
        $this->as($user)->getJson($this->base($w)."/attendance-sessions/{$id}")->assertOk()->assertJsonCount(2, 'data.records');
        $this->correctFirst($w, $user, $session)->assertOk();

        // The ordinary Attendance audit, attributed to the teacher's User.
        $actors = $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()
            ->whereIn('event_type', ['attendance.session.submitted', 'attendance.record.corrected'])->pluck('actor_user_id')->unique()->values()->all());
        $this->assertSame([$user->id], $actors);
    }

    #[Test]
    public function ownership_without_the_capability_is_refused(): void
    {
        $w = $this->teacherAttendanceWorld();
        [$user, $employee] = $this->teacher($w, roleKey: null);
        $this->own($w, $employee, '2026-06-01');

        $this->as($user)->getJson($this->base($w).'/attendance-sessions')->assertForbidden();
        $this->submit($w, $user, self::MONDAY)->assertForbidden();
    }

    #[Test]
    public function a_role_carrying_only_the_curriculum_delivery_capability_is_refused_attendance(): void
    {
        $w = $this->teacherAttendanceWorld();
        $role = LocalCatalogueFixtures::asOwner(fn () => Role::query()->create(['key' => 'test.cd_only.'.Str::uuid(), 'name' => 'Delivery only', 'scope' => 'school', 'is_system' => false]));
        LocalCatalogueFixtures::asOwner(fn () => $role->capabilities()->sync(['curriculum.delivery.teacher']));
        [$user, $employee] = $this->teacher($w, roleKey: $role->key);
        $this->own($w, $employee, '2026-06-01');

        $this->submit($w, $user, self::MONDAY)->assertForbidden();
    }

    #[Test]
    public function the_role_without_an_eligible_employee_reaches_nothing(): void
    {
        $w = $this->teacherAttendanceWorld();
        [$unlinked, $employee] = $this->teacher($w, linked: false);
        $this->own($w, $employee, '2026-06-01');

        $this->as($unlinked)->getJson($this->base($w).'/attendance-sessions')->assertForbidden()->assertJsonPath('error.code', 'HR_ACTING_EMPLOYEE_UNAVAILABLE');
        $this->submit($w, $unlinked, self::MONDAY)->assertForbidden();
        $this->assertSame(0, $this->sessions($w));
    }

    #[Test]
    public function the_capability_and_identity_without_an_assignment_reach_nothing(): void
    {
        $w = $this->teacherAttendanceWorld();
        [$user] = $this->teacher($w);

        $this->as($user)->getJson($this->base($w).'/attendance-sessions/scheduled-classes?attendance_date='.self::MONDAY)->assertOk()->assertJsonCount(0, 'data');
        $this->submit($w, $user, self::MONDAY)->assertNotFound();
        $this->as($user)->getJson($this->base($w)."/attendance-sessions/roster-preview?timetable_entry_id={$w['entry']->id}&attendance_date=".self::MONDAY)->assertNotFound();
    }

    #[Test]
    public function any_role_carrying_the_capability_works_the_same(): void
    {
        $w = $this->teacherAttendanceWorld();
        $role = LocalCatalogueFixtures::asOwner(fn () => Role::query()->create(['key' => 'test.register_keeper.'.Str::uuid(), 'name' => 'Register keeper', 'scope' => 'school', 'is_system' => false]));
        LocalCatalogueFixtures::asOwner(fn () => $role->capabilities()->sync(['attendance.teacher']));
        [$user, $employee] = $this->teacher($w, roleKey: $role->key);
        $this->own($w, $employee, '2026-06-01');

        $this->submit($w, $user, self::MONDAY)->assertCreated();
    }

    #[Test]
    public function the_exact_section_and_offering_are_required(): void
    {
        $w = $this->teacherAttendanceWorld();
        [$user, $employee] = $this->teacher($w);
        $this->own($w, $employee, '2026-06-01');

        $this->submit($w, $user, self::MONDAY, $w['entryB'])->assertNotFound();
        $this->submit($w, $user, self::MONDAY, $w['entryY'])->assertNotFound();
        $this->assertSame(0, $this->inSchool($w['school'], fn () => AttendanceSession::query()->where('section_id', $w['sectionB']->id)->orWhere('subject_offering_id', $w['offeringY']->id)->count()));
    }

    #[Test]
    public function the_assignment_dates_bound_the_attendance_date_inclusively(): void
    {
        $w = $this->teacherAttendanceWorld();
        [$user, $employee] = $this->teacher($w);
        $this->own($w, $employee, '2026-08-31', '2026-09-14');

        $this->submit($w, $user, '2026-08-24')->assertStatus(422)->assertJsonPath('error.code', 'ATTENDANCE_OUTSIDE_TEACHING_ASSIGNMENT');
        $this->submit($w, $user, '2026-08-31')->assertCreated();
        $this->submit($w, $user, '2026-09-14')->assertCreated();
        $this->submit($w, $user, '2026-09-21')->assertStatus(422);
    }

    #[Test]
    public function a_future_assignment_grants_nothing_before_it_starts(): void
    {
        $w = $this->teacherAttendanceWorld();
        [$user, $employee] = $this->teacher($w);
        $this->own($w, $employee, '2026-10-05');

        $this->submit($w, $user, '2026-09-28')->assertStatus(422);
    }

    #[Test]
    public function ownership_follows_the_attendance_date_across_a_handover(): void
    {
        $w = $this->teacherAttendanceWorld();
        [$a, $employeeA] = $this->teacher($w);
        [$b, $employeeB] = $this->teacher($w);
        $this->own($w, $employeeA, '2026-06-01', '2026-08-31');
        $this->own($w, $employeeB, '2026-09-01');
        $august = $this->adminRegister($w, $w['entry'], '2026-08-24');
        $september = $this->adminRegister($w, $w['entry'], '2026-09-07');

        $this->as($a)->getJson($this->base($w)."/attendance-sessions/{$august->id}")->assertOk();
        $this->as($a)->getJson($this->base($w)."/attendance-sessions/{$september->id}")->assertNotFound();
        $this->correctFirst($w, $a, $september)->assertNotFound();
        $this->correctFirst($w, $a, $august)->assertOk();

        // B owns the class NOW, but never A's August register.
        $this->as($b)->getJson($this->base($w)."/attendance-sessions/{$august->id}")->assertNotFound();
        $this->correctFirst($w, $b, $august)->assertNotFound();
        $this->correctFirst($w, $b, $september)->assertOk();
        $this->submit($w, $a, '2026-09-14')->assertStatus(422);
    }

    #[Test]
    public function temporary_cover_works_inside_its_window_whatever_the_timetable_says(): void
    {
        $w = $this->teacherAttendanceWorld();
        [$cover, $coverEmployee] = $this->teacher($w);
        $this->own($w, $coverEmployee, '2026-09-07', '2026-09-11');

        $id = $this->submit($w, $cover, '2026-09-07')->assertCreated()->json('data.id');
        $this->assertSame($w['teacher']->id, $this->inSchool($w['school'], fn () => AttendanceSession::query()->findOrFail($id)->teacher_id), 'The timetable teacher stays the provenance snapshot.');
        $this->submit($w, $cover, '2026-09-14')->assertStatus(422);
    }

    #[Test]
    public function the_timetable_teacher_without_an_assignment_is_refused(): void
    {
        $w = $this->teacherAttendanceWorld();
        // Link the timetable's own teacher Employee to a Teacher-role User.
        $user = $this->createUser();
        $this->assignSchoolRole($this->createMembership($user, $w['school']), 'teacher');
        $this->inSchool($w['school'], fn () => $w['teacher']->forceFill(['user_id' => $user->id])->save());
        $this->createEmploymentRecord($w['teacher'], ['starts_on' => '2026-01-01', 'status' => 'active']);

        $this->submit($w, $user, self::MONDAY)->assertNotFound();
        $this->as($user)->getJson($this->base($w).'/attendance-sessions/scheduled-classes?attendance_date='.self::MONDAY)->assertJsonCount(0, 'data');
    }

    #[Test]
    public function co_teachers_both_operate_the_register(): void
    {
        $w = $this->teacherAttendanceWorld();
        [$a, $employeeA] = $this->teacher($w);
        [$b, $employeeB] = $this->teacher($w);
        $this->own($w, $employeeA, '2026-06-01');
        $this->own($w, $employeeB, '2026-06-01');

        $id = $this->submit($w, $a, self::MONDAY)->assertCreated()->json('data.id');
        $this->correctFirst($w, $b, $this->inSchool($w['school'], fn () => AttendanceSession::query()->findOrFail($id)))->assertOk();
    }

    #[Test]
    public function the_list_holds_exactly_the_owned_registers(): void
    {
        $w = $this->teacherAttendanceWorld();
        [$user, $employee] = $this->teacher($w);
        $this->own($w, $employee, '2026-08-31', '2026-09-07');

        $owned = [$this->adminRegister($w, $w['entry'], '2026-08-31')->id, $this->adminRegister($w, $w['entry'], '2026-09-07')->id];
        $this->adminRegister($w, $w['entry'], '2026-08-24');   // outside the dates
        $this->adminRegister($w, $w['entryB'], '2026-08-31');  // wrong Section
        $this->adminRegister($w, $w['entryY'], '2026-08-31');  // wrong Offering
        $elsewhere = $this->teacherAttendanceWorld();
        $this->adminRegister($elsewhere, $elsewhere['entry'], '2026-08-31'); // another School

        $listed = array_column($this->as($user)->getJson($this->base($w).'/attendance-sessions')->assertOk()->json('data'), 'id');
        sort($listed);
        sort($owned);
        $this->assertSame($owned, $listed);
    }

    #[Test]
    public function other_registers_other_schools_and_bad_ids_are_the_same_not_found(): void
    {
        $w = $this->teacherAttendanceWorld();
        [$user, $employee] = $this->teacher($w);
        $this->own($w, $employee, '2026-06-01');
        $theirs = $this->adminRegister($w, $w['entryB'], self::MONDAY);
        $elsewhere = $this->teacherAttendanceWorld();
        $foreign = $this->adminRegister($elsewhere, $elsewhere['entry'], self::MONDAY);

        foreach ([$theirs->id, $foreign->id, (string) Str::uuid7(), 'not-a-uuid'] as $id) {
            $this->as($user)->getJson($this->base($w)."/attendance-sessions/{$id}")->assertNotFound();
        }

        $this->correctFirst($w, $user, $theirs)->assertNotFound();
        $this->as($user)->postJson($this->base($w).'/attendance-records/'.Str::uuid7().'/correct', ['expected_status' => 'present', 'new_status' => 'absent'])->assertNotFound();
    }

    #[Test]
    public function an_unowned_class_or_register_answers_with_the_unknown_ids_exact_body(): void
    {
        // TCH.6 (ADR 0063 section 18): not merely the same status -- the
        // same body, and never the id of a register the teacher may not see.
        $w = $this->teacherAttendanceWorld();
        [$user, $employee] = $this->teacher($w);
        $this->own($w, $employee, '2026-06-01');
        $theirs = $this->adminRegister($w, $w['entryB'], self::MONDAY);
        $body = fn (TestResponse $r) => Arr::except($r->assertNotFound()->json('error'), ['requestId']);
        $unknown = (string) Str::uuid7();

        $unowned = $body($this->correctFirst($w, $user, $theirs));
        $this->assertSame($body($this->as($user)->postJson($this->base($w)."/attendance-records/{$unknown}/correct", ['expected_status' => 'present', 'new_status' => 'absent'])), $unowned);
        $this->assertStringNotContainsString($theirs->id, (string) json_encode($unowned));

        $this->assertSame(
            $body($this->as($user)->postJson($this->base($w).'/attendance-sessions', ['timetable_entry_id' => $unknown, 'attendance_date' => self::MONDAY, 'records' => $this->allPresent($w['studentsB'])])),
            $body($this->submit($w, $user, self::MONDAY, $w['entryB'])),
        );

        $preview = fn (string $entryId) => $body($this->as($user)->getJson($this->base($w)."/attendance-sessions/roster-preview?timetable_entry_id={$entryId}&attendance_date=".self::MONDAY));
        $this->assertSame($preview($unknown), $preview($w['entryB']->id));
    }

    #[Test]
    public function revoking_the_role_or_ending_the_assignment_ends_access_and_keeps_history(): void
    {
        $w = $this->teacherAttendanceWorld();
        [$user, $employee, $membership] = $this->teacher($w);
        $assignment = $this->own($w, $employee, '2026-06-01');
        $this->submit($w, $user, '2026-08-24')->assertCreated();

        app(TeachingAssignmentService::class)->end($w['school'], $assignment->id, '2026-08-31', 'reassigned', $w['admin']);
        $this->submit($w, $user, '2026-08-31')->assertCreated();
        $this->submit($w, $user, '2026-09-07')->assertStatus(422);

        $schoolAdmin = $this->createUser();
        $this->assignSchoolRole($this->createMembership($schoolAdmin, $w['school']), 'school_admin');
        app(StaffAccessService::class)->revokeRole($w['school'], $schoolAdmin, $membership->id, 'teacher');

        $this->as($user)->getJson($this->base($w).'/attendance-sessions')->assertForbidden();
        $this->inSchool($w['school'], function () use ($employee, $user) {
            $this->assertSame($user->id, $employee->fresh()->user_id);
            $this->assertSame(1, TeachingAssignment::query()->where('employee_id', $employee->id)->count());
            $this->assertSame(2, AttendanceSession::query()->count());
        });
    }

    #[Test]
    public function offboarding_ends_access_and_keeps_the_assignment(): void
    {
        foreach (['suspend', 'archive', 'end_employment', 'disable', 'unlink'] as $case) {
            $w = $this->teacherAttendanceWorld();
            [$user, $employee, $membership] = $this->teacher($w);
            $this->own($w, $employee, '2026-06-01');
            $hr = $this->fullHrActor($w['school']);

            match ($case) {
                'suspend' => DB::table('school_memberships')->where('id', $membership->id)->update(['status' => 'suspended']),
                'archive' => app(EmployeeService::class)->archive($employee, $hr),
                'end_employment' => app(EmploymentService::class)->end($this->inSchool($w['school'], fn () => $employee->employmentRecords()->firstOrFail()), '2026-08-31', $this->createUserWithCapabilities($w['school'], ['hr.employees.assignments.manage'])),
                'disable' => DB::table('users')->where('id', $user->id)->update(['is_disabled' => true, 'disabled_at' => now()]),
                'unlink' => app(EmployeeService::class)->unlinkUser($employee, $hr),
            };

            // 401 disabled token, 404 suspended member (School-route check), 403 ActingEmployee.
            $this->assertContains($this->submit($w, $user, self::MONDAY)->getStatusCode(), [401, 403, 404], $case);
            $this->assertSame(0, $this->sessions($w), $case);
            $this->assertSame(1, $this->inSchool($w['school'], fn () => TeachingAssignment::query()->count()), "{$case}: the assignment stays history");
        }
    }

    #[Test]
    public function tier_one_administrators_are_unchanged_and_need_no_employee_or_assignment(): void
    {
        $w = $this->teacherAttendanceWorld();
        $this->adminRegister($w, $w['entryB'], self::MONDAY);

        $schoolAdmin = $this->createUser();
        $this->assignSchoolRole($this->createMembership($schoolAdmin, $w['school']), 'school_admin');
        $principal = $this->createUser();
        $this->assignSchoolRole($this->createMembership($principal, $w['school']), 'principal');

        foreach ([$w['actor'], $schoolAdmin, $principal] as $admin) {
            $this->as($admin)->getJson("/api/v1/schools/{$w['school']->id}/attendance-sessions")->assertOk()->assertJsonPath('meta.total', 1);
        }

        $this->as($principal)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("/api/v1/schools/{$w['school']->id}/attendance-sessions", [
            'timetable_entry_id' => $w['entry']->id, 'attendance_date' => self::MONDAY, 'records' => $this->allPresent($w['studentsA']),
        ])->assertCreated();
    }

    #[Test]
    public function teachers_reach_no_tier_one_attendance_student_directory_or_assignment_administration(): void
    {
        $w = $this->teacherAttendanceWorld();
        [$user, $employee] = $this->teacher($w);
        $this->own($w, $employee, '2026-06-01');
        $base = "/api/v1/schools/{$w['school']->id}";

        $this->as($user)->getJson("{$base}/attendance-sessions")->assertForbidden();
        $this->as($user)->getJson("{$base}/attendance-sessions/scheduled-classes?attendance_date=".self::MONDAY)->assertForbidden();
        $this->as($user)->getJson("{$base}/students")->assertForbidden();
        $this->as($user)->getJson("{$base}/teaching-assignments")->assertForbidden();
    }
}
