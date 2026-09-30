<?php

namespace Tests\Feature\Postgres;

use App\Support\Tenancy\TenantRls;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as RouteFacade;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Feature\Platform\Elevation\ElevationTestHelpers;
use Tests\TestCase;

/**
 * Phase 0N.3 (ADR 0044 sections 3 and 19): an elevated request on an
 * explicitly opted-in route (test-only; none exists in production) runs
 * with exactly one School in the ordinary GUC, under the unprivileged
 * runtime role, and RLS shows that School's rows only -- raw SQL, not the
 * Eloquent scope. CLAUDE.md rule 28's raw-SQL pattern (RawIsolationTest).
 */
class ElevationRlsIsolationTest extends TestCase
{
    use CreatesTenancyFixtures, ElevationTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        RouteFacade::middleware(['web', 'auth', 'school-context:elevated'])->get('/__test/elevation-raw', fn () => response()->json([
            'user' => DB::selectOne('select current_user as u')->u,
            'guc' => DB::selectOne("select current_setting('app.current_school_id', true) as v")->v,
            'studentSchools' => array_map(fn ($r) => $r->school_id, DB::select('select distinct school_id from students')),
            'campusSchools' => array_map(fn ($r) => $r->school_id, DB::select('select distinct school_id from campuses')),
            'students' => (int) DB::selectOne('select count(*) as c from students')->c,
        ]));
    }

    #[Test]
    public function an_elevated_request_sees_exactly_one_school_through_forced_rls(): void
    {
        $target = $this->createSchool();
        $other = $this->createSchool();
        $this->createStudent($target);
        $this->createStudent($target);
        $this->createStudent($other);
        $this->createCampus($target);
        $this->createCampus($other);
        $admin = $this->platformAdmin();
        $this->elevate($admin, $target);

        $json = $this->getJson('/__test/elevation-raw')->assertOk()->json();

        $this->assertSame('school_os_app', $json['user']);
        $this->assertSame($target->id, $json['guc']);
        $this->assertSame([$target->id], $json['studentSchools']);
        $this->assertSame([$target->id], $json['campusSchools']);
        $this->assertSame(2, $json['students']);

        // The GUC is reset afterwards: no context, no rows.
        DB::statement('RESET '.TenantRls::SESSION_VAR);
        $this->assertSame(0, (int) DB::selectOne('select count(*) as c from students')->c);
    }

    #[Test]
    public function the_runtime_role_still_cannot_bypass_rls_and_every_tenant_table_stays_forced(): void
    {
        $role = DB::connection('pgsql_admin')->selectOne('select rolsuper, rolbypassrls from pg_roles where rolname = ?', ['school_os_app']);
        $this->assertFalse($role->rolsuper);
        $this->assertFalse($role->rolbypassrls);

        $unforced = DB::connection('pgsql_admin')->select(
            "select relname from pg_class where relrowsecurity and not relforcerowsecurity and relnamespace = 'public'::regnamespace",
        );
        $this->assertSame([], $unforced);

        // 144 through Phase 0O.8A; + email_messages and email_submission_attempts (Phase 0O.9A, ADR 0055);
        // + staff_account_invitations and staff_account_invitation_roles (Phase 0O.12B, ADR 0059);
        // + fee_heads, fee_settings, fee_structures, fee_structure_lines,
        // fee_structure_installments and fee_optional_selections (FEE.1, ADR 0062);
        // + fee_assessment_runs, fee_assessment_run_items and fee_assessments (FEE.2, ADR 0062);
        // + fee_concessions and fee_adjustments (FEE.3, ADR 0062);
        // + payment_receipt_counters and payment_receipts (FEE.4, ADR 0062);
        // + fee_late_fee_rules, late_fee_runs, late_fee_run_items and late_fee_assessments (FEE.5, ADR 0062);
        // + teaching_assignments (TCH.2, ADR 0063).
        $this->assertSame(166, (int) DB::connection('pgsql_admin')->selectOne(
            "select count(*) as c from pg_class where relrowsecurity and relforcerowsecurity and relnamespace = 'public'::regnamespace",
        )->c, 'No tenant table gained or lost RLS in Phase 0N.3.');
    }
}
