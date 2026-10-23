<?php

namespace Tests\Feature\Inventory;

use App\Domain\HR\Infrastructure\Department;
use App\Domain\Inventory\Application\InventoryStockService;
use App\Domain\Inventory\Infrastructure\InventoryItem;
use App\Domain\Inventory\Infrastructure\InventoryLocation;
use App\Domain\Inventory\Infrastructure\InventoryStockBalance;
use App\Domain\Inventory\Infrastructure\StockMovement;
use App\Models\Campus;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.9C-2 (ADR 0076 §70): an issue is terminal consumption out of ONE
 * Location. It may name an HR Department (organisational, no personal data)
 * that is active and School-wide or on the Location's Campus; it never
 * names a person and never moves stock into another Location (that is a
 * transfer). A web issue is claimed on a server-issued form key.
 */
class InventoryIssueRecipientTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private Campus $a;

    private Campus $b;

    private InventoryItem $item;

    /** @var array<string, InventoryLocation> */
    private array $loc = [];

    /** @var array<string, Department> */
    private array $dept = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->a = $this->createCampus($this->school);
        $this->b = $this->createCampus($this->school);
        $this->item = $this->createInventoryItem($this->school, ['status' => 'active', 'unit_of_measure' => 'each']);
        $this->loc['a'] = $this->createInventoryLocation($this->school, ['campus_id' => $this->a->id, 'code' => 'STORE-A', 'status' => 'active']);
        $this->loc['b'] = $this->createInventoryLocation($this->school, ['campus_id' => $this->b->id, 'code' => 'STORE-B', 'status' => 'active']);
        $this->loc['school'] = $this->createInventoryLocation($this->school, ['campus_id' => null, 'code' => 'CENTRAL', 'status' => 'active']);
        $this->dept['wide'] = $this->createDepartment($this->school, ['code' => 'ADMIN', 'name' => 'Administration', 'campus_id' => null, 'status' => 'active']);
        $this->dept['a'] = $this->createDepartment($this->school, ['code' => 'SCI-A', 'name' => 'Science North', 'campus_id' => $this->a->id, 'status' => 'active']);
        $this->dept['b'] = $this->createDepartment($this->school, ['code' => 'SCI-B', 'name' => 'Science South', 'campus_id' => $this->b->id, 'status' => 'active']);
        $this->dept['retired'] = $this->createDepartment($this->school, ['code' => 'OLD', 'name' => 'Old Office', 'campus_id' => null, 'status' => 'inactive']);
        $this->inSchool(function (): void {
            foreach ($this->loc as $location) {
                app(InventoryStockService::class)->receive($this->item, $location, '10');
            }
        });
    }

    private function inSchool(callable $fn): mixed
    {
        return app(TenantContext::class)->withSchool($this->school, $fn);
    }

    /** @param  list<?string>  $campusIds */
    private function officer(array $campusIds): User
    {
        $user = $this->createUser();
        $membership = $this->createMembership($user, $this->school);
        foreach ($campusIds as $campusId) {
            $this->assignSchoolRole($membership, 'stores_officer', $campusId);
        }
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");

        return $user;
    }

    /** @param  array<string, mixed>  $overrides */
    private function issue(string $location, ?string $department, array $overrides = []): TestResponse
    {
        return $this->post('/app/inventory-stock/issue', [
            'inventory_item_id' => $this->item->id,
            'inventory_location_id' => $this->loc[$location]->id,
            'quantity' => '2',
            'recipient_department_id' => $department === null ? null : $this->dept[$department]->id,
            'idempotency_key' => $this->formKey(),
            ...$overrides,
        ]);
    }

    /** The key the issue form issued to this session. */
    private function formKey(): string
    {
        return $this->get('/app/inventory-stock/issue')->assertOk()->viewData('page')['props']['idempotencyKey'];
    }

    private function onHand(string $location): string
    {
        return $this->inSchool(fn () => InventoryStockBalance::query()->where('inventory_location_id', $this->loc[$location]->id)->value('quantity_on_hand'));
    }

    /** @return list<?string> recipient department ids of the issues, oldest first */
    private function issuedTo(): array
    {
        return $this->inSchool(fn () => StockMovement::query()->where('movement_type', 'issue')->orderBy('created_at')->orderBy('id')->pluck('recipient_department_id')->all());
    }

    #[Test]
    public function an_issue_may_name_a_department_and_the_history_shows_it(): void
    {
        $this->officer([null]);
        $this->get('/app/inventory-stock/issue')->assertOk()->assertInertia(fn ($p) => $p->has('idempotencyKey'));

        $this->issue('a', 'a')->assertRedirect('/app/inventory-stock');
        $this->issue('a', null)->assertRedirect('/app/inventory-stock');

        $this->assertSame([$this->dept['a']->id, null], $this->issuedTo());
        $this->assertSame('6.000', $this->onHand('a'));
        $audit = $this->inSchool(fn () => SchoolAuditEvent::query()->where('event_type', 'inventory.stock.issued')->orderBy('occurred_at')->orderBy('id')->first());
        $this->assertSame($this->dept['a']->id, $audit->metadata['recipientDepartmentId']);

        $rows = $this->get('/app/inventory-stock?movement_type=issue')->assertOk()->viewData('page')['props']['movements']['data'];
        $this->assertEqualsCanonicalizing(['Science North', null], array_column($rows, 'recipientDepartment'));
    }

    #[Test]
    public function only_an_active_department_school_wide_or_on_the_locations_campus_may_receive(): void
    {
        $this->officer([null]);

        $this->issue('a', 'wide')->assertRedirect();
        $this->issue('a', 'a')->assertRedirect();
        $this->issue('school', 'b')->assertRedirect('/app/inventory-stock'); // a School-wide store serves any Campus's department
        $this->issue('a', 'b')->assertSessionHasErrors('recipient_department_id');
        $this->issue('a', 'retired')->assertSessionHasErrors('recipient_department_id');
        $this->issue('a', null, ['recipient_department_id' => (string) Str::uuid()])->assertSessionHasErrors('recipient_department_id');
        $this->issue('a', null, ['recipient_department_id' => 'not-a-uuid'])->assertSessionHasErrors('recipient_department_id');

        $other = $this->createSchool();
        $foreign = $this->createDepartment($other, ['code' => 'X', 'name' => 'Elsewhere', 'campus_id' => null, 'status' => 'active']);
        $this->issue('a', null, ['recipient_department_id' => $foreign->id])->assertSessionHasErrors('recipient_department_id');

        $this->assertSame('6.000', $this->onHand('a'));
        $this->assertSame('8.000', $this->onHand('school'));
    }

    #[Test]
    public function the_department_lookup_offers_only_school_wide_and_in_scope_departments(): void
    {
        $this->officer([null]);
        $all = collect($this->getJson('/app/inventory-stock/search/departments')->assertOk()->json('data'))->pluck('code')->all();
        $this->assertEqualsCanonicalizing(['ADMIN', 'SCI-A', 'SCI-B'], $all, 'never an inactive department');
        $this->assertSame(['id', 'code', 'name'], array_keys($this->getJson('/app/inventory-stock/search/departments?q=sci')->json('data.0')));

        $this->officer([$this->a->id]);
        $scoped = collect($this->getJson('/app/inventory-stock/search/departments')->assertOk()->json('data'))->pluck('code')->all();
        $this->assertEqualsCanonicalizing(['ADMIN', 'SCI-A'], $scoped, 'never another Campus\'s department');

        $viewer = $this->createUserWithCapabilities($this->school, ['inventory.stock.view']);
        $this->actingAs($viewer)->post("/app/schools/{$this->school->id}/activate");
        $this->getJson('/app/inventory-stock/search/departments')->assertForbidden();
    }

    #[Test]
    public function a_retried_issue_is_recorded_once_and_a_reused_key_with_other_terms_is_refused(): void
    {
        $this->officer([null]);
        $key = $this->formKey();

        $this->issue('a', 'a', ['idempotency_key' => $key])->assertRedirect('/app/inventory-stock');
        $this->issue('a', 'a', ['idempotency_key' => $key])->assertRedirect('/app/inventory-stock');
        $this->assertSame([[$this->dept['a']->id], '8.000'], [$this->issuedTo(), $this->onHand('a')]);

        $this->issue('a', 'wide', ['idempotency_key' => $key])->assertSessionHasErrors('inventory_item_id');
        $this->issue('a', 'a', ['idempotency_key' => $key, 'quantity' => '3'])->assertSessionHasErrors('inventory_item_id');
        $this->issue('a', 'a', ['idempotency_key' => ''])->assertSessionHasErrors('idempotency_key');
        $this->assertSame('8.000', $this->onHand('a'));
    }

    #[Test]
    public function an_issue_never_moves_stock_into_another_location(): void
    {
        $this->officer([null]);
        $this->issue('a', null, ['to_location_id' => $this->loc['b']->id])->assertRedirect('/app/inventory-stock');

        $movement = $this->inSchool(fn () => StockMovement::query()->where('movement_type', 'issue')->sole());
        $this->assertNull($movement->to_location_id, 'an issue leaves stock; a transfer moves it');
        $this->assertSame(['8.000', '10.000'], [$this->onHand('a'), $this->onHand('b')]);
    }

    #[Test]
    public function the_api_issue_accepts_a_department_and_keeps_its_scope(): void
    {
        $officer = $this->createUser();
        $this->assignSchoolRole($this->createMembership($officer, $this->school), 'stores_officer', $this->a->id);
        $token = $officer->createToken('t')->plainTextToken;
        $base = "/api/v1/schools/{$this->school->id}/inventory-stock/issue";
        $body = ['inventory_item_id' => $this->item->id, 'inventory_location_id' => $this->loc['a']->id, 'quantity' => '1'];

        $this->withHeader('Authorization', "Bearer {$token}")->withHeader('Idempotency-Key', 'issue-api-1')->postJson($base, [...$body, 'recipient_department_id' => $this->dept['a']->id])
            ->assertCreated()->assertJsonPath('data.recipientDepartmentId', $this->dept['a']->id);
        $this->withHeader('Idempotency-Key', 'issue-api-2')->postJson($base, [...$body, 'recipient_department_id' => $this->dept['b']->id])->assertUnprocessable();
        $this->withHeader('Idempotency-Key', 'issue-api-3')->postJson($base, [...$body, 'inventory_location_id' => $this->loc['b']->id])->assertNotFound();
        $this->assertSame('9.000', $this->onHand('a'));
    }
}
