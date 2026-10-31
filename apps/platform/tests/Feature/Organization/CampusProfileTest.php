<?php

namespace Tests\Feature\Organization;

use App\Domain\Campuses\Application\CampusServiceType;
use App\Domain\Platform\Application\Modules\SchoolModuleEntitlements;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Database\Factories\SchoolFactory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.12C (ADR 0080 §8): the rich Campus profile -- structured address,
 * institutional contacts, coordinates (both or neither, in range, ~1 m) and
 * the code-owned service offerings, which are descriptive only and never
 * touch module entitlements, capabilities or Finance. Whole-School
 * `school.campuses.manage`; School-isolated in the application and the
 * database (composite FK, forced RLS, no runtime UPDATE).
 */
class CampusProfileTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function enter(User $user, School $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    private function in(School $school, callable $fn): mixed
    {
        return app(TenantContext::class)->withSchool($school, $fn);
    }

    /** @return list<string> */
    private function services(School $school, Campus $campus): array
    {
        return $this->in($school, fn () => DB::table('campus_services')->where('campus_id', $campus->id)->orderBy('service_code')->pluck('service_code')->all());
    }

    #[Test]
    public function a_campus_is_created_with_its_canonical_minimum_then_profiled_and_shown(): void
    {
        $school = $this->createSchool(['country_code' => 'IN']);
        $this->enter($this->schoolAdminFor($school), $school);

        $this->post('/app/school-setup/campuses', ['name' => 'Jubilee Hills', 'code' => 'jh'])->assertSessionHasNoErrors();
        $campus = $this->in($school, fn () => Campus::query()->where('code', 'JH')->firstOrFail());

        $this->put("/app/school-setup/campuses/{$campus->id}", [
            'phone' => '+914023456789', 'email' => 'jh@florets.example',
            'address_line1' => 'Road No. 36', 'address_line2' => 'Jubilee Hills', 'city' => 'Hyderabad',
            'state_region' => 'Telangana', 'postal_code' => '500033', 'country_code' => '',
            'latitude' => '17.43121', 'longitude' => '78.40742', 'services' => ['day_school', 'transport'],
        ])->assertSessionHasNoErrors();

        $page = $this->get("/app/school-setup/campuses/{$campus->id}")->assertOk()->viewData('page');
        $this->assertSame('App/SchoolSetup/Campus', $page['component']);
        $shown = $page['props']['campus'];
        $this->assertSame(['Road No. 36', 'Jubilee Hills', 'Hyderabad', null, '17.43121', '78.40742', ['day_school', 'transport']],
            [$shown['addressLine1'], $shown['addressLine2'], $shown['city'], $shown['countryCode'], $shown['latitude'], $shown['longitude'], $shown['services']]);
        $this->assertSame('IN', $page['props']['schoolCountryCode'], 'an empty Campus country means the School\'s');

        $list = $this->get('/app/school-setup/campuses')->assertOk()->viewData('page')['props']['campuses'][0];
        $this->assertSame(['id', 'name', 'code', 'status', 'city', 'services'], array_keys($list), 'list rows stay short');
    }

    #[Test]
    public function coordinates_are_both_or_neither_and_in_range(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $this->enter($this->schoolAdminFor($school), $school);
        $url = "/app/school-setup/campuses/{$campus->id}";

        foreach ([['latitude' => '90', 'longitude' => '180'], ['latitude' => '-90', 'longitude' => '-180'], ['latitude' => '0', 'longitude' => '0']] as $ok) {
            $this->put($url, $ok)->assertSessionHasNoErrors();
        }
        foreach ([
            [['latitude' => '90.00001', 'longitude' => '0'], 'latitude'], [['latitude' => '0', 'longitude' => '-180.5'], 'longitude'],
            [['latitude' => '12.5', 'longitude' => ''], 'longitude'], [['latitude' => '', 'longitude' => '77.6'], 'latitude'],
            [['latitude' => 'north', 'longitude' => '77.6'], 'latitude'],
        ] as [$bad, $field]) {
            $this->put($url, $bad)->assertSessionHasErrors($field);
        }
        $this->put($url, ['latitude' => '12.971594', 'longitude' => '77.594566'])->assertSessionHasNoErrors();
        $this->assertSame(['12.97159', '77.59457'], array_values($this->in($school, fn () => Campus::query()->findOrFail($campus->id)->only(['latitude', 'longitude']))), 'five decimals (~1 m)');

        $this->put($url, ['latitude' => '', 'longitude' => ''])->assertSessionHasNoErrors();
        $this->assertSame([null, null], array_values($this->in($school, fn () => Campus::query()->findOrFail($campus->id)->only(['latitude', 'longitude']))), 'the pair clears together');

        // The database refuses half a pair or an out-of-range value whatever the caller.
        foreach ([['latitude' => 10, 'longitude' => null], ['latitude' => 91, 'longitude' => 0], ['latitude' => 0, 'longitude' => 181]] as $raw) {
            $this->assertThrows(fn () => $this->in($school, fn () => DB::transaction(fn () => DB::table('campuses')->where('id', $campus->id)->update($raw))), QueryException::class);
        }
    }

    #[Test]
    public function deactivate_and_reactivate_keep_working_and_the_audit_names_fields_only(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school, ['name' => 'Main']);
        $this->enter($this->schoolAdminFor($school), $school);

        $this->put("/app/school-setup/campuses/{$campus->id}", ['status' => 'inactive'])->assertRedirect('/app/school-setup/campuses');
        $this->put("/app/school-setup/campuses/{$campus->id}", ['status' => 'active', 'address_line1' => '1 Secret Lane'])->assertSessionHasNoErrors();

        $audits = $this->in($school, fn () => DB::table('school_audit_events')->where('school_id', $school->id)->where('event_type', 'campus.updated')->pluck('metadata')->all());
        // The reactivation's own event, by content: both updates can share one
        // occurred_at second, so "the last by time" was not deterministic.
        $last = collect($audits)->map(fn (string $m) => json_decode($m, true))->firstWhere('before.status', 'inactive');
        $this->assertNotNull($last);
        $this->assertEqualsCanonicalizing(['status', 'address_line1'], $last['changed']);
        $this->assertSame(['status' => 'inactive'], $last['before']);
        $this->assertStringNotContainsString('Secret Lane', implode(' ', $audits));
    }

    #[Test]
    public function services_are_code_owned_idempotent_and_audited_as_changes(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $this->enter($this->schoolAdminFor($school), $school);
        $url = "/app/school-setup/campuses/{$campus->id}";

        $this->put($url, ['services' => ['transport', 'meals']])->assertSessionHasNoErrors();
        $this->put($url, ['services' => ['transport', 'meals']])->assertSessionHasNoErrors();
        $this->assertSame(['meals', 'transport'], $this->services($school, $campus));

        $this->put($url, ['services' => ['meals', 'boarding']])->assertSessionHasNoErrors();
        $this->assertSame(['boarding', 'meals'], $this->services($school, $campus));

        $this->put($url, ['services' => ['spa']])->assertSessionHasErrors('services.0');
        $this->put($url, ['services' => ['meals', 'meals']])->assertSessionHasErrors();
        $this->put($url, ['name' => 'Renamed'])->assertSessionHasNoErrors();
        $this->assertSame(['boarding', 'meals'], $this->services($school, $campus), 'a submit without services leaves them alone');
        $this->put($url, ['services' => []])->assertSessionHasNoErrors();
        $this->assertSame([], $this->services($school, $campus));

        $changes = $this->in($school, fn () => DB::table('school_audit_events')->where('school_id', $school->id)->where('event_type', 'campus.services_changed')
            // SIS 11.H1: occurred_at has one-second precision; the UUIDv7 id is the write order.
            ->orderBy('id')->pluck('metadata')->map(fn ($m) => json_decode($m, true))->all());
        $this->assertSame([
            ['added' => ['meals', 'transport'], 'removed' => []],
            ['added' => ['boarding'], 'removed' => ['transport']],
            ['added' => [], 'removed' => ['boarding', 'meals']],
        ], $changes, 'one event per real change; the identical resubmit wrote nothing');

        // The database refuses an unknown code and a duplicate whatever the caller.
        $insert = fn (string $code) => $this->in($school, fn () => DB::transaction(fn () => DB::table('campus_services')->insert(['id' => (string) str()->uuid(), 'school_id' => $school->id, 'campus_id' => $campus->id, 'service_code' => $code])));
        $insert('meals');
        $this->assertThrows(fn () => $insert('meals'), QueryException::class);
        $this->assertThrows(fn () => $insert('helipad'), QueryException::class);
        $this->assertEqualsCanonicalizing(array_column(CampusServiceType::options(), 'code'), ['day_school', 'early_years', 'boarding', 'meals', 'transport']);
    }

    #[Test]
    public function offering_a_service_never_touches_entitlements_capabilities_or_other_modules(): void
    {
        $school = School::factory()->withCoreModulesOnly()->create();
        $campus = $this->createCampus($school);
        $admin = $this->schoolAdminFor($school);
        $entitled = app(SchoolModuleEntitlements::class)->entitledModules($school);
        $capabilities = app(CapabilityResolver::class)->schoolCapabilities($admin, $school);
        $counts = fn (): array => $this->in($school, fn () => array_map(fn (string $t) => DB::table($t)->count(), ['school_module_entitlements', 'transport_routes', 'hostels', 'canteen_outlets', 'charges', 'fee_heads']));
        $before = $counts();

        $this->enter($admin, $school);
        $this->put("/app/school-setup/campuses/{$campus->id}", ['services' => array_column(CampusServiceType::options(), 'code')])->assertSessionHasNoErrors();

        app(SchoolModuleEntitlements::class)->forget($school->id);
        $this->assertSame($entitled, app(SchoolModuleEntitlements::class)->entitledModules($school), 'Transport/Boarding/Meals services enable no module');
        $this->assertEqualsCanonicalizing($capabilities, app(CapabilityResolver::class)->schoolCapabilities($admin, $school));
        $this->assertSame($before, $counts(), 'no route, hostel, outlet, charge or fee head appears');

        // ...and enabling the Transport module claims no Campus offers transport.
        SchoolFactory::setOptionalModules($school, true, ['transport']);
        $other = $this->createCampus($school);
        $this->assertSame([], $this->services($school, $other));
    }

    #[Test]
    public function campus_profiles_are_school_isolated_in_the_application_and_the_database(): void
    {
        $a = $this->createSchool();
        $b = $this->createSchool();
        $campusB = $this->createCampus($b, ['name' => 'B Campus']);
        $this->enter($this->schoolAdminFor($a), $a);

        $this->get("/app/school-setup/campuses/{$campusB->id}")->assertNotFound();
        $this->put("/app/school-setup/campuses/{$campusB->id}", ['services' => ['meals'], 'city' => 'X'])->assertNotFound();
        $this->assertSame([], $this->services($b, $campusB));

        // A service row naming another School's Campus is refused by the composite key...
        $this->assertThrows(fn () => $this->in($a, fn () => DB::transaction(fn () => DB::table('campus_services')->insert(['id' => (string) str()->uuid(), 'school_id' => $a->id, 'campus_id' => $campusB->id, 'service_code' => 'meals']))), QueryException::class);
        // ...and RLS hides B's rows from A's context.
        $this->in($b, fn () => DB::table('campus_services')->insert(['id' => (string) str()->uuid(), 'school_id' => $b->id, 'campus_id' => $campusB->id, 'service_code' => 'meals']));
        $this->assertSame(0, $this->in($a, fn () => DB::table('campus_services')->count()));
        $this->assertSame(1, $this->in($b, fn () => DB::table('campus_services')->count()));
        $this->assertFalse((bool) DB::selectOne("select has_table_privilege('school_os_app', 'campus_services', 'UPDATE') as p")->p, 'rows are inserted or deleted, never updated');
    }

    #[Test]
    public function only_whole_school_campus_managers_edit_and_others_read_or_get_nothing(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $principal = $this->createUser();
        $this->assignSchoolRole($this->createMembership($principal, $school), 'principal');
        $this->enter($principal, $school);
        $this->assertFalse($this->get("/app/school-setup/campuses/{$campus->id}")->assertOk()->viewData('page')['props']['canManage']);
        $this->put("/app/school-setup/campuses/{$campus->id}", ['city' => 'Nope'])->assertForbidden();

        $teacher = $this->createUser();
        $this->assignSchoolRole($this->createMembership($teacher, $school), 'teacher');
        $this->enter($teacher, $school);
        $this->get("/app/school-setup/campuses/{$campus->id}")->assertForbidden();
    }

    #[Test]
    public function the_campus_list_query_count_does_not_grow_with_campuses(): void
    {
        $school = $this->createSchool();
        $admin = $this->schoolAdminFor($school);
        $this->enter($admin, $school);
        $counts = [];
        $n = 0;
        DB::listen(function () use (&$n): void {
            $n++;
        });
        foreach ([1, 10, 50] as $size) {
            while ($this->in($school, fn () => Campus::query()->count()) < $size) {
                $campus = $this->createCampus($school);
                $this->in($school, fn () => DB::table('campus_services')->insert(['id' => (string) str()->uuid(), 'school_id' => $school->id, 'campus_id' => $campus->id, 'service_code' => 'meals']));
            }
            $this->get('/app/school-setup/campuses')->assertOk();
            $n = 0;
            $this->get('/app/school-setup/campuses')->assertOk();
            $counts[$size] = $n;
        }

        $this->assertSame($counts[1], $counts[50], 'Campus list queries: '.json_encode($counts));
    }
}
