<?php

namespace App\Support\Retention\Erasure;

use InvalidArgumentException;

/**
 * E21.2F (E21-D10): one category of a data-subject erasure plan, with an
 * outcome from a closed set and a closed reason code. It carries no
 * personal data: codes, an optional not-before date and nothing else.
 */
final class ErasureCategory
{
    /** Retention has passed and nothing retained needs it: execution removes it. */
    public const ELIGIBLE = 'eligible';

    /** An adopted retention period is still running (`not_before` when known). */
    public const RETAINED_UNTIL = 'retained_until';

    /** The School (or platform) is under a retention hold. */
    public const LEGAL_HOLD = 'legal_hold';

    /** A retained record elsewhere still needs it. */
    public const DEPENDENCY_BLOCKED = 'dependency_blocked';

    /** No adopted retention or erasure basis exists yet (E21.2G). */
    public const POLICY_UNRESOLVED = 'policy_unresolved';

    /** Not part of this case (another scope or another domain's lifecycle). */
    public const OUTSIDE_SCOPE = 'outside_scope';

    /** Nothing of the category is left. */
    public const COMPLETED = 'completed';

    public const ERROR = 'error';

    public const OUTCOMES = [self::ELIGIBLE, self::RETAINED_UNTIL, self::LEGAL_HOLD, self::DEPENDENCY_BLOCKED, self::POLICY_UNRESOLVED, self::OUTSIDE_SCOPE, self::COMPLETED, self::ERROR];

    public function __construct(
        public readonly string $category,
        public readonly string $outcome,
        public readonly string $reason,
        public readonly ?string $notBefore = null,
    ) {
        if (! in_array($outcome, self::OUTCOMES, true)) {
            throw new InvalidArgumentException("Not an erasure outcome: {$outcome}");
        }
    }

    /** @return array{category: string, outcome: string, reason: string, not_before: ?string} */
    public function toArray(): array
    {
        return ['category' => $this->category, 'outcome' => $this->outcome, 'reason' => $this->reason, 'not_before' => $this->notBefore];
    }
}
