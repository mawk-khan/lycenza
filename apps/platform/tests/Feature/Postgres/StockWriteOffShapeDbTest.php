<?php

namespace Tests\Feature\Postgres;

use App\Domain\Inventory\Application\InventoryStockService;
use App\Domain\Inventory\Infrastructure\InventoryItem;
use App\Domain\Inventory\Infrastructure\InventoryLocation;
use App\Models\School;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRls;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.9C-2 (ADR 0076 §70): the database itself holds the write-off and
 * issue-recipient shapes, as the runtime role in raw SQL -- a write-off
 * always carries exactly one listed reason, nothing else carries one, only
 * an issue names a Department (of the same School), a form key is claimed
 * once per School, and the runtime role still cannot change or delete a
 * movement (ORG.7H-1R) or re-point a write-off to another Location.
 */
class StockWriteOffShapeDbTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private InventoryItem $item;

    private InventoryLocation $location;

    private InventoryLocation $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->item = $this->createInventoryItem($this->school, ['status' => 'active', 'unit_of_measure' => 'each']);
        $this->location = $this->createInventoryLocation($this->school, ['status' => 'active']);
        $this->other = $this->createInventoryLocation($this->school, ['status' => 'active']);
    }

    private function runtime(): Connection
    {
        $db = DB::connection('pgsql');
        $db->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $this->school->id]);

        return $db;
    }

    /**
     * One raw runtime-role INSERT inside a savepoint: 'ok', or the violated constraint / 'denied'.
     *
     * @param  array<string, mixed>  $columns
     */
    private function insert(array $columns): string
    {
        $row = [
            'id' => (string) new UuidV7,
            'school_id' => $this->school->id,
            'inventory_item_id' => $this->item->id,
            'movement_type' => 'write_off',
            'from_location_id' => $this->location->id,
            'to_location_id' => null,
            'quantity' => '1.000',
            'occurred_at' => now(),
            'created_at' => now(),
            ...$columns,
        ];

        return $this->attempt(fn () => DB::connection('pgsql')->table('stock_movements')->insert($row));
    }

    private function attempt(callable $statement): string
    {
        try {
            $this->runtime()->transaction($statement);

            return 'ok';
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'permission denied')) {
                return 'denied';
            }
            preg_match('/constraint "([a-z_]+)"/', $e->getMessage(), $m);

            return $m[1] ?? $e->getMessage();
        }
    }

    #[Test]
    public function a_write_off_carries_exactly_one_listed_reason_and_nothing_else_carries_one(): void
    {
        $this->assertSame('ok', $this->insert(['write_off_reason' => 'damaged']));
        $this->assertSame('stock_movements_write_off_reason_check', $this->insert([]));
        $this->assertSame('stock_movements_write_off_reason_check', $this->insert(['write_off_reason' => 'other']));
        $this->assertSame('stock_movements_write_off_reason_check', $this->insert(['write_off_reason' => 'count_correction']));
        $this->assertSame('stock_movements_write_off_reason_check', $this->insert(['movement_type' => 'issue', 'write_off_reason' => 'lost']));
        $this->assertSame('stock_movements_shape_check', $this->insert(['write_off_reason' => 'lost', 'to_location_id' => $this->other->id]));
        $this->assertSame('stock_movements_shape_check', $this->insert(['write_off_reason' => 'lost', 'from_location_id' => null]));
        $this->assertSame('stock_movements_movement_type_check', $this->insert(['movement_type' => 'adjustment']));
    }

    #[Test]
    public function only_an_issue_names_a_department_and_only_of_its_own_school(): void
    {
        $department = $this->createDepartment($this->school, ['campus_id' => null, 'status' => 'active']);
        $foreign = $this->createDepartment($this->createSchool(), ['campus_id' => null, 'status' => 'active']);

        $this->assertSame('ok', $this->insert(['movement_type' => 'issue', 'recipient_department_id' => $department->id]));
        $this->assertSame('stock_movements_recipient_issue_only_check', $this->insert(['write_off_reason' => 'lost', 'recipient_department_id' => $department->id]));
        $this->assertSame('stock_movements_recipient_issue_only_check', $this->insert(['movement_type' => 'receipt', 'from_location_id' => null, 'to_location_id' => $this->location->id, 'recipient_department_id' => $department->id]));
        $this->assertSame('stock_movements_recipient_department_fk', $this->insert(['movement_type' => 'issue', 'recipient_department_id' => $foreign->id]));
    }

    #[Test]
    public function a_form_key_is_claimed_once_per_school(): void
    {
        $key = (string) Str::uuid();
        $this->assertSame('ok', $this->insert(['write_off_reason' => 'lost', 'idempotency_key' => $key]));
        $this->assertSame('stock_movements_idempotency_unique', $this->insert(['write_off_reason' => 'lost', 'idempotency_key' => $key]));
    }

    #[Test]
    public function the_runtime_role_cannot_change_delete_or_repoint_a_recorded_write_off(): void
    {
        $actor = $this->createUserWithCapabilities($this->school, ['inventory.stock.manage']);
        $service = app(InventoryStockService::class);
        $movement = app(TenantContext::class)->withSchool($this->school, function () use ($service, $actor) {
            $service->receive($this->item, $this->location, '5');

            return $service->writeOff($this->school, $this->item, $this->location, '2', 'damaged', $actor, new FreshMfaProof($actor->id, CarbonImmutable::now()));
        });
        $this->assertSame('write_off', $movement->movement_type);

        foreach ([
            'reason' => "update stock_movements set write_off_reason = 'lost' where id = ?",
            'quantity' => 'update stock_movements set quantity = 1 where id = ?',
            're-point' => 'update stock_movements set from_location_id = ? where id = ?',
            'delete' => 'delete from stock_movements where id = ?',
        ] as $label => $sql) {
            $bindings = $label === 're-point' ? [$this->other->id, $movement->id] : [$movement->id];
            $this->assertSame('denied', $this->attempt(fn () => DB::connection('pgsql')->affectingStatement($sql, $bindings)), $label);
        }
        $row = $this->runtime()->selectOne('select write_off_reason, quantity, from_location_id from stock_movements where id = ?', [$movement->id]);
        $this->assertSame(['damaged', '2.000', $this->location->id], [$row->write_off_reason, $row->quantity, $row->from_location_id]);
        $this->assertSame('3.000', $this->runtime()->selectOne('select quantity_on_hand from inventory_stock_balances where inventory_location_id = ?', [$this->location->id])->quantity_on_hand);
    }
}
