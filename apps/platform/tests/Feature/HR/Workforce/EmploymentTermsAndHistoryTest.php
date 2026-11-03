<?php

namespace Tests\Feature\HR\Workforce;

use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Application\Exceptions\EmploymentTermsConflictException;
use App\Domain\HR\Application\Exceptions\HrSelfAdministrationException;
use App\Domain\HR\Application\Exceptions\InvalidEmploymentTermsException;
use App\Domain\HR\Application\Workforce\EmploymentTermsChangeReason;
use App\Domain\HR\Application\Workforce\EmploymentTermsService;
use App\Domain\HR\Application\Workforce\EmploymentType;
use App\Domain\HR\Application\Workforce\StaffType;
use App\Domain\HR\Application\Workforce\WorkforceAsOfReader;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\EmploymentTerm;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * HR.1A: employment terms are effective-dated and append-only; staff type is
 * closed and never derived; the historical resolver answers any date from
 * history, and "current" keeps its canonical definition; S-8 (starting
 * statuses) and S-11 (no mass-assigned identity fields).
 */
class EmploymentTermsAndHistoryTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-15T06:00:00Z'));
        $this->school = $this->createSchool(['timezone' => 'Asia/Kolkata']);
    }

    private function inSchool(callable $fn): mixed
    {
        return app(TenantContext::class)->withSchool($this->school, $fn);
    }

    private function hire(array $attributes = []): EmploymentRecord
    {
        return app(EmploymentService::class)->create($this->createEmployee($this->school), [
            'employment_type' => 'probationary', 'staff_type' => 'teaching', 'starts_on' => '2026-04-01', ...$attributes,
        ], $this->fullHrActor($this->school));
    }

    private function terms(EmploymentRecord $employment, string $from, ?User $actor = null, EmploymentType $type = EmploymentType::PERMANENT, StaffType $staff = StaffType::TEACHING, EmploymentTermsChangeReason $reason = EmploymentTermsChangeReason::CONTRACT_CHANGE): EmploymentTerm
    {
        return app(EmploymentTermsService::class)->change($employment, $from, $type, $staff, null, $reason, $actor ?? $this->fullHrActor($this->school));
    }

    #[Test]
    public function a_terms_change_appends_and_history_answers_the_terms_on_any_date(): void
    {
        $employment = $this->hire();
        $actor = $this->fullHrActor($this->school);

        $term = $this->terms($employment, '2026-10-01', $actor, EmploymentType::PERMANENT, StaffType::NON_TEACHING, EmploymentTermsChangeReason::RECLASSIFICATION);

        $reader = app(WorkforceAsOfReader::class);
        $this->assertSame(['probationary', 'teaching'], [$reader->termsOn($this->school, $employment->id, '2026-09-30')?->employment_type, $reader->termsOn($this->school, $employment->id, '2026-09-30')?->staff_type]);
        $this->assertSame(['permanent', 'non_teaching'], [$reader->termsOn($this->school, $employment->id, '2026-10-01')?->employment_type, $reader->termsOn($this->school, $employment->id, '2026-10-01')?->staff_type]);
        $this->assertSame($actor->id, $term->recorded_by_user_id);
        $this->assertSame(['permanent', 'non_teaching'], $this->inSchool(fn () => [EmploymentRecord::query()->whereKey($employment->id)->value('employment_type'), EmploymentRecord::query()->whereKey($employment->id)->value('staff_type')]), 'the projection follows the latest terms');

        $audit = $this->inSchool(fn () => DB::table('school_audit_events')->where('event_type', 'hr.employment_terms.changed')->first());
        $metadata = json_decode((string) $audit?->metadata, true);
        $this->assertSame($actor->id, $audit?->actor_user_id);
        $this->assertSame(['2026-10-01', 'reclassification', ['employment_type', 'staff_type'], 'probationary', 'teaching'], [
            $metadata['effectiveFrom'], $metadata['reason'], $metadata['fields'], $metadata['previousEmploymentType'], $metadata['previousStaffType'],
        ]);
    }

    #[Test]
    public function terms_never_rewrite_history_or_the_future(): void
    {
        $employment = $this->hire();
        $this->terms($employment, '2026-10-01');

        foreach ([['2026-10-01', EmploymentTermsConflictException::class, 'not_after_latest'], ['2026-06-01', EmploymentTermsConflictException::class, 'not_after_latest'], ['2026-11-16', InvalidEmploymentTermsException::class, 'future_dated']] as [$from, $class, $reason]) {
            try {
                $this->terms($employment, $from, null, EmploymentType::CONTRACT);
                $this->fail("{$from} refused");
            } catch (EmploymentTermsConflictException|InvalidEmploymentTermsException $e) {
                $this->assertInstanceOf($class, $e);
                $this->assertSame($reason, $e->reason);
            }
        }

        try {
            $this->terms($employment, '2026-11-01');
            $this->fail('no change refused');
        } catch (InvalidEmploymentTermsException $e) {
            $this->assertSame('no_change', $e->reason);
        }
    }

    #[Test]
    public function an_ended_employment_keeps_its_terms(): void
    {
        $employment = $this->createEmploymentRecord($this->createEmployee($this->school), ['starts_on' => '2026-01-01', 'ends_on' => '2026-06-30', 'status' => 'separated']);

        $this->expectException(EmploymentTermsConflictException::class);
        $this->terms($employment, '2026-03-01');
    }

    #[Test]
    public function terms_are_whole_school_employment_administration_and_never_ones_own(): void
    {
        $employment = $this->hire();
        $campus = $this->createCampus($this->school);
        $campusHr = $this->createUser();
        $this->assignSchoolRole($this->createMembership($campusHr, $this->school), 'hr_officer', $campus->id);

        foreach ([$this->createUserWithCapabilities($this->school, ['hr.employees.assignments.view']), $campusHr] as $actor) {
            try {
                $this->terms($employment, '2026-10-01', $actor);
                $this->fail('refused');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }

        $self = $this->fullHrActor($this->school);
        $this->inSchool(fn () => Employee::query()->whereKey($employment->employee_id)->update(['user_id' => $self->id]));
        $this->expectException(HrSelfAdministrationException::class);
        $this->terms($employment, '2026-10-01', $self);
    }

    #[Test]
    public function staff_type_is_closed_and_never_derived_from_category_department_designation_or_role(): void
    {
        $teachingCategory = $this->createEmployeeCategory($this->school, ['code' => 'TEACH', 'status' => 'active']);
        // A teaching-coded category does NOT make the employment teaching at runtime.
        $unclassified = $this->hire(['staff_type' => null, 'employee_category_id' => $teachingCategory->id]);
        $this->assertNull($unclassified->staff_type);

        foreach (StaffType::values() as $value) {
            $this->assertSame($value, $this->hire(['staff_type' => $value])->staff_type);
        }

        try {
            $this->hire(['staff_type' => 'visiting']);
            $this->fail('unknown staff type refused');
        } catch (InvalidEmploymentTermsException $e) {
            $this->assertSame('staff_type', $e->reason);
        }
        try {
            $this->hire(['employment_type' => 'full_time']);
            $this->fail('unknown employment type refused');
        } catch (InvalidEmploymentTermsException $e) {
            $this->assertSame('employment_type', $e->reason);
        }
    }

    #[Test]
    public function a_new_employment_only_starts_draft_pre_joining_or_active(): void
    {
        foreach (['notice_period', 'separated', 'terminated'] as $status) {
            try {
                $this->hire(['status' => $status]);
                $this->fail("{$status} refused at creation");
            } catch (InvalidEmploymentTermsException $e) {
                $this->assertSame('status_not_a_start', $e->reason);
            }
        }
        try {
            $this->hire(['status' => 'pre_joining', 'starts_on' => '2026-11-15']);
            $this->fail('pre-joining starts after today');
        } catch (InvalidEmploymentTermsException $e) {
            $this->assertSame('pre_joining_not_future', $e->reason);
        }

        $this->assertSame('pre_joining', $this->hire(['status' => 'pre_joining', 'starts_on' => '2026-12-01'])->status);
        $this->assertSame('draft', $this->hire(['status' => 'draft'])->status);
    }

    #[Test]
    public function history_is_permanent_and_current_keeps_its_canonical_meaning(): void
    {
        $reader = app(WorkforceAsOfReader::class);
        $position = $this->createPosition($this->school);

        $left = $this->createEmploymentRecord($this->createEmployee($this->school), ['starts_on' => '2026-01-01', 'ends_on' => '2026-06-30', 'status' => 'separated']);
        $this->createEmployeeAssignment($left, $position, ['is_primary' => true, 'starts_on' => '2026-01-01', 'ends_on' => '2026-06-30']);
        $future = $this->createEmploymentRecord($this->createEmployee($this->school), ['starts_on' => '2026-12-01', 'status' => 'active']);
        $draft = $this->createEmploymentRecord($this->createEmployee($this->school), ['starts_on' => '2026-01-01', 'status' => 'draft']);
        $current = $this->createEmploymentRecord($this->createEmployee($this->school), ['starts_on' => '2026-01-01', 'status' => 'active']);

        // A separated employment still answers for a date it covered, never for today.
        $this->assertSame($left->id, $reader->employmentOn($this->school, $left->employee_id, '2026-03-01')?->id);
        $this->assertNotNull($reader->placementOn($this->school, $left->employee_id, '2026-03-01')?->primaryAssignmentId);
        $this->assertNull($reader->currentPlacement($this->school, $left->employee_id));
        $this->assertFalse($reader->isCurrentlyEmployed($this->school, $left->employee_id));
        // A future hire is not current today; it answers for its own dates.
        $this->assertNull($reader->currentPlacement($this->school, $future->employee_id));
        $this->assertSame($future->id, $reader->employmentOn($this->school, $future->employee_id, '2026-12-15')?->id);
        // A draft is never workforce history.
        $this->assertNull($reader->employmentOn($this->school, $draft->employee_id, '2026-03-01'));
        $this->assertTrue($reader->isCurrentlyEmployed($this->school, $current->employee_id));
        $this->assertSame($current->id, $reader->currentPlacement($this->school, $current->employee_id)?->employmentRecordId);

        // Bounded: the batch read costs the same for one Employee as for four.
        DB::enableQueryLog();
        $reader->placementsOn($this->school, [$left->employee_id], '2026-03-01');
        $one = count(DB::getQueryLog());
        DB::flushQueryLog();
        $reader->placementsOn($this->school, [$left->employee_id, $future->employee_id, $draft->employee_id, $current->employee_id], '2026-03-01');
        $this->assertSame($one, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    #[Test]
    public function an_employees_school_and_record_status_are_never_mass_assigned(): void
    {
        $other = $this->createSchool();
        $employee = $this->createEmployee($this->school);

        $this->inSchool(fn () => $employee->update(['school_id' => $other->id, 'record_status' => 'archived', 'full_name' => 'Renamed']));

        $fresh = $this->inSchool(fn () => Employee::query()->findOrFail($employee->id));
        $this->assertSame([$this->school->id, 'active', 'Renamed'], [$fresh->school_id, $fresh->record_status, $fresh->full_name]);
    }
}
