<?php

namespace Tests\Feature\CurriculumDelivery;

use App\Domain\CurriculumDelivery\Infrastructure\CurriculumDelivery;
use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Application\EmploymentService;
use App\Domain\Identity\Application\Staff\StaffAccessService;
use App\Domain\TeachingAssignments\Application\TeachingAssignmentService;
use App\Domain\TeachingAssignments\Infrastructure\TeachingAssignment;
use App\Models\Role;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Testing\LocalCatalogueFixtures;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTeacherDeliveryFixtures;
use Tests\Concerns\CreatesTeachingAssignmentFixtures;
use Tests\Feature\Timetable\Concerns\CreatesTimetableFixtures;
use Tests\TestCase;

/**
 * TCH.3 (ADR 0063 section 11): the owned (Tier 2) Curriculum Delivery path
 * end to end over the `/my/` API -- `curriculum.delivery.teacher` AND a
 * verified ActingEmployee today AND a TeachingAssignment for the exact
 * Section + SubjectOffering on every date involved -- and the unchanged
 * Tier 1 administrative path beside it.
 */
class TeacherCurriculumDeliveryAccessTest extends TestCase
{
    // CreatesTimetableFixtures brings CreatesTenancyFixtures.
    use CreatesTeacherDeliveryFixtures, CreatesTeachingAssignmentFixtures, CreatesTimetableFixtures;

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

    private function start(array $w, User $user, string $startedOn, $unit = null, $section = null, $offering = null): TestResponse
    {
        return $this->as($user)->postJson($this->base($w).'/curriculum-deliveries', [
            'section_id' => ($section ?? $w['section'])->id,
            'subject_offering_id' => ($offering ?? $w['offering'])->id,
            'syllabus_unit_id' => ($unit ?? $w['units'][0])->id,
            'started_on' => $startedOn,
        ]);
    }

    private function complete(array $w, User $user, string $id, string $completedOn): TestResponse
    {
        return $this->as($user)->postJson($this->base($w)."/curriculum-deliveries/{$id}/transition", [
            'expected_status' => 'in_progress', 'new_status' => 'completed', 'completed_on' => $completedOn,
        ]);
    }

    private function inSchool(array $w, callable $callback): mixed
    {
        return app(TenantContext::class)->withSchool($w['school'], $callback);
    }

    #[Test]
    public function an_eligible_teacher_who_owns_the_class_reads_and_writes_it(): void
    {
        $w = $this->teacherWorld();
        [$user, $employee] = $this->teacher($w);
        $this->own($w, $employee);

        $this->as($user)->getJson($this->base($w).'/curriculum-delivery-contexts')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sectionId', $w['section']->id)
            ->assertJsonPath('data.0.subjectOfferingId', $w['offering']->id)
            ->assertJsonPath('data.0.periods.0', ['startsOn' => '2026-04-01', 'endsOn' => null]);

        $id = $this->start($w, $user, '2026-06-01')->assertCreated()->assertJsonPath('data.status', 'in_progress')->json('data.id');
        $this->as($user)->getJson($this->base($w)."/curriculum-deliveries/{$id}")->assertOk()->assertJsonPath('data.id', $id);
        $this->complete($w, $user, $id, '2026-06-20')->assertOk()->assertJsonPath('data.completedOn', '2026-06-20');
        $this->as($user)->patchJson($this->base($w)."/curriculum-deliveries/{$id}", ['started_on' => '2026-06-02'])->assertOk()->assertJsonPath('data.startedOn', '2026-06-02');

        $units = $this->as($user)->getJson($this->base($w)."/curriculum-deliveries?section_id={$w['section']->id}&subject_offering_id={$w['offering']->id}")
            ->assertOk()->json('data');
        $this->assertSame(['completed', 'not_started', 'not_started'], array_column($units, 'state'));

        // The ordinary Curriculum Delivery audit, attributed to the User.
        $audits = $this->inSchool($w, fn () => SchoolAuditEvent::query()->where('subject_id', $id)->orderBy('occurred_at')->pluck('actor_user_id', 'event_type')->all());
        $this->assertSame(['curriculum.delivery.created', 'curriculum.delivery.transitioned', 'curriculum.delivery.updated'], array_keys($audits));
        $this->assertSame([$user->id], array_values(array_unique($audits)));
    }

