<?php

use App\Domain\Identity\Application\Staff\AccountActivationService;
use App\Domain\Identity\Application\Staff\BootstrapAccountProvisioningService;
use App\Domain\Identity\Application\Staff\BootstrapAccountRefusedException;
use App\Domain\Identity\Application\Staff\StaffAccessService;
use App\Domain\Identity\Application\Staff\StaffAccountException;
use App\Domain\Identity\Application\Staff\StaffInvitationAcceptanceService;
use App\Domain\Identity\Application\Staff\StaffInvitationService;
use App\Models\School;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Concurrency\HeldTransaction;

// Standalone bootstrap script for StaffAccountConcurrencyTest: one staff
// account operation (ADR 0059 / Phase 0O.12B) in a GENUINELY separate OS
// process, so two real PHP processes race against real PostgreSQL.
// Mirrors group-authority-op.php.
//
// Usage:
//   php staff-account-op.php suspend      <schoolId> <actorId> <membershipId>
//   php staff-account-op.php reactivate   <schoolId> <actorId> <membershipId> <roleKey>
//   php staff-account-op.php grant        <schoolId> <actorId> <membershipId> <roleKey>
//   php staff-account-op.php revoke-role  <schoolId> <actorId> <membershipId> <roleKey>
//   php staff-account-op.php invite       <schoolId> <actorId> <email>
//   php staff-account-op.php revoke-invitation <schoolId> <actorId> <invitationId>
//   php staff-account-op.php accept-new   <schoolId> <selector> <secret>
//   php staff-account-op.php activate     <selector> <secret>
//   php staff-account-op.php reissue      <userId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = array_slice($argv, 1);
$operation = array_shift($args);

$school = fn (string $id): School => School::query()->findOrFail($id);
$user = fn (string $id): User => User::query()->findOrFail($id);

try {
    echo HeldTransaction::run(function () use ($app, $operation, $args, $school, $user): string {
        $access = $app->make(StaffAccessService::class);

        switch ($operation) {
            case 'suspend':
                [$schoolId, $actorId, $membershipId] = $args;
                $access->suspend($school($schoolId), $user($actorId), $membershipId);

                return 'suspended';
            case 'reactivate':
                [$schoolId, $actorId, $membershipId, $role] = $args;
                $access->reactivate($school($schoolId), $user($actorId), $membershipId, [$role]);

                return 'reactivated';
            case 'grant':
                [$schoolId, $actorId, $membershipId, $role] = $args;
                $access->grantRole($school($schoolId), $user($actorId), $membershipId, $role);

                return 'granted';
            case 'revoke-role':
                [$schoolId, $actorId, $membershipId, $role] = $args;
                $access->revokeRole($school($schoolId), $user($actorId), $membershipId, $role);

                return 'revoked';
            case 'invite':
                [$schoolId, $actorId, $email] = $args;
                $app->make(StaffInvitationService::class)->issue($school($schoolId), $user($actorId), $email, ['principal']);

                return 'invited';
            case 'revoke-invitation':
                [$schoolId, $actorId, $invitationId] = $args;
                $app->make(StaffInvitationService::class)->revoke($school($schoolId), $user($actorId), $invitationId);

                return 'revoked';
            case 'accept-new':
                [$schoolId, $selector, $secret] = $args;

                return $app->make(StaffInvitationAcceptanceService::class)
                    ->accept($school($schoolId), $selector, $secret, null, 'Race Staff', 'race-staff-password-1', 'race-staff-password-1')->outcome;
            case 'activate':
                [$selector, $secret] = $args;

                return $app->make(AccountActivationService::class)->activate($selector, $secret, 'race-admin-password-1', 'race-admin-password-1')->outcome;
            case 'reissue':
                [$userId] = $args;
                $app->make(BootstrapAccountProvisioningService::class)->reissue($user($userId), null);

                return 'reissued';
        }

        return 'unknown';
    });
} catch (StaffAccountException $e) {
    echo 'rejected:'.$e->outcome;
} catch (BootstrapAccountRefusedException $e) {
    echo 'refused:'.$e->reason;
} catch (Throwable $e) {
    echo 'error:'.$e::class.':'.$e->getMessage();
}
