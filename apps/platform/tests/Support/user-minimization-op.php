<?php

use App\Models\School;
use App\Support\Retention\Erasure\ErasureCaseService;
use App\Support\Retention\Erasure\ErasureCategory;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Concurrency\HeldTransaction;

// Standalone bootstrap script for UserMinimizationConcurrencyTest (E21.4):
// one operation in a GENUINELY separate OS process.
//
// Usage:
//   php user-minimization-op.php minimize    <caseId>
//   php user-minimization-op.php membership  <userId> <schoolId>
//   php user-minimization-op.php link        <userId> <schoolId> <employeeId>
//   php user-minimization-op.php grant       <userId> <grantorId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = array_slice($argv, 1);
$operation = array_shift($args);
$context = $app->make(TenantContext::class);

try {
    echo HeldTransaction::run(fn (): string => match ($operation) {
        'minimize' => implode(',', array_map(
            fn (ErasureCategory $c) => "{$c->outcome}:{$c->reason}",
            array_values(array_filter($app->make(ErasureCaseService::class)->execute($args[0], false), fn (ErasureCategory $c) => $c->category === 'user_identity')),
        )),
        'membership' => (function () use ($args): string {
            DB::table('school_memberships')->insert(['id' => (string) Str::uuid7(), 'user_id' => $args[0], 'school_id' => $args[1], 'status' => 'active', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

            return 'joined';
        })(),
        'link' => $context->withSchool(School::query()->findOrFail($args[1]), function () use ($args): string {
            DB::table('employees')->where('id', $args[2])->update(['user_id' => $args[0]]);

            return 'linked';
        }),
        'grant' => (function () use ($args): string {
            DB::table('platform_role_assignments')->insert([
                'id' => (string) Str::uuid7(), 'user_id' => $args[0], 'role_id' => DB::table('roles')->where('key', 'platform_auditor')->value('id'),
                'granted_by_user_id' => $args[1], 'granted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);

            return 'granted';
        })(),
        default => throw new InvalidArgumentException("unknown operation {$operation}"),
    });
} catch (Throwable $e) {
    echo 'rejected:'.class_basename($e);
}
