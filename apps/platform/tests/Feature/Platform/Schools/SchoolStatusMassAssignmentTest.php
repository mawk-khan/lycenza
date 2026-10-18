<?php

namespace Tests\Feature\Platform\Schools;

use App\Models\School;
use App\Support\Tenancy\SchoolStatus;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.1A (ADR 0076 §24, V-1): `schools.status` is the platform lifecycle
 * column. It is not mass assignable, so `create()`, `fill()` and `update()`
 * drop it even when a field whitelist slips; only the lifecycle services
 * write it, explicitly. The database transition trigger stays authoritative
 * underneath (SchoolLifecycleDatabaseInvariantsTest).
 */
class SchoolStatusMassAssignmentTest extends TestCase
{
    use CreatesTenancyFixtures, SchoolLifecycleTestHelpers;

    #[Test]
    public function status_is_not_fillable_on_the_model(): void
    {
        $school = new School;

        $this->assertFalse($school->isFillable('status'));
        $this->assertTrue($school->isFillable('name'), 'ordinary profile fields stay fillable');

        $school->fill(['name' => 'Filled', 'status' => SchoolStatus::Active->value]);
        $this->assertSame('Filled', $school->name);
        $this->assertNull($school->getAttribute('status'), 'fill() drops status');
    }

    #[Test]
    public function create_through_mass_assignment_cannot_choose_the_lifecycle_state(): void
    {
        $school = School::query()->create([
            'name' => 'Mass Assigned',
            'slug' => 'mass-assigned-'.Str::lower(Str::random(8)),
            'status' => SchoolStatus::Active->value,
        ]);

        $this->assertSame(SchoolStatus::Provisioning->value, $school->fresh()->status, 'the column default applies; the supplied status was dropped');
    }

    #[Test]
    public function update_through_mass_assignment_changes_profile_fields_but_never_status(): void
    {
        $school = $this->createSchool(['status' => SchoolStatus::Active->value]);

        $school->update(['name' => 'Renamed By Update', 'status' => SchoolStatus::Suspended->value]);

        $fresh = $school->fresh();
        $this->assertSame('Renamed By Update', $fresh->name);
        $this->assertSame(SchoolStatus::Active->value, $fresh->status);
    }

    #[Test]
    public function the_governed_lifecycle_path_still_transitions_the_school(): void
    {
        $root = $this->platformAdmin();
        $school = $this->provisionSchool($root, $this->createUser());
        $this->assertSame(SchoolStatus::Provisioning->value, $school->fresh()->status);

        $this->lifecycleAction($root, $school, 'activate')->assertSessionHasNoErrors();
        $this->assertSame(SchoolStatus::Active->value, $school->fresh()->status);

        $this->suspendViaPlatform($root, $school);
        $this->assertSame(SchoolStatus::Suspended->value, $school->fresh()->status);

        $this->lifecycleAction($root, $school, 'resume')->assertSessionHasNoErrors();
        $this->assertSame(SchoolStatus::Active->value, $school->fresh()->status);
    }

    #[Test]
    public function school_settings_update_still_works_and_ignores_a_smuggled_status(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");

        $this->put('/app/settings', [
            'name' => 'Settings Renamed',
            'timezone' => 'Asia/Kolkata',
            'default_locale' => 'en',
            'status' => SchoolStatus::Suspended->value,
        ])->assertRedirect('/app/settings');

        $fresh = $school->fresh();
        $this->assertSame('Settings Renamed', $fresh->name);
        $this->assertSame(SchoolStatus::Active->value, $fresh->status);
    }
}
