<?php

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\Application\Exceptions\DuplicateInventoryItemRequirementException;
use App\Domain\Inventory\Application\Exceptions\EmptyIssueRequirementsException;
use App\Domain\Inventory\Application\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Application\Exceptions\InventoryItemNotFoundException;
use App\Domain\Inventory\Application\Exceptions\ItemNotAvailableException;
use App\Domain\Inventory\Application\Exceptions\LocationNotAvailableException;
use App\Domain\Inventory\Application\InventoryStockService;
use App\Domain\Inventory\Application\IssueRequirement;
use App\Domain\Inventory\Infrastructure\InventoryItem;
use App\Domain\Inventory\Infrastructure\InventoryLocation;
use App\Domain\Inventory\Infrastructure\InventoryStockBalance;
use App\Domain\Inventory\Infrastructure\StockMovement;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 10F foundation -- InventoryStockService::issueMany(). Every
 * test exercises the real service, never a raw model write, mirroring
 * InventoryStockServiceTest's exact pattern. The two-real-process
 * deadlock-freedom proof lives in
 * InventoryStockConcurrencyTest::opposing_concurrent_issue_many_calls_for_the_same_two_items_in_opposite_order_do_not_deadlock
 * (mirrors the existing opposing-transfers proof), not here.
 */
class InventoryStockServiceIssueManyTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function balance(InventoryItem $item, InventoryLocation $location): ?InventoryStockBalance
    {
        return app(TenantContext::class)->withSchool(
            $item->school,
            fn () => InventoryStockBalance::query()->where('inventory_item_id', $item->id)->where('inventory_location_id', $location->id)->first(),
        );
    }

    #[Test]
    public function issue_many_atomically_decrements_three_distinct_items_and_returns_one_movement_each(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $rice = $this->createInventoryItem($school, ['unit_of_measure' => 'kg']);
        $chicken = $this->createInventoryItem($school, ['unit_of_measure' => 'kg']);
        $oil = $this->createInventoryItem($school, ['unit_of_measure' => 'litre']);
        $location = $this->createInventoryLocation($school);
        $service = app(InventoryStockService::class);

        app(TenantContext::class)->withSchool($school, function () use ($service, $rice, $chicken, $oil, $location, $admin) {
            $service->receive($rice, $location, '10.000', $admin);
            $service->receive($chicken, $location, '5.000', $admin);
            $service->receive($oil, $location, '2.000', $admin);
        });

        $movements = app(TenantContext::class)->withSchool($school, fn () => $service->issueMany($location, [
            new IssueRequirement($rice->id, '1.500'),
            new IssueRequirement($chicken->id, '0.750'),
            new IssueRequirement($oil->id, '0.100'),
        ], $admin));

        $this->assertCount(3, $movements);
        foreach ($movements as $movement) {
            $this->assertInstanceOf(StockMovement::class, $movement);
            $this->assertSame('issue', $movement->movement_type);
            $this->assertSame($location->id, $movement->from_location_id);
            $this->assertNull($movement->to_location_id);
        }

        $this->assertSame('8.500', $this->balance($rice, $location)->quantity_on_hand);
        $this->assertSame('4.250', $this->balance($chicken, $location)->quantity_on_hand);
        $this->assertSame('1.900', $this->balance($oil, $location)->quantity_on_hand);

        $movementIds = array_map(fn ($m) => $m->id, $movements);
        $persisted = app(TenantContext::class)->withSchool($school, fn () => StockMovement::query()->whereIn('id', $movementIds)->where('movement_type', 'issue')->count());
        $this->assertSame(3, $persisted, 'Every returned movement must correspond to a real persisted issue StockMovement row.');

        $issueMovementCount = app(TenantContext::class)->withSchool($school, fn () => StockMovement::query()->where('movement_type', 'issue')->count());
        $this->assertSame(3, $issueMovementCount, 'Exactly one StockMovement per requirement must exist.');
    }

    #[Test]
    public function issue_many_audits_one_inventory_stock_issued_event_per_movement_with_matching_metadata(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $rice = $this->createInventoryItem($school, ['unit_of_measure' => 'kg']);
        $oil = $this->createInventoryItem($school, ['unit_of_measure' => 'litre']);
        $location = $this->createInventoryLocation($school);
        $service = app(InventoryStockService::class);

        app(TenantContext::class)->withSchool($school, function () use ($service, $rice, $oil, $location, $admin) {
            $service->receive($rice, $location, '10.000', $admin);
            $service->receive($oil, $location, '2.000', $admin);
        });

        $movements = app(TenantContext::class)->withSchool($school, fn () => $service->issueMany($location, [
            new IssueRequirement($rice->id, '1.500'),
            new IssueRequirement($oil->id, '0.100'),
        ], $admin));

        foreach ($movements as $movement) {
            $item = $movement->inventory_item_id === $rice->id ? $rice : $oil;

            $event = app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()
                ->where('event_type', 'inventory.stock.issued')
                ->where('subject_id', $movement->id)
                ->first());

            $this->assertNotNull($event, "An inventory.stock.issued audit event must exist for movement {$movement->id}.");
            $this->assertSame($admin->id, $event->actor_user_id);
            $this->assertSame($movement->id, $event->metadata['movementId']);
            $this->assertSame($item->id, $event->metadata['itemId']);
            $this->assertSame($item->code, $event->metadata['itemCode']);
            $this->assertSame($location->id, $event->metadata['fromLocationId']);
            $this->assertSame($location->code, $event->metadata['fromLocationCode']);
            $this->assertSame($movement->quantity, $event->metadata['quantity']);
        }
    }

    #[Test]
    public function insufficient_stock_on_the_first_of_three_items_mutates_nothing(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $rice = $this->createInventoryItem($school, ['unit_of_measure' => 'kg']);
        $chicken = $this->createInventoryItem($school, ['unit_of_measure' => 'kg']);
        $oil = $this->createInventoryItem($school, ['unit_of_measure' => 'litre']);
        $location = $this->createInventoryLocation($school);
        $service = app(InventoryStockService::class);

        app(TenantContext::class)->withSchool($school, function () use ($service, $rice, $chicken, $oil, $location, $admin) {
            $service->receive($rice, $location, '1.000', $admin); // insufficient for the 1.500 requirement below
            $service->receive($chicken, $location, '5.000', $admin);
            $service->receive($oil, $location, '2.000', $admin);
        });

        try {
            app(TenantContext::class)->withSchool($school, fn () => $service->issueMany($location, [
                new IssueRequirement($rice->id, '1.500'),
                new IssueRequirement($chicken->id, '0.750'),
                new IssueRequirement($oil->id, '0.100'),
            ], $admin));
            $this->fail('Expected InsufficientStockException.');
        } catch (InsufficientStockException) {
            // expected
        }

        $this->assertSame('1.000', $this->balance($rice, $location)->quantity_on_hand);
        $this->assertSame('5.000', $this->balance($chicken, $location)->quantity_on_hand);
        $this->assertSame('2.000', $this->balance($oil, $location)->quantity_on_hand);

        $issueMovementCount = app(TenantContext::class)->withSchool($school, fn () => StockMovement::query()->where('movement_type', 'issue')->count());
        $this->assertSame(0, $issueMovementCount, 'No StockMovement may be created when any requirement is insufficient.');
    }

    #[Test]
    public function insufficient_stock_on_the_middle_item_of_three_mutates_nothing_even_though_the_others_would_have_succeeded(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $rice = $this->createInventoryItem($school, ['unit_of_measure' => 'kg']);
        $chicken = $this->createInventoryItem($school, ['unit_of_measure' => 'kg']);
        $oil = $this->createInventoryItem($school, ['unit_of_measure' => 'litre']);
        $location = $this->createInventoryLocation($school);
        $service = app(InventoryStockService::class);

        app(TenantContext::class)->withSchool($school, function () use ($service, $rice, $chicken, $oil, $location, $admin) {
            $service->receive($rice, $location, '10.000', $admin);
            $service->receive($chicken, $location, '0.500', $admin); // insufficient for the 0.750 requirement below
            $service->receive($oil, $location, '2.000', $admin);
        });

        try {
            app(TenantContext::class)->withSchool($school, fn () => $service->issueMany($location, [
                new IssueRequirement($rice->id, '1.500'),
                new IssueRequirement($chicken->id, '0.750'),
                new IssueRequirement($oil->id, '0.100'),
            ], $admin));
            $this->fail('Expected InsufficientStockException.');
        } catch (InsufficientStockException) {
            // expected
        }

        // Rice and oil would each individually have succeeded -- proves
        // sufficiency is validated for EVERY item after every lock is
        // held, never greedily/incrementally per-item.
        $this->assertSame('10.000', $this->balance($rice, $location)->quantity_on_hand);
        $this->assertSame('0.500', $this->balance($chicken, $location)->quantity_on_hand);
        $this->assertSame('2.000', $this->balance($oil, $location)->quantity_on_hand);

        $issueMovementCount = app(TenantContext::class)->withSchool($school, fn () => StockMovement::query()->where('movement_type', 'issue')->count());
        $this->assertSame(0, $issueMovementCount);
    }

    #[Test]
    public function insufficient_stock_against_a_location_with_no_prior_balance_row_rolls_back_the_newly_inserted_zero_balance(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $rice = $this->createInventoryItem($school, ['unit_of_measure' => 'kg']);
        $newItem = $this->createInventoryItem($school, ['unit_of_measure' => 'kg']);
        $location = $this->createInventoryLocation($school);
        $service = app(InventoryStockService::class);

        app(TenantContext::class)->withSchool($school, fn () => $service->receive($rice, $location, '10.000', $admin));

        $existsBefore = app(TenantContext::class)->withSchool($school, fn () => InventoryStockBalance::query()->where('inventory_item_id', $newItem->id)->exists());
        $this->assertFalse($existsBefore);

        try {
            app(TenantContext::class)->withSchool($school, fn () => $service->issueMany($location, [
                new IssueRequirement($rice->id, '1.000'),
                new IssueRequirement($newItem->id, '1.000'), // no balance row exists yet -- insufficient
            ], $admin));
            $this->fail('Expected InsufficientStockException.');
        } catch (InsufficientStockException) {
            // expected
        }

        $this->assertSame('10.000', $this->balance($rice, $location)->quantity_on_hand);
        $existsAfter = app(TenantContext::class)->withSchool($school, fn () => InventoryStockBalance::query()->where('inventory_item_id', $newItem->id)->exists());
        $this->assertFalse($existsAfter, 'A failed issueMany() must not leave behind a newly-created zero balance row.');

        $issueMovementCount = app(TenantContext::class)->withSchool($school, fn () => StockMovement::query()->where('movement_type', 'issue')->count());
        $this->assertSame(0, $issueMovementCount);
    }

    #[Test]
    public function a_duplicate_item_id_is_rejected_before_any_database_work(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $rice = $this->createInventoryItem($school, ['unit_of_measure' => 'kg']);
        $location = $this->createInventoryLocation($school);
        $service = app(InventoryStockService::class);

        app(TenantContext::class)->withSchool($school, fn () => $service->receive($rice, $location, '10.000', $admin));

        try {
            app(TenantContext::class)->withSchool($school, fn () => $service->issueMany($location, [
                new IssueRequirement($rice->id, '1.000'),
                new IssueRequirement($rice->id, '2.000'),
            ], $admin));
            $this->fail('Expected DuplicateInventoryItemRequirementException.');
        } catch (DuplicateInventoryItemRequirementException) {
            // expected
        }

        $this->assertSame('10.000', $this->balance($rice, $location)->quantity_on_hand, 'A duplicate requirement must be rejected before any balance is touched.');
        $issueMovementCount = app(TenantContext::class)->withSchool($school, fn () => StockMovement::query()->where('movement_type', 'issue')->count());
        $this->assertSame(0, $issueMovementCount);
    }

    #[Test]
    public function an_empty_requirement_set_is_rejected(): void
    {
        [, $school] = $this->createSchoolAdmin();
        $location = $this->createInventoryLocation($school);
        $service = app(InventoryStockService::class);

        $this->expectException(EmptyIssueRequirementsException::class);
        app(TenantContext::class)->withSchool($school, fn () => $service->issueMany($location, []));
    }

    #[Test]
    public function an_inactive_item_in_the_requirement_set_is_rejected_and_mutates_nothing(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $rice = $this->createInventoryItem($school, ['unit_of_measure' => 'kg']);
        $inactive = $this->createInventoryItem($school, ['unit_of_measure' => 'kg', 'status' => 'inactive']);
        $location = $this->createInventoryLocation($school);
        $service = app(InventoryStockService::class);

        app(TenantContext::class)->withSchool($school, fn () => $service->receive($rice, $location, '10.000', $admin));

        try {
            app(TenantContext::class)->withSchool($school, fn () => $service->issueMany($location, [
                new IssueRequirement($rice->id, '1.000'),
                new IssueRequirement($inactive->id, '1.000'),
            ], $admin));
            $this->fail('Expected ItemNotAvailableException.');
        } catch (ItemNotAvailableException) {
            // expected
        }

        $this->assertSame('10.000', $this->balance($rice, $location)->quantity_on_hand);
        $issueMovementCount = app(TenantContext::class)->withSchool($school, fn () => StockMovement::query()->where('movement_type', 'issue')->count());
        $this->assertSame(0, $issueMovementCount);
    }

    #[Test]
    public function an_inactive_location_is_rejected_and_mutates_nothing(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $rice = $this->createInventoryItem($school, ['unit_of_measure' => 'kg']);
        $location = $this->createInventoryLocation($school, ['status' => 'inactive']);
        $service = app(InventoryStockService::class);

        try {
            app(TenantContext::class)->withSchool($school, fn () => $service->issueMany($location, [
                new IssueRequirement($rice->id, '1.000'),
            ], $admin));
            $this->fail('Expected LocationNotAvailableException.');
        } catch (LocationNotAvailableException) {
            // expected
        }

        $existsAfter = app(TenantContext::class)->withSchool($school, fn () => InventoryStockBalance::query()->where('inventory_item_id', $rice->id)->exists());
        $this->assertFalse($existsAfter, 'No balance row should ever be created against an inactive Location.');
        $issueMovementCount = app(TenantContext::class)->withSchool($school, fn () => StockMovement::query()->where('movement_type', 'issue')->count());
        $this->assertSame(0, $issueMovementCount);
    }

    #[Test]
    public function an_item_id_that_does_not_exist_in_this_school_is_rejected_as_not_found(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $rice = $this->createInventoryItem($school, ['unit_of_measure' => 'kg']);
        $location = $this->createInventoryLocation($school);
        $service = app(InventoryStockService::class);

        app(TenantContext::class)->withSchool($school, fn () => $service->receive($rice, $location, '10.000', $admin));

        $this->expectException(InventoryItemNotFoundException::class);
        app(TenantContext::class)->withSchool($school, fn () => $service->issueMany($location, [
            new IssueRequirement($rice->id, '1.000'),
            new IssueRequirement((string) new UuidV7, '1.000'),
        ], $admin));
    }

    #[Test]
    public function an_item_belonging_to_a_different_school_is_rejected_as_not_found(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $otherSchool = $this->createSchool();
        $rice = $this->createInventoryItem($school, ['unit_of_measure' => 'kg']);
        $foreignItem = $this->createInventoryItem($otherSchool, ['unit_of_measure' => 'kg']);
        $location = $this->createInventoryLocation($school);
        $service = app(InventoryStockService::class);

        app(TenantContext::class)->withSchool($school, fn () => $service->receive($rice, $location, '10.000', $admin));

        $this->expectException(InventoryItemNotFoundException::class);
        app(TenantContext::class)->withSchool($school, fn () => $service->issueMany($location, [
            new IssueRequirement($rice->id, '1.000'),
            new IssueRequirement($foreignItem->id, '1.000'),
        ], $admin));
    }

    #[Test]
    public function exact_fractional_quantities_are_decremented_with_exact_decimal_arithmetic(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $rice = $this->createInventoryItem($school, ['unit_of_measure' => 'kg']);
        $oil = $this->createInventoryItem($school, ['unit_of_measure' => 'litre']);
        $location = $this->createInventoryLocation($school);
        $service = app(InventoryStockService::class);

        app(TenantContext::class)->withSchool($school, function () use ($service, $rice, $oil, $location, $admin) {
            $service->receive($rice, $location, '10.333', $admin);
            $service->receive($oil, $location, '1.250', $admin);
        });

        app(TenantContext::class)->withSchool($school, fn () => $service->issueMany($location, [
            new IssueRequirement($rice->id, '1.500'),
            new IssueRequirement($oil->id, '0.100'),
        ], $admin));

        // 10.333 - 1.500 = 8.833 exactly; 1.250 - 0.100 = 1.150 exactly.
        $this->assertSame('8.833', $this->balance($rice, $location)->quantity_on_hand);
        $this->assertSame('1.150', $this->balance($oil, $location)->quantity_on_hand);
    }

    #[Test]
    public function issuing_the_exact_remaining_quantity_for_every_item_leaves_zero_balances(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $rice = $this->createInventoryItem($school, ['unit_of_measure' => 'kg']);
        $oil = $this->createInventoryItem($school, ['unit_of_measure' => 'litre']);
        $location = $this->createInventoryLocation($school);
        $service = app(InventoryStockService::class);

        app(TenantContext::class)->withSchool($school, function () use ($service, $rice, $oil, $location, $admin) {
            $service->receive($rice, $location, '1.500', $admin);
            $service->receive($oil, $location, '0.100', $admin);
        });

        app(TenantContext::class)->withSchool($school, fn () => $service->issueMany($location, [
            new IssueRequirement($rice->id, '1.500'),
            new IssueRequirement($oil->id, '0.100'),
        ], $admin));

        $this->assertSame('0.000', $this->balance($rice, $location)->quantity_on_hand);
        $this->assertSame('0.000', $this->balance($oil, $location)->quantity_on_hand);
    }
}
