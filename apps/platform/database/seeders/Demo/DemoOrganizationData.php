<?php

namespace Database\Seeders\Demo;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\Platform\Application\Groups\SchoolGroupGovernanceService;
use App\Domain\Platform\Application\Groups\SchoolGroupMembershipReason;
use App\Domain\Platform\Application\Groups\SchoolGroupStructureService;
use App\Models\Campus;
use App\Models\GroupRoleAssignment;
use App\Models\School;
use App\Models\SchoolGroup;
use App\Models\SchoolGroupRegion;
use App\Models\SchoolGroupZone;
use App\Models\User;
use Database\Factories\SchoolFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ORG.12E-D1: the canonical, deterministic Organization reference world for
 * the LOCAL DDEV demo -- what ORG.12B–ORG.12E look like with realistic
 * fictional data. Built only through DemoDataBuilder (DemoEnvironmentGuard:
 * local DDEV, or the automated suite on the verified test database; never
 * production or staging). Every name, address, phone number, coordinate and
 * reference is fictional (coordinates rounded to ~100 m, non-assignable
 * phone ranges); every address is `@example.test`.
 *
 * - Northstar Education Network (a School Group, OD-12.8): Regions Telangana
 *   and Karnataka; Zones Hyderabad Central, Hyderabad West (Telangana) and
 *   Bengaluru North (Karnataka); six member Schools -- owned (multi-Campus),
 *   franchise, managed, affiliate, a Region-only owned School and one legacy
 *   UNCLASSIFIED, unplaced membership (governance attention).
 * - Two standalone customers without any Group: Greenfield Public School
 *   (one Campus, core modules only) and Horizon Academy (three Campuses).
 * - Campus services across all five codes, with service ≠ entitlement shown
 *   both ways.
 * - Network personas (Manager, whole-Network, Region and Zone viewers, a
 *   shared Network + School user) and School personas (School Admin,
 *   whole-School Accountant, Campus-scoped Cashier, a disabled former member).
 *
 * How it writes: Group, Regions, Zones and placements go through the real
 * governance services as the demo platform root (audited). Membership
 * classification and Network grants use the demo builder's existing direct,
 * database-validated writes -- the seeder never fabricates a fresh-MFA proof;
 * the database CHECKs, composite keys and triggers (relationship catalogue,
 * same-Group placement, scope only on a read-only role, Network management
 * granted only by the platform) still apply. No product role, capability,
 * table or column exists for the demo; no external system is called.
 */
final class DemoOrganizationData
{
    public const NETWORK_SLUG = 'northstar-education-network';

    public const NETWORK_NAME = 'Northstar Education Network';

    /** @var array<string, School> */
    public array $schools = [];

    /** @var array<string, Campus> */
    public array $campuses = [];

    private SchoolGroup $network;

    /** @var array<string, SchoolGroupRegion> */
    private array $regions = [];

    /** @var array<string, SchoolGroupZone> */
    private array $zones = [];

    public function __construct(private readonly DemoDataBuilder $b) {}

    public function build(): void
    {
        // Its own guard too: never reachable outside local DDEV or the verified test database.
        DemoEnvironmentGuard::assertLocalDdevOrTesting(
            (string) app()->environment(),
            getenv('IS_DDEV_PROJECT') === false ? null : (string) getenv('IS_DDEV_PROJECT'),
            (array) config('database.connections'),
            (string) config('database.testing_database'),
        );
        $this->buildSchools();
        $this->buildNetwork();
        $this->buildSchoolPersonas();
        $this->buildNetworkPersonas();
        $this->buildPresentation();
        $this->b->note('Organization demo (ORG.12E-D1): Northstar Education Network (2 Regions, 3 Zones, 6 Schools incl. one Unclassified), standalone Greenfield Public School and Horizon Academy -- see docs/demo/ORGANIZATION-DEMO-DATA.md.');
    }

