<?php

namespace App\Console\Commands;

use App\Models\EmailMessage;
use App\Models\EmailProviderReference;
use App\Models\EmailSuppression;
use App\Models\School;
use App\Support\Email\PlatformEmailScope;
use App\Support\Email\Suppression\EmailSuppressionService;
use App\Support\Email\Suppression\SuppressionKeyRing;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Phase 0O.9A (ADR 0055 section 12.3, key-ring amendment): after the
 * suppression HMAC key is rotated (the old key moved to
 * MAIL_SUPPRESSION_HMAC_PREVIOUS_KEY), re-record under the CURRENT key the
 * suppressions the repository can honestly reconstruct: those whose source
 * message still exists with its encrypted recipient (read inside that
 * message's own School context). An HMAC cannot be reversed, so every
 * other previous-key suppression is reported, not dropped -- keep the
 * previous key in the ring until those are released or have been observed
 * (and re-keyed) again. Idempotent; nothing is released.
 */
class RekeyMailSuppressions extends Command
{
    protected $signature = 'platform:mail-suppression-rekey {--batch=500 : Maximum suppressions per run}';

    protected $description = 'Re-key reconstructable email suppressions under the current HMAC key (ADR 0055).';

    public function handle(SuppressionKeyRing $ring, EmailSuppressionService $suppressions, TenantContext $context): int
    {
        $ids = array_keys($ring->all());

        if (count($ids) < 2) {
            $this->info('No previous key is configured; nothing to re-key.');

            return self::SUCCESS;
        }

        $rekeyed = 0;
        $unrecoverable = 0;

        $rows = EmailSuppression::query()->active()->where('key_id', $ids[1])->limit((int) $this->option('batch'))->get();

        foreach ($rows as $row) {
            $reference = $row->source_email_message_id === null ? null
                : EmailProviderReference::query()->where('email_message_id', $row->source_email_message_id)->first();
            $school = $reference?->school_id === null ? null : School::query()->find($reference->school_id);
            $find = fn () => EmailMessage::query()->find($row->source_email_message_id)?->recipient();

            // An identity-level message (ADR 0056) is read in the platform email scope.
            $address = match (true) {
                $reference === null => null,
                $reference->school_id === null => app(PlatformEmailScope::class)->run($find),
                $school === null => null,
                default => $context->withSchool($school, $find),
            };

            if ($address === null) {
                $unrecoverable++;

                continue;
            }

            $suppressions->suppress($address, $row->scope, $row->reason, $row->source_event_id, $row->source_email_message_id);
            $rekeyed++;
        }

        $this->info("Re-keyed {$rekeyed}; cannot be re-keyed (keep the previous key until they are released or re-observed): {$unrecoverable}.");

        return self::SUCCESS;
    }
}
