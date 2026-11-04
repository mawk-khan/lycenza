<?php

namespace App\Domain\HR\Application\Separation;

/**
 * HR.12: one cross-domain offboarding obligation, as its OWNER answers it.
 * A count of records (never of people beyond the one leaver, never names).
 *
 * States:
 * - OPEN: real outstanding work in its owner -- blocks "offboarding complete";
 * - CLEAR: nothing outstanding;
 * - ATTENTION: worth reviewing, never blocking (no contract decides it);
 * - NOT_INTEGRATED: the owner records no staff obligation of this kind, so
 *   nothing can be verified -- shown, never blocking, never "clear";
 * - RETAINED (HR.15E): School access an authorized person recorded as KEPT on
 *   purpose -- the roles are still active, so it is never shown as clear (or
 *   as revoked); it does not block completion (HR.12 owner decision);
 * - UNAVAILABLE (HR.15F): the owner could not be asked (its reader failed) --
 *   unknown is never clear, so it BLOCKS completion until a retry succeeds.
 */
final readonly class SeparationObligation
{
    public const string OPEN = 'open';

    public const string CLEAR = 'clear';

    public const string ATTENTION = 'attention';

    public const string NOT_INTEGRATED = 'not_integrated';

    public const string RETAINED = 'retained';

    public const string UNAVAILABLE = 'unavailable';

    public function __construct(
        public string $code,
        public string $owner,
        public string $label,
        public string $state,
        public ?int $count = null,
        public ?string $detail = null,
    ) {}

    /** @return array{code: string, owner: string, label: string, state: string, count: int|null, detail: string|null} */
    public function toArray(): array
    {
        return ['code' => $this->code, 'owner' => $this->owner, 'label' => $this->label, 'state' => $this->state, 'count' => $this->count, 'detail' => $this->detail];
    }
}