    #[Test]
    public function ownership_alone_without_the_capability_is_refused(): void
    {
        $w = $this->teacherWorld();
        [$user, $employee] = $this->teacher($w, roleKey: null);
        $this->own($w, $employee);

        $this->as($user)->getJson($this->base($w).'/curriculum-delivery-contexts')->assertForbidden();
        $this->start($w, $user, '2026-06-01')->assertForbidden();
    }

    #[Test]
    public function the_teacher_role_without_an_employee_link_or_eligibility_reaches_nothing(): void
    {
        $w = $this->teacherWorld();

        [$unlinked, $employee] = $this->teacher($w, linked: false);
        $this->own($w, $employee);
        $this->as($unlinked)->getJson($this->base($w).'/curriculum-delivery-contexts')->assertForbidden()->assertJsonPath('error.code', 'HR_ACTING_EMPLOYEE_UNAVAILABLE');
        $this->start($w, $unlinked, '2026-06-01')->assertForbidden();

        [$separated, $employee2] = $this->teacher($w);
        $this->own($w, $employee2);
        $this->inSchool($w, fn () => DB::table('employment_records')->where('employee_id', $employee2->id)->update(['ends_on' => '2026-08-31', 'status' => 'separated']));
        $this->start($w, $separated, '2026-06-01')->assertForbidden();
        $this->assertSame(1, $this->inSchool($w, fn () => TeachingAssignment::query()->where('employee_id', $employee2->id)->count()), 'The assignment remains history.');
    }

    #[Test]
    public function the_capability_and_identity_without_an_assignment_reach_nothing(): void
    {
        $w = $this->teacherWorld();
        [$user] = $this->teacher($w);

        $this->as($user)->getJson($this->base($w).'/curriculum-delivery-contexts')->assertOk()->assertJsonCount(0, 'data');
        $this->start($w, $user, '2026-06-01')->assertNotFound();
        $this->as($user)->getJson($this->base($w)."/curriculum-deliveries?section_id={$w['section']->id}&subject_offering_id={$w['offering']->id}")->assertNotFound();
    }

    #[Test]
    public function any_role_carrying_the_capability_works_the_same_the_role_label_is_never_consulted(): void
    {
        $w = $this->teacherWorld();
        $role = LocalCatalogueFixtures::asOwner(fn () => Role::query()->create(['key' => 'test.class_lead.'.Str::uuid(), 'name' => 'Class lead', 'scope' => 'school', 'is_system' => false]));
        LocalCatalogueFixtures::asOwner(fn () => $role->capabilities()->sync(['curriculum.delivery.teacher']));
        [$user, $employee] = $this->teacher($w, roleKey: $role->key);
        $this->own($w, $employee);

        $this->start($w, $user, '2026-06-01')->assertCreated();
    }

    #[Test]
    public function the_exact_section_and_offering_are_required(): void
    {
        $w = $this->teacherWorld();
        [$user, $employee] = $this->teacher($w);
        $this->own($w, $employee);

        $this->start($w, $user, '2026-06-01', section: $w['sectionB'])->assertNotFound();
        $this->start($w, $user, '2026-06-01', unit: $w['unitY'], offering: $w['offeringY'])->assertNotFound();
        $this->assertSame(0, $this->inSchool($w, fn () => CurriculumDelivery::query()->count()));
    }

    #[Test]
    public function the_assignment_dates_bound_every_write_inclusively(): void
    {
        $w = $this->teacherWorld();
        [$user, $employee] = $this->teacher($w);
        $this->own($w, $employee, '2026-06-01', '2026-08-31');

        $this->start($w, $user, '2026-05-31', $w['units'][0])->assertStatus(422)->assertJsonPath('error.code', 'CURRICULUM_DELIVERY_OUTSIDE_TEACHING_ASSIGNMENT');
        $this->start($w, $user, '2026-09-01', $w['units'][0])->assertStatus(422);
        $first = $this->start($w, $user, '2026-06-01', $w['units'][0])->assertCreated()->json('data.id');
        $this->start($w, $user, '2026-08-31', $w['units'][1])->assertCreated();

        $this->complete($w, $user, $first, '2026-09-01')->assertStatus(422)->assertJsonPath('error.code', 'CURRICULUM_DELIVERY_OUTSIDE_TEACHING_ASSIGNMENT');
        $this->complete($w, $user, $first, '2026-08-31')->assertOk();
    }

