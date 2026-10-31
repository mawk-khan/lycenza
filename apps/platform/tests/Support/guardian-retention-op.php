<?php

use App\Domain\Admissions\Application\Retention\ConvertedApplicationRetentionService;
use App\Domain\Admissions\Application\Retention\TerminalApplicationRetentionService;
use App\Models\School;
use App\Support\Retention\GuardianRetention;
use App\Support\Retention\RetentionExpiry;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Concurrency\HeldTransaction;

// Standalone bootstrap script for AdmissionsGuardianRetentionConcurrencyTest
// (E21.3C): one operation in a GENUINELY separate OS process.
//
// Usage:
//   php guardian-retention-op.php link <schoolId> <guardianId> <studentId>
//   php guardian-retention-op.php account-link <schoolId> <guardianId> <membershipId> <userId>
//   php guardian-retention-op.php guardian-prune <schoolId> <cutoff>
//   php guardian-retention-op.php apply <schoolId> <applicantId> <yearId> <campusId> <gradeId>
//   php guardian-retention-op.php admissions-prune <schoolId> <cutoff>
//   php guardian-retention-op.php converted-purge <schoolId> <studentId> <applicantId>   (SIS 11.H1, L-3)

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
            'link' => $context->withSchool($school, function () use ($school, $args): string {
                DB::table('student_guardian_relationships')->insert([
                    'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'student_id' => $args[2], 'guardian_id' => $args[1],
                    'relationship_type' => 'mother', 'is_primary' => false, 'is_legal_guardian' => false, 'is_emergency_contact' => false,
                    'is_authorized_pickup' => false, 'created_at' => now(), 'updated_at' => now(),
                ]);

                return 'linked';
            }),
            'account-link' => $context->withSchool($school, function () use ($school, $args): string {
                DB::table('student_guardian_account_links')->insert([
                    'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'guardian_id' => $args[1], 'school_membership_id' => $args[2],
                    'status' => 'active', 'linked_by_user_id' => $args[3], 'linked_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);

                return 'account-linked';
            }),
            'guardian-prune' => 'deleted:'.$app->make(GuardianRetention::class)->prune($school, CarbonImmutable::parse($args[1], 'UTC'), null, 100, false)['deleted'],
            'apply' => $context->withSchool($school, function () use ($school, $args): string {
                DB::table('admission_applications')->insert([
                    'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'applicant_id' => $args[1], 'academic_year_id' => $args[2],
                    'campus_id' => $args[3], 'grade_level_id' => $args[4], 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
                ]);

                return 'applied';
            }),
            'admissions-prune' => 'deleted:'.$app->make(TerminalApplicationRetentionService::class)->prune($school, CarbonImmutable::parse($args[1], 'UTC'), 100, false, false)['deleted'],
            // The Student core participant's purge, as the core unit runs it: inside a declared retention unit.
            'converted-purge' => $app->make(RetentionExpiry::class)->retained('student_core', false, $school->id, ['purged' => 0, 'errors' => 0], fn (): array => $context->withSchool($school, function () use ($app, $school, $args): array {
                DB::transaction(fn () => $app->make(ConvertedApplicationRetentionService::class)->purge($school, $args[1], '2024-01-01'));

                return ['purged' => 1, 'errors' => 0];
            }), recordedBefore: '2024-01-01 00:00:00')['errors'] === 0
                ? 'purged:applicant-'.($context->withSchool($school, fn () => DB::table('applicants')->where('id', $args[2])->exists()) ? 'kept' : 'deleted')
                : 'rejected:unit-error',
            default => throw new InvalidArgumentException("unknown operation {$operation}"),
        };
    });
    // E21-RH.6: the retention operations run as the retention identity, their held transaction on that
    // connection (the unit transactions nest in it); the racing writes stay ordinary runtime writes.
    echo in_array($operation, ['guardian-prune', 'admissions-prune', 'converted-purge'], true) ? DB::usingConnection(RetentionExpiry::PRIVILEGED_CONNECTION, $run) : $run();
} catch (Throwable $e) {
    echo 'rejected:'.class_basename($e);
}
