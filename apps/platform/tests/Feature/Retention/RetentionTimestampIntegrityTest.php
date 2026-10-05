<?php

namespace Tests\Feature\Retention;

use App\Models\School;
use App\Support\Operations\CheckResult;
use App\Support\Operations\DatabaseRoleVerifier;
use App\Support\Retention\RetentionExpiry;
use App\Support\Retention\RetentionHolds;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\CommitsRetentionFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * E21-RH.7 (ADR 0066 §15): retention eligibility counts only from what the
 * database recorded (`retention_recorded_at`), never from a clock the
 * runtime role wrote -- proven on raw sessions as `school_os_app`,
 * `school_os_retention` and the schema owner.
 */
class RetentionTimestampIntegrityTest extends TestCase
{
    use CommitsRetentionFixtures, CreatesTenancyFixtures;

    /** These proofs set the database recording themselves. */
    protected bool $alignRetentionAnchors = false;

    private const LONG_AGO = '2001-01-01 00:00:00';

    /** @param  'pgsql'|'pgsql_retention'|'pgsql_admin'  $connection */
    private function attempt(string $connection, ?School $school, callable $statement): string
    {
        try {
            DB::usingConnection($connection, fn () => DB::transaction(function () use ($school, $statement): void {
                if ($school !== null) {
                    DB::select("SELECT set_config('app.current_school_id', ?, true)", [$school->id]);
                }
                $statement();
            }));
        } catch (QueryException $e) {
            return $e->getMessage();
        }

        return '';
    }

    private function outbox(?School $school, string $processedAt, string $status = 'dispatched'): string
    {
        $id = (string) Str::uuid7();
        DB::table('domain_event_outbox')->insert([
            'id' => $id, 'school_id' => $school?->id, 'event_type' => 'school.setting.changed.v1', 'event_version' => 1,
            'correlation_id' => (string) Str::uuid(), 'payload' => json_encode(['key' => 'x']), 'status' => $status,
            'occurred_at' => $processedAt, 'available_at' => $processedAt, 'dispatched_at' => $processedAt, 'processed_at' => $processedAt,
            'created_at' => $processedAt, 'updated_at' => $processedAt,
            'retention_recorded_at' => self::LONG_AGO, // supplied by the caller: ignored
        ]);

        return $id;
    }