    #[Test]
    public function a_future_assignment_grants_nothing_before_it_starts(): void
    {
        $w = $this->teacherWorld();
        [$user, $employee] = $this->teacher($w);
        $this->own($w, $employee, '2026-10-01');

        $this->start($w, $user, '2026-09-15')->assertStatus(422);
    }

    #[Test]
    public function historical_ownership_follows_the_delivery_dates_not_today(): void
    {
        $w = $this->teacherWorld();
        [$a, $employeeA] = $this->teacher($w);
        [$b, $employeeB] = $this->teacher($w);
        $this->own($w, $employeeA, '2026-04-01', '2026-06-30');
        $this->own($w, $employeeB, '2026-07-01');

        $spring = $this->deliveryRow($w, $w['units'][0], '2026-05-01', '2026-06-15');
        $summer = $this->deliveryRow($w, $w['units'][1], '2026-07-10', '2026-08-01');
        $handover = $this->deliveryRow($w, $w['units'][2], '2026-06-20');

        // A (still an eligible Employee today) keeps her own period.
        $this->as($a)->getJson($this->base($w)."/curriculum-deliveries/{$spring->id}")->assertOk();
        $this->as($a)->patchJson($this->base($w)."/curriculum-deliveries/{$spring->id}", ['started_on' => '2026-05-02'])->assertOk();
        $this->as($a)->getJson($this->base($w)."/curriculum-deliveries/{$summer->id}")->assertNotFound();

        // B owns the class NOW but never A's spring record.
        $this->as($b)->getJson($this->base($w)."/curriculum-deliveries/{$spring->id}")->assertNotFound();
        $this->as($b)->patchJson($this->base($w)."/curriculum-deliveries/{$spring->id}", ['started_on' => '2026-05-03'])->assertNotFound();
        $this->as($b)->getJson($this->base($w)."/curriculum-deliveries/{$summer->id}")->assertOk();

        // A unit A started and left in progress: B sees it and completes it
        // in B's own period, but cannot rewrite A's start date.
        $this->as($b)->getJson($this->base($w)."/curriculum-deliveries/{$handover->id}")->assertOk();
        $this->as($b)->patchJson($this->base($w)."/curriculum-deliveries/{$handover->id}", ['started_on' => '2026-07-02'])->assertStatus(422);
        $this->complete($w, $b, $handover->id, '2026-07-20')->assertOk();

        $unitsForB = $this->as($b)->getJson($this->base($w)."/curriculum-deliveries?section_id={$w['section']->id}&subject_offering_id={$w['offering']->id}")->json('data');
        $this->assertSame(['unavailable', 'completed', 'completed'], array_column($unitsForB, 'state'));
        $this->assertNull($unitsForB[0]['deliveryId'], 'Nothing about a delivery outside the teacher\'s periods leaves the server but its existence.');
        $this->assertNull($unitsForB[0]['startedOn']);
    }

    #[Test]
    public function co_teachers_both_operate_the_class(): void
    {
        $w = $this->teacherWorld();
        [$a, $employeeA] = $this->teacher($w);
        [$b, $employeeB] = $this->teacher($w);
        $this->own($w, $employeeA);
        $this->own($w, $employeeB);

        $id = $this->start($w, $a, '2026-06-01')->assertCreated()->json('data.id');
        $this->complete($w, $b, $id, '2026-06-15')->assertOk();
    }

    #[Test]
    public function temporary_cover_works_only_inside_its_dates(): void
    {
        $w = $this->teacherWorld();
        [$cover, $employee] = $this->teacher($w);
        $this->own($w, $employee, '2026-09-01', '2026-09-14');

        $this->start($w, $cover, '2026-09-05', $w['units'][0])->assertCreated();
        $this->start($w, $cover, '2026-09-15', $w['units'][1])->assertStatus(422);
    }

    #[Test]
    public function a_timetable_entry_is_never_ownership(): void
    {
        $w = $this->teacherWorld();
        [$user, $employee] = $this->teacher($w);
        $this->own($w, $employee); // Section A only
        $this->createTimetableEntry($w['offering'], $w['sectionB'], $employee, $this->createTimetablePeriod($w['school']));

        $this->start($w, $user, '2026-06-01', section: $w['sectionB'])->assertNotFound();
        $this->start($w, $user, '2026-06-01')->assertCreated(); // no timetable entry needed for A
    }

