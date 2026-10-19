<?php

namespace Tests\Feature\Canteen;

use App\Models\School;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCanteenFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.7D-0 R3 (ADR 0076 §36): the Canteen order-entry Student picker needs
 * `canteen.orders.manage` on BOTH the web page and the API -- one authority
 * contract for one purpose. A read-only order viewer keeps reading orders
 * but never reaches the School-wide Student picker. No new capability; no
 * Campus semantics; the response is unchanged.
 */
class CanteenStudentPickerAuthorityTest extends TestCase
{
    use CreatesCanteenFixtures, CreatesTenancyFixtures;

    private function api(User $user): static
    {
        app('auth')->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$user->createToken('t')->plainTextToken);
    }

    /** @return array{0: int, 1: int} [web status, API status] of the picker for $user */
    private function picker(User $user, School $school): array
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
        $web = $this->getJson('/app/canteen-orders/search/students?q=Ananya')->status();
        $api = $this->api($user)->getJson("/api/v1/schools/{$school->id}/canteen-orders/search/students?q=Ananya")->status();

        return [$web, $api];
    }

    #[Test]
    public function web_and_api_require_orders_manage_and_agree(): void
    {
        $school = $this->createSchool();
        $this->createStudent($school, ['first_name' => 'Ananya', 'status' => 'active']);

        $this->assertSame([200, 200], $this->picker($this->createUserWithCapabilities($school, ['canteen.orders.manage']), $school));
        $this->assertSame([403, 403], $this->picker($this->createUserWithCapabilities($school, ['canteen.orders.view']), $school));
        $this->assertSame([403, 403], $this->picker($this->createUserWithCapabilities($school, ['canteen.directory.manage']), $school));
    }

    #[Test]
    public function the_picker_response_is_minimal_and_school_isolated(): void
    {
        $school = $this->createSchool();
        $other = $this->createSchool();
        $mine = $this->createStudent($school, ['first_name' => 'Ananya', 'status' => 'active']);
        $this->createStudent($other, ['first_name' => 'Ananya', 'status' => 'active']);
        $user = $this->createUserWithCapabilities($school, ['canteen.orders.manage']);

        $rows = $this->api($user)->getJson("/api/v1/schools/{$school->id}/canteen-orders/search/students?q=Ananya")->assertOk()->json('data');
        // ORG.7D-3 (ADR 0076 §39, D2-7): the minimal identity -- one display name.
        $this->assertSame([['id' => $mine->id, 'studentNumber' => $mine->student_number, 'name' => trim('Ananya '.$mine->last_name)]], $rows);

        // Another School's path is refused before the picker runs (not a member: 404, as before).
        $this->api($user)->getJson("/api/v1/schools/{$other->id}/canteen-orders/search/students?q=Ananya")->assertNotFound();
    }

    #[Test]
    public function a_read_only_viewer_still_reads_orders_without_the_picker(): void
    {
        $school = $this->createSchool();
        $viewer = $this->createUserWithCapabilities($school, ['canteen.orders.view']);

        $this->api($viewer)->getJson("/api/v1/schools/{$school->id}/canteen-orders")->assertOk();
        $this->actingAs($viewer)->post("/app/schools/{$school->id}/activate");
        $this->get('/app/canteen-orders')->assertOk();
    }
}
