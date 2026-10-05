<?php

namespace Tests\Concerns;

use App\Models\School;
use Illuminate\Support\Facades\DB;

/**
 * HRX.6 / E21-RH: hermetic cleanup for tests that COMMIT their fixtures
 * (two real OS processes must see them, or -- E21-RH.2+ -- the dedicated
 * retention connection, a separate session, must see them).
 *
 * Every durable row such a test creates goes in tearDown, through the
 * migration role in replica mode (test database only: it also bypasses the
 * history guards of append-only tables such as `retention_holds`):
 * - every School-scoped row of the test's Schools -- the ones passed in AND
 *   every School created since setUp -- and those Schools;
 * - the Users created since setUp (and members of those Schools);
 * - the `test.capability_grant.*` roles `createUserWithCapabilities()`
 *   minted since setUp, with their `role_capabilities`;
 * - rows of the School-less platform tables (GLOBAL_TABLES) written since
 *   setUp: platform audit events, retention holds (a platform hold has no
 *   School), suppressions, platform/Group grants, Groups, platform-level
 *   erasure cases, API tokens (replica mode skips their User cascade);
 * - rows of the trigger-maintained `operational_work_backlog` projection
 *   created since setUp (replica mode skips its sync trigger), and the
 *   scheduler heartbeats a committed command run recorded since setUp.
 *
 * Shared baseline fixtures of the canonical seed (capabilities, the seeded
 * roles and their grants, the seeded User) existed before setUp and are
 * never touched. `assertDurableFixturesRestored()` then proves the run left
 * nothing behind: the durable counts equal the setUp snapshot.
 */
trait PurgesCommittedHrxFixtures
{
    /** @var array{schools: list<string>, users: list<string>, roles: list<string>, global: array<string, list<string>>, backlog: list<string>, heartbeats: list<string>, counts: array<string, int>}|null */
    private ?array $durableSnapshot = null;

    /** School-less tables a committed fixture can leave rows in (cleaned by id difference). */
    private const GLOBAL_TABLES = ['platform_audit_events', 'retention_holds', 'email_suppressions', 'platform_role_assignments', 'group_role_assignments', 'school_groups', 'erasure_cases', 'personal_access_tokens'];

    /** Global tables whose counts must be restored. */
    private const DURABLE_TABLES = ['schools', 'users', 'roles', 'role_capabilities', 'school_memberships', 'membership_role_assignments', 'operational_work_backlog', 'scheduler_heartbeats', ...self::GLOBAL_TABLES];

    /**
     * The trigger-maintained `operational_work_backlog` projection (no id, no
     * School): replica mode skips its sync trigger, so rows projected from
     * the purged deliveries are removed by key difference.
     */
    private const BACKLOG_KEY = "source || ':' || item_id::text";

    protected function snapshotDurableFixtures(): void
    {
        $admin = DB::connection('pgsql_admin');
        $this->durableSnapshot = [
            'schools' => $admin->table('schools')->pluck('id')->all(),
            'users' => $admin->table('users')->pluck('id')->all(),
            'roles' => $admin->table('roles')->pluck('id')->all(),
            'global' => array_combine(self::GLOBAL_TABLES, array_map(fn (string $t) => $admin->table($t)->pluck('id')->all(), self::GLOBAL_TABLES)),
            'backlog' => $admin->table('operational_work_backlog')->selectRaw(self::BACKLOG_KEY.' AS k')->pluck('k')->all(),
            'heartbeats' => $admin->table('scheduler_heartbeats')->pluck('name')->all(),
            'counts' => $this->durableCounts(),
        ];
    }

    /** @param  list<School>  $schools */
    protected function purgeCommittedHrxSchools(array $schools): void
    {
        $admin = DB::connection('pgsql_admin');
        $snapshot = $this->durableSnapshot;
        $schoolIds = array_values(array_unique([
            ...array_map(fn (School $s) => $s->id, $schools),
            ...($snapshot === null ? [] : $admin->table('schools')->whereNotIn('id', $snapshot['schools'])->pluck('id')->all()),
        ]));

        $users = array_values(array_unique([
            ...$admin->table('school_memberships')->whereIn('school_id', $schoolIds)->pluck('user_id')->all(),
            ...$admin->table('users')->whereNotIn('id', $snapshot['users'] ?? [])->pluck('id')->all(),
        ]));
        $roles = array_values(array_unique([
            ...$admin->table('membership_role_assignments as a')->join('roles as r', 'r.id', '=', 'a.role_id')
                ->whereIn('a.school_id', $schoolIds)->where('r.key', 'like', 'test.capability_grant.%')->pluck('r.id')->all(),
            ...$admin->table('roles')->where('key', 'like', 'test.capability_grant.%')->whereNotIn('id', $snapshot['roles'] ?? [])->pluck('id')->all(),
        ]));

        $admin->transaction(function () use ($admin, $schoolIds, $users, $roles, $snapshot): void {
            $admin->statement("SET LOCAL session_replication_role = 'replica'");
            if ($schoolIds !== []) {
                $tables = $admin->select(
                    "SELECT c.table_name FROM information_schema.columns c
                       JOIN pg_class t ON t.relname = c.table_name AND t.relkind = 'r' AND t.relnamespace = 'public'::regnamespace
                      WHERE c.table_schema = 'public' AND c.column_name = 'school_id'",
                );
                foreach ($tables as $row) {
                    $admin->table($row->table_name)->whereIn('school_id', $schoolIds)->delete();
                }
                $admin->table('schools')->whereIn('id', $schoolIds)->delete();
            }
            if ($snapshot !== null) {
                foreach (self::GLOBAL_TABLES as $table) {
                    $admin->table($table)->whereNotIn('id', $snapshot['global'][$table])->delete();
                }
                $admin->table('operational_work_backlog')->whereNotIn($admin->raw(self::BACKLOG_KEY), $snapshot['backlog'])->delete();
                $admin->table('scheduler_heartbeats')->whereNotIn('name', $snapshot['heartbeats'])->delete();
            }
            $admin->table('role_capabilities')->whereIn('role_id', $roles)->delete();
            $admin->table('roles')->whereIn('id', $roles)->delete();
            $admin->table('users')->whereIn('id', $users)->delete();
        });
    }

    /** The committed run left nothing durable behind (call after purgeCommittedHrxSchools()). */
    protected function assertDurableFixturesRestored(): void
    {
        if ($this->durableSnapshot !== null) {
            $this->assertSame($this->durableSnapshot['counts'], $this->durableCounts(), 'the committed fixtures left durable rows behind');
        }
    }

    /** @return array<string, int> */
    private function durableCounts(): array
    {
        $admin = DB::connection('pgsql_admin');

        return array_combine(self::DURABLE_TABLES, array_map(fn (string $t) => $admin->table($t)->count(), self::DURABLE_TABLES));
    }
}
