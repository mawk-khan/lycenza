<?php

namespace App\Console\Commands;

use App\Domain\Identity\Application\Staff\BootstrapAccountProvisioningService;
use App\Domain\Identity\Application\Staff\BootstrapAccountRefusedException;
use App\Domain\Platform\Application\Roles\PlatformRootProvisioningRefusedException;
use App\Domain\Platform\Application\Roles\PlatformRootProvisioningService;
use App\Models\School;
use App\Support\Privacy\EmailNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Phase 0O.12B (ADR 0059 section 5): flow A -- the operator console path
 * that creates the ordinary, credential-less User who will become a
 * provisioning School's bootstrap administrator, and shows its one-time
 * activation link ONCE. Run again for a still credential-less account, it
 * re-issues the link (the previous one stops working).
 *
 * - Interactive only: there is deliberately no `--force` -- a display-once
 *   secret needs a human at the terminal. It runs only where the operator
 *   (admin) database connection is configured, i.e. the operator console,
 *   never a long-running web/worker role.
 * - The operator types the account's exact email to confirm.
 * - It writes NO membership, School role, Employee, platform or Group
 *   grant, and never a password. School authority still comes only from
 *   root, through the ADR 0047 create / bootstrap-admin replace path (fresh
 *   MFA).
 * - The link is printed only to this terminal -- never logged, stored or
 *   audited. It is Highly Sensitive: hand it to the named person over an
 *   authenticated channel, never chat, a ticket or a shared mailbox.
 */
class ProvisionSchoolAdminAccount extends Command
{
    protected $signature = 'platform:provision-school-admin-account
        {email : The person\'s email address (their sign-in identity)}
        {--name= : Their display name (asked if omitted; new accounts only)}
        {--school= : The id or slug of the provisioning School they will administer (audit context only)}
        {--hours=24 : Activation link lifetime in hours (1-72)}';

    protected $description = 'Create a School bootstrap administrator account and show its one-time activation link (operator console only, ADR 0059).';

    public function handle(BootstrapAccountProvisioningService $accounts, PlatformRootProvisioningService $operator): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Refused: this command is interactive only (it shows a one-time activation link to the operator).');

            return self::FAILURE;
        }

        try {
            $operator->assertOperatorConnection();
        } catch (PlatformRootProvisioningRefusedException $e) {
            $this->error('Refused: '.$e->getMessage());

            return self::FAILURE;
        }

        $email = EmailNormalizer::canonical((string) $this->argument('email'));
        $school = null;

        if (($identifier = trim((string) $this->option('school'))) !== '') {
            $school = School::query()
                ->when(Str::isUuid($identifier), fn ($q) => $q->whereKey(strtolower($identifier)), fn ($q) => $q->where('slug', strtolower($identifier)))
                ->first();

            if ($school === null) {
                $this->error('Refused: no School matches that id or slug.');

                return self::FAILURE;
            }
        }

        $existing = $accounts->existingAccount($email);
        $name = $existing !== null ? $existing->name : trim((string) ($this->option('name') ?: $this->ask('Display name')));

        $this->warn($existing === null
            ? 'This creates a NEW account with no password and shows its one-time activation link once.'
            : 'This account has not been activated yet: a NEW activation link replaces the previous one.');
        $this->line('It grants no School access by itself: root names this account as the School\'s bootstrap administrator (ADR 0047).');
        $this->table(['Email', 'Name', 'School (context)'], [[$email, $name, $school === null ? '—' : "{$school->name} ({$school->slug})"]]);

        $typed = EmailNormalizer::canonical((string) $this->ask('Type the account\'s email address exactly to confirm'));

        if ($typed !== $email) {
            $this->error('Confirmation did not match. Nothing changed.');

            return self::FAILURE;
        }

        try {
            $issued = $existing === null
                ? $accounts->provision($email, $name, $school, (int) $this->option('hours'))
                : $accounts->reissue($existing, $school, (int) $this->option('hours'));
        } catch (BootstrapAccountRefusedException $e) {
            $this->error('Refused: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(($issued->reissued ? 'Activation link re-issued' : 'Account created').' (audited as '
            .($issued->reissued ? BootstrapAccountProvisioningService::REISSUED : BootstrapAccountProvisioningService::PROVISIONED).').');
        $this->line("Account id: {$issued->user->id}");
        $this->warn('ONE-TIME ACTIVATION LINK -- shown only now, never stored. Hand it to this person over an authenticated channel:');
        $this->line($issued->link);
        $this->line('Expires: '.$issued->expiresAt->toDayDateTimeString().' (UTC). The person opens it on the platform host and sets their password.');
        $this->line('Next: root creates the School naming this email as its administrator (or replaces the bootstrap administrator), then activates it once the account is activated.');

        return self::SUCCESS;
    }
}
