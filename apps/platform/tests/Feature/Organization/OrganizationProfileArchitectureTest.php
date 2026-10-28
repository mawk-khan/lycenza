<?php

namespace Tests\Feature\Organization;

use App\Domain\Campuses\Application\CampusServiceType;
use App\Models\School;
use App\Support\Approval\ApprovalWorkflowRegistry;
use App\Support\Setup\OrganizationSetupReadService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Finder\Finder;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.12C (ADR 0080 §8): profile boundaries that must hold whatever the UI.
 *
 * - No Campus timezone or currency; no tax/statutory or secret-shaped
 *   column on `schools`, `campuses` or `campus_services`; no generic
 *   metadata column.
 * - The service catalogue equals the database CHECK, and the Campus code
 *   never reaches module entitlements, capabilities or other modules.
 * - The identity writer is a declared direct-assured action; identity
 *   fields are not mass assignable.
 * - Readiness asks only for the required minimum: optional rich-profile
 *   fields never make a School incomplete.
 */
class OrganizationProfileArchitectureTest extends TestCase
{
    use CreatesTenancyFixtures;

    /** @return list<string> */
    private function columns(string $table): array
    {
        return DB::table('information_schema.columns')->where('table_schema', 'public')->where('table_name', $table)->pluck('column_name')->all();
    }

    #[Test]
    public function profiles_hold_no_campus_timezone_currency_tax_secret_or_free_form_metadata(): void
    {
        $this->assertNotContains('timezone', $this->columns('campuses'), 'the School timezone stays canonical (OD-12.3)');
        $this->assertNotContains('currency', $this->columns('campuses'), 'Finance owns the currency (OD-12.4)');
        $this->assertNotContains('currency', $this->columns('schools'));
        $this->assertNotContains('address', $this->columns('campuses'), 'one structured address shape, no free-text twin');

        foreach (['schools', 'campuses', 'campus_services'] as $table) {
            foreach ($this->columns($table) as $column) {
                $this->assertDoesNotMatchRegularExpression('/(gst|pan|vat|ein|tax|statutory|secret|password|token|api_key|credential|metadata|custom|extra)/', $column, "{$table}.{$column}");
            }
        }
    }

    #[Test]
    public function the_service_catalogue_is_the_database_constraint_and_stays_descriptive(): void
    {
        $check = (string) DB::selectOne("select pg_get_constraintdef(oid) as d from pg_constraint where conname = 'campus_services_code_check'")->d;
        preg_match_all("/'([a-z_]+)'/", $check, $m);
        $this->assertEqualsCanonicalizing(array_column(CampusServiceType::options(), 'code'), $m[1]);

        foreach (Finder::create()->files()->in(app_path('Domain/Campuses'))->name('*.php') as $file) {
            foreach (['ModuleCatalogue', 'SchoolModuleEntitlement', 'school_module_entitlements', 'role_capabilities', 'membership_role_assignments', 'Transport\\', 'Hostel\\', 'Canteen\\', 'Fees\\', 'Finance\\'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $file->getContents(), "{$file->getFilename()}: a Campus service never reaches {$forbidden}");
            }
        }
        foreach (Finder::create()->files()->in(app_path('Domain/Platform/Application/Modules'))->name('*.php') as $file) {
            $this->assertStringNotContainsString('campus_services', $file->getContents(), 'entitlements never read or write Campus services');
            $this->assertStringNotContainsString('CampusServiceType', $file->getContents());
        }
    }

    #[Test]
    public function the_identity_is_a_declared_direct_assured_action_and_not_mass_assignable(): void
    {
        $this->assertArrayHasKey('App\\Domain\\Schools\\Application\\SchoolIdentityService', ApprovalWorkflowRegistry::DIRECT_ASSURED);
        foreach (['legal_name', 'registration_reference', 'affiliation_reference', 'status'] as $field) {
            $this->assertNotContains($field, (new School)->getFillable(), "{$field} is written only by its owning service");
        }
    }

    #[Test]
    public function optional_profile_fields_never_make_a_school_incomplete(): void
    {
        $school = $this->createSchool(['email' => 'office@school.example', 'phone' => null, 'address_line1' => '1 Main Road', 'city' => 'Pune', 'country_code' => 'IN',
            'legal_name' => null, 'website' => null, 'registration_reference' => null, 'affiliation_reference' => null]);
        app(TenantContext::class)->withSchool($school, fn () => $this->createCampus($school));
        $admin = $this->schoolAdminFor($school);
        $items = fn (): array => collect(app(OrganizationSetupReadService::class)->evaluate($school->refresh(), $admin)['items'])->keyBy('key')->all();

        $this->assertSame('configured', $items()['organization.school_profile']['status'], 'no legal name, website, references, logo: still configured');
        $this->assertSame('configured', $items()['organization.campuses']['status'], 'a Campus with no address, coordinates or services still counts');

        DB::table('schools')->where('id', $school->id)->update(['email' => null]);
        $profile = $items()['organization.school_profile'];
        $this->assertSame(['partially_configured', 1, 2], [$profile['status'], $profile['completedChecks'], $profile['totalChecks']], 'a missing required contact is incomplete');
    }
}
