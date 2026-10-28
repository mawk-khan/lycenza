<?php

namespace Tests\Feature\Setup;

use App\Domain\Guardians\Infrastructure\ContactType;
use App\Models\School;
use App\Models\User;
use App\Support\Setup\OrganizationSetupRegistry;
use App\Support\Testing\LocalCatalogueFixtures;
use Database\Factories\SchoolFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Feature\Platform\Groups\GroupTestHelpers;
use Tests\TestCase;

/**
 * ORG.12B (ADR 0080 §7): Organization -> Setup & readiness over real HTTP.
 * Entry (floor + active School + active membership, never Platform, Group
 * or a Campus-only grant), navigation, the 21-area registry rendered as
 * served, entitlement, isolation, privacy, read-only and the page budget.
 */
class OrganizationSetupCenterPageTest extends TestCase
{
    use CreatesTenancyFixtures, GroupTestHelpers;

    private const string PAGE = '/app/school-setup/readiness';

    /** Measured 57 (51 readiness + 6 page: auth, context, navigation); readiness budget 60 plus 10 (ADR 0080 §7). */
    private const int PAGE_QUERY_BUDGET = 70;

    private function enter(User $user, School $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    /** @return array<string, mixed> the `center` prop */
    private function center(TestResponse $response): array
    {
        $response->assertOk();
        $page = $response->viewData('page');
        $this->assertSame('App/SchoolSetup/Readiness', $page['component']);

        return $page['props']['center'];
    }

    /** @return array<string, array<string, mixed>> */
    private function cards(array $center): array
    {
        $cards = [];
        foreach ($center['categories'] as $category) {
            foreach ($category['items'] as $card) {
                $cards[$card['key']] = $card;
            }
        }

        return $cards;
    }

    private function configure(School $school): void
    {
        $school->forceFill(['legal_name' => 'Zephyrine Legal Trust', 'email' => 'office@zephyrine.example', 'phone' => '+910000099999', 'address_line1' => '1 Quillon Road', 'city' => 'Vantar', 'country_code' => 'IN'])->save();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school, ['code' => 'Y'.Str::random(5), 'status' => 'active', 'starts_on' => now()->subMonths(2)->toDateString(), 'ends_on' => now()->addMonths(9)->toDateString()]);
        $grade = $this->createGradeLevel($school);
        $subject = $this->createSubject($school);
        $section = $this->createSection($year, $campus, $grade);
        $this->createSubjectOffering($year, $campus, $grade, $subject);
        $student = $this->createStudent($school, ['first_name' => 'Xanthippe', 'last_name' => 'Quorvald']);
        $this->createStudentEnrollment($student, $section, ['starts_on' => $year->starts_on->toDateString()]);
        $guardian = $this->createGuardian($school, ['first_name' => 'Ulrikka', 'last_name' => 'Brannoch']);
        $this->createStudentGuardianRelationship($student, $guardian, ['is_legal_guardian' => true]);
        $this->createGuardianContact($guardian, ContactType::Email, 'ulrikka.private@example.test');
        $this->createDepartment($school);
        $this->createPosition($school);
        $this->createEmploymentRecord($this->createEmployee($school, ['full_name' => 'Ottoline Vreeswijk']), ['status' => 'active', 'starts_on' => now()->subYear()->toDateString()]);
    }

    // -- Entry ---------------------------------------------------------------

    #[Test]
    public function the_school_administrator_sees_every_area_once_with_actions(): void
    {
        $school = $this->createSchool();
        $this->enter($this->schoolAdminFor($school), $school);
        $center = $this->center($this->get(self::PAGE));

        $rendered = [...array_keys($this->cards($center)), ...array_column($center['notEnabled'], 'key')];
        $this->assertEqualsCanonicalizing(app(OrganizationSetupRegistry::class)->keys(), $rendered, 'the page renders the registry as served -- every area exactly once');
        $this->assertSame(0, $center['overview']['hiddenAreas']);
        $this->assertSame('configure', $this->cards($center)['organization.school_profile']['action']['kind']);
        $this->assertSame($school->name, $center['school']['name']);
    }

    #[Test]
    public function a_principal_reads_the_center_with_actions_limited_to_their_capabilities(): void
    {
        $school = $this->createSchool();
        $principal = $this->createUser();
        $this->assignSchoolRole($this->createMembership($principal, $school), 'principal');
        $this->enter($principal, $school);
        $cards = $this->cards($this->center($this->get(self::PAGE)));

        $this->assertSame('view', $cards['organization.school_profile']['action']['kind'], 'the Principal does not hold school.profile.manage');
        $this->assertSame('view', $cards['organization.campuses']['action']['kind']);
        $this->assertSame('configure', $cards['academic.academic_year']['action']['kind']);
    }

    #[Test]
    public function ordinary_staff_suspended_disabled_and_campus_only_members_are_refused(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);

        $teacher = $this->createUser();
        $this->assignSchoolRole($this->createMembership($teacher, $school), 'teacher');
        $this->enter($teacher, $school);
        $this->get(self::PAGE)->assertForbidden();

