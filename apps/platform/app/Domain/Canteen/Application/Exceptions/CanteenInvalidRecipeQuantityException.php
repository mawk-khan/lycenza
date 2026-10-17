<?php

namespace App\Domain\Canteen\Application\Exceptions;

/**
 * LC-1: a recipe quantity must be above zero and fit
 * `canteen_item_inventory_requirements.quantity_required` (decimal(14,3)).
 * The database CHECK `canteen_item_inv_req_quantity_positive_check` already
 * refused zero; this says so before the write, on web and API alike.
 */
class CanteenInvalidRecipeQuantityException extends CanteenException
{
    public function __construct()
    {
        parent::__construct(422, 'CANTEEN_INVALID_RECIPE_QUANTITY', 'A recipe quantity must be greater than zero and no more than 99,999,999,999.999.');
    }
}
