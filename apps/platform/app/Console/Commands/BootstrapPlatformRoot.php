<?php

namespace App\Console\Commands;

use App\Domain\Platform\Application\Roles\PlatformRootProvisioningRefusedException;
use App\Domain\Platform\Application\Roles\PlatformRootProvisioningService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\Console\Exception\RuntimeException as ConsoleRuntimeException;

/**
 * Phase 0O.1A: first-boot bootstrap of a fresh installation -- creates the
 * FIRST platform account and provisions the root platform role for it
 * (ADR 0046 section 2), because production has no other way to create a
 * platform account. Console only; no HTTP route or UI.
 *
 * - Refused once any active root assignment exists: it is not an account
 *   factory (an existing account uses `platform:provision-root`).
 * - Interactive only. The password is read twice through hidden prompts
 *   with NO visible fallback (a terminal that cannot hide input is
 *   refused); it is never an option or argument, so it never reaches shell
 *   history or the process list, and it is never printed, logged or
 *   audited. It must satisfy the application's password rules
 *   (Password::defaults(), as invitation acceptance uses).
 * - Creates no School membership and no Group grant.
 * - The account, the root grant and `platform.role_grant.provisioned` are
 *   written in one administrative transaction
 *   (PlatformRootProvisioningService::bootstrapFirstRoot()).
 */
class BootstrapPlatformRoot extends Command
{
    protected $signature = 'platform:bootstrap-root';

    protected $description = 'Create the first platform account with the root role on a fresh installation (interactive operator console only).';

    public function handle(PlatformRootProvisioningService $provisioning): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Refused: first-boot bootstrap is interactive only (the password is entered through a hidden prompt).');

            return self::FAILURE;
        }

        try {
            $provisioning->assertOperatorConnection();

            if ($provisioning->hasActiveRoot($provisioning->rootRole())) {
                $this->error('Refused: a root platform account already exists. Use platform:provision-root for an existing account.');

                return self::FAILURE;
            }
        } catch (PlatformRootProvisioningRefusedException $e) {
            $this->error('Refused: '.$e->getMessage());

            return self::FAILURE;
        }

        $name = trim((string) $this->ask('Full name'));
        $email = strtolower(trim((string) $this->ask('Email address (the sign-in identity)')));

        $identity = Validator::make(['name' => $name, 'email' => $email], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        if ($identity->fails()) {
            $this->error('Refused: '.$identity->errors()->first());

            return self::FAILURE;
        }

        try {
            $password = (string) $this->secret('Password (hidden)', false);
            $confirmation = (string) $this->secret('Confirm password (hidden)', false);
        } catch (ConsoleRuntimeException) {
            $this->error('Refused: this terminal cannot hide input. Run the command from an interactive terminal.');

            return self::FAILURE;
        }

        $rules = Validator::make(
            ['password' => $password, 'password_confirmation' => $confirmation],
            ['password' => ['required', 'confirmed', Password::defaults()]],
        );

        if ($rules->fails()) {
            $this->error('Refused: '.$rules->errors()->first('password').' Nothing changed.');

            return self::FAILURE;
        }

        $this->warn('This creates a platform account holding the ROOT platform role (all platform authority).');
        $this->table(['Name', 'Email'], [[$name, $email]]);

        if (! $this->confirm('Create this account and grant it the root platform role?')) {
            $this->info('Nothing changed.');

            return self::FAILURE;
        }

        try {
            $user = $provisioning->bootstrapFirstRoot($name, $email, $password);
        } catch (PlatformRootProvisioningRefusedException $e) {
            $this->error('Refused: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Created the first platform account ({$user->id}) with the root platform role (audited as ".PlatformRootProvisioningService::EVENT.').');
        $this->line('Sign in and enrol MFA before using platform pages that require it. There is no password reset yet.');

        return self::SUCCESS;
    }
}
