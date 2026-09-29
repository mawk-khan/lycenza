<?php

namespace Tests\Feature\Postgres;

use App\Domain\Fees\Infrastructure\FeeHead;
use App\Domain\Fees\Infrastructure\FeeOptionalSelection;
use App\Domain\Fees\Infrastructure\FeeStructure;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Feature\Fees\Concerns\CreatesFeeSetupFixtures;
use Tests\TestCase;

/**
 * FEE.1 (ADR 0062 §22): the fee setup tables at the raw PostgreSQL layer --
 * forced RLS, cross-School isolation, same-School composite foreign keys,
 * and every lifecycle/immutability/validation trigger, each proven with
 * direct SQL that bypasses the Application layer entirely (rule 28).
 */
class FeeSetupRlsIsolationTest extends TestCase
{
    use CreatesFeeSetupFixtures;

    private const TABLES = [
        'fee_heads', 'fee_settings', 'fee_structures', 'fee_structure_lines',
        'fee_structure_installments', 'fee_optional_selections',
    ];

    private function setSchool(?string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId ?? '']);
    }

    private function id(): string
    {
        return (string) new UuidV7;
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

    private function insertHead(array $w, array $overrides = []): string
    {
        $id = $this->id();
        DB::table('fee_heads')->insert(array_merge([
            'id' => $id, 'school_id' => $w['school']->id, 'code' => 'H'.strtoupper(substr($id, -6)), 'name' => 'Head',
            'status' => 'active', 'receivable_ledger_account_id' => $w['receivable']->id,
            'revenue_ledger_account_id' => $w['revenue']->id, 'currency' => 'INR',
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));

        return $id;
    }

    private function insertStructure(array $w, array $overrides = []): string
    {
        $id = $this->id();
        DB::table('fee_structures')->insert(array_merge([
            'id' => $id, 'school_id' => $w['school']->id, 'academic_year_id' => $w['year']->id,
            'grade_level_id' => $w['grade']->id, 'campus_id' => null, 'code' => 'S'.strtoupper(substr($id, -6)),
            'name' => 'Structure', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
        ], $overrides));

        return $id;
    }

    private function insertLine(array $w, string $structureId, string $headId, string $amount = '100.00', bool $optional = false): string
    {
        $id = $this->id();
        DB::table('fee_structure_lines')->insert([
            'id' => $id, 'school_id' => $w['school']->id, 'fee_structure_id' => $structureId, 'fee_head_id' => $headId,
            'is_optional' => $optional, 'frequency' => 'custom', 'amount' => $amount, 'currency' => 'INR',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function insertInstallment(array $w, string $lineId, array $overrides = []): string
    {
        $id = $this->id();
        DB::table('fee_structure_installments')->insert(array_merge([
            'id' => $id, 'school_id' => $w['school']->id, 'fee_structure_line_id' => $lineId, 'sequence' => 1,
            'label' => 'Annual', 'billing_period_key' => 'ANNUAL', 'period_starts_on' => '2026-06-01',
            'period_ends_on' => '2027-05-31', 'due_date' => '2026-06-01', 'amount' => '100.00', 'currency' => 'INR',
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));

        return $id;
    }

    // --- RLS ------------------------------------------------------------------

    #[Test]
    public function every_fee_setup_table_has_rls_enabled_and_forced(): void
    {
        foreach (self::TABLES as $table) {
            $row = DB::connection('pgsql_admin')->selectOne(
                'select relrowsecurity, relforcerowsecurity from pg_class where relname = ? and relnamespace = ?::regnamespace',
                [$table, 'public'],
            );

            $this->assertNotNull($row, $table);
            $this->assertTrue($row->relrowsecurity, "{$table} must have RLS ENABLED");
            $this->assertTrue($row->relforcerowsecurity, "{$table} must have RLS FORCED");
        }
    }

    #[Test]
    public function tenant_isolation_holds_at_the_raw_sql_layer_and_missing_context_fails_closed(): void
    {
        $a = $this->feeWorld();
        $b = $this->feeWorld();

        $this->setSchool($a['school']->id);
        $headId = $this->insertHead($a);
        $structureId = $this->insertStructure($a);
        $this->insertLine($a, $structureId, $headId);

        foreach (['fee_heads', 'fee_structures', 'fee_structure_lines'] as $table) {
            $this->assertSame(1, DB::table($table)->count(), $table);
        }

        $this->setSchool($b['school']->id);
        foreach (['fee_heads', 'fee_structures', 'fee_structure_lines'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} rows of School A must be invisible to School B");
        }
        $this->assertSame(0, DB::table('fee_heads')->where('id', $headId)->update(['name' => 'hijacked']));

        $this->setSchool(null);
        foreach (self::TABLES as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} must be empty without tenant context");
        }

        app(TenantContext::class)->clearAll();
        $this->assertSame(0, FeeHead::query()->count(), 'Eloquent without tenant context returns nothing.');
        $this->assertSame(0, FeeStructure::query()->count());
        $this->assertSame(0, FeeOptionalSelection::query()->count());
    }

    #[Test]
    public function a_raw_insert_for_another_school_is_rejected_by_the_policy(): void
    {
        $a = $this->feeWorld();
        $b = $this->feeWorld();
        $this->setSchool($b['school']->id);

        // fee_settings has no BEFORE trigger, so the RLS WITH CHECK is the
        // first thing that sees the row; fee_structures' trigger only
        // validates supersession, so the policy is reached there too.
        $this->assertRejectedBy('row-level security', fn () => DB::table('fee_settings')->insert([
            'id' => $this->id(), 'school_id' => $a['school']->id, 'currency' => 'INR', 'created_at' => now(), 'updated_at' => now(),
        ]), 'RLS must refuse a settings row for another School.');
        $this->assertRejectedBy('row-level security', fn () => $this->insertStructure($a), 'RLS must refuse a structure for another School.');
    }

    #[Test]
    public function eloquent_reads_are_scoped_to_the_active_school(): void
    {
        $a = $this->feeWorld();
        $b = $this->feeWorld();
        $this->makeFeeHead($a, ['code' => 'A-ONLY']);
        $this->makeDraftStructure($a);

        $this->assertSame(0, $this->inSchool($b['school'], fn () => FeeHead::query()->count()));
        $this->assertSame(0, $this->inSchool($b['school'], fn () => FeeStructure::query()->count()));
        $this->assertSame(1, $this->inSchool($a['school'], fn () => FeeHead::query()->count()));
    }

    // --- composite same-School foreign keys --------------------------------------

    #[Test]
    public function cross_school_references_are_structurally_impossible(): void
    {
        $a = $this->feeWorld();
        $b = $this->feeWorld();
        $this->setSchool($a['school']->id);

        // The account-type trigger runs before the composite FK and already
        // refuses an account it cannot see in this School.
        $this->assertRejectedBy('of the same School', fn () => $this->insertHead($a, ['receivable_ledger_account_id' => $b['receivable']->id]), 'A fee head cannot map to another School\'s account.');
        $this->assertRejectedBy('fee_structures_academic_year_fk', fn () => $this->insertStructure($a, ['academic_year_id' => $b['year']->id]), 'A structure cannot use another School\'s year.');
        $this->assertRejectedBy('fee_structures_grade_level_fk', fn () => $this->insertStructure($a, ['grade_level_id' => $b['grade']->id]), 'A structure cannot use another School\'s grade.');
        $this->assertRejectedBy('fee_structures_campus_fk', fn () => $this->insertStructure($a, ['campus_id' => $b['campus']->id]), 'A structure cannot use another School\'s campus.');

        $this->setSchool($b['school']->id);
        $foreignHead = $this->insertHead($b);
        $this->setSchool($a['school']->id);
        $structure = $this->insertStructure($a);
        $this->assertRejectedBy('fee_structure_lines_fee_head_fk', fn () => $this->insertLine($a, $structure, $foreignHead), 'A line cannot use another School\'s fee head.');
    }

    // --- triggers ------------------------------------------------------------------

    #[Test]
    public function fee_head_account_types_and_code_shape_are_database_enforced(): void
    {
        $w = $this->feeWorld();
        $expense = $this->createLedgerAccount($w['school'], ['type' => 'expense']);
        $this->setSchool($w['school']->id);

        $this->assertRejectedBy('must be an asset account', fn () => $this->insertHead($w, ['receivable_ledger_account_id' => $expense->id]), 'Receivable must be an asset.');
        $this->assertRejectedBy('must be an income account', fn () => $this->insertHead($w, ['revenue_ledger_account_id' => $expense->id]), 'Revenue must be income.');
        // Same account on both sides: the asset account is not an income
        // account, so the type trigger refuses it before the CHECK.
        $this->assertRejectedBy('must be an income account', fn () => $this->insertHead($w, ['revenue_ledger_account_id' => $w['receivable']->id]), 'Accounts must differ.');
        $this->assertNotNull(DB::connection('pgsql_admin')->selectOne(
            "select 1 from pg_constraint where conname = 'fee_heads_distinct_accounts_check'",
        ), 'The distinct-accounts CHECK exists as a backstop.');
        $this->assertRejectedBy('fee_heads_code_format_check', fn () => $this->insertHead($w, ['code' => 'lower']), 'Codes are stored normalized.');

        $this->insertHead($w, ['code' => 'DUP']);
        $this->assertRejectedBy('fee_heads_school_id_code_ci_unique', fn () => DB::statement(
            "insert into fee_heads (id, school_id, code, name, status, receivable_ledger_account_id, revenue_ledger_account_id, currency, created_at, updated_at) values (?, ?, 'DUP', 'x', 'active', ?, ?, 'INR', now(), now())",
            [$this->id(), $w['school']->id, $w['receivable']->id, $w['revenue']->id],
        ), 'Duplicate codes are refused by the database.');

        $this->assertRejectedBy('mapped by a fee head', fn () => DB::table('ledger_accounts')->where('id', $w['revenue']->id)->update(['type' => 'expense']), 'A mapped account\'s type cannot change.');
    }

    #[Test]
    public function the_structure_lifecycle_and_draft_only_children_are_database_enforced(): void
    {
        $w = $this->feeWorld();
        $this->setSchool($w['school']->id);
        $head = $this->insertHead($w);

        $this->assertRejectedBy('always created as a draft', fn () => $this->insertStructure($w, ['status' => 'active', 'activated_at' => now()]), 'Structures start as drafts.');

        $structure = $this->insertStructure($w);
        $this->assertRejectedBy('has no lines', fn () => DB::table('fee_structures')->where('id', $structure)->update(['status' => 'active', 'activated_at' => now()]), 'An empty structure cannot activate.');

        $line = $this->insertLine($w, $structure, $head, '100.00');
        $this->insertInstallment($w, $line, ['amount' => '60.00']);
        $this->assertRejectedBy('do not sum to the line amount', fn () => DB::table('fee_structures')->where('id', $structure)->update(['status' => 'active', 'activated_at' => now()]), 'Instalments must sum to the line amount.');

        $this->insertInstallment($w, $line, ['sequence' => 2, 'billing_period_key' => 'REST', 'amount' => '40.00']);
        $this->assertSame(1, DB::table('fee_structures')->where('id', $structure)->update(['status' => 'active', 'activated_at' => now()]));

        $this->assertRejectedBy('is active and its lines/instalments are immutable', fn () => $this->insertLine($w, $structure, $this->insertHead($w)), 'No line may be added to an active structure.');
        $this->assertRejectedBy('is active and its lines/instalments are immutable', fn () => DB::table('fee_structure_installments')->where('fee_structure_line_id', $line)->update(['amount' => '1.00']), 'No instalment may change on an active structure.');
        $this->assertRejectedBy('is active and its lines/instalments are immutable', fn () => DB::table('fee_structure_lines')->where('id', $line)->delete(), 'No line may be deleted from an active structure.');
        $this->assertRejectedBy('is active and immutable', fn () => DB::table('fee_structures')->where('id', $structure)->update(['name' => 'Renamed']), 'An active structure is immutable.');
        $this->assertRejectedBy('illegal fee structure transition', fn () => DB::table('fee_structures')->where('id', $structure)->update(['status' => 'draft', 'activated_at' => null, 'activated_by_user_id' => null]), 'Active never returns to draft.');

        $this->assertSame(1, DB::table('fee_structures')->where('id', $structure)->update(['status' => 'retired', 'retired_at' => now()]));
        $this->assertRejectedBy('illegal fee structure transition', fn () => DB::table('fee_structures')->where('id', $structure)->update(['status' => 'active']), 'Retired is final.');
    }

    #[Test]
    public function one_active_structure_per_scope_is_a_database_invariant(): void
    {
        $w = $this->feeWorld();
        $this->setSchool($w['school']->id);
        $head = $this->insertHead($w);

        $activate = function (?string $campus) use ($w, $head): string {
            $s = $this->insertStructure($w, ['campus_id' => $campus]);
            $l = $this->insertLine($w, $s, $head);
            $this->insertInstallment($w, $l);
            DB::table('fee_structures')->where('id', $s)->update(['status' => 'active', 'activated_at' => now()]);

            return $s;
        };

        $activate(null);
        $activate($w['campus']->id);
        $this->assertRejectedBy('fee_structures_one_active_per_scope', fn () => $activate(null), 'A second active School default must be refused.');
        $this->assertRejectedBy('fee_structures_one_active_per_scope', fn () => $activate($w['campus']->id), 'A second active campus override must be refused.');
    }

    #[Test]
    public function instalment_periods_and_terms_must_belong_to_the_structure_year(): void
    {
        $w = $this->feeWorld();
        $otherYear = $this->createAcademicYear($w['school'], ['code' => 'AY2030', 'starts_on' => '2030-06-01', 'ends_on' => '2031-05-31']);
        $foreignTerm = $this->createAcademicTerm($otherYear, ['starts_on' => '2030-06-01', 'ends_on' => '2030-10-31']);
        $this->setSchool($w['school']->id);
        $line = $this->insertLine($w, $this->insertStructure($w), $this->insertHead($w));

        $this->assertRejectedBy('must lie inside the academic year', fn () => $this->insertInstallment($w, $line, ['period_starts_on' => '2026-05-01']), 'Periods lie inside the year.');
        $this->assertRejectedBy('does not belong to the structure academic year', fn () => $this->insertInstallment($w, $line, ['academic_term_id' => $foreignTerm->id]), 'Terms belong to the same year.');
        $this->assertRejectedBy('fee_structure_installments_due_date_check', fn () => $this->insertInstallment($w, $line, ['due_date' => '2026-05-31']), 'Due date is not before the period.');
        $this->assertRejectedBy('fee_structure_installments_period_key_format_check', fn () => $this->insertInstallment($w, $line, ['billing_period_key' => 'q1']), 'Period keys are normalized.');
    }

    #[Test]
    public function a_selection_needs_an_optional_line_of_the_same_year_and_head_and_is_final_once_withdrawn(): void
    {
        $w = $this->feeWorld();
        $student = $this->createStudent($w['school']);
        $this->setSchool($w['school']->id);
        $head = $this->insertHead($w);
        $structure = $this->insertStructure($w);
        $required = $this->insertLine($w, $structure, $head);
        $optionalHead = $this->insertHead($w);
        $optional = $this->insertLine($w, $structure, $optionalHead, '50.00', true);

        $insert = fn (string $line, string $headId) => DB::table('fee_optional_selections')->insert([
            'id' => $id = $this->id(), 'school_id' => $w['school']->id, 'student_id' => $student->id,
            'academic_year_id' => $w['year']->id, 'fee_head_id' => $headId, 'fee_structure_line_id' => $line,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]) ? $id : '';

        $this->assertRejectedBy('is not an optional line', fn () => $insert($required, $head), 'A required line cannot be selected.');
        $this->assertRejectedBy('is not an optional line', fn () => $insert($optional, $head), 'The fee head must match the line.');

        $selection = $insert($optional, $optionalHead);
        $this->assertRejectedBy('fee_optional_selections_one_active', fn () => $insert($optional, $optionalHead), 'One active selection per Student, year and head.');

        $this->assertSame(1, DB::table('fee_optional_selections')->where('id', $selection)->update(['status' => 'withdrawn', 'withdrawn_at' => now()]));
        $this->assertRejectedBy('withdrawn and final', fn () => DB::table('fee_optional_selections')->where('id', $selection)->update(['status' => 'active', 'withdrawn_at' => null]), 'A withdrawal is final.');
        $this->assertRejectedBy('identity is immutable', fn () => DB::table('fee_optional_selections')->where('id', $selection)->update(['fee_head_id' => $head]), 'Selection identity is immutable.');
    }

    #[Test]
    public function the_runtime_role_cannot_delete_permanent_fee_setup_records(): void
    {
        $w = $this->feeWorld();
        $this->setSchool($w['school']->id);
        $head = $this->insertHead($w);
        $structure = $this->insertStructure($w);

        foreach (['fee_heads' => $head, 'fee_structures' => $structure] as $table => $id) {
            $this->assertRejectedBy('permission denied', fn () => DB::table($table)->where('id', $id)->delete(), "DELETE on {$table} must be revoked.");
        }
        $this->assertRejectedBy('permission denied', fn () => DB::table('fee_optional_selections')->delete(), 'DELETE on fee_optional_selections must be revoked.');
        $this->assertRejectedBy('permission denied', fn () => DB::table('fee_settings')->delete(), 'DELETE on fee_settings must be revoked.');
    }
}
