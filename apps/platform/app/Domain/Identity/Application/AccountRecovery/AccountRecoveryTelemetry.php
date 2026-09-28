<?php

namespace App\Domain\Identity\Application\AccountRecovery;

use App\Support\Observability\MetricsRecorder;

/**
 * Phase 0O.10A (ADR 0056 section 15): closed-label recovery metrics. Never
 * an email, User, School, selector, IP or token. Metrics are operator-only
 * (the private scrape), so issuance outcomes may be distinguished there --
 * the browser never sees any of them.
 */
final class AccountRecoveryTelemetry
{
    public const REQUEST_OUTCOMES = ['accepted', 'rate_limited_identity', 'dispatch_failed'];

    public const ISSUANCE_OUTCOMES = ['issued', 'unknown', 'ineligible', 'active_limit', 'email_unavailable', 'disabled'];

    public const RESET_OUTCOMES = ['succeeded', 'invalid', 'policy_rejected'];

    public function __construct(private readonly MetricsRecorder $metrics) {}

    public function request(string $outcome): void
    {
        $this->count('lycenza_account_recovery_requests_total', self::REQUEST_OUTCOMES, $outcome);
    }

    public function issuance(string $outcome): void
    {
        $this->count('lycenza_account_recovery_issuance_total', self::ISSUANCE_OUTCOMES, $outcome);
    }

    public function reset(string $outcome): void
    {
        $this->count('lycenza_account_recovery_resets_total', self::RESET_OUTCOMES, $outcome);
    }

    /** @param list<string> $allowed */
    private function count(string $metric, array $allowed, string $outcome): void
    {
        if (in_array($outcome, $allowed, true)) {
            $this->metrics->counter($metric, 1, ['outcome' => $outcome]);
        }
    }
}
