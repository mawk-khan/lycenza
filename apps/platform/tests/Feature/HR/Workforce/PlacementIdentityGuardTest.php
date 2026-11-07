<?php

namespace Tests\Feature\HR\Workforce;

use App\Domain\HR\Application\EmployeeAssignmentService;
use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Application\Exceptions\PlacementChangeConflictException;
use App\Domain\HR\Application\Workforce\WorkforceAsOfReader;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * HR.R1-C9 (HR.R1-C2 "Placement guard scope"): every write here is a raw UPDATE by
 * the runtime role, so no service stands between it and the database.
 * `employment_record_id` is frozen. `is_primary` is frozen on a placement that never
 * took effect, a closed primary period stays primary history, and nothing is
 * promoted on an ended employment. On a running employment `is_primary` stays
 * writable, because setPrimary() and a named placement change write it there; the
 * positive controls prove that still works.
 */
class PlacementIdentityGuardTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private User $hr;

    private EmploymentRecord $employment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-06-15 06:00:00');
        $this->school = $this->createSchool();
        $this->hr = $this->fullHrActor($this->school);
        $this->employment = $this->employment();
    }

    private function employment(array $attributes = []): EmploymentRecord
    {
        return $this->createEmploymentRecord($this->createEmployee($this->school), $attributes + ['status' => 'active', 'starts_on' => '2024-01-01', 'ends_on' => null]);
    }

    private function placement(EmploymentRecord $employment, array $attributes): EmployeeAssignment
    {
        return $this->createEmployeeAssignment($employment, $this->createPosition($this->school), $attributes + ['ends_on' => null, 'is_primary' => false]);
    }

    /** The raw write; returns the SQLSTATE it raised, or null. */
    private function write(string $id, array $values): ?string
    {
        try {
            DB::transaction(fn () => app(TenantContext::class)->withSchool($this->school, fn () => DB::table('employee_assignments')->where('id', $id)->update($values)));

            return null;
        } catch (QueryException $e) {
            return (string) $e->getCode();
        }
    }

    private function neverEffective(bool $primary): EmployeeAssignment
    {
        $employment = $this->employment();
        $planned = $this->placement($employment, ['starts_on' => '2026-09-01', 'is_primary' => $primary]);
        app(EmploymentService::class)->end($employment, '2026-06-30', $this->hr, 'separated');

        return app(TenantContext::class)->withSchool($this->school, fn () => $planned->fresh());
    }

    #[Test]
    public function a_placement_never_changes_employment_whatever_its_state(): void
    {
        $other = $this->employment();
        $ended = $this->employment();
        $closed = $this->placement($ended, ['starts_on' => '2024-01-01']);
        app(EmploymentService::class)->end($ended, '2026-01-31', $this->hr, 'separated');

        foreach ([$this->placement($this->employment, ['starts_on' => '2024-01-01']), app(TenantContext::class)->withSchool($this->school, fn () => $closed->fresh()), $this->neverEffective(false)] as $placement) {
            $this->assertSame('23514', $this->write($placement->id, ['employment_record_id' => $other->id]));
        }
        $this->assertSame(0, app(TenantContext::class)->withSchool($this->school, fn () => EmployeeAssignment::query()->where('employment_record_id', $other->id)->count()));
    }

    #[Test]
    public function a_placement_that_never_took_effect_keeps_its_primary_flag_in_both_directions(): void
    {
        $plain = $this->neverEffective(false);
        $this->assertSame('23514', $this->write($plain->id, ['is_primary' => true]));

        $primary = $this->neverEffective(true);
        $this->assertSame('23514', $this->write($primary->id, ['is_primary' => false]));

        $this->assertNull($this->write($plain->id, ['is_primary' => false]), 'writing the same value changes nothing');
    }

    #[Test]
    public function a_closed_primary_period_stays_primary_history(): void
    {
        $closed = $this->placement($this->employment, ['starts_on' => '2024-01-01', 'ends_on' => '2025-12-31', 'is_primary' => true]);

        $this->assertSame('23514', $this->write($closed->id, ['is_primary' => false]));
    }

    #[Test]
    public function a_primary_is_not_cleared_in_the_statement_that_closes_it(): void
    {
        $open = $this->placement($this->employment, ['starts_on' => '2024-01-01', 'is_primary' => true]);

        $this->assertSame('23514', $this->write($open->id, ['ends_on' => '2025-12-31', 'is_primary' => false]));
    }

    #[Test]
    public function nothing_is_promoted_on_an_ended_employment(): void
    {
        $ended = $this->employment();
        $closed = $this->placement($ended, ['starts_on' => '2024-01-01']);
        app(EmploymentService::class)->end($ended, '2026-01-31', $this->hr, 'separated');

        $this->assertSame('23514', $this->write($closed->id, ['is_primary' => true]));
        $this->assertRefusedAsEnded(app(TenantContext::class)->withSchool($this->school, fn () => $closed->fresh()));
    }

    #[Test]
    public function a_placement_that_is_over_never_takes_the_primary_from_the_current_one(): void
    {
        $current = $this->placement($this->employment, ['starts_on' => '2024-01-01', 'is_primary' => true]);
        $over = $this->placement($this->employment, ['starts_on' => '2024-01-01', 'ends_on' => '2025-12-31']);

        $this->assertRefusedAsEnded($over);

        app(TenantContext::class)->withSchool($this->school, function () use ($current, $over): void {
            $this->assertTrue($current->fresh()->is_primary);
            $this->assertFalse($over->fresh()->is_primary);
        });
    }

    private function assertRefusedAsEnded(EmployeeAssignment $assignment): void
    {
        try {
            app(EmployeeAssignmentService::class)->setPrimary($assignment, $this->hr);
            $this->fail('A placement that is over never becomes primary.');
        } catch (PlacementChangeConflictException $e) {
            $this->assertStringContainsString('has ended', $e->getMessage());
        }
    }

    #[Test]
    public function the_authorized_writers_of_the_flag_keep_working(): void
    {
        $first = $this->placement($this->employment, ['starts_on' => '2024-01-01', 'is_primary' => true]);
        $second = $this->placement($this->employment, ['starts_on' => '2025-01-01']);

        $this->assertNull($this->write($second->id, ['is_primary' => false]));

        app(EmployeeAssignmentService::class)->setPrimary($second, $this->hr);

        app(TenantContext::class)->withSchool($this->school, function () use ($first, $second): void {
            $this->assertFalse($first->fresh()->is_primary);
            $this->assertTrue($second->fresh()->is_primary);
        });
    }

    #[Test]
    public function another_schools_placement_is_not_reachable(): void
    {
        $placement = $this->placement($this->employment, ['starts_on' => '2024-01-01']);
        $other = $this->createSchool();

        $updated = app(TenantContext::class)->withSchool($other, fn () => DB::table('employee_assignments')->where('id', $placement->id)->update(['is_primary' => true]));

        $this->assertSame(0, $updated);
    }

    #[Test]
    public function these_writes_ran_as_the_runtime_role_not_the_exempt_owner(): void
    {
        $this->assertFalse((bool) DB::selectOne("select pg_has_role(current_user, (select relowner from pg_class where oid = 'employee_assignments'::regclass), 'MEMBER') as m")->m, 'the tests above ran as the runtime role');
    }

    #[Test]
    public function a_cancelled_plan_stays_invisible_to_the_history_readers(): void
    {
        $planned = $this->neverEffective(true);

        $this->assertSame('23514', $this->write($planned->id, ['is_primary' => false]));
        $this->assertNull(app(WorkforceAsOfReader::class)->primaryAssignmentOn($this->school, $planned->employment_record_id, '2026-09-15'));
        $this->assertNull(app(WorkforceAsOfReader::class)->primaryAssignmentOn($this->school, $planned->employment_record_id, '2026-08-31'));
    }
}
