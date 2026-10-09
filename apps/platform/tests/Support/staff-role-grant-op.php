<?php

use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Concurrency\HeldTransaction;

// SR.1 (ADR 0071 §11.6, §14): one raw database operation in a GENUINELY
// separate OS process, for StaffRoleGrantorRaceTest.
//
//   php staff-role-grant-op.php revoke-issuer <schoolId> <issuerMembershipId>
//   php staff-role-grant-op.php grant <schoolId> <targetMembershipId> <roleId> <issuerUserId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = array_slice($argv, 1);
$operation = array_shift($args);
$context = $app->make(TenantContext::class);

try {
    echo HeldTransaction::run(function () use ($operation, $args, $context): string {
        $school = School::query()->findOrFail($args[0]);

        return $context->withSchool($school, fn (): string => match ($operation) {
            'revoke-issuer' => 'revoked:'.DB::table('membership_role_assignments')
                ->where('school_membership_id', $args[1])->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'revocation_reason' => 'revoked']),
            'grant' => (function () use ($school, $args): string {
                DB::table('membership_role_assignments')->insert([
                    'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'school_membership_id' => $args[1],
                    'role_id' => $args[2], 'assigned_by_user_id' => $args[3], 'created_at' => now(), 'updated_at' => now(),
                ]);

                return 'granted';
            })(),
            default => throw new InvalidArgumentException("unknown operation {$operation}"),
        });
    });
} catch (Throwable $e) {
    echo 'rejected:'.(str_contains($e->getMessage(), 'does not hold school.roles.manage') ? 'issuer_no_longer_covers' : class_basename($e));
}