    // ------------------------------------------------------------------
    // Schools, Campuses, services (ORG.12B/ORG.12C)
    // ------------------------------------------------------------------

    private function buildSchools(): void
    {
        $definitions = [
            'gachibowli' => [
                'school' => ['name' => 'Northstar International School — Gachibowli', 'slug' => 'northstar-gachibowli', 'code' => 'NIS-GCB',
                    'legal_name' => 'Northstar International School Gachibowli (fictional)', 'registration_reference' => 'DEMO-REG-TS-0101', 'affiliation_reference' => 'DEMO-AFF-0101',
                    'email' => 'office@gachibowli.northstar.example.test', 'phone' => '+914000000101', 'website' => 'https://gachibowli.northstar.example.test',
                    'address_line1' => '12 Demo Knowledge Park Road', 'address_line2' => 'Block A', 'city' => 'Hyderabad', 'state_region' => 'Telangana', 'postal_code' => '500032'],
                'modules' => 'all',
                'campuses' => [
                    'gachibowli_main' => ['name' => 'Gachibowli Main Campus', 'code' => 'GCB-MAIN', 'address_line1' => '12 Demo Knowledge Park Road', 'city' => 'Hyderabad', 'state_region' => 'Telangana', 'postal_code' => '500032',
                        'phone' => '+914000000111', 'email' => 'main@gachibowli.northstar.example.test', 'latitude' => '17.44000', 'longitude' => '78.34800', 'services' => ['day_school', 'meals', 'transport']],
                    'gachibowli_early' => ['name' => 'Gachibowli Early Years Campus', 'code' => 'GCB-EY', 'address_line1' => '4 Demo Garden Lane', 'city' => 'Hyderabad', 'state_region' => 'Telangana', 'postal_code' => '500032',
                        'phone' => '+914000000112', 'email' => 'earlyyears@gachibowli.northstar.example.test', 'latitude' => '17.44500', 'longitude' => '78.35100', 'services' => ['early_years', 'meals']],
                ],
            ],
            'jubilee' => [
                'school' => ['name' => 'Northstar Early Learning — Jubilee Hills', 'slug' => 'northstar-jubilee-hills', 'code' => 'NEL-JBH',
                    'legal_name' => 'Northstar Early Learning Jubilee Hills (fictional franchise)', 'registration_reference' => 'DEMO-REG-TS-0202',
                    'email' => 'hello@jubilee.northstar.example.test', 'phone' => '+914000000202',
                    'address_line1' => '7 Demo Hill Road', 'city' => 'Hyderabad', 'state_region' => 'Telangana', 'postal_code' => '500033'],
                'modules' => 'core',
                'campuses' => [
                    'jubilee_main' => ['name' => 'Jubilee Hills Campus', 'code' => 'JBH-MAIN', 'address_line1' => '7 Demo Hill Road', 'city' => 'Hyderabad', 'state_region' => 'Telangana', 'postal_code' => '500033',
                        'latitude' => '17.43100', 'longitude' => '78.40700',
                        // Claims Transport while the Transport module is NOT enabled: a service is descriptive, never an entitlement.
                        'services' => ['early_years', 'meals', 'transport']],
                ],
            ],
            'secunderabad' => [
                'school' => ['name' => 'Northstar Academy — Secunderabad', 'slug' => 'northstar-secunderabad', 'code' => 'NAC-SEC',
                    'legal_name' => 'Northstar Academy Secunderabad (fictional, managed)', 'affiliation_reference' => 'DEMO-AFF-0303',
                    'email' => 'office@secunderabad.northstar.example.test', 'phone' => '+914000000303',
                    'address_line1' => '21 Demo Parade Road', 'city' => 'Secunderabad', 'state_region' => 'Telangana', 'postal_code' => '500003'],
                'modules' => 'all',
                'campuses' => [
                    'secunderabad_main' => ['name' => 'Secunderabad Campus', 'code' => 'SEC-MAIN', 'address_line1' => '21 Demo Parade Road', 'city' => 'Secunderabad', 'state_region' => 'Telangana', 'postal_code' => '500003',
                        'latitude' => '17.43900', 'longitude' => '78.49800', 'services' => ['day_school', 'boarding', 'meals']],
                ],
            ],
            'hebbal' => [
                'school' => ['name' => 'Northstar Partner School — Hebbal', 'slug' => 'northstar-hebbal', 'code' => 'NPS-HBL',
                    'email' => 'office@hebbal.northstar.example.test', 'phone' => '+914000000404',
                    'address_line1' => '3 Demo Lake View Road', 'city' => 'Bengaluru', 'state_region' => 'Karnataka', 'postal_code' => '560024'],
                'modules' => 'all',
                'campuses' => [
                    'hebbal_main' => ['name' => 'Hebbal Campus', 'code' => 'HBL-MAIN', 'address_line1' => '3 Demo Lake View Road', 'city' => 'Bengaluru', 'state_region' => 'Karnataka', 'postal_code' => '560024',
                        'latitude' => '13.03500', 'longitude' => '77.59700', 'services' => ['day_school', 'transport']],
                ],
            ],
            'warangal' => [
                'school' => ['name' => 'Northstar School — Warangal', 'slug' => 'northstar-warangal', 'code' => 'NSW-WGL',
                    'email' => 'office@warangal.northstar.example.test', 'phone' => '+914000000505',
                    'address_line1' => '9 Demo Fort Road', 'city' => 'Warangal', 'state_region' => 'Telangana', 'postal_code' => '506002'],
                'modules' => 'all',
                'campuses' => [
                    'warangal_main' => ['name' => 'Warangal Campus', 'code' => 'WGL-MAIN', 'city' => 'Warangal', 'state_region' => 'Telangana', 'services' => ['day_school']],
                ],
            ],
            'mysuru' => [
                'school' => ['name' => 'Northstar Heritage School — Mysuru', 'slug' => 'northstar-mysuru', 'code' => 'NHS-MYS',
                    'address_line1' => '5 Demo Palace Road', 'city' => 'Mysuru', 'state_region' => 'Karnataka', 'postal_code' => '570001'],
                'modules' => 'all',
                'campuses' => [
                    'mysuru_main' => ['name' => 'Mysuru Campus', 'code' => 'MYS-MAIN', 'city' => 'Mysuru', 'state_region' => 'Karnataka', 'services' => []],
                ],
            ],
            'greenfield' => [
                'school' => ['name' => 'Greenfield Public School', 'slug' => 'greenfield-public', 'code' => 'GPS',
                    'legal_name' => 'Greenfield Public School (fictional)', 'email' => 'office@greenfield.example.test', 'phone' => '+914400000606',
                    'address_line1' => '1 Demo Green Street', 'city' => 'Chennai', 'state_region' => 'Tamil Nadu', 'postal_code' => '600001'],
                'modules' => 'core',
                'campuses' => [
                    'greenfield_main' => ['name' => 'Greenfield Campus', 'code' => 'GPS-MAIN', 'address_line1' => '1 Demo Green Street', 'city' => 'Chennai', 'state_region' => 'Tamil Nadu', 'postal_code' => '600001',
                        'latitude' => '13.08200', 'longitude' => '80.27000', 'services' => ['day_school']],
                ],
            ],
            'horizon' => [
                'school' => ['name' => 'Horizon Academy', 'slug' => 'horizon-academy', 'code' => 'HZA',
                    'legal_name' => 'Horizon Academy Trust School (fictional)', 'registration_reference' => 'DEMO-REG-MH-0707', 'affiliation_reference' => 'DEMO-AFF-0707',
                    'email' => 'office@horizon.example.test', 'phone' => '+912000000707', 'website' => 'https://horizon.example.test',
                    'address_line1' => '10 Demo Horizon Avenue', 'city' => 'Pune', 'state_region' => 'Maharashtra', 'postal_code' => '411001'],
                'modules' => 'all',
                'campuses' => [
                    'horizon_central' => ['name' => 'Central Campus', 'code' => 'HZA-CEN', 'address_line1' => '10 Demo Horizon Avenue', 'city' => 'Pune', 'state_region' => 'Maharashtra', 'postal_code' => '411001',
                        'latitude' => '18.52000', 'longitude' => '73.85600', 'services' => ['day_school', 'meals', 'transport']],
                    'horizon_north' => ['name' => 'North Campus', 'code' => 'HZA-NTH', 'address_line1' => '44 Demo Ridge Road', 'city' => 'Pune', 'state_region' => 'Maharashtra', 'postal_code' => '411015',
                        'latitude' => '18.56000', 'longitude' => '73.84000', 'services' => ['day_school', 'boarding']],
                    'horizon_early' => ['name' => 'Early Years Campus', 'code' => 'HZA-EY', 'address_line1' => '2 Demo Meadow Lane', 'city' => 'Pune', 'state_region' => 'Maharashtra', 'postal_code' => '411001',
                        'latitude' => '18.52500', 'longitude' => '73.86000', 'services' => ['early_years', 'meals']],
                ],
            ],
        ];

        foreach ($definitions as $key => $definition) {
            // `status` and the identity references are not fillable: the guarded demo writes them explicitly.
            $school = School::query()->forceCreate([
                ...$definition['school'],
                'status' => 'active',
                'timezone' => 'Asia/Kolkata',
                'default_locale' => 'en',
                'country_code' => 'IN',
            ]);
            SchoolFactory::setOptionalModules($school, $definition['modules'] === 'all');
            $this->schools[$key] = $school;

            foreach ($definition['campuses'] as $campusKey => $campus) {
                $services = $campus['services'];
                unset($campus['services']);
                $this->campuses[$campusKey] = $this->b->inSchool(function () use ($school, $campus, $services): Campus {
                    $created = Campus::query()->create([...$campus, 'school_id' => $school->id, 'status' => 'active', 'country_code' => 'IN']);
                    foreach ($services as $code) {
                        DB::table('campus_services')->insert([
                            'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'campus_id' => $created->id,
                            'service_code' => $code, 'created_by_user_id' => $this->b->platformAdmin->id, 'created_at' => now(),
                        ]);
                    }

                    return $created;
                }, $school);
            }
        }

        // ORG.12B "Partially configured" + "Blocked": a DRAFT academic year
        // (not yet active) makes Academic year partial, and Grades/sections
        // -- which needs an active year -- shows as Blocked.
        $start = Carbon::now('Asia/Kolkata')->startOfYear()->addYear()->setMonth(6)->startOfMonth();
        $this->b->inSchool(fn () => AcademicYear::query()->create([
            'school_id' => $this->schools['gachibowli']->id,
            'name' => $start->format('Y').'–'.$start->copy()->addYear()->format('y'),
            'code' => 'AY'.$start->format('Y'),
            'starts_on' => $start->toDateString(),
            'ends_on' => $start->copy()->addYear()->setMonth(3)->endOfMonth()->toDateString(),
            'status' => 'draft',
        ]), $this->schools['gachibowli']);
    }

