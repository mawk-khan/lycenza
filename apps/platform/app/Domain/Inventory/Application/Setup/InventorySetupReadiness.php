<?php

namespace App\Domain\Inventory\Application\Setup;

use App\Domain\Inventory\Infrastructure\InventoryItem;
use App\Domain\Inventory\Infrastructure\InventoryLocation;
use App\Models\School;
use App\Support\Setup\SetupReadiness;
use App\Support\Setup\SetupReadinessProvider;

/**
 * ORG.12A (ADR 0080 §10): Inventory readiness -- the directory only (an
 * active Location and an active Item). Never stock, movements or balances.
 */
class InventorySetupReadiness implements SetupReadinessProvider
{
    public function keys(): array
    {
        return ['inventory.inventory'];
    }

    public function evaluate(string $key, School $school): SetupReadiness
    {
        return SetupReadiness::fromChecks(
            [
                'locations' => InventoryLocation::query()->where('school_id', $school->id)->where('status', 'active')->exists(),
                'items' => InventoryItem::query()->where('school_id', $school->id)->where('status', 'active')->exists(),
            ],
            configured: 'Stock locations and items are set up.',
            incomplete: 'Add a stock location and the items it holds.',
        );
    }
}
