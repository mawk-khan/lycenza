<?php

namespace Tests\Feature\Corrections;

use App\Models\School;
use App\Models\User;
use App\Support\Settings\SettingDefinition;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * X-02 (OD-V11-7, ADR 0075 section 12): a School timezone is validated at
 * its write paths against BOTH consumers -- PHP's canonical zone list and
 * PostgreSQL's (database triggers evaluate `now() AT TIME ZONE` on it).
 */
class SchoolTimezoneValidationTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function manager(School $school): User
    {
        $user = $this->createUserWithCapabilities($school, ['school.settings.view', 'school.settings.manage']);
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");

        return $user;
    }

    private function save(School $school, string $timezone): TestResponse
    {
        return $this->put('/app/settings', ['name' => $school->name, 'timezone' => $timezone, 'default_locale' => 'en']);
    }

    private function storedTimezone(School $school): string
    {
        return app(TenantContext::class)->withSchool($school, fn () => School::query()->findOrFail($school->id)->timezone);
    }

    #[Test]
    public function a_valid_timezone_is_saved_and_every_consumer_accepts_it(): void
    {
        $school = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $this->manager($school);

        $this->save($school, 'Asia/Dubai')->assertRedirect('/app/settings')->assertSessionHasNoErrors();

        $this->assertSame('Asia/Dubai', $this->storedTimezone($school));
        $this->assertSame('Asia/Dubai', SchoolTimezone::resolve(School::query()->findOrFail($school->id))->getName());
        // The database consumer (late-fee and receipt triggers) evaluates it.
        $this->assertNotNull(DB::selectOne('SELECT (now() AT TIME ZONE ?)::date AS d', ['Asia/Dubai'])->d);
    }

    #[Test]
    public function invalid_malformed_and_deprecated_values_are_refused_and_nothing_changes(): void
    {
        $school = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $this->manager($school);

        foreach (['Asia/Kolkatta', 'not a timezone', 'asia/kolkata', 'Asia/Calcutta', '+05:30', 'UTC; DROP TABLE schools', ''] as $bad) {
            $this->save($school, $bad)->assertSessionHasErrors('timezone');
        }

        $this->assertSame('Asia/Kolkata', $this->storedTimezone($school), 'The existing valid value is kept.');
    }

    #[Test]
    public function saving_other_fields_keeps_an_existing_valid_timezone(): void
    {
        $school = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $this->manager($school);

        $this->put('/app/settings', ['name' => 'Renamed School', 'timezone' => 'Asia/Kolkata', 'default_locale' => 'en'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Asia/Kolkata', $this->storedTimezone($school));
    }

    #[Test]
    public function only_a_settings_manager_can_change_it(): void
    {
        $school = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $viewer = $this->createUserWithCapabilities($school, ['school.settings.view']);
        $this->actingAs($viewer)->post("/app/schools/{$school->id}/activate");

        $this->save($school, 'Asia/Dubai')->assertForbidden();
        $this->assertSame('Asia/Kolkata', $this->storedTimezone($school));
    }

    #[Test]
    public function the_offered_list_contains_only_zones_both_consumers_accept(): void
    {
        $school = $this->createSchool();
        $this->manager($school);

        $offered = $this->get('/app/settings')->assertOk()->viewData('page')['props']['timezones'];

        $this->assertContains('Asia/Kolkata', $offered);
        $this->assertNotContains('Asia/Calcutta', $offered, 'No deprecated aliases.');
        $this->assertSame($offered, SchoolTimezone::identifiers());
        foreach (['Asia/Kolkata', 'Europe/London', 'America/New_York', 'UTC'] as $zone) {
            $this->assertTrue(SchoolTimezone::isSupported($zone), $zone);
        }
    }

    #[Test]
    public function the_settings_registry_timezone_type_uses_the_same_contract(): void
    {
        $definition = new SettingDefinition(key: 'test.timezone', type: 'timezone', default: 'Asia/Kolkata');

        $this->assertTrue($definition->validate('Asia/Kolkata'));
        $this->assertFalse($definition->validate('Asia/Kolkatta'));
        $this->assertFalse($definition->validate('Asia/Calcutta'));
    }
}