    // ------------------------------------------------------------------
    // Northstar Education Network (ORG.12D)
    // ------------------------------------------------------------------

    private function buildNetwork(): void
    {
        $root = $this->b->platformAdmin;
        $this->network = app(SchoolGroupGovernanceService::class)->create($root, self::NETWORK_NAME, self::NETWORK_SLUG);
        $structure = app(SchoolGroupStructureService::class);

        $this->regions['TELANGANA'] = $structure->createRegion($root, $this->network, ['code' => 'TELANGANA', 'name' => 'Telangana', 'sort_order' => 1, 'description' => 'Hyderabad and the rest of Telangana']);
        $this->regions['KARNATAKA'] = $structure->createRegion($root, $this->network, ['code' => 'KARNATAKA', 'name' => 'Karnataka', 'sort_order' => 2]);
        $this->zones['HYD-CENTRAL'] = $structure->createZone($root, $this->network, ['code' => 'HYD-CENTRAL', 'name' => 'Hyderabad Central', 'region_id' => $this->regions['TELANGANA']->id, 'sort_order' => 1]);
        $this->zones['HYD-WEST'] = $structure->createZone($root, $this->network, ['code' => 'HYD-WEST', 'name' => 'Hyderabad West', 'region_id' => $this->regions['TELANGANA']->id, 'sort_order' => 2]);
        $this->zones['BLR-NORTH'] = $structure->createZone($root, $this->network, ['code' => 'BLR-NORTH', 'name' => 'Bengaluru North', 'region_id' => $this->regions['KARNATAKA']->id, 'sort_order' => 3]);

        // key => [relationship (null = legacy, unclassified), network reference, region, zone]
        $memberships = [
            'gachibowli' => ['owned', 'NSN-HYD-001', null, 'HYD-WEST'],
            'jubilee' => ['franchise', 'NSN-HYD-F01', null, 'HYD-CENTRAL'],
            'secunderabad' => ['managed', 'NSN-HYD-M01', null, 'HYD-CENTRAL'],
            'hebbal' => ['affiliate', 'NSN-BLR-A01', null, 'BLR-NORTH'],
            'warangal' => ['owned', 'NSN-TS-002', 'TELANGANA', null],
            // A legacy membership kept as ORG.12D found it: unclassified and
            // unplaced -- whole-Network only, and flagged for attention.
            'mysuru' => [null, null, null, null],
        ];
        foreach ($memberships as $key => [$relationship, $reference, $region, $zone]) {
            $this->network->schools()->attach($this->schools[$key]->id, [
                'id' => (string) Str::uuid7(),
                'relationship_type' => $relationship,
                'network_reference' => $reference,
            ]);
            if ($region !== null || $zone !== null) {
                app(SchoolGroupGovernanceService::class)->placeMembership($root, $this->network, $this->schools[$key],
                    $region !== null ? $this->regions[$region]->id : null, $zone !== null ? $this->zones[$zone]->id : null,
                    ['region_id' => null, 'zone_id' => null], SchoolGroupMembershipReason::INITIAL_CLASSIFICATION);
            }
        }
    }

