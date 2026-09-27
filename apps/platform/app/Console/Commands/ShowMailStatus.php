<?php

namespace App\Console\Commands;

use App\Support\Email\EmailStatus;
use App\Support\Email\Suppression\EmailSuppressionService;
use App\Support\Email\Suppression\SuppressionKeyRing;
use Illuminate\Console\Command;
use Throwable;

/**
 * Phase 0O.9A (ADR 0055 section 16): the email component and its bounded
 * facts for an operator -- provider MODE (never a credential), verification
 * attestation, backlog by purpose, provider-event freshness, suppression
 * counts by key-ring position and retention configuration. Read-only; no
 * provider call.
 */
class ShowMailStatus extends Command
{
    protected $signature = 'platform:mail-status {--json : Machine-readable output}';

    protected $description = 'Show the production email status (ADR 0055; read-only, no secrets).';

    public function handle(EmailStatus $status, SuppressionKeyRing $ring, EmailSuppressionService $suppressions): int
    {
        $component = $status->component();

        try {
            $keys = ['key_ids' => array_keys($ring->all()), 'previous_key_suppressions' => $suppressions->previousKeyCount(), 'orphaned_key_suppressions' => $suppressions->orphanedCount()];
        } catch (Throwable) {
            $keys = ['key_ids' => [], 'error' => 'mail_suppression_keys_invalid'];
        }

        $report = [
            'component' => $component->component,
            'status' => $component->status->value,
            'reason' => $component->reason,
            'detail' => $component->detail,
            'suppression_key_ring' => $keys,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line("email: {$report['status']}".($report['reason'] !== null ? " ({$report['reason']})" : ''));
        $this->line('provider: '.$component->detail['provider'].'; events: '.$component->detail['provider_events'].'; sending verified: '.($component->detail['sending_verified'] ? 'yes' : 'no'));
        foreach ($component->detail['backlog'] as $purpose => $backlog) {
            $this->line(sprintf('  %-22s pending=%d submitting=%d oldest=%ds', $purpose, $backlog['pending'], $backlog['submitting'], $backlog['oldest_age_seconds']));
        }
        $this->line('active suppressions: '.$component->detail['active_suppressions'].'; key ids: '.implode(', ', $keys['key_ids']));
        if (isset($keys['previous_key_suppressions'])) {
            $this->line("  under the previous key: {$keys['previous_key_suppressions']}; under a key no longer in the ring: {$keys['orphaned_key_suppressions']}");
        }
        $this->line('retention: '.$component->detail['retention'].($component->detail['retention'] === 'unconfigured' ? ' [LEGAL REVIEW REQUIRED] -- nothing is deleted' : ''));

        return self::SUCCESS;
    }
}
