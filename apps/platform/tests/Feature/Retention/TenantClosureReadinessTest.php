<?php

namespace Tests\Feature\Retention;

use App\Models\School;
use App\Support\Retention\TenantClosureReadiness;
use App\Support\Retention\TenantRetentionCatalog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesFeesFixtures;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * E21.2F (E21-D11): closure readiness is read-only and never reports a
 * School purge-ready while anything retained, unresolved, blocked, held or
 * unclassified remains. Today it is never purge-ready at all: no tenant
 * purge exists or is authorized.
 */
class TenantClosureReadinessTest extends TestCase
{
    use CreatesFeesFixtures, CreatesFinanceFixtures, CreatesTenancyFixtures;

    private function report(School $school): array
    {
        return app(TenantClosureReadiness::class)->report($school);
    }

    /** @return array<string, array<string, mixed>> */
    private function byCategory(array $report): array
    {
        return array_column($report['categories'], null, 'category');
    }

    #[Test]
    public function the_catalog_classifies_exactly_the_live_tenant_tables(): void
    {
        $live = array_column(DB::select(
            "SELECT c.table_name FROM information_schema.columns c
               JOIN pg_class t ON t.relname = c.table_name AND t.relkind = 'r' AND t.relnamespace = 'public'::regnamespace
              WHERE c.table_schema = 'public' AND c.column_name = 'school_id' ORDER BY 1",
        ), 'table_name');
        $catalog = array_keys(TenantRetentionCatalog::tables());
        sort($catalog);

        $this->assertSame($live, $catalog, 'a tenant table changed: classify it in TenantRetentionCatalog');
        $this->assertCount(count($catalog), array_unique($catalog), 'each table in exactly one category');
    }

    #[Test]
    public function an_open_school_with_finance_unresolved_and_running_categories_is_never_purge_ready(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $this->createGuardian($school);
        $this->assessCharge($school, $student, $this->createAcademicYear($school), $this->createLedgerAccount($school, ['type' => 'asset']), $this->createLedgerAccount($school, ['type' => 'income']), '100.00');

        $report = $this->report($school);
        $categories = $this->byCategory($report);

        $this->assertFalse($report['purge_ready']);
        foreach (['school_not_closed', 'd8_finance_retention_not_enabled', 'retention_mechanism_pending', 'retention_periods_running', 'final_ratification_pending', 'no_tenant_purge_authorized'] as $gate) {
            $this->assertContains($gate, $report['gates']);
        }
        $this->assertSame('retained', $categories['finance_ledger']['outcome'], 'E21.3A2: D8 is an adopted, implemented period');
        $this->assertSame(TenantRetentionCatalog::TENANT_LIFETIME, $categories['finance_period_evidence']['outcome']);
        $this->assertSame(TenantRetentionCatalog::MECHANISM_PENDING, $categories['guardians']['outcome']);
        $this->assertSame('retained', $categories['student_core']['outcome']);
        $this->assertSame('empty', $categories['hr_evidence']['outcome']);
    }

    #[Test]
    public function an_empty_closed_school_still_waits_for_ratification_and_an_authorized_purge(): void
    {
        $school = $this->createSchool(['status' => 'suspended']);
        DB::table('schools')->where('id', $school->id)->update(['closed_at' => now(), 'closure_reason' => 'ceased_operations']);

        $report = $this->report($school->fresh());

        $this->assertTrue($report['closed']);
        $this->assertFalse($report['purge_ready']);
        $this->assertSame(TenantClosureReadiness::PERMANENT_GATES, $report['gates']);
    }

    #[Test]
    public function a_hold_is_reported_and_an_adopted_category_knows_its_earliest_day(): void
    {
        $school = $this->createSchool();
        config(['retention.hold_school_ids' => [$school->id], 'retention.audit_years' => 7]);
        app(TenantContext::class)->withSchool($school, fn () => DB::table('school_audit_events')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'occurred_at' => '2026-03-15 10:00:00',
            'event_type' => 'test.event', 'metadata' => '{}',
        ]));

        $report = $this->report($school);

        $this->assertTrue($report['held']);
        $this->assertContains('legal_hold', $report['gates']);
        $this->assertSame('2033-03-16', $this->byCategory($report)['audit']['not_before']);
    }

    #[Test]
    public function an_unclassified_tenant_table_fails_closed(): void
    {
        $school = $this->createSchool();
        $admin = DB::connection('pgsql_admin');
        $admin->statement('CREATE TABLE retention_unclassified_probe (id uuid PRIMARY KEY, school_id uuid)');

        try {
            $report = $this->report($school);
            $this->assertContains('unclassified_tables', $report['gates']);
            $this->assertSame(['retention_unclassified_probe'], $report['unclassified']);
        } finally {
            $admin->statement('DROP TABLE IF EXISTS retention_unclassified_probe');
        }
    }

    #[Test]
    public function the_status_command_is_read_only(): void
    {
        $school = $this->createSchool();
        $this->createStudent($school);
        $before = DB::table('students')->count();

        $this->artisan('platform:school-closure-status', ['school' => $school->id])
            ->expectsOutputToContain('purge-ready: NO')
            ->expectsOutputToContain('final_ratification_pending')
            ->assertSuccessful();

        $this->assertSame($before, DB::table('students')->count());
        $this->assertTrue(DB::table('schools')->where('id', $school->id)->exists());
    }

    #[Test]
    public function every_category_has_a_decision_and_every_pending_mechanism_names_its_checkpoint(): void
    {
        // E21.2G: no category is left without a project decision. A decided
        // period without a mechanism names the follow-up checkpoint that ships it.
        foreach (TenantRetentionCatalog::CATEGORIES as $category => [$status, $decision]) {
            $this->assertNotSame(TenantRetentionCatalog::POLICY_UNRESOLVED, $status, "{$category} has no decision");
            if ($status === TenantRetentionCatalog::MECHANISM_PENDING) {
                $this->assertMatchesRegularExpression('/E21\.3[B-E]/', $decision, "{$category} must name its follow-up checkpoint");
            }
            if ($status === TenantRetentionCatalog::TECHNICAL_BLOCKER) {
                $this->assertSame('payroll_ledger', $category, 'E21.3A2: the only technical blocker left is the D8 x D9 payroll ledger');
                $this->assertStringContainsString('D9', $decision);
            }
        }
    }
}