    // ------------------------------------------------------------------
    // School personas (ORG.12E Access Center)
    // ------------------------------------------------------------------

    private function buildSchoolPersonas(): void
    {
        $gachibowli = $this->schools['gachibowli'];

        $admin = $this->b->user('Deepa Kulkarni (Gachibowli School Admin)', 'gachibowli.admin@example.test');
        $this->b->assignSchoolRole($this->b->member($admin, $gachibowli), 'school_admin');
        $this->b->account('School Admin (Northstar Gachibowli)', $admin, $gachibowli->name, 'school_admin of the multi-Campus Network School: Setup & readiness (partial, blocked), rich School and Campus profiles, the Access Center and effective access');

        $accounts = $this->b->user('Imran Qureshi (Accountant)', 'gachibowli.accounts@example.test');
        $this->b->assignSchoolRole($this->b->member($accounts, $gachibowli), 'accountant');
        $this->b->account('Accountant — Whole School (Gachibowli)', $accounts, $gachibowli->name, 'accountant for the whole School: effective access reads "Accountant — Whole School"');

        $cashier = $this->b->user('Kavya Reddy (Cashier, Main Campus)', 'gachibowli.cashier@example.test');
        $this->b->assignSchoolRole($this->b->member($cashier, $gachibowli), 'cashier', $this->campuses['gachibowli_main']->id);
        $this->b->account('Cashier — Campus: Gachibowli Main Campus', $cashier, $gachibowli->name, 'cashier for ONE Campus: effective only on Gachibowli Main Campus, never the Early Years Campus or School-wide');

        // A stopping-layer example: a former member whose account is disabled
        // (no sign-in; shown in Access Center → Users with "Account disabled").
        $former = $this->b->user('Suresh Babu (former librarian)', 'gachibowli.former@example.test');
        $this->b->assignSchoolRole($this->b->member($former, $gachibowli), 'librarian');
        $former->forceFill(['is_disabled' => true])->save();

        foreach (['greenfield' => ['Anita Verma (Greenfield School Admin)', 'greenfield.admin@example.test'], 'horizon' => ['Rohan Patil (Horizon School Admin)', 'horizon.admin@example.test']] as $key => [$name, $email]) {
            $person = $this->b->user($name, $email);
            $this->b->assignSchoolRole($this->b->member($person, $this->schools[$key]), 'school_admin');
            $this->b->account('School Admin ('.$this->schools[$key]->name.')', $person, $this->schools[$key]->name,
                $key === 'greenfield' ? 'Standalone single-Campus School, no Group, core modules only (optional areas "Not enabled")' : 'Standalone School with three Campuses, no Group');
        }
    }