    #[Test]
    public function other_teachers_other_classes_and_other_schools_are_all_the_same_not_found(): void
    {
        $w = $this->teacherWorld();
        [$user, $employee] = $this->teacher($w);
        [, $other] = $this->teacher($w);
        $this->own($w, $employee);
        $this->own($w, $other, section: $w['sectionB']);
        $theirs = $this->deliveryRow($w, $w['units'][0], '2026-06-01', section: $w['sectionB']);

        $elsewhere = $this->teacherWorld();
        $foreign = $this->deliveryRow($elsewhere, $elsewhere['units'][0], '2026-06-01');

        foreach ([$theirs->id, $foreign->id, (string) Str::uuid7(), 'not-a-uuid'] as $id) {
            $this->as($user)->getJson($this->base($w)."/curriculum-deliveries/{$id}")->assertNotFound();
            $this->complete($w, $user, $id, '2026-06-10')->assertNotFound();
        }

        $this->as($user)->getJson($this->base($w)."/curriculum-deliveries?section_id={$w['sectionB']->id}&subject_offering_id={$w['offering']->id}")->assertNotFound();
        $this->assertSame('in_progress', $this->inSchool($w, fn () => $theirs->fresh()->status));
    }

    #[Test]
    public function an_unowned_delivery_or_class_answers_with_the_unknown_ids_exact_body(): void
    {
        // TCH.6 (ADR 0063 section 18): not merely the same status -- the
        // same body, and never the id of a delivery the teacher may not see.
        $w = $this->teacherWorld();
        [$user, $employee] = $this->teacher($w);
        $this->own($w, $employee);
        $theirs = $this->deliveryRow($w, $w['units'][0], '2026-06-01', section: $w['sectionB']);
        $body = fn (TestResponse $r) => Arr::except($r->assertNotFound()->json('error'), ['requestId']);
        $unknown = (string) Str::uuid7();

        foreach ([
            fn (string $id) => $this->complete($w, $user, $id, '2026-06-10'),
            fn (string $id) => $this->as($user)->patchJson($this->base($w)."/curriculum-deliveries/{$id}", ['started_on' => '2026-06-02']),
            fn (string $id) => $this->as($user)->getJson($this->base($w)."/curriculum-deliveries/{$id}"),
        ] as $request) {
            $unowned = $body($request($theirs->id));
            $this->assertSame($body($request($unknown)), $unowned);
            $this->assertStringNotContainsString($theirs->id, (string) json_encode($unowned));
        }

        $this->assertSame(
            $body($this->as($user)->postJson($this->base($w).'/curriculum-deliveries', ['section_id' => $unknown, 'subject_offering_id' => $w['offering']->id, 'syllabus_unit_id' => $w['units'][0]->id, 'started_on' => '2026-06-01'])),
            $body($this->start($w, $user, '2026-06-01', section: $w['sectionB'])),
        );
    }

    #[Test]
    public function revoking_the_teacher_role_ends_access_and_touches_nothing_else(): void
    {
        $w = $this->teacherWorld();
        [$user, $employee, $membership] = $this->teacher($w);
        $this->own($w, $employee);
        [$schoolAdmin] = $this->adminIn($w);
        $this->start($w, $user, '2026-06-01')->assertCreated();

        app(StaffAccessService::class)->revokeRole($w['school'], $schoolAdmin, $membership->id, 'teacher');

        $this->start($w, $user, '2026-06-02', $w['units'][1])->assertForbidden();
        $this->inSchool($w, function () use ($employee, $user) {
            $this->assertSame($user->id, $employee->fresh()->user_id);
            $this->assertSame(1, TeachingAssignment::query()->where('employee_id', $employee->id)->count());
        });
    }

    #[Test]
    public function ending_the_assignment_ends_access_for_dates_it_no_longer_covers(): void
    {
        $w = $this->teacherWorld();
        [$user, $employee] = $this->teacher($w);
        $assignment = $this->own($w, $employee);
        app(TeachingAssignmentService::class)->end($w['school'], $assignment->id, '2026-06-30', 'reassigned', $w['admin']);

        $this->start($w, $user, '2026-06-15', $w['units'][0])->assertCreated();
        $this->start($w, $user, '2026-07-01', $w['units'][1])->assertStatus(422);
    }

