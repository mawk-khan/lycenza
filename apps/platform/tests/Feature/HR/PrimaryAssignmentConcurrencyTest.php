<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * Phase 8A closure correction (item 10) -- REQUIRED real-concurrency
 * proof for the primary-assignment partial unique index
 * (`employee_assignments_one_primary_open_per_employment`), mirroring
 * ReportingHierarchyConcurrencyTest's/EmployeeNumberConcurrencyTest's
 * exact real-process pattern: two GENUINELY separate OS processes --
 * not sequential calls in one PHP process -- race
 * App\Domain\HR\Application\EmployeeAssignmentService::setPrimary() for
 * TWO DIFFERENT Assignments under the SAME EmploymentRecord. Exactly
 * one must succeed and the other must be rejected with
 * ConcurrentPrimaryAssignmentConflictException (never both succeeding,
 * which would produce two persisted open primaries under one
 * EmploymentRecord, and never both being rejected).
 *
 * Deliberately does NOT use DatabaseTransactions for the fixtures this
 * test creates (see $connectionsToTransact) -- the subprocesses are
 * separate PostgreSQL sessions and can never see this test process's
 * uncommitted rows.
 */
class PrimaryAssignmentConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            $this->school->delete(); // cascades employees, employment_records, employee_assignments
        }

        parent::tearDown();
    }

    #[Test]
    public function concurrent_set_primary_on_two_assignments_under_the_same_employment_produces_exactly_one_rejection(): void
    {
        $this->school = $this->createSchool();
        $position = $this->createPosition($this->school);
        $employee = $this->createEmployee($this->school);
        $employment = $this->createEmploymentRecord($employee);

        // Deliberately NEITHER starts as primary -- if one already were,
        // its own setPrimary() call would be a same-row no-op that
        // never contends with the other transaction's demote step,
        // which would not actually exercise the partial unique index's
        // race window at all.
        $assignmentA = $this->createEmployeeAssignment($employment, $position, ['is_primary' => false]);
        $assignmentB = $this->createEmployeeAssignment($employment, $position, ['is_primary' => false]);
        // Committed BEFORE the subprocesses spawn -- no DB transactions
        // in this test ($connectionsToTransact = [] above), same as
        // ReportingHierarchyConcurrencyTest's identical pattern.
        $actor = $this->fullHrActor($this->school);

        $script = __DIR__.'/../../Support/set-assignment-primary.php';

        // Forced, verified overlap (Tests\Concerns\ForcesConcurrentOverlap):
        // a SEQUENTIAL second setPrimary() legitimately replaces the first,
        // so B is proven blocked on A's uncommitted partial-unique-index
        // entry before A commits.
        $outputs = $this->raceWithHeldHolder(
            ['php', $script, $this->school->id, $assignmentA->id, $actor->id],
            ['php', $script, $this->school->id, $assignmentB->id, $actor->id],
        );
        $this->assertSame('ok:'.$assignmentA->id, $outputs[0], 'The first (held) setPrimary() must succeed.');

        $succeeded = array_filter($outputs, fn (string $o) => str_starts_with($o, 'ok:'));
        $rejected = array_filter($outputs, fn (string $o) => str_starts_with($o, 'rejected:'));

        $this->assertCount(1, $succeeded, 'Exactly one of the two concurrent setPrimary() attempts must succeed; got: '.implode(', ', $outputs));
        $this->assertCount(1, $rejected, 'Exactly one of the two concurrent setPrimary() attempts must be rejected; got: '.implode(', ', $outputs));
        $this->assertStringContainsString('ConcurrentPrimaryAssignmentConflictException', implode(', ', $rejected));

        $context = app(TenantContext::class);
        $openPrimaryCount = $context->withSchool(
            $this->school,
            fn () => EmployeeAssignment::query()
                ->where('employment_record_id', $employment->id)
                ->where('is_primary', true)
                ->whereNull('ends_on')
                ->count(),
        );

        $this->assertSame(1, $openPrimaryCount, 'Exactly one open primary Assignment must exist under the EmploymentRecord after the race -- never zero, never two.');
    }
}
