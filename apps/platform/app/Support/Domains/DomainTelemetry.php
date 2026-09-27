<?php

namespace App\Support\Domains;

use App\Support\Observability\MetricsRecorder;
use Illuminate\Support\Facades\Log;

/**
 * ADR 0054 section 12: bounded domain metrics and log lines. Labels are
 * closed vocabularies only -- never a hostname, School, domain id, token or
 * ticket. Log lines carry the domain id and closed codes.
 */
final class DomainTelemetry
{
    public const CHECKS = ['ownership', 'routing', 'tls'];

    public const CHECK_OUTCOMES = ['match', 'mismatch', 'absent', 'indeterminate', 'pass', 'fail'];

    public const HOST_OUTCOMES = ['misdirected', 'not_served', 'surface_not_found', 'alias_redirect'];

    public function __construct(private readonly MetricsRecorder $metrics) {}

    public function check(string $check, string $outcome, string $domainId, ?string $reason = null): void
    {
        $label = match ($outcome) {
            'tls_invalid', 'proof_mismatch' => 'fail',
            default => $outcome,
        };

        $this->metrics->counter('lycenza_domain_checks_total', 1, ['check' => $check, 'outcome' => $label]);
        Log::info('domains.check', ['domain_id' => $domainId, 'check' => $check, 'outcome' => $outcome, 'reason' => $reason]);
    }

    public function transition(string $to, string $domainId, string $cause): void
    {
        $this->metrics->counter('lycenza_domain_transitions_total', 1, ['to' => $to]);
        Log::info('domains.transition', ['domain_id' => $domainId, 'to_state' => $to, 'cause' => $cause]);
    }

    public function hostResponse(string $outcome): void
    {
        $this->metrics->counter('lycenza_host_responses_total', 1, ['outcome' => $outcome]);
    }
}
