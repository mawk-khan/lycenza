<?php

use App\Models\School;
use App\Support\Retention\RetentionExpiry;
use App\Support\Retention\StudentRetention;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Concurrency\HeldTransaction;

// Standalone bootstrap script for StudentRetentionConcurrencyTest (E21.2D):
// one operation in a GENUINELY separate OS process.
//
// Usage:
//   php student-retention-op.php re-enroll  <schoolId> <studentId> <sectionId>
//   php student-retention-op.php reactivate <schoolId> <studentId>
//   php student-retention-op.php core-prune <schoolId> <cutoffDate>
//   php student-retention-op.php operational-prune <schoolId> <cutoffDate>   (E21.3B: deleted Library-loan units)
//   php student-retention-op.php record-authorization <schoolId> <studentId> <userId>   (E21.3B)

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = array_slice($argv, 1);
$operation = array_shift($args);
$context = $app->make(TenantContext::class);

try {
    $run = fn () => HeldTransaction::run(function () use ($app, $operation, $args, $context): string {
        $school = School::query()->findOrFail($args[0]);

        return match ($operation) {
            're-enroll' => $context->withSchool($school, function () use ($school, $args): string {
                $section = DB::table('sections')->where('id', $args[2])->first();
                DB::table('student_enrollments')->insert([
                    'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'student_id' => $args[1], 'academic_year_id' => $section->academic_year_id,
                    'campus_id' => $section->campus_id, 'grade_level_id' => $section->grade_level_id, 'section_id' => $section->id,
                    'roll_number' => 'R'.random_int(1000, 9999), 'status' => 'active', 'starts_on' => '2027-04-01', 'created_at' => now(), 'updated_at' => now(),
                ]);

                return 're-enrolled';
            }),
            'reactivate' => $context->withSchool($school, function () use ($args): string {
                DB::table('students')->where('id', $args[1])->update(['status' => 'active', 'updated_at' => now()]);

                return 'reactivated';
            }),
            'core-prune' => 'deleted:'.$app->make(StudentRetention::class)->core($school, $args[1], null, 100, false)['deleted'],
            'operational-prune' => 'deleted:'.$app->make(StudentRetention::class)->operational($school, $args[1], 100, false)['student_library_loan']['deleted'],
            'record-authorization' => $context->withSchool($school, function () use ($school, $args): string {
                DB::table('student_processing_authorizations')->insert([
                    'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'student_id' => $args[1], 'purpose' => 'academic_records',
                    'basis_type' => 'statutory_school_purpose', 'status' => 'recorded', 'recorded_at' => now(), 'recorded_by_user_id' => $args[2],
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                return 'recorded';
            }),
            default => throw new InvalidArgumentException("unknown operation {$operation}"),
        };
    });
    // E21-RH.6: the retention operations run as the retention identity, their held transaction on that
    // connection (the unit transactions nest in it); the racing writes stay ordinary runtime writes.
    echo in_array($operation, ['core-prune', 'operational-prune'], true) ? DB::usingConnection(RetentionExpiry::PRIVILEGED_CONNECTION, $run) : $run();
} catch (Throwable $e) {
    echo 'rejected:'.class_basename($e);
}
