<?php

namespace Tests\Feature\Postgres;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * HR.R1-C22 (WO-5, PAY-15, rule 143): the calendar migration's down() refuses once anything beyond the cutover
 * exists -- including a week scheduled at runtime, whose reason is NULL (a plain `<> 'cutover'` would miss it and
 * drop the School's scheduled change) and a withdrawn holiday. Each case runs the real down() in one admin
 * transaction that is always rolled back; the rows are planted with triggers off, so no School is needed.
 */
class StaffCalendarRollbackGuardTest extends TestCase
{
    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private function refuses(string $plant): void
    {
        $admin = DB::connection('pgsql_admin');
        $default = DB::getDefaultConnection();
        $admin->beginTransaction();
        try {
            $admin->statement("SET LOCAL session_replication_role = 'replica'");
            $admin->statement($plant);
            $admin->statement("SET LOCAL session_replication_role = 'origin'");

            DB::setDefaultConnection('pgsql_admin');
            $migration = require database_path('migrations/2027_02_15_090000_version_the_staff_calendar.php');
            try {
                $migration->down();
                $this->fail('down() dropped recorded calendar history');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('Refusing to roll back', $e->getMessage());
            }
            $this->assertTrue($admin->getSchemaBuilder()->hasTable('staff_working_week_versions'), 'refused before any DDL');
        } finally {
            DB::setDefaultConnection($default);
            $admin->rollBack();
        }
    }

    #[Test]
    public function a_week_scheduled_at_runtime_without_a_reason_stops_the_rollback(): void
    {
        $this->refuses("INSERT INTO staff_working_week_versions (id, school_id, effective_from, weekday_1, weekday_2, weekday_3, weekday_4, weekday_5, weekday_6, weekday_7, status, recorded_by_user_id, recorded_at)
            VALUES (gen_random_uuid(), gen_random_uuid(), DATE '2031-01-06', 'full', 'full', 'full', 'full', 'off', 'off', 'off', 'active', gen_random_uuid(), now())");
    }

    #[Test]
    public function a_withdrawn_holiday_stops_the_rollback(): void
    {
        $this->refuses("INSERT INTO staff_holidays (id, school_id, holiday_on, portion, name, created_by_user_id, status, recorded_at, withdrawn_by_user_id, withdrawn_at)
            VALUES (gen_random_uuid(), gen_random_uuid(), DATE '2031-01-07', 'full', 'Probe', gen_random_uuid(), 'withdrawn', now(), gen_random_uuid(), now())");
    }
}
