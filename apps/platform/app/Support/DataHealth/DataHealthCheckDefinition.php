<?php

namespace App\Support\DataHealth;

/**
 * The fixed, documented contract of one check (ADR 0075 section 10.3).
 * Every text is plain language for School staff.
 */
final readonly class DataHealthCheckDefinition
{
    /** Severity: consequence-based, smallest useful scale. */
    public const string BLOCKING = 'blocking';

    public const string ACTION_REQUIRED = 'action_required';

    public const string REVIEW = 'review';

    public const string ADVISORY = 'advisory';

    public const array SEVERITIES = [self::BLOCKING, self::ACTION_REQUIRED, self::REVIEW, self::ADVISORY];

    /** Kind: what sort of problem it is (never "error" for optional data). */
    public const string INVALID = 'invalid';

    public const string INCONSISTENT = 'inconsistent';

    public const string INCOMPLETE = 'incomplete';

    public const string REVIEW_RECOMMENDED = 'review_recommended';

    public const array KINDS = [self::INVALID, self::INCONSISTENT, self::INCOMPLETE, self::REVIEW_RECOMMENDED];

    /**
     * @param  string  $code  stable identifier, `<area>.<condition>` in snake_case
     * @param  list<string>  $viewCapabilities  ALL required to see the check and its findings
     *                                          -- the same capabilities that show this data at its source
     * @param  string  $repair  how it is corrected, and by which existing workflow
     */
    public function __construct(
        public string $code,
        public string $area,
        public string $title,
        public string $condition,
        public string $whyItMatters,
        public string $severity,
        public string $kind,
        public array $viewCapabilities,
        public string $repair,
        public bool $automaticRepair = false,
    ) {
        if (preg_match('/^[a-z_]+\.[a-z_]+$/', $code) !== 1) {
            throw new \InvalidArgumentException("Invalid Data Health check code {$code}.");
        }
        if (! in_array($severity, self::SEVERITIES, true) || ! in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException("Invalid severity or kind for {$code}.");
        }
        if ($viewCapabilities === []) {
            throw new \InvalidArgumentException("{$code} must name the capabilities that may see it.");
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'area' => $this->area,
            'title' => $this->title,
            'condition' => $this->condition,
            'whyItMatters' => $this->whyItMatters,
            'severity' => $this->severity,
            'kind' => $this->kind,
            'repair' => $this->repair,
            'automaticRepair' => $this->automaticRepair,
        ];
    }
}
