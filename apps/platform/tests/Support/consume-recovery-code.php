<?php

use App\Models\User;
use App\Support\Auth\Mfa\MfaRecoveryCodeService;
use Illuminate\Contracts\Console\Kernel;

// Standalone bootstrap script for MfaRecoveryCodeConcurrencyTest: one
// recovery-code consumption attempt run in a genuinely separate OS
// process, so two real processes can race the SAME code against real
// PostgreSQL. Mirrors tests/Support/activate-grade-scale.php's
// structure (no TenantContext here -- MFA is User-global, not
// School-scoped, see UserMfaRecoveryCode's migration docblock).
//
// Usage: php consume-recovery-code.php <userId> <code>

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $userId, $code] = $argv;

$user = User::query()->findOrFail($userId);
$consumed = $app->make(MfaRecoveryCodeService::class)->consume($user, $code);

echo $consumed ? 'consumed:true' : 'consumed:false';
