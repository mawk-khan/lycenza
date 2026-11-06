<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concurrency\HeldTransaction;

// HR.R1-C5: a rollback and a write racing in GENUINELY separate OS processes.
//
//   php rollback-guard-op.php <connectionJson> rollback <path>
//   php rollback-guard-op.php <connectionJson> insert <table> <id> <schoolId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $connection, $kind] = $argv;
config(['database.connections.pgsql_c5' => json_decode($connection, true)]);
DB::setDefaultConnection('pgsql_c5');

try {
    HeldTransaction::run(function () use ($kind, $argv): void {
        if ($kind === 'insert') {
            DB::table($argv[3])->insert(['id' => $argv[4], 'school_id' => $argv[5]]);

            return;
        }
        Artisan::call('migrate:rollback', ['--database' => 'pgsql_c5', '--path' => $argv[3], '--realpath' => true, '--force' => true]);
    });
    echo 'ok';
} catch (Throwable $e) {
    echo 'rejected:'.$e::class.' '.substr($e->getMessage(), 0, 120);
}
