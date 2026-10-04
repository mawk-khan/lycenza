<?php

namespace Tests\Concerns;

use App\Models\School;
use Illuminate\Support\Facades\DB;

/**
 * HRX.6: hermetic cleanup for the HRX race tests, which COMMIT their
 * fixtures (two real OS processes must see them).
 *
 * Every durable row a race test creates goes in tearDown, through the
 * migration role in replica mode (test database only; the ledgers are
 * append-only and every Employee link is RESTRICT):
 * - every School-scoped row of the test's Schools, and the Schools;
 * - the Users the test created (members of those Schools, or created since
 *   setUp);
 * - the `test.capability_grant.*` roles `createUserWithCapabilities()`
 *   minted since setUp, with their `role_capabilities`;
 * - School-less platform audit rows written since setUp (e.g. an erasure
 *   case's `platform.erasure_case.*` events).
 *
 * Shared baseline fixtures of the canonical seed (capabilities, the seeded
 * roles and their grants, the seeded User) existed before setUp and are
 * never touched. `assertDurableFixturesRestored()` then proves the run left
 * nothing behind: the durable counts equal the setUp snapshot.
 */
trait PurgesCommittedHrxFixtures
{
    /** @var array{users: list<string>, roles: list<string>, platform_audit: list<string>, counts: array<string, int>}|null */
    private ?array $durableSnapshot = null;

    /** Global tables a committed race fixture can leave rows in. */
    private const DURABLE_TABLES = ['schools', 'users', 'roles', 'role_capabilities', 'school_memberships', 'membership_role_assignments', 'platform_audit_events'];

    protected function snapshotDurableFixtures(): void
    {
        $admin = DB::connection('pgsql_admin');
        $this->durableSnapshot = [
            'users' => $admin->table('users')->pluck('id')->all(),
            'roles' => $admin->table('roles')->pluck('id')->all(),
            'platform_audit' => $admin->table('platform_audit_events')->pluck('id')->all(),
            'counts' => $this->durableCounts(),
        ];
    }

    /** @param  list<School>  $schools */
    protected function purgeCommittedHrxSchools(array $schools): void
    {
        $admin = DB::connection('pgsql_admin');
        $snapshot = $this->durableSnapshot ?? ['users' => [], 'roles' => [], 'platform_audit' => null];
        $schoolIds = array_map(fn (School $s) => $s->id, $schools);

        $users = array_values(array_unique([
            ...$admin->table('school_memberships')->whereIn('school_id', $schoolIds)->pluck('user_id')->all(),
            ...$admin->table('users')->whereNotIn('id', $snapshot['users'])->pluck('id')->all(),
        ]));
        $roles = array_values(array_unique([
            ...$admin->table('membership_role_assignments as a')->join('roles as r', 'r.id', '=', 'a.role_id')
                ->whereIn('a.school_id', $schoolIds)->where('r.key', 'like', 'test.capability_grant.%')->pluck('r.id')->all(),
            ...$admin->table('roles')->where('key', 'like', 'test.capability_grant.%')->whereNotIn('id', $snapshot['roles'])->pluck('id')->all(),
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
            $admin->table('role_capabilities')->whereIn('role_id', $roles)->delete();
            $admin->table('roles')->whereIn('id', $roles)->delete();
            $admin->table('users')->whereIn('id', $users)->delete();
            if ($snapshot['platform_audit'] !== null) {
                $admin->table('platform_audit_events')->whereNotIn('id', $snapshot['platform_audit'])->delete();
            }
        });
    }

    /** The committed run left nothing durable behind (call after purgeCommittedHrxSchools()). */
    protected function assertDurableFixturesRestored(): void
    {
        if ($this->durableSnapshot !== null) {
            $this->assertSame($this->durableSnapshot['counts'], $this->durableCounts(), 'the committed race fixtures left durable rows behind');
        }
    }

    /** @return array<string, int> */
    private function durableCounts(): array
    {
        $admin = DB::connection('pgsql_admin');

        return array_combine(self::DURABLE_TABLES, array_map(fn (string $t) => $admin->table($t)->count(), self::DURABLE_TABLES));
    }
}