    // ------------------------------------------------------------------
    // Network personas (ORG.12E)
    // ------------------------------------------------------------------

    private function buildNetworkPersonas(): void
    {
        $personas = [
            ['Network Manager', 'Ravi Menon (Network Manager)', 'network.manager@example.test', 'network_manager', null, null,
                'Whole Northstar Network: Regions, Zones, classification, placement, removal and read-only Network Viewers (fresh MFA for classification, removal and viewers); never adds a School, enters a School or holds a School permission'],
            ['Network Viewer (whole Network)', 'Leela Nair (Network Viewer)', 'network.viewer@example.test', 'network_viewer', null, null,
                'Read-only, whole Northstar Network: every member School including the unclassified, unplaced one'],
            ['Network Viewer (Region: Telangana)', 'Sana Iyer (Telangana)', 'network.region@example.test', 'network_viewer', 'TELANGANA', null,
                'Read-only, Telangana: Hyderabad Central, Hyderabad West and the Region-only Warangal School; never Karnataka or the unplaced School'],
            ['Network Viewer (Zone: Hyderabad Central)', 'Arjun Das (Hyderabad Central)', 'network.zone@example.test', 'network_viewer', null, 'HYD-CENTRAL',
                'Read-only, Hyderabad Central only: Jubilee Hills and Secunderabad'],
        ];
        foreach ($personas as [$persona, $name, $email, $roleKey, $region, $zone, $access]) {
            $person = $this->b->user($name, $email);
            $this->grant($person, $roleKey, $region, $zone);
            $this->b->account($persona, $person, '(none -- Group scope: '.self::NETWORK_NAME.')', $access);
        }

        // Shared Network + School identity: a Karnataka Network Viewer who is
        // also Principal of Northstar Gachibowli -- and holds nothing in any
        // other School. Each context is resolved separately.
        $shared = $this->b->user('Priya Sharma (Karnataka viewer + Gachibowli Principal)', 'network.shared@example.test');
        $this->grant($shared, 'network_viewer', 'KARNATAKA', null);
        $this->b->assignSchoolRole($this->b->member($shared, $this->schools['gachibowli']), 'principal');
        $this->b->account('Network + School (shared identity)', $shared, $this->schools['gachibowli']->name.' + Network (Karnataka)', 'Network Viewer for Karnataka AND Principal of Northstar Gachibowli; no role in Secunderabad or any other School');
    }

