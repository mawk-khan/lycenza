<?php

namespace Tests\Feature\Postgres;

use App\Support\Audit\AuditRecorder;
use App\Support\Audit\SchoolAuditEventReader;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRls;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0L.4 -- the School audit ledger now has a reader
 * (SchoolAuditEventReader, the Compliance audit-log review's source), so
 * its isolation is proven at the raw-SQL/RLS layer on the runtime
 * `pgsql` connection, independent of Eloquent (CLAUDE.md rule 28), and
 * through the reader itself.
 */
class SchoolAuditEventsRlsIsolationTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function recordIn($school, string $eventType): void
    {
        app(TenantContext::class)->withSchool($school, fn () => app(AuditRecorder::class)->school($school, $eventType));
    }

    #[Test]
    public function the_table_has_rls_enabled_and_forced(): void
    {
        $row = DB::connection('pgsql_admin')->selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class '.
            "where relname = 'school_audit_events' and relnamespace = 'public'::regnamespace",
        );

        $this->assertTrue($row->relrowsecurity);
        $this->assertTrue($row->relforcerowsecurity);
    }

    #[Test]
    public function raw_reads_see_only_the_context_school_and_nothing_without_context(): void
    {
        $a = $this->createSchool();
        $b = $this->createSchool();
        $this->recordIn($a, 'test.rls.a');
        $this->recordIn($b, 'test.rls.b');

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $a->id]);
        $types = array_column(DB::connection('pgsql')->select("select event_type from school_audit_events where event_type like 'test.rls.%'"), 'event_type');
        $this->assertSame(['test.rls.a'], $types);

        // Even naming School B explicitly returns nothing under A's context.
        $this->assertSame(0, (int) DB::connection('pgsql')->selectOne('select count(*) as c from school_audit_events where school_id = ?', [$b->id])->c);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);
        $this->assertSame(0, (int) DB::connection('pgsql')->selectOne('select count(*) as c from school_audit_events')->c);
    }

    #[Test]
    public function the_reader_returns_only_the_given_schools_events(): void
    {
        $a = $this->createSchool();
        $b = $this->createSchool();
        $this->recordIn($a, 'test.reader.a');
        $this->recordIn($b, 'test.reader.b');

        // Ambient context set to B must not leak into a read for A.
        $types = app(TenantContext::class)->withSchool($b, fn () => array_map(
            fn ($entry) => $entry->eventType,
            app(SchoolAuditEventReader::class)->page($a)->entries,
        ));

        $this->assertSame(['test.reader.a'], $types);
    }
}
