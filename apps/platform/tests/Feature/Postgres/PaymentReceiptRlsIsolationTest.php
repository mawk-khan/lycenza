<?php

namespace Tests\Feature\Postgres;

use App\Domain\Payments\Infrastructure\PaymentReceipt;
use App\Domain\Payments\Infrastructure\PaymentReceiptCounter;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Feature\Fees\Concerns\CreatesReceiptFixtures;
use Tests\TestCase;

/**
 * FEE.4 (ADR 0062 §17, §22) at the raw PostgreSQL layer: forced RLS,
 * isolation and fail-closed missing context; receipts immutable for the
 * runtime role (permission) and even the owner role (trigger); insert
 * validation of series, sequence and format; the counter's +1-with-receipt
 * rule; the fee_settings numbering guard -- each proven with SQL that
 * bypasses the Application layer.
 */
class PaymentReceiptRlsIsolationTest extends TestCase
{
    use CreatesReceiptFixtures;

    private const TABLES = ['payment_receipt_counters', 'payment_receipts'];

    private function setSchool(?string $schoolId, string $connection = 'pgsql'): void
    {
        DB::connection($connection)->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId ?? '']);
    }

    private function assertRejectedBy(string $fragment, callable $op, string $message, string $connection = 'pgsql'): void
    {
        try {
            DB::connection($connection)->transaction($op);
            $this->fail($message);
        } catch (QueryException $e) {
            $this->assertStringContainsString($fragment, $e->getMessage(), $message);
        }
    }

    private function issuedWorld(): array
    {
        $w = $this->concessionWorld();
        $w['payment'] = $this->pay($w, '10.00', '2026-04-01')->paymentId;
        $w['receipt'] = $this->receiptOf($w, $w['payment']);

        return $w;
    }

    #[Test]
    public function the_tables_have_rls_enabled_and_forced(): void
    {
        foreach (self::TABLES as $table) {
            $row = DB::connection('pgsql_admin')->selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ? and relnamespace = ?::regnamespace', [$table, 'public']);
            $this->assertTrue($row->relrowsecurity && $row->relforcerowsecurity, "{$table} must have RLS enabled and forced");
        }
    }

    #[Test]
    public function rows_are_isolated_by_school_and_missing_context_fails_closed(): void
    {
        $a = $this->issuedWorld();
        $b = $this->concessionWorld();

        $this->setSchool($a['school']->id);
        foreach (self::TABLES as $table) {
            $this->assertSame(1, DB::table($table)->count(), $table);
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
        $this->assertSame(0, PaymentReceipt::query()->count());
        $this->assertSame(0, PaymentReceiptCounter::query()->count());
    }

    #[Test]
    public function receipts_are_immutable_and_never_deleted(): void
    {
        $w = $this->issuedWorld();
        $id = $w['receipt']->id;
        $this->setSchool($w['school']->id);

        foreach ([['receipt_number' => 'RCPT/2026-27/000009'], ['payment_id' => $w['payment']], ['series_key' => '2025-26'], ['sequence_value' => 9], ['issued_at' => now()], ['issued_by_user_id' => null]] as $change) {
            $this->assertRejectedBy('permission denied', fn () => DB::table('payment_receipts')->where('id', $id)->update($change), 'The runtime role cannot update a receipt: '.json_encode(array_keys($change)));
        }
        $this->assertRejectedBy('permission denied', fn () => DB::table('payment_receipts')->where('id', $id)->delete(), 'The runtime role cannot delete a receipt.');

        // Even the owner role meets the immutability trigger (asserted from the
        // catalog: the test's uncommitted row is invisible to the admin connection).
        $definition = DB::connection('pgsql_admin')->selectOne("select pg_get_triggerdef(oid) as d from pg_trigger where tgname = 'payment_receipts_guard_trigger'")->d;
        $this->assertStringContainsString('BEFORE INSERT OR DELETE OR UPDATE ON public.payment_receipts', $definition);
        $source = DB::connection('pgsql_admin')->selectOne("select prosrc from pg_proc where proname = 'payments_validate_payment_receipt'")->prosrc;
        $this->assertStringContainsString('is immutable', $source);
        $this->assertStringContainsString('is never deleted', $source);
    }

    #[Test]
    public function a_raw_receipt_must_take_the_next_value_in_the_exact_format_and_series(): void
    {
        $w = $this->issuedWorld();
        $second = $this->legacyPayment($w, '10.00', '2026-04-02')->paymentId;
        $other = $this->concessionWorld();
        $foreignPayment = $this->legacyPayment($other, '10.00', '2026-04-02')->paymentId;
        $this->setSchool($w['school']->id);

        $row = fn (array $o) => array_merge([
            'id' => (string) new UuidV7, 'school_id' => $w['school']->id, 'payment_id' => $second,
            'receipt_number' => 'RCPT/2026-27/000002', 'series_key' => '2026-27', 'sequence_value' => 2, 'issued_at' => now(),
        ], $o);

        $this->assertRejectedBy('is not the next value', fn () => DB::table('payment_receipts')->insert($row(['sequence_value' => 3, 'receipt_number' => 'RCPT/2026-27/000003'])), 'No skipped number.');
        $this->assertRejectedBy('does not match its series format', fn () => DB::table('payment_receipts')->insert($row(['receipt_number' => 'RCPT/2026-27/2'])), 'Exact format.');
        $this->assertRejectedBy('does not match its series format', fn () => DB::table('payment_receipts')->insert($row(['receipt_number' => 'OTHER/2026-27/000002'])), 'The series prefix.');
        $this->assertRejectedBy('is not the financial year of its Payment', fn () => DB::table('payment_receipts')->insert($row(['series_key' => '2025-26', 'receipt_number' => 'RCPT/2025-26/000002'])), 'The Payment decides the series.');
        $this->assertRejectedBy('needs a Payment of the same School', fn () => DB::table('payment_receipts')->insert($row(['payment_id' => $foreignPayment])), 'Another School\'s Payment.');
        $this->assertRejectedBy('payment_receipts_payment_unique', fn () => DB::table('payment_receipts')->insert($row(['payment_id' => $w['payment']])), 'One receipt per Payment.');
    }

    #[Test]
    public function the_counter_only_advances_by_one_behind_its_receipt(): void
    {
        $w = $this->issuedWorld();
        $this->setSchool($w['school']->id);
        $counter = fn () => DB::table('payment_receipt_counters')->where('series_key', '2026-27');

        $this->assertRejectedBy('only advances by one', fn () => $counter()->update(['next_value' => 5]), 'No jump.');
        $this->assertRejectedBy('only advances by one', fn () => $counter()->update(['next_value' => 1]), 'Never backwards.');
        $this->assertRejectedBy('cannot advance past 2 without its receipt', fn () => $counter()->update(['next_value' => 3]), 'No gap without a receipt.');
        $this->assertRejectedBy('series identity is immutable', fn () => $counter()->update(['prefix' => 'OTHER']), 'The series prefix is fixed.');
        $this->assertRejectedBy('permission denied', fn () => $counter()->delete(), 'Counters are never deleted.');
        $this->assertRejectedBy('always starts at 1', fn () => DB::table('payment_receipt_counters')->insert([
            'id' => (string) new UuidV7, 'school_id' => $w['school']->id, 'series_key' => '2030-31', 'prefix' => 'RCPT', 'next_value' => 7, 'created_at' => now(), 'updated_at' => now(),
        ]), 'A series starts at 1.');
    }

    #[Test]
    public function the_numbering_settings_are_guarded_in_the_database(): void
    {
        $w = $this->concessionWorld();
        $this->setSchool($w['school']->id);
        $this->assertRejectedBy('fee_settings_receipt_prefix_format_check', fn () => DB::table('fee_settings')->where('school_id', $w['school']->id)->update(['receipt_prefix' => 'A/B']), 'Prefix format.');

        $this->pay($w, '10.00', $this->today($w));
        $this->setSchool($w['school']->id);

        $this->assertRejectedBy('the receipt prefix cannot change', fn () => DB::table('fee_settings')->where('school_id', $w['school']->id)->update(['receipt_prefix' => 'OTHER']), 'Current series has receipts.');
        $this->assertRejectedBy('start month cannot change', fn () => DB::table('fee_settings')->where('school_id', $w['school']->id)->update(['financial_year_start_month' => 7]), 'Receipts exist.');
    }
}
