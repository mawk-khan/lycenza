<?php

use App\Models\UserMfaFactor;
use App\Support\Auth\Mfa\MfaChallengeService;
use Illuminate\Contracts\Console\Kernel;

// Standalone bootstrap script for MfaTotpReplayConcurrencyTest: one
// TOTP verification attempt run in a genuinely separate OS process, so
// two real processes can race the SAME valid code against real
// PostgreSQL. This is what actually exercises MfaChallengeService::
// verifyTotp()'s `SELECT ... FOR UPDATE` row lock -- a single PHP
// process calling verifyTotp() twice in a row never contends with
// itself the way two independent connections do. Mirrors
// tests/Support/consume-recovery-code.php's structure.
//
// Usage: php verify-totp.php <factorId> <code>

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $factorId, $code] = $argv;

$factor = UserMfaFactor::query()->findOrFail($factorId);
$accepted = $app->make(MfaChallengeService::class)->verifyTotp($factor, $code);

echo $accepted ? 'accepted:true' : 'accepted:false';
