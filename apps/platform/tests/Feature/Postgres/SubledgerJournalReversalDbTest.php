<?php

namespace Tests\Feature\Postgres;

use App\Domain\Finance\Infrastructure\FinancialPeriod;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Feature\Finance\Concerns\CreatesSubledgerJournalFixtures;
use Tests\TestCase;

/**
 * ORG.8-P1 (ADR 0076 §57): the database refuses, for raw SQL as the runtime
 * role, every reversal of a journal entry another domain owns unless that
 * domain links it. A never-reversed entry (Payment, Payment correction, a
 * cancellation) is refused at INSERT; an owner-reversed original (Charge,
 * fee adjustment) is refused when the deferred owner-link check runs, as it
 * would at COMMIT. A ledger-native entry stays reversible. Also pins the
 * installed objects and that every foreign key into journal_entries is
 * classified. Payroll kinds: PayrollJournalReversalGuardTest and the races.
 */
class SubledgerJournalReversalDbTest extends TestCase
{
    use CreatesSubledgerJournalFixtures;

    /** Every foreign key into journal_entries: owner columns (with the kind the database names) or ledger structure. */
    private const OWNER_COLUMNS = [
        'charges.cancellation_journal_entry_id' => 'charge_cancellation',
        'charges.journal_entry_id' => 'charge',
        'fee_adjustments.cancellation_journal_entry_id' => 'fee_adjustment_cancellation',
        'fee_adjustments.journal_entry_id' => 'fee_adjustment',
        'payment_corrections.journal_entry_id' => 'payment_correction',
        'payments.journal_entry_id' => 'payment',
        'payroll_run_postings.journal_entry_id' => 'payroll_posting (+ _reversal)',
        'payroll_statutory_run_postings.journal_entry_id' => 'payroll_statutory_posting (+ _reversal)',
    ];

    private const STRUCTURE_COLUMNS = ['journal_entries.reversal_of_journal_entry_id', 'journal_lines.journal_entry_id'];

    private const NEVER_REVERSED = ['payment', 'payment_correction', 'charge_cancellation', 'fee_adjustment_cancellation'];

    /** A raw runtime-role reversal row of $entryId, in a savepoint: null when it stands, else the refusal message. */
    private function rawReversal(string $schoolId, string $entryId, bool $checkNow): ?string
    {
        $db = DB::connection('pgsql');
        $db->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
        try {
            $db->transaction(function () use ($db, $schoolId, $entryId, $checkNow): void {
                $db->table('journal_entries')->insert(['id' => (string) Str::uuid7(), 'school_id' => $schoolId, 'currency' => 'INR',
                    'description' => 'raw reversal', 'reversal_of_journal_entry_id' => $entryId, 'posted_at' => now()]);
                if ($checkNow) {
                    $db->statement('SET CONSTRAINTS journal_entries_subledger_reversal_owner_check IMMEDIATE');
                }
                throw new RuntimeException('stood');
            });
        } catch (QueryException $e) {
            return $e->getMessage();
        } catch (RuntimeException $e) {
            if ($e->getMessage() !== 'stood') {
                throw $e;
            }
        } finally {
            $db->statement('SET CONSTRAINTS journal_entries_subledger_reversal_owner_check DEFERRED');
        }

        return null;
    }

    #[Test]
    public function raw_runtime_sql_cannot_reverse_any_owned_entry(): void
    {
        $w = $this->ownedJournalWorld();
        $this->assertSame('school_os_app', DB::selectOne('select current_user as u')->u, 'proven on the real runtime role');

        foreach ($w['entries'] as $owner => $entryId) {
            if (in_array($owner, self::NEVER_REVERSED, true)) {
                $guard = $owner === 'payment' ? 'journal_entries_payment_reversal_guard' : 'journal_entries_subledger_reversal_guard';
                $this->assertStringContainsString($guard, (string) $this->rawReversal($w['school']->id, $entryId, checkNow: false), "{$owner}: refused at INSERT");
            } else {
                $this->assertNull($this->rawReversal($w['school']->id, $entryId, checkNow: false), "{$owner}: the INSERT alone waits for COMMIT");
                $this->assertStringContainsString('journal_entries_subledger_reversal_guard', (string) $this->rawReversal($w['school']->id, $entryId, checkNow: true), "{$owner}: refused at COMMIT without its owner's link");
            }
        }
    }

    #[Test]
    public function a_ledger_native_entry_stays_reversible_by_raw_sql(): void
    {
        $w = $this->ownedJournalWorld();
        $manual = $this->postBalancedJournalEntry($w['school'], $w['settlement'], $w['revenue'], '5.00');

        $this->assertNull($this->rawReversal($w['school']->id, $manual->id, checkNow: true));
    }

