<?php

namespace Tests\Feature\Postgres;

use App\Domain\Attendance\Infrastructure\AttendanceRecord;
use App\Domain\Attendance\Infrastructure\AttendanceSession;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AssertsTenantRlsIsolation;
use Tests\Concerns\CreatesTeacherAttendanceFixtures;
use Tests\Concerns\CreatesTeacherDeliveryFixtures;
use Tests\Concerns\CreatesTeachingAssignmentFixtures;
use Tests\Feature\Attendance\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * E33 / TCH-L1 control 3 (ADR 0063 section 44; CLAUDE.md rule 28) at the raw
 * PostgreSQL layer: a register and its records (the same tables the owned
 * teacher path reads and writes) are invisible to another School's session and to a session with no tenant context, and another School can
 * neither rewrite nor insert them. Plain SQL as the runtime role, bypassing
 * every Application-layer check.
 */
class AttendanceRlsIsolationTest extends TestCase
{
    use AssertsTenantRlsIsolation, CreatesAttendanceFixtures, CreatesTeacherAttendanceFixtures, CreatesTeacherDeliveryFixtures, CreatesTeachingAssignmentFixtures;

    #[Test]
    public function registers_and_records_are_tenant_isolated_at_the_raw_sql_layer(): void
    {
        $w = $this->teacherAttendanceWorld();
        $this->adminRegister($w, $w['entry'], self::MONDAY);
        $other = $this->teacherAttendanceWorld(); // School B: a full world with no register of its own

        $this->assertTenantRlsIsolation([
            'attendance_sessions' => AttendanceSession::class,
            'attendance_records' => AttendanceRecord::class,
        ], $w['school']->id, $other['school']->id);
    }
}
