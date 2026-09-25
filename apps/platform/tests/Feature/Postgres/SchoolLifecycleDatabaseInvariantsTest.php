<?php

namespace Tests\Feature\Postgres;

use App\Models\School;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0N.9 (ADR 0047 sections 2, 8, 12): what the DATABASE enforces for
 * the School lifecycle, independent of application code -- raw SQL as the
 * runtime role, each violation inside its own savepoint.
 */
class SchoolLifecycleDatabaseInvariantsTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function insertSchool(?string $status = null): string
    {
        $id = (string) Str::uuid7();
        $row = ['id' => $id, 'name' => 'Raw School', 'slug' => 'raw-'.Str::lower(Str::random(12)), 'created_at' => now(), 'updated_at' => now()];

        if ($status !== null) {
            $row['status'] = $status;
        }

        DB::table('schools')->insert($row);

        return $id;
    }

    private function setStatus(string $id, string $status): void
    {
        DB::table('schools')->where('id', $id)->update(['status' => $status]);
    }

    #[Test]
    public function the_status_is_checked_and_defaults_to_provisioning(): void
    {
        $this->assertSame('provisioning', DB::table('schools')->where('id', $this->insertSchool())->value('status'));

        foreach (['provisioning', 'active', 'suspended', 'archived'] as $status) {
            $this->assertSame($status, DB::table('schools')->where('id', $this->insertSchool($status))->value('status'));
        }

        foreach (['inactive', 'deleted', 'ACTIVE', ''] as $bad) {
            $this->assertRejected(fn () => $this->insertSchool($bad), 'schools_status_check');
        }

        $default = DB::connection('pgsql_admin')->selectOne("select column_default from information_schema.columns where table_name = 'schools' and column_name = 'status'");
        $this->assertSame("'provisioning'::character varying", $default->column_default);
    }

    #[Test]
    public function only_the_three_lifecycle_transitions_are_permitted(): void
    {
        $id = $this->insertSchool('provisioning');
        $this->assertRejected(fn () => $this->setStatus($id, 'suspended'), 'provisioning -> suspended is not permitted');
        $this->assertRejected(fn () => $this->setStatus($id, 'archived'), 'provisioning -> archived is not permitted');

        $this->setStatus($id, 'active');
        $this->assertRejected(fn () => $this->setStatus($id, 'provisioning'), 'active -> provisioning is not permitted');
        $this->assertRejected(fn () => $this->setStatus($id, 'archived'), 'active -> archived is not permitted');

        $this->setStatus($id, 'suspended');
        $this->assertRejected(fn () => $this->setStatus($id, 'provisioning'), 'suspended -> provisioning is not permitted');
        $this->assertRejected(fn () => $this->setStatus($id, 'archived'), 'suspended -> archived is not permitted');

        $this->setStatus($id, 'active');
        $this->assertSame('active', DB::table('schools')->where('id', $id)->value('status'));

        // Unchanged status and other columns update freely.
        DB::table('schools')->where('id', $id)->update(['status' => 'active', 'name' => 'Renamed']);
        $this->assertSame('Renamed', DB::table('schools')->where('id', $id)->value('name'));

        // Archived is terminal for the application: nothing leaves it.
        $archived = $this->insertSchool('archived');
        foreach (['active', 'suspended', 'provisioning'] as $status) {
            $this->assertRejected(fn () => $this->setStatus($archived, $status), "archived -> {$status} is not permitted");
        }
    }

    #[Test]
    public function the_runtime_role_cannot_delete_a_school_but_the_administrative_connection_can(): void
    {
        $school = $this->createSchool();
        $this->assertRejected(fn () => DB::table('schools')->where('id', $school->id)->delete(), 'permission denied for table schools');
        $this->assertFalse((bool) DB::connection('pgsql_admin')->selectOne("select has_table_privilege('school_os_app', 'schools', 'DELETE') as p")->p);
        $this->assertTrue((bool) DB::connection('pgsql_admin')->selectOne("select has_table_privilege('school_os_app', 'schools', 'UPDATE') as p")->p);

        // Controlled test cleanup through the admin connection (its own,
        // committed row -- the admin connection cannot see this test's
        // uncommitted fixtures).
        $admin = DB::connection('pgsql_admin');
        $id = (string) Str::uuid7();
        $admin->table('schools')->insert(['id' => $id, 'name' => 'Admin Cleanup', 'slug' => 'admin-cleanup-'.Str::lower(Str::random(10)), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(1, $admin->table('schools')->where('id', $id)->delete());
    }

    #[Test]
    public function elevation_accepts_the_suspension_end_reason_and_never_starts_into_a_non_active_school(): void
    {
        $actor = $this->createUser();
        $active = $this->createSchool();

        $insert = fn (string $schoolId) => DB::table('school_elevations')->insertGetId([
            'id' => (string) Str::uuid7(),
            'actor_user_id' => $actor->id,
            'school_id' => $schoolId,
            'authority_type' => 'platform',
            'reason_code' => 'operational_support',
            'status' => 'active',
            'started_at' => now(),
            'expires_at' => now()->addMinutes(30),
        ]);

        foreach (['provisioning', 'suspended', 'archived'] as $status) {
            $school = $this->createSchool(['status' => $status]);
            $this->assertRejected(fn () => $insert($school->id), 'the target School is not active');
        }

        $id = $insert($active->id);
        DB::table('school_elevations')->where('id', $id)->update(['status' => 'terminated', 'ended_at' => now(), 'end_reason' => 'school_suspended', 'updated_at' => now()]);
        $this->assertSame('school_suspended', DB::table('school_elevations')->where('id', $id)->value('end_reason'));

        $other = $this->createUser();
        $second = DB::table('school_elevations')->insertGetId([
            'id' => (string) Str::uuid7(), 'actor_user_id' => $other->id, 'school_id' => $active->id, 'authority_type' => 'platform',
            'reason_code' => 'operational_support', 'status' => 'active', 'started_at' => now(), 'expires_at' => now()->addMinutes(30),
        ]);
        $this->assertRejected(fn () => DB::table('school_elevations')->where('id', $second)->update(['status' => 'terminated', 'ended_at' => now(), 'end_reason' => 'school_archived']), 'school_elevations_end_check');
    }

    #[Test]
    public function every_existing_fixture_path_creates_an_operational_school_explicitly(): void
    {
        $this->assertSame('active', $this->createSchool()->status);
        $this->assertSame('provisioning', School::factory()->provisioning()->create()->status);
        $this->assertSame('suspended', School::factory()->suspended()->create()->status);
    }

    private function assertRejected(callable $statement, string $needle): void
    {
        try {
            DB::transaction(fn () => $statement());
        } catch (QueryException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());

            return;
        }

        $this->fail("Expected the database to reject the statement ({$needle}).");
    }
}