    #[Test]
    public function the_installed_objects_are_invoker_scoped_and_enabled(): void
    {
        foreach (['journal_entry_subledger_owner', 'journal_entries_refuse_terminal_subledger_reversal', 'journal_entries_require_owner_linked_reversal'] as $function) {
            $fn = DB::selectOne("select p.prosecdef, coalesce(array_to_string(p.proconfig, ','), '') as config, pg_get_userbyid(p.proowner) as owner,
                has_function_privilege('public', p.oid, 'EXECUTE') as public_exec from pg_proc p where p.proname = ?", [$function]);
            $this->assertFalse($fn->prosecdef, "{$function} runs as the caller");
            $this->assertStringContainsString('search_path=pg_catalog, public, pg_temp', $fn->config);
            $this->assertFalse($fn->public_exec, "{$function} is not executable by PUBLIC");
            $this->assertNotSame('school_os_app', $fn->owner, 'the runtime role cannot replace it');
        }

        $triggers = collect(DB::select("select tgname, tgenabled, tgdeferrable, tginitdeferred, pg_get_triggerdef(oid) as def from pg_trigger
            where tgrelid = 'journal_entries'::regclass and tgname in ('journal_entries_subledger_reversal_guard', 'journal_entries_subledger_reversal_owner_check', 'journal_entries_payment_reversal_guard')"))->keyBy('tgname');
        $this->assertCount(3, $triggers, 'the Payment guard is kept alongside the two new ones');
        $this->assertSame(['O'], $triggers->pluck('tgenabled')->unique()->values()->all());
        $this->assertStringContainsString('BEFORE INSERT ON public.journal_entries FOR EACH ROW WHEN ((new.reversal_of_journal_entry_id IS NOT NULL))', $triggers['journal_entries_subledger_reversal_guard']->def);
        $this->assertTrue($triggers['journal_entries_subledger_reversal_owner_check']->tgdeferrable && $triggers['journal_entries_subledger_reversal_owner_check']->tginitdeferred, 'checked at COMMIT');

        $grants = DB::selectOne("select has_table_privilege('school_os_app', 'journal_entries', 'UPDATE') or has_table_privilege('school_os_app', 'journal_entries', 'DELETE')
            or has_table_privilege('school_os_app', 'journal_lines', 'UPDATE') or has_table_privilege('school_os_app', 'journal_lines', 'DELETE') as destructive");
        $this->assertFalse($grants->destructive, 'the ledger stays append-only for the runtime role');
    }

    #[Test]
    public function every_foreign_key_into_journal_entries_is_classified_and_named_by_the_one_predicate(): void
    {
        $referencing = collect(DB::select("select c.conrelid::regclass::text as tbl, a.attname as col from pg_constraint c
            join pg_attribute a on a.attrelid = c.conrelid and a.attnum = c.conkey[1]
            where c.contype = 'f' and c.confrelid = 'public.journal_entries'::regclass"))
            ->map(fn ($r) => "{$r->tbl}.{$r->col}")->unique()->sort()->values()->all();

        $classified = [...array_keys(self::OWNER_COLUMNS), ...self::STRUCTURE_COLUMNS];
        sort($classified);
        $this->assertSame($classified, $referencing, 'a new table referencing a journal entry must be classified as an owner (and named by journal_entry_subledger_owner) or ledger structure');

        $predicate = (string) DB::selectOne("select pg_get_functiondef('public.journal_entry_subledger_owner(uuid, uuid)'::regprocedure) as d")->d;
        foreach (array_keys(self::OWNER_COLUMNS) as $column) {
            [$table, $col] = explode('.', $column);
            $this->assertStringContainsString("public.{$table} o WHERE o.school_id = p_school AND o.{$col} = p_entry", $predicate, "{$column} is classified");
        }

        // Never-reversed kinds are decided first, so a second (raw) owner row can never turn one into a reversible original.
        $lastTerminal = max(array_map(fn (string $kind) => strpos($predicate, "RETURN '{$kind}'"), [...self::NEVER_REVERSED, 'payroll_posting_reversal', 'payroll_statutory_posting_reversal']));
        $firstOriginal = min(array_map(fn (string $kind) => strpos($predicate, "RETURN '{$kind}'"), ['charge', 'fee_adjustment', 'payroll_posting', 'payroll_statutory_posting']));
        $this->assertLessThan($firstOriginal, $lastTerminal);
    }

    #[Test]
    public function another_schools_entry_classifies_as_nothing_and_cannot_be_reversed_from_here(): void
    {
        $w = $this->ownedJournalWorld();
        $other = $this->createSchool();
        $this->inSchool($other, fn () => FinancialPeriod::ensureContaining($other->id, 'UTC', now()));

        $this->assertNull($this->inSchool($other, fn () => DB::selectOne('select public.journal_entry_subledger_owner(?, ?) as owner', [$w['entries']['charge'], $w['school']->id])->owner),
            'RLS hides another School\'s owners: the predicate is no cross-tenant oracle');
        $this->assertStringContainsString('foreign key', (string) $this->rawReversal($other->id, $w['entries']['charge'], checkNow: false),
            'a reversal under another School cannot even name the entry (same-School composite key)');
    }
}
