<?php

namespace App\Console\Commands;

use App\Support\Audit\AuditRecorder;
use App\Support\Email\Suppression\EmailSuppressionService;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Phase 0O.9A (ADR 0055 section 12.3): the ONLY way to release a
 * suppression -- an operator console action, never a School one. Release
 * it only after confirming the mailbox problem is fixed; never to "get a
 * critical email through". The address is read from a hidden prompt (not
 * shell history), never printed, logged or audited; the platform audit
 * records the closed reason and how many rows were released. A provider
 * may ALSO suppress the address on its side -- clearing that is a separate
 * provider-console step (docs/operations/EMAIL-DELIVERABILITY.md).
 */
class ReleaseMailSuppression extends Command
{
    public const REASONS = ['mailbox_repaired', 'recipient_request', 'false_positive'];

    protected $signature = 'platform:mail-suppression-release {--reason= : mailbox_repaired|recipient_request|false_positive} {--force : Skip the confirmation}';

    protected $description = 'Release the suppression of one email address (ADR 0055; operator only, audited).';

    public function handle(EmailSuppressionService $suppressions, AuditRecorder $audit): int
    {
        $reason = (string) $this->option('reason');

        if (! in_array($reason, self::REASONS, true)) {
            $this->error('A --reason is required: '.implode('|', self::REASONS).'.');

            return self::FAILURE;
        }

        $address = (string) $this->secret('Email address to release');

        if (! $this->option('force') && ! $this->confirm('Release every active suppression of that address?')) {
            $this->error('Not confirmed; nothing changed.');

            return self::FAILURE;
        }

        try {
            $released = $suppressions->release($address, null, $reason);
        } catch (InvalidArgumentException) {
            $this->error('That is not a valid email address.');

            return self::FAILURE;
        }

        $audit->platform('platform.email_suppression.released', metadata: ['released' => $released, 'reason' => $reason]);
        $this->info("Released {$released} suppression(s).");

        return self::SUCCESS;
    }
}
