<?php

namespace Tests\Feature\Postgres;

use App\Models\SchoolElevation;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0N.3 (ADR 0044 section 4): what the DATABASE enforces for
 * school_elevations, independent of application code -- run as the
 * runtime role, each violation inside its own savepoint.
 */
class SchoolElevationDatabaseInvariantsTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function at_most_one_active_elevation_per_actor(): void
    {
        [$actor, $school] = [$this->createUser(), $this->createSchool()];
        $this->insertElevation($actor->id, $school->id);

        $this->assertRejected(fn () => $this->insertElevation($actor->id, $this->createSchool()->id), 'school_elevations_one_active_per_actor');

        // A finished one does not count.
        DB::table('school_elevations')->where('actor_user_id', $actor->id)->update(['status' => 'ended', 'ended_at' => now(), 'end_reason' => 'exited']);
        $this->insertElevation($actor->id, $school->id);
        $this->assertSame(2, DB::table('school_elevations')->where('actor_user_id', $actor->id)->count());
    }

    #[Test]
    public function codes_and_the_thirty_minute_maximum_are_database_checked(): void
    {
        [$actor, $school] = [$this->createUser(), $this->createSchool()];

        $this->assertRejected(fn () => $this->insertElevation($actor->id, $school->id, ['reason_code' => 'other']), 'school_elevations_reason_code_check');
        $this->assertRejected(fn () => $this->insertElevation($actor->id, $school->id, ['status' => 'paused']), 'violates check constraint "school_elevations_');
        $this->assertRejected(fn () => $this->insertElevation($actor->id, $school->id, ['expires_at' => now()->addMinutes(30)->addSecond()]), 'school_elevations_lifetime_check');
        $this->assertRejected(fn () => $this->insertElevation($actor->id, $school->id, ['expires_at' => now()]), 'school_elevations_lifetime_check');
        $this->assertRejected(fn () => $this->insertElevation($actor->id, $school->id, ['status' => 'ended', 'ended_at' => now(), 'end_reason' => 'expired']), 'school_elevations_end_check');
        $this->assertRejected(fn () => $this->insertElevation($actor->id, $school->id, ['end_reason' => 'exited']), 'school_elevations_end_check');
    }

    #[Test]
    public function a_finished_elevation_never_becomes_active_again_and_core_fields_never_change(): void
    {
        [$actor, $school] = [$this->createUser(), $this->createSchool()];
        $id = $this->insertElevation($actor->id, $school->id);

        $this->assertRejected(fn () => DB::table('school_elevations')->where('id', $id)->update(['expires_at' => now()->addMinutes(10)]), 'may only be finished');
        $this->assertRejected(fn () => DB::table('school_elevations')->where('id', $id)->update(['school_id' => $this->createSchool()->id, 'status' => 'ended', 'ended_at' => now(), 'end_reason' => 'exited']), 'immutable');

        DB::table('school_elevations')->where('id', $id)->update(['status' => 'expired', 'ended_at' => now(), 'end_reason' => 'expired']);

        $this->assertRejected(fn () => DB::table('school_elevations')->where('id', $id)->update(['status' => 'active', 'ended_at' => null, 'end_reason' => null]), 'finished elevation is immutable');
        $this->assertRejected(fn () => DB::table('school_elevations')->where('id', $id)->update(['end_reason' => 'expired', 'updated_at' => now()]), 'finished elevation is immutable');
        $this->assertSame('expired', DB::table('school_elevations')->where('id', $id)->value('status'));
    }

    #[Test]
    public function the_runtime_role_cannot_delete_evidence_and_a_school_with_history_cannot_be_deleted(): void
    {
        [$actor, $school] = [$this->createUser(), $this->createSchool()];
        $id = $this->insertElevation($actor->id, $school->id);

        $this->assertRejected(fn () => DB::table('school_elevations')->where('id', $id)->delete(), 'permission denied');
        $this->assertRejected(fn () => DB::table('schools')->where('id', $school->id)->delete(), 'school_elevations');
        $this->assertTrue(DB::table('school_elevations')->where('id', $id)->exists());
    }

    #[Test]
    public function the_table_is_platform_owned_without_rls_and_school_audit_rows_reference_it(): void
    {
        $rls = DB::connection('pgsql_admin')->selectOne("select relrowsecurity from pg_class where relname = 'school_elevations' and relnamespace = 'public'::regnamespace");
        $this->assertFalse($rls->relrowsecurity);

        $fk = DB::connection('pgsql_admin')->selectOne(
            "select count(*) as c from pg_constraint where conrelid = 'school_audit_events'::regclass and confrelid = 'school_elevations'::regclass and contype = 'f'",
        );
        $this->assertSame(1, (int) $fk->c);

        $this->assertNotContains(BelongsToSchool::class, class_uses_recursive(SchoolElevation::class));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertElevation(string $actorId, string $schoolId, array $overrides = []): string
    {
        $id = (string) Str::uuid7();
        $now = now()->startOfSecond();

        DB::table('school_elevations')->insert(array_merge([
            'id' => $id, 'actor_user_id' => $actorId, 'school_id' => $schoolId,
            'reason_code' => 'operational_support', 'status' => 'active',
            'started_at' => $now, 'expires_at' => $now->copy()->addMinutes(30),
            'created_at' => $now, 'updated_at' => $now,
        ], $overrides));

        return $id;
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
