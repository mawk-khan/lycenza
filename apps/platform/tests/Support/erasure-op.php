<?php

use App\Models\School;
use App\Support\Retention\Erasure\ErasureCaseService;
use App\Support\Retention\RetentionExpiry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Concurrency\HeldTransaction;

// Standalone bootstrap script for ErasureConcurrencyTest (E21.2F): one
// operation in a GENUINELY separate OS process.
//
// Usage:
//   php erasure-op.php execute   <caseId>            (adopted periods: D7 7/25, D9 2/8)
//   php erasure-op.php re-enroll <schoolId> <studentId> <sectionId>
//   php erasure-op.php rehire    <schoolId> <employeeId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config([
    'retention.student_operational_years' => 7, 'retention.student_core_years' => 25,
    'retention.employee_ancillary_years' => 2, 'retention.employee_evidence_years' => 8,
    'retention.authority_history_years' => 7, 'retention.hold_school_ids' => [],
]);

$args = array_slice($argv, 1);
$operation = array_shift($args);
$context = $app->make(TenantContext::class);

// E21-RH.6: an erasure's purge units run as the retention identity on their own connection, while the case
// itself is a runtime write. Both transactions are held: the units nest (as savepoints) in the open retention
// transaction, which commits once the held runtime transaction is released.
$retention = $operation === 'execute' ? DB::connection(RetentionExpiry::PRIVILEGED_CONNECTION) : null;
// As a contender, the session that waits is the retention one: it carries the observed session name.
$sessionName = getenv('CONCURRENCY_SESSION_NAME');
if ($retention !== null && $sessionName !== false && $sessionName !== '') {
    $retention->statement('SET application_name TO '.$retention->getPdo()->quote($sessionName));
    putenv('CONCURRENCY_SESSION_NAME');
}
$retention?->beginTransaction();

try {
    echo HeldTransaction::run(function () use ($app, $operation, $args, $context): string {
        return match ($operation) {
            'execute' => 'outcome:'.implode(',', array_map(fn ($c) => $c->category.'='.$c->outcome, $app->make(ErasureCaseService::class)->execute($args[0], false))),
            're-enroll' => $context->withSchool(School::query()->findOrFail($args[0]), function () use ($args): string {
                $section = DB::table('sections')->where('id', $args[2])->first();
                DB::table('student_enrollments')->insert([
                    'id' => (string) Str::uuid7(), 'school_id' => $args[0], 'student_id' => $args[1], 'academic_year_id' => $section->academic_year_id,
                    'campus_id' => $section->campus_id, 'grade_level_id' => $section->grade_level_id, 'section_id' => $section->id,
                    'roll_number' => 'R'.random_int(1000, 9999), 'status' => 'active', 'starts_on' => '2027-04-01', 'created_at' => now(), 'updated_at' => now(),
                ]);

                return 're-enrolled';
            }),
            'rehire' => $context->withSchool(School::query()->findOrFail($args[0]), function () use ($args): string {
                DB::table('employment_records')->insert([
                    'id' => (string) Str::uuid7(), 'school_id' => $args[0], 'employee_id' => $args[1], 'employment_type' => 'permanent',
                    'starts_on' => '2033-01-01', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
                ]);

                return 'rehired';
            }),
            default => throw new InvalidArgumentException("unknown operation {$operation}"),
        };
    });
    $retention?->commit();
} catch (Throwable $e) {
    $retention?->rollBack();
    echo 'rejected:'.class_basename($e);
}
