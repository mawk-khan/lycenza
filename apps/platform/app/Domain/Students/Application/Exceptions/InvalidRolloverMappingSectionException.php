<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * UAT-C1 (UAT-YR-04): a mapping's Section must be a Section of that same
 * side's Grade in that side's Academic Year -- the source Section in the
 * plan's source year and source Grade, the target Section in the plan's
 * target year and target Grade. Refused when the mapping is saved, so a
 * mismatch never reaches dry-run as a misleading per-Student block.
 */
class InvalidRolloverMappingSectionException extends StudentException
{
    public function __construct(public readonly string $side)
    {
        parent::__construct(
            422,
            'INVALID_ROLLOVER_MAPPING_SECTION',
            "The {$side} Section must be a Section of the {$side} Grade in the rollover plan's {$side} Academic Year.",
        );
    }
}
