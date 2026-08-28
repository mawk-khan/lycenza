<?php

namespace App\Domain\Canteen\Application\Exceptions;

/**
 * A CanteenItem appearing on more than one line of the same placement
 * request is rejected outright -- never silently merged into a single
 * line with a summed quantity.
 */
class DuplicateCanteenItemLineException extends CanteenException
{
    public function __construct(string $canteenItemId)
    {
        parent::__construct(422, 'CANTEEN_DUPLICATE_ITEM_LINE', "Canteen Item '{$canteenItemId}' appears more than once in this order -- combine quantities into a single line instead.");
    }
}
