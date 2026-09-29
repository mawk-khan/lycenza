<?php

namespace Tests\Feature\Postgres;

use App\Domain\Fees\Application\AssessChargeData;
use App\Domain\Fees\Application\ChargeService;
use App\Domain\Fees\Infrastructure\Charge;
use App\Domain\Fees\Infrastructure\FeeAssessment;
use App\Domain\Fees\Infrastructure\FeeAssessmentRun;
use App\Domain\Fees\Infrastructure\FeeAssessmentRunItem;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Feature\Fees\Concerns\CreatesFeeAssessmentFixtures;
use Tests\TestCase;

/**
 * FEE.2 (ADR 0062 §9, §11, §22) at the raw PostgreSQL layer: forced RLS on
 * the three new tables, isolation and fail-closed missing context, the run
 * lifecycle and open-run invariants, item phase immutability, the
 * one-live-assessment key, and both halves of the void guard -- each
 * proven with SQL that bypasses the Application layer.
 */
class FeeAssessmentRlsIsolationTest extends TestCase
{
    use CreatesFeeAssessmentFixtures;

    private const TABLES = ['fee_assessment_runs', 'fee_assessment_run_items', 'fee_assessments'];

    private function setSchool(?string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId ?? '']);
    }

    private function assertRejectedBy(string $fragment, callable $op, string $message): void
    {
        try {
            DB::connection('pgsql')->transaction($op);
            $this->fail($message);
        } catch (QueryException $e) {
            $this->assertStringContainsString($fragment, $e->getMessage(), $message);
        }
    }

    /** A world with one executed T1 run (one Student, one charge, one assessment). */
    private function executedWorld(): array
    {
        $w = $this->assessmentWorld();
        $w['student'] = $this->enroll($w)->student_id;
        $w['run'] = $this->executedRun($w);
        $w['assessment'] = $this->inSchool($w['school'], fn () => FeeAssessment::query()->sole());

        return $w;
    }

    #[Test]
    public function the_new_tables_have_rls_enabled_and_forced(): void
    {
        foreach (self::TABLES as $table) {
            $row = DB::connection('pgsql_admin')->selectOne(
                'select relrowsecurity, relforcerowsecurity from pg_class where relname = ? and relnamespace = ?::regnamespace',
                [$table, 'public'],
            );
            $this->assertTrue($row->relrowsecurity && $row->relforcerowsecurity, "{$table} must have RLS enabled and forced");
        }
    }

    #[Test]
    public function rows_are_isolated_by_school_and_missing_context_fails_closed(): void
    {
        $a = $this->executedWorld();
        $b = $this->assessmentWorld();

        $this->setSchool($a['school']->id);
        foreach (self::TABLES as $table) {
            $this->assertGreaterThan(0, DB::table($table)->count(), $table);
        }

        $this->setSchool($b['school']->id);
        foreach (self::TABLES as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} of School A must be invisible to School B");
        }
        $this->assertSame(0, DB::table('fee_assessment_runs')->where('id', $a['run']->id)->update(['updated_at' => now()]));

        $this->setSchool(null);
        foreach (self::TABLES as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} must be empty without tenant context");
        }

        app(TenantContext::class)->clearAll();
        $this->assertSame(0, FeeAssessmentRun::query()->count());
        $this->assertSame(0, FeeAssessmentRunItem::query()->count());
        $this->assertSame(0, FeeAssessment::query()->count());
    }

    #[Test]
    public function a_run_cannot_reference_another_schools_structure_or_start_outside_draft(): void
    {
        $a = $this->assessmentWorld();
        $b = $this->assessmentWorld();
        $this->setSchool($a['school']->id);

        $insert = fn (array $o) => DB::table('fee_assessment_runs')->insert(array_merge([
            'id' => (string) new UuidV7, 'school_id' => $a['school']->id, 'fee_structure_id' => $a['structure']->id,
            'billing_period_key' => 'T1', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
        ], $o));

        $this->assertRejectedBy('needs an active fee structure', fn () => $insert(['fee_structure_id' => $b['structure']->id]), 'Another School\'s structure is not visible, so not active.');
        $this->assertRejectedBy('always created as a draft', fn () => $insert(['status' => 'executing', 'execution_started_at' => now(), 'previewed_configuration_version' => 1]), 'Runs start as drafts.');
        $this->assertRejectedBy('does not exist on fee structure', fn () => $insert(['billing_period_key' => 'T9']), 'The period must exist.');

        $insert([]);
        $this->assertRejectedBy('fee_assessment_runs_one_open_per_period', fn () => $insert([]), 'One open run per structure and period.');
    }

    #[Test]
    public function run_transitions_and_terminal_immutability_are_database_enforced(): void
    {
        $w = $this->executedWorld();
        $this->setSchool($w['school']->id);
        $draft = $this->runs()->create($w['school'], $w['structure']->id, 'T2', $w['actor']);
        $this->setSchool($w['school']->id);

        $this->assertRejectedBy('illegal assessment run transition', fn () => DB::table('fee_assessment_runs')->where('id', $draft->id)
            ->update(['status' => 'executing', 'execution_started_at' => now(), 'previewed_configuration_version' => 1]), 'draft cannot jump to executing');
        $this->assertRejectedBy('is completed and immutable', fn () => DB::table('fee_assessment_runs')->where('id', $w['run']->id)
            ->update(['succeeded_count' => 99]), 'A completed run is immutable.');
        $this->assertRejectedBy('fee_assessment_runs_shape_check', fn () => DB::table('fee_assessment_runs')->where('id', $draft->id)
            ->update(['status' => 'previewed']), 'Previewed needs its preview provenance.');
    }

    #[Test]
    public function items_are_fixed_by_run_phase(): void
    {
        $w = $this->assessmentWorld();
        $this->enroll($w);
        $this->enroll($w);
        Queue::fake();
        $run = $this->previewedRun($w);
        $this->runs()->execute($w['school'], $run->id, $w['actor']);
        $item = $this->items($w, $run)->first();
        $this->setSchool($w['school']->id);

        $this->assertRejectedBy('items are fixed', fn () => DB::table('fee_assessment_run_items')->where('id', $item->id)->delete(), 'Executing items cannot be deleted.');
        $this->assertRejectedBy('provenance is immutable', fn () => DB::table('fee_assessment_run_items')->where('id', $item->id)->update(['amount' => '1.00']), 'The amount is never changed (no proration).');
        $this->assertRejectedBy('preview facts are fixed', fn () => DB::table('fee_assessment_run_items')->where('id', $item->id)
            ->update(['preview_result' => 'excluded', 'reason' => 'staff_excluded', 'excluded_by_user_id' => $w['actor']->id, 'excluded_at' => now()]), 'No exclusion after execution starts.');
        $this->assertRejectedBy('illegal item execution transition', fn () => DB::table('fee_assessment_run_items')->where('id', $item->id)
            ->update(['execution_status' => null]), 'Execution state only moves forward.');
    }

    #[Test]
    public function one_live_assessment_per_student_year_head_and_period(): void
    {
        $w = $this->executedWorld();
        $this->setSchool($w['school']->id);
        $a = $w['assessment'];
        $row = DB::table('fee_assessments')->where('id', $a->id)->first();

        // A second, genuine charge for the same Student and year...
        $second = app(ChargeService::class)->assess($w['school'], new AssessChargeData(
            studentId: $row->student_id, academicYearId: $row->academic_year_id, description: 'Second', amount: Money::of('1.00', 'INR'),
            receivableLedgerAccountId: $w['receivable']->id, revenueLedgerAccountId: $w['revenue']->id,
        ));
        $this->setSchool($w['school']->id);

        // ...still cannot become a second live assessment of the same key.
        $this->assertRejectedBy('fee_assessments_one_live_per_period', fn () => DB::table('fee_assessments')->insert(array_merge((array) $row, [
            'id' => (string) new UuidV7, 'charge_id' => $second->chargeId,
        ])), 'A second live assessment for the same key is refused.');

        $this->assertRejectedBy('is immutable except its one-time void', fn () => DB::table('fee_assessments')->where('id', $a->id)
            ->update(['billing_period_key' => 'T2']), 'Assessment identity is immutable.');
    }

    #[Test]
    public function a_live_assessments_charge_cannot_be_cancelled_and_a_void_needs_the_charge_cancelled(): void
    {
        $w = $this->executedWorld();
        $charge = $this->inSchool($w['school'], fn () => Charge::query()->sole());
        $this->setSchool($w['school']->id);

        $this->assertRejectedBy('is fee-assessed; void its fee assessment', fn () => DB::table('charges')->where('id', $charge->id)
            ->update(['cancelled_at' => now(), 'cancellation_journal_entry_id' => $charge->journal_entry_id]), 'A fee-assessed charge is cancelled only through the void.');

        $this->assertRejectedBy('was voided but its charge', function () use ($w) {
            DB::table('fee_assessments')->where('id', $w['assessment']->id)->update(['voided_at' => now(), 'void_reason' => 'x']);
            DB::statement('SET CONSTRAINTS fee_assessments_void_requires_cancelled_charge IMMEDIATE');
        }, 'Voiding without cancelling the charge is refused at commit.');
    }

    #[Test]
    public function the_runtime_role_cannot_delete_runs_or_assessments(): void
    {
        $w = $this->executedWorld();
        $this->setSchool($w['school']->id);

        $this->assertRejectedBy('permission denied', fn () => DB::table('fee_assessment_runs')->where('id', $w['run']->id)->delete(), 'Runs are never deleted.');
        $this->assertRejectedBy('permission denied', fn () => DB::table('fee_assessments')->where('id', $w['assessment']->id)->delete(), 'Assessments are never deleted.');
    }
}