    // ------------------------------------------------------------------
    // Presentation profiles (ORG.12F)
    // ------------------------------------------------------------------

    /**
     * ORG.12F (ADR 0080 §11): the Northstar Network brand; Gachibowli EXPLICITLY
     * inherits it, Jubilee Hills (a franchise) keeps its own brand, Greenfield
     * stays on the system default. Navigation: Gachibowli hides Data health,
     * renames Students to Learners and puts Setup & readiness first; Jubilee
     * Hills holds a preference for Library, whose module it does not have (it
     * stays absent); the Annexe (compatibility School) renames Students to
     * Pupils, which the two-School user sees only there; the Demo School's
     * Guardian portal shows the calendar first. Direct, database-validated
     * writes like the rest of the demo (no fabricated MFA proof).
     */
    private function buildPresentation(): void
    {
        DB::table('school_group_brand_profiles')->insert([
            'id' => (string) Str::uuid7(), 'school_group_id' => $this->network->id,
            'display_label' => 'Northstar Education', 'primary_accent' => '#1D4E89', 'version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $brand = fn (School $school, array $values) => $this->b->inSchool(fn () => DB::table('school_brand_profiles')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'created_at' => now(), 'updated_at' => now(),
            'brand_source_group_id' => null, 'display_label' => null, 'primary_accent' => null, ...$values,
        ]), $school);
        $brand($this->schools['gachibowli'], ['brand_source_group_id' => $this->network->id]);
        $brand($this->schools['jubilee'], ['display_label' => 'Jubilee Little Stars', 'primary_accent' => '#7A3E9D']);

