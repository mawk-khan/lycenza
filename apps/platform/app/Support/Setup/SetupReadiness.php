<?php

namespace App\Support\Setup;

/**
 * ORG.12A (ADR 0080 §8): one provider's answer for one setup area.
 *
 * Facts only: a status, a short code-owned explanation and optional check
 * counts. It never carries a person's name, an email, a phone number, an
 * amount, a record id or any other business record -- and never a count of
 * people (a count of people is Analytics, ADR 0040). Counts of
 * configuration objects ("3 active Campuses") are allowed.
 */
final readonly class SetupReadiness
{
    public const int MAX_EXPLANATION_LENGTH = 200;

    public function __construct(
        public SetupStatus $status,
        public string $explanation,
        public ?int $completedChecks = null,
        public ?int $totalChecks = null,
    ) {
        if ($status === SetupStatus::NOT_APPLICABLE) {
            throw new \InvalidArgumentException('NOT_APPLICABLE is decided from module entitlement, never by a readiness provider.');
        }
        if (trim($explanation) === '' || mb_strlen($explanation) > self::MAX_EXPLANATION_LENGTH) {
            throw new \InvalidArgumentException('A readiness explanation is required and stays short.');
        }
        if (($completedChecks === null) !== ($totalChecks === null)
            || ($totalChecks !== null && ($totalChecks < 1 || $completedChecks < 0 || $completedChecks > $totalChecks))) {
            throw new \InvalidArgumentException('Check counts are both absent or 0 <= completed <= total, total >= 1.');
        }
    }

    /**
     * The usual shape: every named check is a code-owned boolean over
     * canonical state. None pass = NOT_CONFIGURED, all = CONFIGURED,
     * otherwise PARTIALLY_CONFIGURED.
     *
     * @param  array<string, bool>  $checks  code-owned check name => passed
     */
    public static function fromChecks(array $checks, string $configured, string $incomplete): self
    {
        $total = count($checks);
        $passed = count(array_filter($checks));
        $status = match (true) {
            $passed === $total => SetupStatus::CONFIGURED,
            $passed === 0 => SetupStatus::NOT_CONFIGURED,
            default => SetupStatus::PARTIALLY_CONFIGURED,
        };

        return new self($status, $status === SetupStatus::CONFIGURED ? $configured : $incomplete, $passed, $total);
    }

    /** An area that needs no School-specific setup once its module is available. */
    public static function noSetupRequired(string $explanation): self
    {
        return new self(SetupStatus::CONFIGURED, $explanation);
    }
}
