<?php

namespace App\Domain\Canteen\Application;

/**
 * One requested line for CanteenOrderService::place() -- a trusted,
 * already-known CanteenItem id and a strictly-positive integer
 * quantity. `place()` itself resolves/validates the Item's existence,
 * School ownership, and active status; this class performs no
 * validation of its own, matching
 * App\Domain\Inventory\Application\IssueRequirement's precedent.
 */
final class CanteenOrderLineData
{
    public function __construct(
        public readonly string $canteenItemId,
        public readonly int $quantity,
    ) {}
}
