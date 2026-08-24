<?php

namespace Tests\Feature\Postgres;

use App\Support\Tenancy\TenantRls;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5A.4 §9/§37 (mandatory per root CLAUDE.md rule 28): proven at
 * the raw-SQL level against real PostgreSQL, independent of Eloquent,
 * mirroring CommunicationAnnouncementsRlsIsolationTest's pattern
 * exactly.
 */
class CommunicationTemplatesRlsIsolationTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    #[Test]
    public function the_templates_table_has_rls_enabled_and_forced(): void
    {
        $row = DB::connection('pgsql_admin')->selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class '.
            'where relname = ? and relnamespace = ?::regnamespace',
            ['communication_templates', 'public'],
        );

        $this->assertNotNull($row, 'communication_templates must exist');
        $this->assertTrue($row->relrowsecurity, 'communication_templates must have RLS enabled');
        $this->assertTrue($row->relforcerowsecurity, 'communication_templates must FORCE RLS');
    }

    #[Test]
    public function no_school_context_sees_zero_templates(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $this->createTemplate($school, $creator);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from communication_templates')->c;
        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function school_a_cannot_read_school_bs_template(): void
    {
        $schoolA = $this->createSchool();
        [$creatorB, $schoolB] = $this->createSchoolAdmin('school_admin');
        $templateB = $this->createTemplate($schoolB, $creatorB);

        $this->setSchool($schoolA->id);

        $rows = DB::connection('pgsql')->select('select id from communication_templates where id = ?', [$templateB->id]);
        $this->assertCount(0, $rows, "School A must not see School B's template");
    }

    #[Test]
    public function cross_school_writes_to_a_template_affect_zero_rows(): void
    {
        $schoolA = $this->createSchool();
        [$creatorB, $schoolB] = $this->createSchoolAdmin('school_admin');
        $templateB = $this->createTemplate($schoolB, $creatorB);

        $this->setSchool($schoolA->id);

        $this->assertSame(0, DB::connection('pgsql')->update("update communication_templates set status = 'inactive' where id = ?", [$templateB->id]));
    }
}