        $navigation = fn (School $school, string $audience, array $rows) => $this->b->inSchool(fn () => DB::table('school_navigation_preferences')->insert(array_map(
            fn (array $row) => ['id' => (string) Str::uuid7(), 'school_id' => $school->id, 'audience' => $audience, 'hidden' => false,
                'sort_order' => null, 'label_override' => null, 'created_at' => now(), 'updated_at' => now(), ...$row],
            $rows,
        )), $school);
        $navigation($this->schools['gachibowli'], 'staff_web', [
            ['navigation_key' => 'data-health', 'hidden' => true],
            ['navigation_key' => 'students', 'label_override' => 'Learners'],
            ['navigation_key' => 'setup-readiness', 'sort_order' => 0],
            ['navigation_key' => 'school-setup', 'sort_order' => 1],
        ]);
        $navigation($this->schools['jubilee'], 'staff_web', [['navigation_key' => 'library-catalogue', 'label_override' => 'Story books']]);
        $navigation($this->b->secondSchool, 'staff_web', [['navigation_key' => 'students', 'label_override' => 'Pupils']]);
        $navigation($this->b->school, 'guardian_portal', [
            ['navigation_key' => 'portal-calendar', 'sort_order' => 0],
            ['navigation_key' => 'portal-communications', 'sort_order' => 1],
            ['navigation_key' => 'portal-conversations', 'label_override' => 'Talk to the School'],
        ]);

        $jubileeAdmin = $this->b->user('Neha Varma (Jubilee Hills School Admin)', 'jubilee.admin@example.test');
        $this->b->assignSchoolRole($this->b->member($jubileeAdmin, $this->schools['jubilee']), 'school_admin');
        $this->b->account('School Admin (Northstar Jubilee Hills)', $jubileeAdmin, $this->schools['jubilee']->name, 'Franchise School with its OWN brand (not the Network\'s), core modules only: a saved Library preference never makes Library appear');
    }

    private function grant(User $person, string $roleKey, ?string $region, ?string $zone): void
    {
        // The platform root is the recorded grantor (trg_group_role_assignments_network checks it).
        GroupRoleAssignment::query()->create([
            'user_id' => $person->id,
            'school_group_id' => $this->network->id,
            'role_id' => $this->b->role($roleKey, 'group')->id,
            'granted_by_user_id' => $this->b->platformAdmin->id,
            'granted_at' => now(),
            'region_id' => $region !== null ? $this->regions[$region]->id : null,
            'zone_id' => $zone !== null ? $this->zones[$zone]->id : null,
        ]);
    }
}