    private function receipt(string $eventId, ?School $school): string
    {
        $id = (string) Str::uuid7();
        DB::table('event_consumer_receipts')->insert([
            'id' => $id, 'consumer_name' => 'test-consumer', 'event_id' => $eventId, 'school_id' => $school?->id,
            'processed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function anchor(string $table, string $id): string
    {
        return (string) DB::connection('pgsql_admin')->table($table)->where('id', $id)->value('retention_recorded_at');
    }

    private function today(): string
    {
        return (string) DB::selectOne("SELECT to_char(now() AT TIME ZONE 'UTC', 'YYYY-MM-DD') AS d")->d;
    }

    /** The reviewed maintenance path (model D): only the schema owner may record a time explicitly. */
    private function recordLongAgo(string $table, string $id): void
    {
        DB::connection('pgsql_admin')->table($table)->where('id', $id)->update(['retention_recorded_at' => self::LONG_AGO]);
    }

    #[Test]
    public function the_database_records_every_write_and_a_clock_written_in_the_past_buys_nothing(): void
    {
        // INSERT: an artificially old clock and an artificially old anchor are both recorded as now.
        $id = $this->outbox(null, self::LONG_AGO);
        $this->assertStringStartsWith($this->today(), $this->anchor('domain_event_outbox', $id), 'a supplied anchor is ignored on INSERT');
        $this->assertSame(self::LONG_AGO, (string) DB::table('domain_event_outbox')->where('id', $id)->value('processed_at'), 'the domain clock keeps its value');

        $this->recordLongAgo('domain_event_outbox', $id);
        $this->assertSame(self::LONG_AGO, $this->anchor('domain_event_outbox', $id), 'the schema owner may record explicitly');

        // UPDATE of an untracked column, or of the anchor itself: the record is unchanged.
        $this->assertSame('', $this->attempt('pgsql', null, fn () => DB::table('domain_event_outbox')->where('id', $id)->update(['attempts' => 3])));
        $this->assertSame(self::LONG_AGO, $this->anchor('domain_event_outbox', $id));
        $this->assertSame('', $this->attempt('pgsql', null, fn () => DB::table('domain_event_outbox')->where('id', $id)->update(['retention_recorded_at' => '1999-01-01 00:00:00'])));
        $this->assertSame(self::LONG_AGO, $this->anchor('domain_event_outbox', $id), 'a supplied anchor is ignored on UPDATE');

        // Clearing a tracked clock never makes a row eligible, so it keeps the record ...
        $this->assertSame('', $this->attempt('pgsql', null, fn () => DB::table('domain_event_outbox')->where('id', $id)->update(['processed_at' => null])));
        $this->assertSame(self::LONG_AGO, $this->anchor('domain_event_outbox', $id));
        // ... and writing one -- backdated, or re-closing to obtain an old time -- re-records the row now.
        $this->assertSame('', $this->attempt('pgsql', null, fn () => DB::table('domain_event_outbox')->where('id', $id)->update(['processed_at' => '1995-01-01 00:00:00'])));
        $this->assertStringStartsWith($this->today(), $this->anchor('domain_event_outbox', $id), 'backdating re-records the row');
    }

    #[Test]
    public function re_linking_a_row_onto_an_older_parent_re_records_it(): void
    {
        $old = $this->outbox(null, self::LONG_AGO);
        $young = $this->outbox(null, now()->format('Y-m-d H:i:s'));
        $receipt = $this->receipt($young, null);
        $this->recordLongAgo('event_consumer_receipts', $receipt);

        $this->assertSame('', $this->attempt('pgsql', null, fn () => DB::table('event_consumer_receipts')->where('id', $receipt)->update(['event_id' => $old])));
        $this->assertStringStartsWith($this->today(), $this->anchor('event_consumer_receipts', $receipt), 'every foreign key on an anchored table is tracked');
    }

    #[Test]
    public function the_retention_identity_consumes_eligibility_and_never_records_it(): void
    {
        $id = $this->outbox(null, self::LONG_AGO);
        $retention = RetentionExpiry::PRIVILEGED_CONNECTION;

        $this->assertStringContainsString('permission denied', $this->attempt($retention, null, fn () => DB::table('domain_event_outbox')->where('id', $id)->update(['retention_recorded_at' => self::LONG_AGO])));
        $this->assertStringContainsString('permission denied', $this->attempt($retention, null, fn () => DB::table('domain_event_outbox')->where('id', $id)->update(['processed_at' => self::LONG_AGO])));
        $this->assertStringContainsString('permission denied', $this->attempt($retention, null, fn () => DB::table('domain_event_outbox')->insert(['id' => (string) Str::uuid7()])));
        $this->assertStringContainsString('permission denied', $this->attempt($retention, null, fn () => DB::table('school_audit_events')->where('id', (string) Str::uuid7())->update(['occurred_at' => self::LONG_AGO])));
    }

    #[Test]
    public function a_retention_delete_needs_a_declared_cutoff_and_takes_only_rows_recorded_before_it(): void
    {
        $id = $this->outbox(null, self::LONG_AGO);
        $retention = RetentionExpiry::PRIVILEGED_CONNECTION;
        $delete = fn (?string $cutoff) => $this->attempt($retention, null, function () use ($cutoff, $id): void {
            if ($cutoff !== null) {
                DB::select("SELECT set_config('app.retention_anchor_cutoff', ?, true)", [$cutoff]);
            }
            DB::table('domain_event_outbox')->where('id', $id)->delete();
        });

        $this->assertStringContainsString('without a declared recorded-before cutoff (retention_anchor)', $delete(null));
        $this->assertStringContainsString('not yet eligible (retention_anchor)', $delete('2020-01-01 00:00:00'), 'recorded today: refused whatever its clock says');
        $this->assertTrue(DB::table('domain_event_outbox')->where('id', $id)->exists());

        $this->recordLongAgo('domain_event_outbox', $id);
        $this->assertSame('', $delete('2020-01-01 00:00:00'));
        $this->assertFalse(DB::table('domain_event_outbox')->where('id', $id)->exists());
    }

    #[Test]
    public function forged_clocks_buy_nothing_on_the_php_and_database_paths(): void
    {
        config(['retention.outbox_days' => 30, 'retention.hold_school_ids' => [], 'retention.batch_size' => 500]);
        $school = $this->createSchool();

        // PHP path (platform:outbox-prune): a row processed "in 2001" but written today is skipped, never an error.
        $forged = $this->outbox($school, self::LONG_AGO);
        // A receipt re-linked onto a genuinely old event keeps that event.
        $genuine = $this->outbox($school, self::LONG_AGO);
        $this->recordLongAgo('domain_event_outbox', $genuine);
        $relinked = $this->receipt($this->outbox($school, now()->format('Y-m-d H:i:s')), $school);
        DB::table('event_consumer_receipts')->where('id', $relinked)->update(['event_id' => $genuine]);
        $clean = $this->outbox($school, self::LONG_AGO);
        $this->recordLongAgo('domain_event_outbox', $clean);

        $this->artisan('platform:outbox-prune')->assertSuccessful();
        $this->assertTrue(DB::table('domain_event_outbox')->where('id', $forged)->exists(), 'forged clock: kept');
        $this->assertTrue(DB::table('domain_event_outbox')->where('id', $genuine)->exists(), 're-linked receipt: kept');
        $this->assertFalse(DB::table('domain_event_outbox')->where('id', $clean)->exists(), 'recorded long ago: expired');

        // Database path (an expiry function): an audit event written today "as of 2001" is not selected.
        $audit = (string) Str::uuid7();
        app(TenantContext::class)->withSchool($school, fn () => DB::table('school_audit_events')->insert([
            'id' => $audit, 'school_id' => $school->id, 'occurred_at' => self::LONG_AGO, 'event_type' => 'test.event', 'metadata' => '{}', 'created_at' => self::LONG_AGO,
        ]));
        $expire = fn () => DB::usingConnection(RetentionExpiry::PRIVILEGED_CONNECTION, fn () => app(TenantContext::class)->withSchool($school, fn () => DB::transaction(
            fn () => (int) DB::selectOne('SELECT retention_expire_school_audit_events(?, ?, 10, false) AS n', [$school->id, now()->subYears(10)->format('Y-m-d H:i:s')])->n,
        )));
        $this->assertSame(0, $expire(), 'a forged occurred_at is skipped (not selected), never an error');
        $this->recordLongAgo('school_audit_events', $audit);
        $this->assertSame(1, $expire(), 'recorded long ago: expired');
    }

    #[Test]
    public function the_rollback_fences_refuse_and_change_nothing(): void
    {
        $before = DB::table('migrations')->count();
        foreach (DatabaseRoleVerifier::RETENTION_FENCES as $name) {
            $migration = require database_path("migrations/{$name}.php");
            try {
                $migration->down();
                $this->fail("{$name} must refuse rollback");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('deliberately irreversible', $e->getMessage(), $name);
            }
        }
        $this->assertSame($before, DB::table('migrations')->count());
        $this->assertSame(CheckResult::PASS, collect(app(DatabaseRoleVerifier::class)->verify())->keyBy('code')['retention_anchors_recorded']->status, 'nothing was dropped');
    }

    #[Test]
    public function the_verifier_proves_the_timestamp_architecture_and_detects_each_regression(): void
    {
        $checks = fn () => collect(app(DatabaseRoleVerifier::class)->verify())->keyBy('code');
        foreach (['retention_anchors_recorded', 'retention_functions_declare_cutoff', 'retention_rollback_fences', 'retention_deletes_guarded', 'retention_eligibility_guards'] as $code) {
            $this->assertSame(CheckResult::PASS, $checks()[$code]->status, $code);
        }

        $admin = DB::connection(RetentionHolds::MAINTENANCE_CONNECTION);
        $fence = DatabaseRoleVerifier::RETENTION_FENCES[1];
        $batch = (int) $admin->table('migrations')->where('migration', $fence)->value('batch');
        $regressions = [
            'retention_anchors_recorded' => [
                fn () => $admin->statement('ALTER TABLE school_audit_events DISABLE TRIGGER zzz_retention_anchor'),
                fn () => $admin->statement('ALTER TABLE school_audit_events ENABLE TRIGGER zzz_retention_anchor'),
            ],
            'retention_deletes_guarded' => [
                fn () => $admin->statement('ALTER TABLE school_audit_events DISABLE TRIGGER trg_retention_guard_school_audit_events'),
                fn () => $admin->statement('ALTER TABLE school_audit_events ENABLE TRIGGER trg_retention_guard_school_audit_events'),
            ],
            'retention_functions_narrow' => [
                fn () => $admin->statement('GRANT EXECUTE ON FUNCTION retention_expire_school_audit_events(uuid, timestamp, integer, boolean) TO school_os_app'),
                fn () => $admin->statement('REVOKE EXECUTE ON FUNCTION retention_expire_school_audit_events(uuid, timestamp, integer, boolean) FROM school_os_app'),
            ],
            'runtime_retention_deletes_revoked' => [
                fn () => $admin->statement('GRANT DELETE ON visitors TO school_os_app'),
                fn () => $admin->statement('REVOKE DELETE ON visitors FROM school_os_app'),
            ],
            'retention_rollback_fences' => [
                fn () => $admin->table('migrations')->where('migration', $fence)->delete(),
                fn () => $admin->table('migrations')->insert(['migration' => $fence, 'batch' => $batch]),
            ],
        ];
        foreach ($regressions as $code => [$break, $restore]) {
            $break();
            try {
                $this->assertSame(CheckResult::FAIL, $checks()[$code]->status, "{$code} detects the regression");
            } finally {
                $restore();
            }
            $this->assertSame(CheckResult::PASS, $checks()[$code]->status, $code);
        }
    }
}
