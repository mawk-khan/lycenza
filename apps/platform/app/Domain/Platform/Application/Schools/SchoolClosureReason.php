<?php

namespace App\Domain\Platform\Application\Schools;

/**
 * E21.2F (E21-D11): the closed set of reasons a School is closed. Stored as
 * a code on the School (`closure_reason`, database-checked) and in platform
 * audit; there is no free-text reason.
 */
enum SchoolClosureReason: string
{
    case CeasedOperations = 'ceased_operations';
    case ContractEnded = 'contract_ended';
    case MergedOrTransferred = 'merged_or_transferred';

    public function label(): string
    {
        return match ($this) {
            self::CeasedOperations => 'The School ceased operations',
            self::ContractEnded => 'The service contract ended',
            self::MergedOrTransferred => 'Merged into or transferred to another School',
        };
    }
}
