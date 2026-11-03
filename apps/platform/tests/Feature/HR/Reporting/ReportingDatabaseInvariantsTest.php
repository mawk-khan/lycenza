<?php

namespace Tests\Feature\HR\Reporting;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\ReportingRelationship;
use App\Models\School;
use App\Support\Operations\CheckResult;
use App\Support\Operations\DatabaseRoleVerifier;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * HR.3A: the database backstops of `reporting_relationships`, proven with
 * RAW writes as the runtime role (no service in the way). Inclusive ranges;
 * `ends_on = starts_on - 1` is the empty interval.
 */
class ReportingDatabaseInvariantsTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
    }

    private function inSchool(callable $fn): mixed
    {
        return app(TenantContext::class)->withSchool($this->school, $fn);
    }

    /** Runs $write in a savepoint and returns the SQLSTATE it raised (or null). */
    private function sqlstate(callable $write): ?string
    {
        try {
            DB::transaction(fn () => $this->inSchool($write));

            return null;
        } catch (QueryException $e) {
            return (string) $e->getCode();
        }
    }

    private function employment(string $startsOn = '2024-01-01', ?string $endsOn = null, ?School $school = null): EmploymentRecord
    {
        return $this->createEmploymentRecord($this->createEmployee($school ?? $this->school), ['starts_on' => $startsOn, 'ends_on' => $endsOn, 'status' => $endsOn === null ? 'active' : 'separated']);
    }

    /** @param  array<string, mixed>  $extra */
    private function line(EmploymentRecord $sub, EmploymentRecord $manager, string $startsOn, ?string $endsOn = null, array $extra = []): ReportingRelationship
    {
        $line = new ReportingRelationship;
        $line->forceFill([
            'school_id' => $this->school->id, 'subordinate_employment_id' => $sub->id, 'manager_employment_id' => $manager->id,
            'starts_on' => $startsOn, 'ends_on' => $endsOn, 'started_via' => 'backfill', ...$extra,
        ])->save();

        return $line;
    }

    #[Test]
    public function one_line_per_subordinate_per_day_adjacent_is_valid_and_empty_intervals_hold_no_day(): void
    {
        [$sub, $m1, $m2, $m3] = [$this->employment(), $this->employment(), $this->employment(), $this->employment()];
        $this->inSchool(fn () => $this->line($sub, $m1, '2025-01-01', '2025-06-30'));

        $this->assertNull($this->sqlstate(fn () => $this->line($sub, $m2, '2025-07-01')), 'adjacent');
        $this->assertSame('23P01', $this->sqlstate(fn () => $this->line($sub, $m3, '2025-06-30', '2025-06-30')), 'one shared day');
        $this->assertNull($this->sqlstate(fn () => $this->line($sub, $m3, '2025-03-01', '2025-02-28')), 'the empty interval overlaps nothing');
        $this->assertSame('23514', $this->sqlstate(fn () => $this->line($sub, $m3, '2025-03-01', '2025-02-27')), 'an end before the empty interval');
    }

    #[Test]
    public function never_oneself_and_never_the_same_employee(): void
    {
        $employee = $this->createEmployee($this->school);
        $first = $this->createEmploymentRecord($employee, ['starts_on' => '2023-01-01', 'ends_on' => '2023-12-31', 'status' => 'separated']);
        $second = $this->createEmploymentRecord($employee, ['starts_on' => '2024-01-01', 'ends_on' => null, 'status' => 'active']);

        $this->assertSame('23514', $this->sqlstate(fn () => $this->line($second, $second, '2024-02-01')), 'CHECK: the same employment');
        $this->assertSame('23514', $this->sqlstate(fn () => $this->line($second, $first, '2024-02-01')), 'trigger: the same Employee');
    }

    #[Test]
    public function a_line_lies_within_both_employments(): void
    {
        $sub = $this->employment('2024-01-01');
        $manager = $this->employment('2025-01-01', '2025-12-31');

        $this->assertSame('23514', $this->sqlstate(fn () => $this->line($sub, $manager, '2024-12-31')), 'before the manager was employed');
        $this->assertSame('23514', $this->sqlstate(fn () => $this->line($sub, $manager, '2025-01-01')), 'open past the manager\'s end');
        $this->assertNull($this->sqlstate(fn () => $this->line($sub, $manager, '2025-01-01', '2025-12-31')));
    }

    #[Test]
    public function another_schools_employment_is_refused_by_the_composite_key(): void
    {
        $sub = $this->employment();
        $foreign = $this->employment(school: $this->createSchool());

        $this->assertSame('23503', $this->sqlstate(fn () => $this->line($sub, $foreign, '2025-01-01')));
    }

    #[Test]
    public function a_loop_is_refused_on_any_shared_day_but_not_across_disjoint_dates(): void
    {
        [$a, $b, $c] = [$this->employment(), $this->employment(), $this->employment()];
        $this->inSchool(fn () => $this->line($a, $b, '2025-01-01', '2025-12-31'));
        $this->inSchool(fn () => $this->line($b, $c, '2025-01-01'));

        $this->assertSame('23514', $this->sqlstate(fn () => $this->line($c, $a, '2025-12-31')), 'A -> B -> C -> A on 2025-12-31');
        $this->assertSame('23P01', $this->sqlstate(fn () => $this->line($b, $a, '2025-06-01')), 'B already has a line: the overlap check fires first');
        $this->assertNull($this->sqlstate(fn () => $this->line($c, $a, '2026-01-01')), 'A no longer reports to B from 2026');
    }

    #[Test]
    public function parties_and_start_are_immutable_the_end_only_narrows_and_a_void_is_final(): void
    {
        [$sub, $manager, $other] = [$this->employment(), $this->employment(), $this->employment()];
        $line = $this->inSchool(fn () => $this->line($sub, $manager, '2025-01-01', '2025-12-31'));
        $actor = $this->fullHrActor($this->school);

        $this->assertSame('23514', $this->sqlstate(fn () => DB::table('reporting_relationships')->where('id', $line->id)->update(['manager_employment_id' => $other->id])));
        $this->assertSame('23514', $this->sqlstate(fn () => DB::table('reporting_relationships')->where('id', $line->id)->update(['starts_on' => '2025-02-01'])));
        $this->assertSame('23514', $this->sqlstate(fn () => DB::table('reporting_relationships')->where('id', $line->id)->update(['ends_on' => null])), 'widened');
        $this->assertNull($this->sqlstate(fn () => DB::table('reporting_relationships')->where('id', $line->id)->update(['ends_on' => '2025-06-30'])));
        $this->assertNull($this->sqlstate(fn () => DB::table('reporting_relationships')->where('id', $line->id)->update(['voided_at' => now(), 'voided_by_user_id' => $actor->id, 'void_reason' => 'administrative_correction'])));
        $this->assertSame('23514', $this->sqlstate(fn () => DB::table('reporting_relationships')->where('id', $line->id)->update(['ends_on' => '2025-03-31'])), 'voided is final');
        $this->assertSame('23514', $this->sqlstate(fn () => DB::table('reporting_relationships')->insert([
            'id' => (string) str()->uuid7(), 'school_id' => $this->school->id, 'subordinate_employment_id' => $sub->id, 'manager_employment_id' => $manager->id,
            'starts_on' => '2025-01-01', 'started_via' => 'backfill', 'voided_at' => now(), 'voided_by_user_id' => $actor->id, 'void_reason' => 'administrative_correction',
            'created_at' => now(), 'updated_at' => now(),
        ])), 'never created voided');
    }

    #[Test]
    public function the_shape_of_each_start_end_and_void_is_checked(): void
    {
        [$sub, $manager] = [$this->employment(), $this->employment()];
        $actor = $this->fullHrActor($this->school);

        $this->assertSame('23514', $this->sqlstate(fn () => $this->line($sub, $manager, '2025-01-01', null, ['started_via' => 'assigned'])), 'a person names a reason and an actor');
        $this->assertSame('23514', $this->sqlstate(fn () => $this->line($sub, $manager, '2025-01-01', null, ['start_reason' => 'free text'])));
        $this->assertSame('23514', $this->sqlstate(fn () => $this->line($sub, $manager, '2025-01-01', null, ['started_via' => 'assigned', 'start_reason' => 'restructuring', 'started_by_user_id' => $actor->id, 'ended_via' => 'removed'])), 'an end needs a date and an actor');
        $this->assertNull($this->sqlstate(fn () => $this->line($sub, $manager, '2025-01-01', null, ['started_via' => 'assigned', 'start_reason' => 'restructuring', 'started_by_user_id' => $actor->id])));
    }

    #[Test]
    public function an_employment_never_ends_or_starts_so_that_a_live_line_falls_outside_it(): void
    {
        [$sub, $manager] = [$this->employment(), $this->employment()];
        $this->inSchool(fn () => $this->line($sub, $manager, '2025-01-01'));

        $this->assertSame('23514', $this->sqlstate(fn () => DB::table('employment_records')->where('id', $manager->id)->update(['ends_on' => '2025-12-31', 'status' => 'separated'])), 'the manager side');
        $this->assertSame('23514', $this->sqlstate(fn () => DB::table('employment_records')->where('id', $sub->id)->update(['ends_on' => '2025-12-31', 'status' => 'separated'])), 'the subordinate side');
        $this->assertNotNull($this->sqlstate(fn () => DB::table('employment_records')->where('id', $sub->id)->update(['starts_on' => '2025-02-01'])), 'a later start (refused; an earlier employment-dates guard may answer first)');
        $this->assertNull($this->sqlstate(fn () => DB::table('reporting_relationships')->where('subordinate_employment_id', $sub->id)->update(['ends_on' => '2025-12-31'])));
        $this->assertNull($this->sqlstate(fn () => DB::table('employment_records')->where('id', $manager->id)->update(['ends_on' => '2025-12-31', 'status' => 'separated'])), 'once the line ends with it');
    }

    #[Test]
    public function the_runtime_role_cannot_delete_a_line_and_another_school_cannot_see_one(): void
    {
        [$sub, $manager] = [$this->employment(), $this->employment()];
        $line = $this->inSchool(fn () => $this->line($sub, $manager, '2025-01-01'));

        $this->assertSame('42501', $this->sqlstate(fn () => DB::table('reporting_relationships')->where('id', $line->id)->delete()));

        $other = $this->createSchool();
        $this->assertSame(0, app(TenantContext::class)->withSchool($other, fn () => DB::table('reporting_relationships')->where('id', $line->id)->count()), 'RLS');
        DB::select('select set_config(?, ?, true)', [TenantRls::SESSION_VAR, '']);
        $this->assertSame(0, DB::table('reporting_relationships')->count(), 'no tenant context: nothing (fails closed)');
    }

    #[Test]
    public function the_legacy_pointer_is_gone_and_the_verifier_checks_the_backstops(): void
    {
        $this->assertFalse(Schema::hasColumn('employee_assignments', 'manager_assignment_id'), 'HR.3A Option A: one source of truth');
        $checks = collect(app(DatabaseRoleVerifier::class)->verify())->keyBy('code');
        $this->assertSame(CheckResult::PASS, $checks['hr_reporting_invariants']->status);
    }
}
