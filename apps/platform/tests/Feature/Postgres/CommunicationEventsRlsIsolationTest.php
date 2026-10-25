<?php

namespace Tests\Feature\Postgres;

use App\Support\Tenancy\TenantRls;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Feature\Communications\Concerns\CreatesCommunicationEventFixtures;
use Tests\TestCase;

/**
 * ORG.9D-3 (ADR 0076 §75; mandatory per root CLAUDE.md rule 28): raw-SQL
 * tenant isolation for `communication_events` -- forced RLS, no rows
 * without a School context, none of another School's rows, and no row
 * claiming another School.
 */
class CommunicationEventsRlsIsolationTest extends TestCase
{
    use CreatesCommunicationEventFixtures, CreatesTenancyFixtures;

    #[Test]
    public function the_table_is_forced_rls_and_isolates_schools_at_the_sql_layer(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-01T06:00:00Z'));
        $row = DB::connection('pgsql_admin')->selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class where relname = ? and relnamespace = ?::regnamespace',
            ['communication_events', 'public'],
        );
        $this->assertTrue($row->relrowsecurity);
        $this->assertTrue($row->relforcerowsecurity);

        $schoolB = $this->createSchool();
        $eventB = $this->draftEvent($schoolB, $this->createUserWithCapabilities($schoolB, ['communications.announce']));
        $schoolA = $this->createSchool();
        $preparerA = $this->createUserWithCapabilities($schoolA, ['communications.announce']);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);
        $this->assertSame(0, (int) DB::connection('pgsql')->selectOne('select count(*) as c from communication_events')->c, 'No School context: no rows.');

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolA->id]);
        $this->assertCount(0, DB::connection('pgsql')->select('select id from communication_events where id = ?', [$eventB->id]));
        $this->assertSame(0, DB::connection('pgsql')->table('communication_events')->where('id', $eventB->id)->update(['title' => 'Hijacked']));
        $this->assertThrows(fn () => DB::connection('pgsql')->transaction(fn () => DB::connection('pgsql')->table('communication_events')->insert([
            'id' => (string) new UuidV7, 'school_id' => $schoolB->id, 'created_by_user_id' => $preparerA->id, 'title' => 'X', 'body' => 'Y',
            'date_mode' => 'all_day', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-01', 'audience_type' => 'school_wide', 'status' => 'draft',
            'created_at' => now(), 'updated_at' => now(),
        ])), QueryException::class);
    }
}
