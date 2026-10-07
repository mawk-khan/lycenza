<?php

use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Concurrency\HeldTransaction;

// S6 (ADR 0068 §27.11): RAW runtime-role writes for StudentMarkDatabaseDefenceConcurrencyTest -- no service, no
// application lock: only the database's own rules stand between these statements and a mark that contradicts its
// paper. HeldTransaction keeps the holder uncommitted until released (Tests\Concerns\ForcesConcurrentOverlap).
//
//   php student-mark-raw-op.php insert-mark <schoolId> <json: the student_marks column values>
//   php student-mark-raw-op.php repoint <schoolId> <paperId> <column: subject_offering_id|examination_id> <newId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$op = $argv[1];
$school = School::query()->findOrFail($argv[2]);
$context = $app->make(TenantContext::class);
$context->set($school); // the runtime role, under this School's RLS context

try {
    echo HeldTransaction::run(function () use ($op, $argv): string {
        if ($op === 'insert-mark') {
            DB::table('student_marks')->insert([...json_decode($argv[3], true, flags: JSON_THROW_ON_ERROR), 'id' => (string) Str::uuid7(), 'created_at' => now(), 'updated_at' => now()]);

            return 'inserted';
        }
        $updated = DB::table('examination_papers')->where('id', $argv[3])->update([$argv[4] => $argv[5], 'updated_at' => now()]);

        return "repointed:{$updated}";
    });
} catch (Throwable $e) {
    echo preg_match('/student_marks: [^(\n]*|examination_papers: [^(\n]*/', $e->getMessage(), $m) === 1 ? 'refused:'.trim($m[0]) : 'error:'.$e::class;
} finally {
    $context->clearAll();
}
