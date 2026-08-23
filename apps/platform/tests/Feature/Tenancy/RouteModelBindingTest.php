<?php

namespace Tests\Feature\Tenancy;

use App\Models\Campus;
use Illuminate\Support\Facades\Route as RouteFacade;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Section 37: tenant-scoped route model resolution must not resolve a
 * record belonging to another School -- the record fails to resolve
 * (404) rather than resolving-then-authorizing.
 */
class RouteModelBindingTest extends TestCase
{
    use CreatesTenancyFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        // Ad hoc test-only route binding a tenant-scoped Campus, to
        // prove SchoolScope protects implicit route model binding
        // generically -- no production route currently binds Campus
        // directly (no Campus-management UI exists yet), but any
        // future one automatically inherits this behaviour for free.
        RouteFacade::middleware(['web', 'auth'])
            ->get('/__test/campuses/{campus}', fn (Campus $campus) => response()->json(['id' => $campus->id]));
    }

    #[Test]
    public function a_campus_belonging_to_another_school_does_not_resolve_while_a_different_school_is_active(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $campusB = $this->createCampus($schoolB);

        $user = $this->createUser();
        $this->createMembership($user, $schoolA);
        $this->actingAs($user)->post("/app/schools/{$schoolA->id}/activate");

        $response = $this->get("/__test/campuses/{$campusB->id}");

        $response->assertNotFound();
    }

    #[Test]
    public function a_campus_belonging_to_the_active_school_resolves_normally(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);

        $user = $this->createUser();
        $this->createMembership($user, $school);
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");

        $response = $this->get("/__test/campuses/{$campus->id}");

        $response->assertOk();
        $response->assertJson(['id' => $campus->id]);
    }

    #[Test]
    public function school_id_tampering_in_the_activate_url_is_rejected_by_real_membership_check(): void
    {
        // SchoolSwitchController::store() route-model-binds School
        // (central, not RLS-scoped) then re-checks real membership --
        // this proves tampering the id in the URL still can't grant
        // access, per section 22 ("no access via manually altered IDs").
        $school = $this->createSchool();
        $user = $this->createUser();
        // No membership at all.

        $response = $this->actingAs($user)->post("/app/schools/{$school->id}/activate");

        $response->assertSessionHasErrors('school');
    }
}
