<?php

use App\Domain\Students\Application\Retention\StudentRecordRetentionService;
use App\Models\School;
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

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = array_slice($argv, 1);
$operation = array_shift($args);
$context = $app->make(TenantContext::class);

try {
    echo HeldTransaction::run(function () use ($app, $operation, $args, $context): string {
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
            'core-prune' => 'deleted:'.$app->make(StudentRecordRetentionService::class)->pruneCore($school, $args[1], null, 100, false)['deleted'],
            default => throw new InvalidArgumentException("unknown operation {$operation}"),
        };
    });
} catch (Throwable $e) {
    echo 'rejected:'.class_basename($e);
}
