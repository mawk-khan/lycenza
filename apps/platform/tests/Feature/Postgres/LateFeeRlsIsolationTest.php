<?php

namespace Tests\Feature\Postgres;

use App\Domain\Fees\Application\AssessChargeData;
use App\Domain\Fees\Application\ChargeService;
use App\Domain\Fees\Infrastructure\FeeLateFeeRule;
use App\Domain\Finance\Application\LedgerService;
use App\Domain\Payments\Infrastructure\LateFeeAssessment;
use App\Domain\Payments\Infrastructure\LateFeeRun;
use App\Domain\Payments\Infrastructure\LateFeeRunItem;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Feature\Fees\Concerns\CreatesLateFeeFixtures;
use Tests\TestCase;

/**
 * FEE.5 (ADR 0062 §16, §22) at the raw PostgreSQL layer: forced RLS on the
 * four tables, isolation and fail-closed missing context, the rule CHECKs
 * and edit lock, the run lifecycle and future-date refusal, the one-live
 * key, "no late fee on a late fee", the charge-cancel guards, item phase
 * immutability, and no DELETE -- each proven with SQL that bypasses the
 * Application layer.
 */
class LateFeeRlsIsolationTest extends TestCase
{
    use CreatesLateFeeFixtures;

    private const TABLES = ['fee_late_fee_rules', 'late_fee_runs', 'late_fee_run_items', 'late_fee_assessments'];

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

    private function assessedWorld(): array
    {
        $w = $this->lateFeeWorld();
        $w['rule'] = $this->activeRule($w);
        $w['run'] = $this->executedLateRun($w, $w['rule']);
        $w['link'] = $this->lateFees($w)->sole();

        return $w;
    }