        // Campus-scoped Student and Attendance reads are never School setup authority.
        $campusOnly = $this->createUser();
        LocalCatalogueFixtures::grantRole($this->createMembership($campusOnly, $school), $this->createFixtureRole(['students.view', 'attendance.view']), $campus->id);
        $this->enter($campusOnly, $school);
        $this->get(self::PAGE)->assertForbidden();

        $suspended = $this->createUserWithCapabilities($school, ['school.profile.view'], 'suspended');
        $this->enter($suspended, $school);
        $this->assertNotSame(200, $this->get(self::PAGE)->status());

        $disabled = $this->schoolAdminFor($school);
        $this->enter($disabled, $school);
        $disabled->forceFill(['is_disabled' => true])->save();
        $this->assertNotSame(200, $this->get(self::PAGE)->status());
    }

    #[Test]
    public function platform_and_group_operators_never_open_a_schools_center(): void
    {
        $school = $this->createSchool();
        $root = $this->createPlatformRoot();
        $manager = $this->createUser();
        $this->assignPlatformRole($manager, 'platform_manager', $root);
        $auditor = $this->createUser();
        $this->assignPlatformRole($auditor, 'platform_auditor', $root);
        $groupAdmin = $this->groupAdmin($this->createGroup([$school]));

        foreach (['root' => $root, 'manager' => $manager, 'auditor' => $auditor, 'group admin' => $groupAdmin] as $who => $user) {
            $this->enter($user, $school);
            $response = $this->get(self::PAGE);
            $this->assertNotSame(200, $response->status(), "{$who} must not open the Setup Center");
        }
    }

    #[Test]
    public function a_school_that_is_not_active_is_refused(): void
    {
        $school = $this->createSchool();
        $admin = $this->schoolAdminFor($school);
        $this->enter($admin, $school);
        $school->forceFill(['status' => 'suspended'])->save();

        $this->assertNotSame(200, $this->get(self::PAGE)->status());
    }

    // -- Navigation ------------------------------------------------------------

    #[Test]
    public function navigation_offers_the_center_only_to_floor_holders_and_never_replaces_the_route_check(): void
    {
        $school = $this->createSchool();
        $keys = fn (TestResponse $r): array => array_merge(...array_map(fn (array $g): array => array_column($g['items'], 'key'), $r->viewData('page')['props']['navigation']['groups'] ?? []));

        $this->enter($this->schoolAdminFor($school), $school);
        $this->assertContains('setup-readiness', $keys($this->get('/app/school-setup')));

        $teacher = $this->createUser();
        $this->assignSchoolRole($this->createMembership($teacher, $school), 'teacher');
        $this->enter($teacher, $school);
        $this->assertNotContains('setup-readiness', $keys($this->get('/app')->assertOk()));
        $this->get(self::PAGE)->assertForbidden();
    }

    // -- States, entitlement, isolation ---------------------------------------

    #[Test]
    public function a_new_school_renders_safely_with_a_sensible_first_action(): void
    {
        $school = School::factory()->withCoreModulesOnly()->create();
        $this->enter($this->schoolAdminFor($school), $school);
        $center = $this->center($this->get(self::PAGE));

        $this->assertSame('getting_started', $center['overview']['state']);
        $this->assertSame(['organization.campuses', 'academic.academic_year', 'organization.school_profile'], array_column($center['nextActions'], 'key'));
        $this->assertCount(13, $center['notEnabled'], 'every optional module is listed as not enabled');
        $this->assertSame(0, $center['overview']['optional']['total'], 'and none counts towards progress');
    }

    #[Test]
    public function a_partial_school_separates_configured_partial_blocked_attention_and_not_enabled(): void
    {
        $school = $this->createSchool();
        $this->createCampus($school);
        // An active year that has ended: configured once, now needs attention.
        $this->createAcademicYear($school, ['code' => 'E'.Str::random(5), 'status' => 'active', 'starts_on' => now()->subYears(2)->toDateString(), 'ends_on' => now()->subMonths(2)->toDateString()]);
        $this->createGradeLevel($school);
        SchoolFactory::setOptionalModules($school, false, ['transport', 'library', 'inventory']);
        $this->enter($this->schoolAdminFor($school), $school);
        $center = $this->center($this->get(self::PAGE));
        $cards = $this->cards($center);

        $this->assertSame('configured', $cards['organization.campuses']['status']);
        $this->assertSame('attention_required', $cards['academic.academic_year']['status']);
        $this->assertSame('partially_configured', $cards['academic.structure']['status'], 'grade levels only; attention never blocks');
        // No Students yet, and its prerequisite (the structure) is incomplete: blocked, not merely unconfigured.
        $this->assertSame('blocked', $cards['students.student_records']['status']);
        $this->assertSame([['key' => 'academic.structure', 'title' => 'Grades, sections and subjects']], $cards['students.student_records']['prerequisites']);
        $this->assertSame('blocked', $cards['attendance.student_attendance']['status']);
        $this->assertSame(['students.student_records', 'academic.timetable'], array_column($cards['attendance.student_attendance']['prerequisites'], 'key'));
        $this->assertNotSame($cards['academic.academic_year']['status'], $cards['students.student_records']['status'], 'attention is distinct from blocked');
        $this->assertEqualsCanonicalizing(['transport.transport', 'library.library', 'inventory.inventory'], array_column($center['notEnabled'], 'key'));
        $this->assertSame(10, $center['overview']['optional']['total'], '13 optional areas minus the 3 not enabled');
    }

    #[Test]
    public function a_configured_school_reaches_complete_core_without_false_blockers_or_a_ready_label(): void
    {
        $school = School::factory()->withCoreModulesOnly()->create();
        $this->configure($school);
        $this->enter($this->schoolAdminFor($school), $school);
        $center = $this->center($this->get(self::PAGE));

        $this->assertSame(['configured' => 8, 'total' => 8], $center['overview']['core']);
        $this->assertSame([0, 0], [$center['overview']['blocked'], $center['overview']['attention']]);
        $this->assertSame('complete', $center['overview']['state']);
        $this->assertSame([], $center['nextActions']);
        $this->assertDoesNotMatchRegularExpression('/production.?ready|fully secure|compliance passed/i', $this->get(self::PAGE)->getContent());
    }

    #[Test]
    public function re_enabling_a_module_shows_its_current_readiness_on_the_next_visit(): void
    {
        $school = School::factory()->withCoreModulesOnly()->create();
        $this->enter($this->schoolAdminFor($school), $school);
        $this->assertContains('inventory.inventory', array_column($this->center($this->get(self::PAGE))['notEnabled'], 'key'));

        SchoolFactory::setOptionalModules($school, true, ['inventory']);
        $card = $this->cards($this->center($this->get(self::PAGE)))['inventory.inventory'];
        $this->assertSame(['not_configured', 'configure'], [$card['status'], $card['action']['kind']]);
    }

    #[Test]
    public function each_school_sees_only_its_own_readiness_and_a_shared_user_follows_the_selected_school(): void
    {
        $a = School::factory()->withCoreModulesOnly()->create();
        $this->configure($a);
        $b = $this->createSchool();
        $shared = $this->schoolAdminFor($a);
        $this->assignSchoolRole($this->createMembership($shared, $b), 'school_admin');

        $this->enter($shared, $a);
        $inA = $this->center($this->get(self::PAGE));
        $this->enter($shared, $b);
        $inB = $this->center($this->get(self::PAGE));

        $this->assertSame([$a->name, 'complete'], [$inA['school']['name'], $inA['overview']['state']]);
        $this->assertSame([$b->name, 'getting_started'], [$inB['school']['name'], $inB['overview']['state']]);
        $this->assertSame(13, count($inA['notEnabled']));
        $this->assertSame(0, count($inB['notEnabled']), 'entitlement is per School');
        $this->assertNotSame($inA['nextActions'], $inB['nextActions']);

        $this->enter($shared, $a);
        $this->assertSame('complete', $this->center($this->get(self::PAGE))['overview']['state'], 'switching back recalculates for A');
    }

    // -- Privacy, read-only, performance ----------------------------------------

    #[Test]
    public function the_rendered_page_carries_no_people_contacts_identifiers_or_amounts(): void
    {
        $school = $this->createSchool();
        $this->configure($school);
        $this->enter($this->schoolAdminFor($school), $school);
        $html = (string) $this->get(self::PAGE)->assertOk()->getContent();

        foreach (['Xanthippe', 'Quorvald', 'Ulrikka', 'Brannoch', 'ulrikka.private', 'Ottoline', 'Vreeswijk', 'Zephyrine', 'zephyrine.example', '0000099999', 'Quillon', 'Vantar', 'school.profile.manage', 'academics.years.view'] as $absent) {
            $this->assertStringNotContainsString($absent, $html, "the page never carries {$absent}");
        }
    }

    #[Test]
    public function opening_the_center_writes_nothing_and_stays_within_its_query_budget(): void
    {
        $school = $this->createSchool();
        $this->configure($school);
        $admin = $this->schoolAdminFor($school);
        $this->enter($admin, $school);
        $this->get(self::PAGE)->assertOk(); // warm caches as a returning visitor
        $ledgers = fn (): array => [DB::table('school_audit_events')->count(), DB::table('platform_audit_events')->count(), DB::table('domain_event_outbox')->count()];
        $before = $ledgers();

        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = strtolower(ltrim($query->sql));
        });
        $started = hrtime(true);
        $response = $this->get(self::PAGE)->assertOk();
        $elapsedMs = (hrtime(true) - $started) / 1e6;

        $writes = array_filter($statements, fn (string $sql): bool => preg_match('/^(insert|update|delete|merge|truncate)\b/', $sql) === 1);
        $this->assertSame([], array_values($writes), 'the Setup Center never writes');
        $this->assertSame($before, $ledgers());
        $this->assertLessThanOrEqual(self::PAGE_QUERY_BUDGET, count($statements), 'full page used '.count($statements).' queries');
        $this->assertLessThan(200_000, strlen(json_encode($response->viewData('page')['props']['center'])), 'bounded payload');
        $this->assertLessThan(5_000, $elapsedMs);
    }
}
