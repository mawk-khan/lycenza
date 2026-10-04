<?php

namespace App\Console\Commands;

use App\Support\Audit\AuditRecorder;
use App\Support\Retention\RetentionHolds;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * E21-RH.3 (ADR 0066 §6): places an authoritative retention hold -- one
 * School (`--school=<id>`) or the whole platform (`--platform`). Operator
 * console only: it runs on the migration/owner connection through
 * `retention_hold_place()`, which the database attributes to the
 * authenticated maintenance login. Idempotent: an already active hold is
 * reported, never duplicated. Audited as `platform.retention_hold.placed`.
 */
class PlaceRetentionHold extends Command
{
    protected $signature = 'platform:retention-hold-place
        {--school= : The School id to hold}
        {--platform : Hold the whole platform (every School and every School-less record)}
        {--reason= : litigation|regulatory_inquiry|audit|investigation|configuration_transition|other}
        {--reference= : The change/ticket reference (letters, digits, . _ : / -; at most 64)}';

    protected $description = 'Places a retention hold on one School or the whole platform (E21-RH.3; operator only, audited).';

    public function handle(RetentionHolds $holds, AuditRecorder $audit): int
    {
        $school = $this->option('school');
        if (($school === null) === ! $this->option('platform')) {
            $this->error('Give exactly one of --school=<id> or --platform.');

            return self::FAILURE;
        }

        try {
            $hold = $holds->place($school, (string) $this->option('reason'), $this->option('reference'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $hold['created']) {
            $this->info('Already held: hold '.$hold['id'].' is active for that scope; nothing changed.');

            return self::SUCCESS;
        }

        $audit->platform('platform.retention_hold.placed', metadata: [
            'hold_id' => $hold['id'], 'scope' => $school === null ? 'platform' : 'school', 'school_id' => $school,
            'reason_code' => $this->option('reason'), 'reference' => $this->option('reference'), 'placed_via' => 'operator_command',
        ]);
        $this->info('Placed hold '.$hold['id'].' ('.($school === null ? 'platform' : 'school').'). Destructive retention refuses while it is active.');

        return self::SUCCESS;
    }
}