    #[Test]
    public function the_tables_have_rls_enabled_and_forced_and_are_isolated(): void
    {
        foreach (self::TABLES as $table) {
            $row = DB::connection('pgsql_admin')->selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ? and relnamespace = ?::regnamespace', [$table, 'public']);
            $this->assertTrue($row->relrowsecurity && $row->relforcerowsecurity, "{$table} must have RLS enabled and forced");
        }

        $a = $this->assessedWorld();
        $b = $this->lateFeeWorld();
        $this->setSchool($a['school']->id);
        foreach (self::TABLES as $table) {
            $this->assertGreaterThan(0, DB::table($table)->count(), $table);
        }
        $this->setSchool($b['school']->id);
        foreach (self::TABLES as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} of School A is invisible to School B");
        }
        $this->setSchool(null);
        foreach (self::TABLES as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} is empty without tenant context");
        }

        app(TenantContext::class)->clearAll();
        foreach ([FeeLateFeeRule::class, LateFeeRun::class, LateFeeRunItem::class, LateFeeAssessment::class] as $model) {
            $this->assertSame(0, $model::query()->count(), $model);
        }
    }

    #[Test]
    public function rule_checks_and_the_active_edit_lock_are_database_enforced(): void
    {
        $w = $this->lateFeeWorld();
        $rule = $this->activeRule($w);
        $this->setSchool($w['school']->id);
        $row = fn (array $o) => array_merge([
            'id' => (string) new UuidV7, 'school_id' => $w['school']->id, 'name' => 'Raw', 'fee_structure_id' => $w['structure']->id,
            'late_fee_head_id' => $w['lateHead']->id, 'grace_days' => 0, 'kind' => 'fixed', 'fixed_amount' => '10.00', 'currency' => 'INR',
            'status' => 'inactive', 'created_at' => now(), 'updated_at' => now(),
        ], $o);

        $this->assertRejectedBy('fee_late_fee_rules_kind_check', fn () => DB::table('fee_late_fee_rules')->insert($row(['kind' => 'daily'])), 'Closed kinds.');
        $this->assertRejectedBy('fee_late_fee_rules_value_shape_check', fn () => DB::table('fee_late_fee_rules')->insert($row(['percentage' => '5.00'])), 'Fixed never carries a percentage.');
        $this->assertRejectedBy('fee_late_fee_rules_value_shape_check', fn () => DB::table('fee_late_fee_rules')->insert($row(['fixed_amount' => '0'])), 'Positive amount.');
        $this->assertRejectedBy('fee_late_fee_rules_grace_days_check', fn () => DB::table('fee_late_fee_rules')->insert($row(['grace_days' => -1])), 'Non-negative grace.');
        $this->assertRejectedBy('fee_late_fee_rules_max_amount_check', fn () => DB::table('fee_late_fee_rules')->insert($row(['max_amount' => '0'])), 'Positive cap.');
        $this->assertRejectedBy('fee_late_fee_rules_currency_inr_only_check', fn () => DB::table('fee_late_fee_rules')->insert($row(['currency' => 'USD'])), 'INR only.');
        $this->assertRejectedBy('always created inactive', fn () => DB::table('fee_late_fee_rules')->insert($row(['status' => 'active'])), 'Created inactive.');
        $this->assertRejectedBy('is not a line of fee structure', fn () => DB::table('fee_late_fee_rules')->insert($row(['fee_head_id' => $w['lateHead']->id])), 'Scope head must be a structure line.');
        $this->assertRejectedBy('deactivate it before editing', fn () => DB::table('fee_late_fee_rules')->where('id', $rule->id)->update(['fixed_amount' => '999.00']), 'Active rules are not editable.');
        $this->assertRejectedBy('permission denied', fn () => DB::table('fee_late_fee_rules')->where('id', $rule->id)->delete(), 'Never deleted.');
    }

    #[Test]
    public function the_run_lifecycle_refuses_future_dates_and_terminal_changes(): void
    {
        $w = $this->assessedWorld();
        $this->setSchool($w['school']->id);

        $this->assertRejectedBy('cannot evaluate a future date', fn () => DB::table('late_fee_runs')->insert([
            'id' => (string) new UuidV7, 'school_id' => $w['school']->id, 'fee_late_fee_rule_id' => $w['rule']->id,
            'evaluation_date' => now($w['school']->timezone)->addDays(2)->toDateString(), 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
        ]), 'No future evaluation date.');
        $this->assertRejectedBy('is completed and immutable', fn () => DB::table('late_fee_runs')->where('id', $w['run']->id)->update(['succeeded_count' => 9]), 'Terminal runs are immutable.');
        $this->assertRejectedBy('late-fee run', fn () => DB::table('late_fee_run_items')->where('late_fee_run_id', $w['run']->id)->update(['final_amount' => '1.00']), 'Items of a terminal run are immutable.');
        $this->assertRejectedBy('permission denied', fn () => DB::table('late_fee_runs')->where('id', $w['run']->id)->delete(), 'Runs are never deleted.');
    }

    #[Test]
    public function one_live_late_fee_per_source_and_rule_and_never_on_a_late_fee(): void
    {
        $w = $this->assessedWorld();
        $open = $this->lateRuns()->create($w['school'], $w['rule']->id, '2026-06-12', $w['actor']);
        $this->inSchool($w['school'], fn () => DB::table('late_fee_runs')->where('id', $open->id)->update(['status' => 'previewed', 'previewed_at' => now(), 'previewed_rule_version' => 2]));
        $this->inSchool($w['school'], fn () => DB::table('late_fee_runs')->where('id', $open->id)->update(['status' => 'executing', 'execution_started_at' => now()]));
        $newFee = fn ($student, $year, $due = '2026-06-12') => app(ChargeService::class)->assess($w['school'], new AssessChargeData(
            studentId: $student, academicYearId: $year, description: 'raw', amount: Money::of('5.00', 'INR'),
            receivableLedgerAccountId: $w['lateHead']->receivable_ledger_account_id, revenueLedgerAccountId: $w['lateIncome']->id, dueDate: $due,
        ))->chargeId;
        $second = $newFee($w['source']->student_id, $w['source']->academic_year_id);
        $this->setSchool($w['school']->id);

        $row = fn (array $o) => array_merge([
            'id' => (string) new UuidV7, 'school_id' => $w['school']->id, 'source_charge_id' => $w['source']->id,
            'fee_late_fee_rule_id' => $w['rule']->id, 'charge_id' => $second, 'late_fee_run_id' => $open->id,
            'created_at' => now(), 'updated_at' => now(),
        ], $o);

        $this->assertRejectedBy('late_fee_assessments_one_live_per_rule', fn () => DB::table('late_fee_assessments')->insert($row([])), 'One live late fee per source charge and rule.');
        $this->assertRejectedBy('never attracts a late fee', fn () => DB::table('late_fee_assessments')->insert($row(['source_charge_id' => $w['link']->charge_id])), 'A late fee is never a source.');
        $this->assertRejectedBy('is immutable except its one-time void', fn () => DB::table('late_fee_assessments')->where('id', $w['link']->id)->update(['charge_id' => $second]), 'Links are immutable.');
        $this->assertRejectedBy('was voided but its charge', function () use ($w) {
            DB::table('late_fee_assessments')->where('id', $w['link']->id)->update(['voided_at' => now()]);
            DB::statement('SET CONSTRAINTS late_fee_assessments_void_requires_cancelled_charge IMMEDIATE');
        }, 'A void must cancel the late-fee charge in the same transaction.');
        // A genuine reversal, so only the late-fee guard can refuse the cancel.
        $fee = $this->chargeOf($w, $w['link']->charge_id);
        $reversal = $this->inSchool($w['school'], fn () => DB::transaction(fn () => app(LedgerService::class)->reverseById($w['school'], $fee->journal_entry_id)))->journalEntryId;
        $this->setSchool($w['school']->id);
        $this->assertRejectedBy('is a late fee; void its late-fee assessment', fn () => DB::table('charges')->where('id', $fee->id)->update(['cancelled_at' => now(), 'cancellation_journal_entry_id' => $reversal]), 'A live late fee is cancelled only by its void.');
        $this->assertRejectedBy('permission denied', fn () => DB::table('late_fee_assessments')->where('id', $w['link']->id)->delete(), 'Links are never deleted.');
    }
}
