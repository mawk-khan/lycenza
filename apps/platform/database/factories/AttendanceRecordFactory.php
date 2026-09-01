<?php

namespace Database\Factories;

use App\Domain\Attendance\Infrastructure\AttendanceRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceRecord>
 *
 * Defaults only `status`. The structural context columns
 * (`academic_year_id`/`campus_id`/`grade_level_id`/`section_id`) must
 * be supplied by the caller and must match BOTH the Session and the
 * StudentEnrollment -- the two composite FKs on `attendance_records`
 * reject any other combination, which is exactly the guarantee
 * `Tests\Feature\Postgres\AttendanceRecordsContextIntegrityTest` proves.
 * A factory cannot and must not paper over that.
 */
class AttendanceRecordFactory extends Factory
{
    protected $model = AttendanceRecord::class;

    public function definition(): array
    {
        return [
            'status' => 'present',
        ];
    }
}
