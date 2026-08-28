<?php

namespace Tests\Feature\Postgres;

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 10C (mandatory per root CLAUDE.md rule 28).
 */
class VisitorsRlsIsolationTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    #[Test]
    public function the_table_has_rls_enabled_and_forced(): void
    {
        $row = DB::connection('pgsql_admin')->selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class '.
            'where relname = ? and relnamespace = ?::regnamespace',
            ['visitors', 'public'],
        );

        $this->assertNotNull($row);
        $this->assertTrue($row->relrowsecurity);
        $this->assertTrue($row->relforcerowsecurity);
    }

    #[Test]
    public function no_school_context_sees_zero_rows(): void
    {
        $school = $this->createSchool();
        $this->createVisitor($school);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from visitors')->c;
        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function school_a_cannot_select_school_bs_visitor(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $visitorB = $this->createVisitor($schoolB);

        $this->setSchool($schoolA->id);

        $rows = DB::connection('pgsql')->select('select id from visitors where id = ?', [$visitorB->id]);
        $this->assertCount(0, $rows);
    }

    #[Test]
    public function school_a_cannot_insert_a_visitor_for_school_b(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();

        $this->setSchool($schoolA->id);

        $rejected = false;

        try {
            DB::connection('pgsql')->transaction(function () use ($schoolB): void {
                DB::connection('pgsql')->table('visitors')->insert([
                    'id' => (string) new UuidV7,
                    'school_id' => $schoolB->id,
                    'full_name' => 'Cross-School Visitor',
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'RLS WITH CHECK must reject a row written under School A context but claiming School B.');
    }

    #[Test]
    public function school_a_cannot_update_school_bs_visitor(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $visitorB = $this->createVisitor($schoolB);

        $this->setSchool($schoolA->id);

        $updated = DB::connection('pgsql')->update(
            'update visitors set status = ? where id = ?',
            ['inactive', $visitorB->id],
        );

        $this->assertSame(0, $updated);
    }

    /**
     * THE historical-integrity proof: `visitor_visits.visitor_id`
     * RESTRICTs on delete (docs/modules/VISITOR.md "Historical
     * integrity") -- a Visitor referenced by any historical Visit,
     * checked-in or checked-out, cannot be physically deleted, by
     * ANY means. The delete attempt uses the SAME `pgsql` connection/
     * session as the fixture writes above (never `pgsql_admin`) --
     * mirrors
     * VisitorVisitsRlsIsolationTest::the_database_rejects_a_second_active_visit_for_the_same_visitor's
     * exact pattern (itself mirroring
     * TransportStudentAssignmentsRlsIsolationTest's precedent): a
     * separate `pgsql_admin` connection cannot see this test's still-
     * uncommitted (`DatabaseTransactions`-wrapped) fixture rows at
     * all, which would make the delete silently "succeed" against a
     * row `pgsql_admin` never actually saw a reference to -- not a
     * genuine proof of anything. Using the raw `pgsql` connection
     * directly (rather than an Eloquent `delete()`) still proves the
     * database constraint itself, independent of any application-
     * layer safeguard, and is what a future maintenance script, a raw
     * SQL admin path, or a later refactor reaching for
     * `Visitor::destroy()` would actually hit.
     */
    #[Test]
    public function a_visitor_referenced_by_a_historical_visit_cannot_be_deleted(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $visitor = $this->createVisitor($school);
        $visit = $this->createVisitorVisit($visitor, $campus, ['status' => 'checked_out', 'checked_out_at' => now()]);

        $this->setSchool($school->id);

        $rejected = false;

        try {
            DB::connection('pgsql')->transaction(function () use ($visitor): void {
                DB::connection('pgsql')->table('visitors')->where('id', $visitor->id)->delete();
            });
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'visitor_visits_visitor_id_school_id_foreign must RESTRICT deletion of a Visitor referenced by a historical Visit.');

        $visitorStillExists = DB::connection('pgsql')->table('visitors')->where('id', $visitor->id)->exists();
        $this->assertTrue($visitorStillExists, 'The Visitor row must survive the rejected deletion attempt.');

        $visitStillExists = DB::connection('pgsql')->table('visitor_visits')->where('id', $visit->id)->exists();
        $this->assertTrue($visitStillExists, 'The historical Visit row must survive the rejected deletion attempt.');
    }
}
