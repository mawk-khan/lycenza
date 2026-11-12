<?php

namespace Tests\Feature\Postgres;

use App\Domain\Leave\Application\LeaveRequestService;
use App\Domain\Leave\Infrastructure\LeaveType;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Leave\Concerns\CreatesLeaveFixtures;
use Tests\TestCase;

/**
 * HR.R1-C28 (C28-N2): a guard function that names tables without a schema resolves them through the caller's
 * `search_path`, and PostgreSQL searches `pg_temp` first for relations unless the path places it last. The
 * runtime role holds TEMP (the database's default grant to PUBLIC), so a session running raw SQL as
 * `school_os_app` could create a temporary table with a guarded table's name and the guard would read it.
 * Every HR-family guard now pins `search_path` with `pg_temp` last (migration `2027_02_17_090000`), and
 * `platform:verify-database` keeps it so (`hr_family_trigger_functions`).
 *
 * The proof: `leave_types_freeze` refuses a paid/balance/half-day change on a type in use, by reading
 * `leave_requests`, `leave_policies` and `leave_ledger_entries`. Empty temporary tables of those names must
 * not make the type look unused.
 */
class GuardSearchPathShadowTest extends TestCase
{
    use CreatesLeaveFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-05 10:00:00');
    }

    #[Test]
    public function a_temporary_table_cannot_hide_what_a_guard_reads(): void
    {
        $w = $this->leaveWorld();
        $this->workingWeek($w['school'], $w['admin']);
        $type = $this->leaveType($w['school'], $w['admin'], 'SHD', true, false);
        $request = $this->submitLeave($w, '2026-10-12', '2026-10-12', 'full', null, null, $type);
        app(LeaveRequestService::class)->approve($w['school'], $request->id, $w['admin']);
        $this->assertSame('school_os_app', DB::connection('pgsql')->selectOne('select current_user as u')->u);

        try {
            DB::transaction(function () use ($w, $type): void {
                foreach (['leave_requests', 'leave_policies', 'leave_ledger_entries'] as $shadow) {
                    DB::statement("CREATE TEMPORARY TABLE {$shadow} (school_id uuid, leave_type_id uuid) ON COMMIT DROP");
                }
                app(TenantContext::class)->withSchool($w['school'], fn () => DB::table('leave_types')->where('id', $type->id)->update(['is_paid' => false]));
            });
            $this->fail('A temporary table shadowed the tables leave_types_freeze reads: the guard let a type in use change.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('leave_type_in_use', $e->getMessage());
        }

        $this->assertTrue((bool) app(TenantContext::class)->withSchool($w['school'], fn () => LeaveType::query()->whereKey($type->id)->value('is_paid')));
    }
}
