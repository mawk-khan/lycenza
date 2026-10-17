<?php

namespace Tests\Feature\Http;

use App\Domain\Canteen\Infrastructure\CanteenItemInventoryRequirement;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Inventory\Infrastructure\StockMovement;
use App\Domain\Timetable\Infrastructure\TimetablePeriod;
use App\Models\School;
use App\Models\User;
use App\Support\Http\WebDomainRefusalResponder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\ViewErrorBag;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCanteenFixtures;
use Tests\Feature\Timetable\Concerns\CreatesTimetableFixtures;
use Tests\TestCase;

/**
 * LC-1 (Local Product Completion M1): every case the audit confirmed as a
 * live 500 is now a controlled browser response -- the same request is
 * still refused, the page gets an explanation under `refusal` (or a 404),
 * and nothing is written. The JSON API refuses the same input with the
 * same rule (parity), so neither path is weaker.
 */
class WebDomainRefusalTest extends TestCase
{
    use CreatesCanteenFixtures, CreatesTimetableFixtures;

    private const UUID = '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i';

    private function signedIn(User $user, School $school): static
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");

        return $this;
    }

    private function inSchool(School $school, callable $fn): mixed
    {
        return app(TenantContext::class)->withSchool($school, $fn);
    }

    /**
     * The message under $key -- `refusal` (the shared layout banner) unless
     * the page's own controller already maps the refusal onto a field.
     */
    private function refusal(string $key = WebDomainRefusalResponder::ERROR_KEY): string
    {
        $errors = session('errors');
        $messages = $errors instanceof ViewErrorBag
            ? $errors->getBag('default')->get($key)
            : ($errors['default']['messages'][$key] ?? []);
        $this->assertNotEmpty($messages, "a refusal is returned as the `{$key}` form error");

        return (string) $messages[0];
    }

    #[Test]
    public function an_overlapping_timetable_period_is_refused_on_the_page(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['timetable.periods.view', 'timetable.periods.manage']);
        $this->createTimetablePeriod($school, ['code' => 'P1', 'start_time' => '08:30:00', 'end_time' => '09:15:00']);

        $this->signedIn($user, $school)
            ->from('/app/timetable-periods')
            ->post('/app/timetable-periods', ['code' => 'PX', 'name' => 'Overlap', 'start_time' => '08:30:00', 'end_time' => '09:15:00'])
            ->assertRedirect('/app/timetable-periods');

        $this->assertStringContainsString('overlaps an existing active Period', $this->refusal());
        $this->assertSame(1, $this->inSchool($school, fn () => TimetablePeriod::query()->count()));
    }

    #[Test]
    public function a_second_open_employment_and_an_end_before_start_are_refused_without_internal_ids(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['hr.employees.view', 'hr.employees.assignments.manage']);
        $employee = $this->createEmployee($school);
        $employment = $this->createEmploymentRecord($employee, ['starts_on' => '2026-01-01', 'ends_on' => null, 'status' => 'active']);
        $page = "/app/hr/employees/{$employee->id}";

        $this->signedIn($user, $school)->from($page)
            ->post("{$page}/employment-records", ['employment_type' => 'full_time', 'starts_on' => '2026-06-01'])
            ->assertRedirect($page);
        $overlap = $this->refusal();
        $this->assertStringContainsString('already has an employment that overlaps', $overlap);
        $this->assertDoesNotMatchRegularExpression(self::UUID, $overlap);

        $this->from($page)
            ->post("{$page}/employment-records/{$employment->id}/end", ['ends_on' => '1990-01-01'])
            ->assertRedirect($page);
        $date = $this->refusal();
        $this->assertStringContainsString("before this employment's start date", $date);
        $this->assertDoesNotMatchRegularExpression(self::UUID, $date);

        $this->inSchool($school, function () use ($employee, $employment): void {
            $this->assertSame(1, EmploymentRecord::query()->where('employee_id', $employee->id)->count());
            $this->assertNull($employment->fresh()->ends_on);
        });
    }

    #[Test]
    public function a_zero_recipe_quantity_is_refused_on_the_page_and_by_the_api(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $item = $this->createCanteenItem($school);
        $inventory = $this->createInventoryItem($school);
        $page = "/app/canteen-items/{$item->id}";

        foreach (['0', '0.000', '100000000000'] as $quantity) {
            $this->signedIn($user, $school)->from($page)
                ->post("{$page}/recipe", ['inventory_item_id' => $inventory->id, 'quantity_required' => $quantity])
                ->assertRedirect($page);
            $this->assertStringContainsString('greater than zero', $this->refusal());
        }

        $this->withHeader('Authorization', 'Bearer '.$user->createToken('lc1')->plainTextToken)
            ->postJson("/api/v1/schools/{$school->id}/canteen-items/{$item->id}/recipe", ['inventory_item_id' => $inventory->id, 'quantity_required' => '0'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CANTEEN_INVALID_RECIPE_QUANTITY');

        $this->assertSame(0, $this->inSchool($school, fn () => CanteenItemInventoryRequirement::query()->count()));
    }

    #[Test]
    public function an_oversized_stock_receipt_is_refused_on_the_page_and_by_the_api(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $item = $this->createInventoryItem($school);
        $location = $this->createInventoryLocation($school);
        $payload = ['inventory_item_id' => $item->id, 'inventory_location_id' => $location->id, 'quantity' => '100000000000'];

        $this->signedIn($user, $school)->from('/app/inventory-stock/receive')
            ->post('/app/inventory-stock/receive', $payload)
            ->assertRedirect('/app/inventory-stock/receive');
        // The stock page already maps a quantity refusal onto its own form field.
        $this->assertStringContainsString('cannot be more than 99,999,999,999.999', $this->refusal('inventory_item_id'));

        // A balance can never be pushed past what its column holds either.
        $this->post('/app/inventory-stock/receive', ['quantity' => '99999999999'] + $payload)->assertRedirect();
        $this->from('/app/inventory-stock/receive')->post('/app/inventory-stock/receive', ['quantity' => '1'] + $payload)->assertRedirect('/app/inventory-stock/receive');
        $this->assertStringContainsString('above 99,999,999,999.999', $this->refusal('inventory_item_id'));

        $this->withHeader('Authorization', 'Bearer '.$user->createToken('lc1')->plainTextToken)
            ->withHeader('Idempotency-Key', 'lc1-oversized-receipt')
            ->postJson("/api/v1/schools/{$school->id}/inventory-stock/receive", $payload)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVENTORY_INVALID_QUANTITY');

        $this->assertSame(1, $this->inSchool($school, fn () => StockMovement::query()->count()));
    }

    #[Test]
    public function malformed_ids_and_mistyped_paths_are_a_plain_not_found(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->signedIn($user, $school);

        foreach (['/app/attendance/not-a-uuid', '/app/communications/settings', '/app/students/not-a-uuid', '/app/hr/employees/123'] as $path) {
            $response = $this->get($path);
            $response->assertNotFound();
            $this->assertStringNotContainsString('SQLSTATE', (string) $response->getContent(), $path);
        }

        // A well-formed id that names nothing is still the same 404 (no existence leak).
        $this->get('/app/attendance/'.fake()->uuid())->assertNotFound();
    }

    #[Test]
    public function a_refusal_in_another_school_is_still_a_not_found(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['hr.employees.view', 'hr.employees.assignments.manage']);
        $other = $this->createSchool();
        $foreign = $this->createEmploymentRecord($this->createEmployee($other), ['starts_on' => '2026-01-01', 'ends_on' => null, 'status' => 'active']);

        $this->signedIn($user, $school)
            ->post("/app/hr/employees/{$foreign->employee_id}/employment-records/{$foreign->id}/end", ['ends_on' => '1990-01-01'])
            ->assertNotFound();
    }
}
