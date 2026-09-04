<?php

use App\Models\UserMfaFactor;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

// Standalone bootstrap script for MfaFactorConcurrencyTest: activates
// ONE specific pending UserMfaFactor row in a genuinely separate OS
// process, so two real processes racing two DIFFERENT pending factors
// for the SAME User can prove the partial unique index
// (user_mfa_factors_one_active_per_user) is what actually prevents two
// simultaneously-active factors -- not application code. Mirrors
// tests/Support/activate-grade-scale.php's structure.
//
// Usage: php activate-mfa-factor.php <factorId>

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $factorId] = $argv;

try {
    DB::transaction(function () use ($factorId) {
        $factor = UserMfaFactor::query()->findOrFail($factorId);
        $factor->forceFill(['status' => 'active', 'confirmed_at' => now()])->save();
    });

    echo 'activated:'.$factorId;
} catch (UniqueConstraintViolationException) {
    echo 'rejected:unique_violation';
}