    #[Test]
    public function offboarding_ends_access_and_keeps_the_assignment(): void
    {
        foreach (['suspend', 'archive', 'end_employment', 'disable', 'unlink'] as $case) {
            $w = $this->teacherWorld();
            [$user, $employee, $membership] = $this->teacher($w);
            $this->own($w, $employee);
            $hr = $this->fullHrActor($w['school']);

            match ($case) {
                'suspend' => DB::table('school_memberships')->where('id', $membership->id)->update(['status' => 'suspended']),
                'archive' => app(EmployeeService::class)->archive($employee, $hr),
                'end_employment' => app(EmploymentService::class)->end($this->inSchool($w, fn () => $employee->employmentRecords()->firstOrFail()), '2026-08-31', $this->createUserWithCapabilities($w['school'], ['hr.employees.assignments.manage'])),
                'disable' => DB::table('users')->where('id', $user->id)->update(['is_disabled' => true, 'disabled_at' => now()]),
                'unlink' => app(EmployeeService::class)->unlinkUser($employee, $hr),
            };

            // 401 for a disabled account's token, 404 from the School-route
            // membership check for a suspended member, 403 from ActingEmployee.
            $response = $this->start($w, $user, '2026-06-01');
            $this->assertContains($response->getStatusCode(), [401, 403, 404], "{$case}: refused");
            $this->assertSame(0, $this->inSchool($w, fn () => CurriculumDelivery::query()->count()), $case);
            $this->assertSame(1, $this->inSchool($w, fn () => TeachingAssignment::query()->count()), "{$case}: the assignment stays history");
        }
    }

    #[Test]
    public function tier_one_administrators_are_unchanged_and_need_no_employee_or_assignment(): void
    {
        $w = $this->teacherWorld();
        [$user, $employee] = $this->teacher($w);
        $this->own($w, $employee, section: $w['sectionB']);
        $theirs = $this->deliveryRow($w, $w['units'][0], '2026-06-01', section: $w['sectionB']);

        foreach ([$w['deliveryAdmin'], $this->adminIn($w)[0], $this->adminIn($w, 'principal')[0]] as $admin) {
            $this->as($admin)->getJson("/api/v1/schools/{$w['school']->id}/curriculum-deliveries/{$theirs->id}")->assertOk();
            $this->as($admin)->getJson("/api/v1/schools/{$w['school']->id}/subject-offerings/{$w['offering']->id}/curriculum-deliveries")->assertOk()->assertJsonCount(1, 'data');
        }

        $this->as($w['deliveryAdmin'])->postJson("/api/v1/schools/{$w['school']->id}/subject-offerings/{$w['offering']->id}/curriculum-deliveries", [
            'section_id' => $w['section']->id, 'syllabus_unit_id' => $w['units'][1]->id, 'started_on' => '2026-06-01',
        ])->assertCreated();

        // A teacher never reaches the Tier 1 surface.
        $this->as($user)->getJson("/api/v1/schools/{$w['school']->id}/curriculum-deliveries/{$theirs->id}")->assertForbidden();
    }

    #[Test]
    public function teachers_cannot_administer_teaching_assignments(): void
    {
        $w = $this->teacherWorld();
        [$user, $employee] = $this->teacher($w);
        $this->own($w, $employee);

        $this->as($user)->getJson("/api/v1/schools/{$w['school']->id}/teaching-assignments")->assertForbidden();
        $this->as($user)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("/api/v1/schools/{$w['school']->id}/teaching-assignments", [
            'employee_id' => $employee->id, 'section_id' => $w['sectionB']->id, 'subject_offering_id' => $w['offering']->id, 'starts_on' => '2026-06-01',
        ])->assertForbidden();
    }

    /** @return array{0: User} a seeded-role administrator of this School (no Employee) */
    private function adminIn(array $w, string $role = 'school_admin'): array
    {
        $user = $this->createUser();
        $this->assignSchoolRole($this->createMembership($user, $w['school']), $role);

        return [$user];
    }
}
