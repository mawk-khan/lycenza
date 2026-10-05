<?php

namespace Tests\Feature\Retention;

use App\Models\School;
use App\Support\Operations\CheckResult;
use App\Support\Operations\DatabaseRoleVerifier;
use App\Support\Retention\RetentionExpiry;
use App\Support\Retention\RetentionHolds;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PurgesCommittedHrxFixtures;
use Tests\Feature\Retention\Concerns\CreatesHrxRetentionFixtures;
use Tests\TestCase;

/**
 * E21-RH.3 (ADR 0066 §6): PostgreSQL retention holds are AUTHORITATIVE.
 * School and platform holds, history-preserving and database-attributed,
 * placed and released only by the audited operator commands on the
 * maintenance connection; configuration is an add-only input; the HRX
 * destructive functions refuse a held School or an active platform hold in
 * the database itself; neither the runtime nor the retention role can read
 * or change hold state beyond the retention role's active-scopes read.
 *
 * COMMITTED fixtures (the retention and maintenance connections must see
 * them); PurgesCommittedHrxFixtures removes them, platform holds included.
 */
class RetentionHoldsTest extends TestCase
{
    use CreatesHrxRetentionFixtures, PurgesCommittedHrxFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    /** @var list<School> */
    private array $schools = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->snapshotDurableFixtures();
        config(['retention.employee_ancillary_years' => 2, 'retention.employee_evidence_years' => 8, 'retention.hold_school_ids' => [], 'retention.hold_platform' => false]);
    }

    protected function tearDown(): void
    {
        DB::purge(RetentionExpiry::PRIVILEGED_CONNECTION);
        $this->purgeCommittedHrxSchools($this->schools);
        try {
            $this->assertDurableFixturesRestored();
        } finally {
            parent::tearDown();
        }
    }

    private function admin(): Connection
    {
        return DB::connection(RetentionHolds::MAINTENANCE_CONNECTION);
    }

    private function school(): School
    {
        $school = $this->createSchool();
        $this->schools[] = $school;

        return $school;
    }

    /** The message of the exception $statement raises ('' when it succeeds). */
    private function refusal(callable $statement): string
    {
        try {
            $statement();

            return '';
        } catch (QueryException $e) {
            return $e->getMessage();
        }
    }

    /** The destructive HRX call as the retention identity: its row count, or the refusal. */
    private function purge(string $kind, School $school, string $employeeId): mixed
    {
        $function = $kind === 'leave' ? 'retention_expire_leave_employee_evidence' : 'retention_expire_staff_attendance_employee_evidence';

        return $this->asRetention($school, fn () => DB::selectOne("SELECT {$function}(?, ?, ?, false) AS n", [$school->id, $employeeId, '2018-01-01'])->n);
    }

    #[Test]
    public function the_store_enforces_scope_one_active_hold_and_immutable_history_for_every_role(): void
    {
        $school = $this->school();
        $admin = $this->admin();

        // Scope shape and closed codes are database-checked.
        foreach ([
            "INSERT INTO retention_holds (scope, school_id, reason_code, placed_via, placed_by_login, placed_at) VALUES ('school', NULL, 'audit', 'operator_command', 'x', now())",
            "INSERT INTO retention_holds (scope, school_id, reason_code, placed_via, placed_by_login, placed_at) VALUES ('platform', '{$school->id}', 'audit', 'operator_command', 'x', now())",
            "INSERT INTO retention_holds (scope, reason_code, placed_via, placed_by_login, placed_at) VALUES ('platform', 'because', 'operator_command', 'x', now())",
            "INSERT INTO retention_holds (scope, reason_code, reference, placed_via, placed_by_login, placed_at) VALUES ('platform', 'audit', 'free text, with spaces', 'operator_command', 'x', now())",
            "INSERT INTO retention_holds (scope, reason_code, placed_via, placed_by_login, placed_at, released_at, released_by_login, release_reason_code) VALUES ('platform', 'audit', 'operator_command', 'x', now(), now(), 'x', 'other')",
        ] as $sql) {
            $this->assertNotSame('', $this->refusal(fn () => $admin->statement($sql)), $sql);
        }

        $holds = app(RetentionHolds::class);
        $first = $holds->place($school->id, 'litigation', 'CASE-7');
        $this->assertTrue($first['created']);
        $this->assertSame(['id' => $first['id'], 'created' => false], $holds->place($school->id, 'audit', 'CASE-8'), 'idempotent: one active hold per School');
        $this->assertStringContainsString('retention_holds_one_active_school', $this->refusal(fn () => $admin->statement(
            "INSERT INTO retention_holds (scope, school_id, reason_code, placed_via, placed_by_login, placed_at) VALUES ('school', '{$school->id}', 'audit', 'operator_command', 'x', now())",
        )));

        $row = $admin->table('retention_holds')->where('id', $first['id'])->first();
        $this->assertSame([$admin->selectOne('select session_user::text as u')->u, 'operator_command', 'CASE-7'], [$row->placed_by_login, $row->placed_via, $row->reference], 'attributed by the database, not the caller');

        // Only a release changes an active row; nothing changes or disappears afterwards -- not even for the owner.
        $this->assertStringContainsString('only change is the release', $this->refusal(fn () => $admin->table('retention_holds')->where('id', $first['id'])->update(['reason_code' => 'audit'])));
        $this->assertStringContainsString('never deleted', $this->refusal(fn () => $admin->table('retention_holds')->where('id', $first['id'])->delete()));
        $this->assertStringContainsString('never deleted', $this->refusal(fn () => $admin->statement('TRUNCATE retention_holds')));
        $holds->release($school->id, 'placed_in_error', 'CASE-7');
        $this->assertStringContainsString('history and never changes', $this->refusal(fn () => $admin->table('retention_holds')->where('id', $first['id'])->update(['release_reason_code' => 'other'])));
        $this->assertStringContainsString('retention_hold_not_active', $this->refusal(fn () => $holds->release($school->id, 'other', null)), 'a release needs an active hold');

        // A new hold after a release is a NEW row: the history keeps both.
        $second = $holds->place($school->id, 'audit', 'CASE-9');
        $this->assertNotSame($first['id'], $second['id']);
        $this->assertSame([1, 1], [$admin->table('retention_holds')->where('school_id', $school->id)->whereNotNull('released_at')->count(), $admin->table('retention_holds')->where('school_id', $school->id)->whereNull('released_at')->count()]);
    }

    #[Test]
    public function the_operator_commands_place_inspect_and_explicitly_release_with_audit(): void
    {
        $school = $this->school();
        $audit = fn (string $type) => DB::table('platform_audit_events')->where('event_type', $type)->count();
        [$placedBefore, $releasedBefore] = [$audit('platform.retention_hold.placed'), $audit('platform.retention_hold.released')];

        $this->artisan('platform:retention-hold-place', ['--reason' => 'litigation'])->expectsOutputToContain('Give exactly one of')->assertFailed();
        $this->artisan('platform:retention-hold-place', ['--school' => $school->id, '--reason' => 'because'])->expectsOutputToContain('Choose one of')->assertFailed();
        $this->artisan('platform:retention-hold-place', ['--school' => $school->id, '--reason' => 'litigation', '--reference' => 'LEGAL-42'])->expectsOutputToContain('Placed hold')->assertSuccessful();
        $this->artisan('platform:retention-hold-place', ['--school' => $school->id, '--reason' => 'litigation', '--reference' => 'LEGAL-42'])->expectsOutputToContain('Already held')->assertSuccessful();
        $this->artisan('platform:retention-hold-place', ['--platform' => true, '--reason' => 'regulatory_inquiry', '--reference' => 'REG-1'])->expectsOutputToContain('(platform)')->assertSuccessful();
        $this->artisan('platform:retention-holds')->expectsOutputToContain('2 hold(s) shown (active only)')->assertSuccessful();

        // Release is explicit: a wrong confirmation releases nothing.
        $this->artisan('platform:retention-hold-release', ['--platform' => true, '--reason' => 'inquiry_closed', '--reference' => 'REG-1'])
            ->expectsQuestion('Releasing a retention hold allows destructive retention again. Type platform to confirm', 'no')
            ->expectsOutputToContain('Nothing was released')->assertFailed();
        $this->assertTrue(app(RetentionHolds::class)->databaseHolds()['platform']);
        $this->artisan('platform:retention-hold-release', ['--platform' => true, '--reason' => 'inquiry_closed', '--reference' => 'REG-1'])
            ->expectsQuestion('Releasing a retention hold allows destructive retention again. Type platform to confirm', 'platform')
            ->expectsOutputToContain('Released hold')->assertSuccessful();
        $this->artisan('platform:retention-hold-release', ['--platform' => true, '--reason' => 'inquiry_closed', '--force' => true])->expectsOutputToContain('No active hold')->assertFailed();

        $this->artisan('platform:retention-holds', ['--history' => true])->expectsOutputToContain('2 hold(s) shown (with history)')->assertSuccessful();
        $this->assertSame([$placedBefore + 2, $releasedBefore + 1], [$audit('platform.retention_hold.placed'), $audit('platform.retention_hold.released')], 'every state change is audited (an idempotent re-place is not a change)');
        $released = $this->admin()->table('retention_holds')->where('scope', 'platform')->first();
        $this->assertSame(['inquiry_closed', 'REG-1'], [$released->release_reason_code, $released->release_reference]);
        $this->assertNotNull($released->released_by_login);
    }

    #[Test]
    public function configuration_is_reconciled_add_only_and_its_removal_never_releases(): void
    {
        $held = $this->school();
        config(['retention.hold_school_ids' => [$held->id], 'retention.hold_platform' => true]);
        $this->artisan('platform:retention-holds-reconcile')->expectsOutputToContain('Placed 2 hold(s) from configuration')->assertSuccessful();
        $this->artisan('platform:retention-holds-reconcile')->expectsOutputToContain('Placed 0 hold(s)')->assertSuccessful();

        config(['retention.hold_school_ids' => [], 'retention.hold_platform' => false]);
        $this->artisan('platform:retention-holds-reconcile')->expectsOutputToContain('released none')->assertSuccessful();
        $database = app(RetentionHolds::class)->databaseHolds();
        $this->assertTrue($database['platform'], 'still held after the configuration dropped it');
        $this->assertContains($held->id, $database['schools']);
        $this->assertSame(2, $this->admin()->table('retention_holds')->where('placed_via', 'configuration_reconciliation')->whereNull('released_at')->count());

        // An unknown configured School places nothing and fails, so the operator fixes the configuration.
        config(['retention.hold_school_ids' => ['01900000-0000-7000-8000-000000000000']]);
        $this->artisan('platform:retention-holds-reconcile')->expectsOutputToContain('name no existing School')->assertFailed();
    }

    #[Test]
    public function an_active_platform_hold_blocks_hrx_for_every_school_until_explicitly_released(): void
    {
        $w = $this->pastWorld();
        $leaver = $this->pastLeaver($w);
        $before = $this->hrxRows($w['school'], $leaver['employeeId']);
        $holds = app(RetentionHolds::class);
        $holds->place(null, 'regulatory_inquiry', 'REG-2');

        // The School itself is not held; the platform hold still refuses both functions in the database.
        $this->assertNotContains($w['school']->id, $holds->databaseHolds()['schools']);
        foreach (['leave', 'staff_attendance'] as $kind) {
            $this->assertStringContainsString('retention_hold', (string) $this->purge($kind, $w['school'], $leaver['employeeId']), $kind);
        }
        $this->assertSame($before, $this->hrxRows($w['school'], $leaver['employeeId']));
        // The PHP side (legacy flows, reporting) agrees: every School and every School-less record is held.
        $this->assertTrue($holds->isHeld($w['school']->id));
        $this->assertTrue($holds->platformHeld());

        $holds->release(null, 'inquiry_closed', 'REG-2');
        $this->assertGreaterThan(0, (int) $this->purge('leave', $w['school'], $leaver['employeeId']), 'released: the platform hold no longer blocks');
        $this->assertGreaterThan(0, (int) $this->purge('staff_attendance', $w['school'], $leaver['employeeId']));
        $this->assertSame(array_fill_keys(self::HRX_EVIDENCE, 0), array_intersect_key($this->hrxRows($w['school'], $leaver['employeeId']), array_fill_keys(self::HRX_EVIDENCE, 0)));
    }

    #[Test]
    public function a_school_hold_blocks_only_that_school(): void
    {
        $a = $this->pastWorld();
        $leaverA = $this->pastLeaver($a);
        $b = $this->pastWorld();
        $leaverB = $this->pastLeaver($b);
        $beforeA = $this->hrxRows($a['school'], $leaverA['employeeId']);
        app(RetentionHolds::class)->place($a['school']->id, 'litigation', 'CASE-11');

        foreach (['leave', 'staff_attendance'] as $kind) {
            $this->assertStringContainsString('retention_hold', (string) $this->purge($kind, $a['school'], $leaverA['employeeId']), "{$kind}: School A is held");
            $this->assertGreaterThan(0, (int) $this->purge($kind, $b['school'], $leaverB['employeeId']), "{$kind}: School B is not");
        }
        $this->assertSame($beforeA, $this->hrxRows($a['school'], $leaverA['employeeId']));
        $this->assertTrue(app(RetentionHolds::class)->isHeld($a['school']->id));
        $this->assertFalse(app(RetentionHolds::class)->isHeld($b['school']->id));
    }

    #[Test]
    public function the_retention_identity_reads_active_scopes_only_and_can_change_nothing(): void
    {
        $school = $this->school();
        app(RetentionHolds::class)->place($school->id, 'audit', 'CHG-3');
        $retention = DB::connection(RetentionExpiry::PRIVILEGED_CONNECTION);

        $this->assertSame([['scope' => 'school', 'school_id' => $school->id]], array_map(fn ($r) => (array) $r, $retention->select("SELECT scope, school_id FROM retention_hold_active_scopes() WHERE school_id = '{$school->id}'")));
        foreach ([
            'SELECT count(*) FROM retention_holds',
            "UPDATE retention_holds SET release_reason_code = 'other' WHERE school_id = '{$school->id}'",
            "DELETE FROM retention_holds WHERE school_id = '{$school->id}'",
            "INSERT INTO retention_holds (scope, reason_code, placed_via, placed_by_login, placed_at) VALUES ('platform', 'other', 'operator_command', 'x', now())",
            "SELECT * FROM retention_hold_place('platform', null, 'other', null, 'operator_command')",
            "SELECT * FROM retention_hold_release('school', '{$school->id}', 'other', null)",
            "SELECT retention_assert_not_held('{$school->id}')",
        ] as $sql) {
            $this->assertStringContainsString('permission denied', $this->refusal(fn () => $retention->select($sql)), $sql);
        }
        $this->assertSame(1, $this->admin()->table('retention_holds')->where('school_id', $school->id)->whereNull('released_at')->count());
    }

    #[Test]
    public function unreadable_hold_state_counts_as_held_everywhere(): void
    {
        $school = $this->school();
        $connection = 'database.connections.'.RetentionExpiry::PRIVILEGED_CONNECTION;
        $username = config("{$connection}.username");
        config(["{$connection}.username" => null]);
        DB::purge(RetentionExpiry::PRIVILEGED_CONNECTION);
        try {
            $holds = app(RetentionHolds::class);
            $this->assertNull($holds->databaseHolds());
            $this->assertTrue($holds->isHeld($school->id), 'fail closed');
            $this->assertTrue($holds->platformHeld(), 'fail closed');
        } finally {
            config(["{$connection}.username" => $username]);
            DB::purge(RetentionExpiry::PRIVILEGED_CONNECTION);
        }
    }

    #[Test]
    public function the_verifier_proves_the_authoritative_store_and_detects_a_grant(): void
    {
        $checks = fn () => collect(app(DatabaseRoleVerifier::class)->verify())->keyBy('code');
        foreach (['retention_holds_authoritative', 'retention_role_functions_exact', 'retention_role_writes_exact', 'privileged_retention_functions_closed'] as $code) {
            $this->assertSame(CheckResult::PASS, $checks()[$code]->status, $code);
        }

        foreach ([
            ['GRANT SELECT ON retention_holds TO school_os_retention', 'REVOKE SELECT ON retention_holds FROM school_os_retention'],
            ['GRANT EXECUTE ON FUNCTION retention_hold_release(text, uuid, text, text) TO school_os_retention', 'REVOKE EXECUTE ON FUNCTION retention_hold_release(text, uuid, text, text) FROM school_os_retention'],
            ['ALTER TABLE retention_holds DISABLE TRIGGER trg_retention_holds_guard', 'ALTER TABLE retention_holds ENABLE TRIGGER trg_retention_holds_guard'],
        ] as [$break, $restore]) {
            $this->admin()->statement($break);
            try {
                $this->assertSame(CheckResult::FAIL, $checks()['retention_holds_authoritative']->status, $break);
            } finally {
                $this->admin()->statement($restore);
            }
        }
        $this->assertSame(CheckResult::PASS, $checks()['retention_holds_authoritative']->status);
    }
}
