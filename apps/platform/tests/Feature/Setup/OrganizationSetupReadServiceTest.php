<?php

namespace Tests\Feature\Setup;

use App\Domain\Guardians\Infrastructure\ContactType;
use App\Models\School;
use App\Models\User;
use App\Support\Setup\OrganizationSetupReadService;
use App\Support\Setup\OrganizationSetupRegistry;
use App\Support\Setup\SetupReadiness;
use App\Support\Setup\SetupReadinessProvider;
use App\Support\Setup\SetupReadinessProviders;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Database\Factories\SchoolFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Feature\Platform\Groups\GroupTestHelpers;
use Tests\TestCase;

/**
 * ORG.12A (ADR 0080 §9, §11): the Organization setup read service.
 *
 * Authority (floor + per-area view capabilities, never Platform or Group),
 * entitlement (unentitled optional = NOT_APPLICABLE, never evaluated),
 * dependencies (BLOCKED with keys only), School isolation, privacy (facts,
 * never business data), read-only, and the measured query budget.
 */
class OrganizationSetupReadServiceTest extends TestCase
{
    use CreatesTenancyFixtures, GroupTestHelpers;

    /** Measured with every area applicable and every provider on its longest path (ADR 0080 §11.4). */
    private const int QUERY_BUDGET = 60;

    private const array ITEM_FIELDS = [
        'key', 'title', 'description', 'ownerModule', 'category', 'scopeLevel', 'core', 'route', 'dependsOn', 'order',
        'status', 'explanation', 'completedChecks', 'totalChecks', 'blockers',
    ];

    private function service(): OrganizationSetupReadService
    {
        return app(OrganizationSetupReadService::class);
    }

    /** @return array<string, array<string, mixed>> key => item */
    private function items(School $school, User $viewer): array
    {
        return collect($this->service()->evaluate($school, $viewer)['items'])->keyBy('key')->all();
    }

    /**
     * A School with the core areas configured and recognisable personal and
     * financial values planted, so a leak would be visible.
     *
     * @return array{school: School, admin: User}
     */
    private function configuredWorld(?School $school = null): array
    {
        $school ??= $this->createSchool([
            'legal_name' => 'Zephyrine Legal Trust', 'email' => 'office@zephyrine.example', 'phone' => '+910000099999',
            'address_line1' => '1 Quillon Road', 'city' => 'Vantar', 'country_code' => 'IN',
        ]);
        $campus = $this->createCampus($school);
        $today = SchoolTimezone::today($school);
        $year = $this->createAcademicYear($school, [
            'code' => 'Y'.Str::random(5), 'status' => 'active',
            'starts_on' => now()->subMonths(2)->toDateString(), 'ends_on' => now()->addMonths(9)->toDateString(),
        ]);
        $this->assertLessThanOrEqual($today, $year->starts_on->toDateString());
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
        $employee = $this->createEmployee($school, ['full_name' => 'Ottoline Vreeswijk']);
        $this->createEmploymentRecord($employee, ['status' => 'active', 'starts_on' => now()->subYear()->toDateString()]);

        return ['school' => $school, 'admin' => $this->schoolAdminFor($school)];
    }

    // -- Authority -----------------------------------------------------------

    #[Test]
    public function a_school_administrator_and_a_principal_read_their_schools_readiness(): void
    {
        ['school' => $school, 'admin' => $admin] = $this->configuredWorld();
        $principal = $this->createUser();
        $this->assignSchoolRole($this->createMembership($principal, $school), 'principal');

        $report = $this->service()->evaluate($school, $admin);
        $this->assertSame($school->id, $report['schoolId']);
        $this->assertCount(count(OrganizationSetupRegistry::DEFINITIONS), $report['items'], 'the School Admin sees every area');
        $this->assertNotEmpty($this->service()->evaluate($school, $principal)['items']);
    }

    #[Test]
    public function readiness_is_refused_without_the_school_floor_in_that_school(): void
    {
        $school = $this->createSchool();
        $teacher = $this->createUser();
        $this->assignSchoolRole($this->createMembership($teacher, $school), 'teacher');
        $suspended = $this->createUserWithCapabilities($school, ['school.profile.view'], 'suspended');
        $disabled = $this->createUserWithCapabilities($school, ['school.profile.view']);
        $disabled->forceFill(['is_disabled' => true])->save();

        foreach (['teacher' => $teacher, 'no membership' => $this->createUser(), 'suspended membership' => $suspended, 'disabled account' => $disabled] as $case => $viewer) {
            try {
                $this->service()->evaluate($school, $viewer->fresh());
                $this->fail("{$case} must be refused");
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function a_school_that_is_not_active_is_refused_even_to_its_administrator(): void
    {
        foreach (['suspended' => School::factory()->suspended()->create(), 'provisioning' => School::factory()->provisioning()->create()] as $state => $school) {
            try {
                $this->service()->evaluate($school, $this->schoolAdminFor($school));
                $this->fail("a {$state} School must be refused (rule 86)");
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function platform_and_group_authority_never_read_school_readiness(): void
    {
        $school = $this->createSchool();
        $root = $this->createPlatformRoot();
        $manager = $this->createUser();
        $this->assignPlatformRole($manager, 'platform_manager', $root);
        $group = $this->createGroup([$school]);
        $groupAdmin = $this->groupAdmin($group);

        foreach (['platform root' => $root, 'platform manager' => $manager, 'group admin' => $groupAdmin] as $case => $viewer) {
            try {
                $this->service()->evaluate($school, $viewer);
                $this->fail("{$case} must not read a School's readiness");
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function an_area_is_shown_only_to_a_viewer_holding_its_view_capabilities(): void
    {
        ['school' => $school] = $this->configuredWorld();
        $floorOnly = $this->createUserWithCapabilities($school, ['school.profile.view']);
        $items = $this->items($school, $floorOnly);

        $this->assertArrayHasKey('organization.school_profile', $items);
        $this->assertArrayHasKey('students.student_records', $items, 'floor-only areas follow the floor');
        $this->assertArrayNotHasKey('academic.academic_year', $items, 'needs academics.years.view');
        $this->assertArrayNotHasKey('finance.fees', $items, 'needs finance.fee_structures.view');
        $this->assertArrayNotHasKey('integrations.integrations', $items);
    }

    // -- Entitlement -----------------------------------------------------------

    #[Test]
    public function unentitled_optional_areas_are_not_applicable_and_never_evaluated(): void
    {
        $school = School::factory()->withCoreModulesOnly()->create();
        $spy = new class implements SetupReadinessProvider
        {
            public int $calls = 0;

            public function keys(): array
            {
                return ['transport.transport'];
            }

            public function evaluate(string $key, School $school): SetupReadiness
            {
                $this->calls++;

                return SetupReadiness::noSetupRequired('spy');
            }
        };
        $this->swapProvider('transport.transport', $spy);

        $items = $this->items($school, $this->schoolAdminFor($school));

        foreach (app(OrganizationSetupRegistry::class)->all() as $definition) {
            $item = $items[$definition->key];
            if ($definition->isCore()) {
                $this->assertNotSame('not_applicable', $item['status'], "{$definition->key} is core and always applicable");
            } else {
                $this->assertSame('not_applicable', $item['status'], "{$definition->key} is optional and not entitled");
                $this->assertNull($item['totalChecks']);
                $this->assertSame([], $item['blockers']);
            }
        }
        $this->assertSame(0, $spy->calls, 'an unentitled area is never evaluated');

        SchoolFactory::setOptionalModules($school, true, ['transport']);
        $transport = $this->items($school, $this->schoolAdminFor($school))['transport.transport'];
        $this->assertSame('blocked', $transport['status'], 'evaluated, then blocked by its unconfigured dependencies');
        $this->assertSame(['organization.campuses', 'staff.staff_records'], $transport['blockers']);
        $this->assertSame(1, $spy->calls, 'an entitled area is evaluated by its provider');
    }

    #[Test]
    public function an_unentitled_module_never_makes_a_dependent_area_blocked(): void
    {
        ['school' => $school, 'admin' => $admin] = $this->configuredWorld();
        SchoolFactory::setOptionalModules($school, false, ['attendance', 'timetable']);

        $items = $this->items($school, $admin);
        $this->assertSame('not_applicable', $items['academic.timetable']['status']);
        $this->assertSame('not_applicable', $items['attendance.student_attendance']['status']);
    }

    // -- Dependencies ----------------------------------------------------------

    #[Test]
    public function an_area_whose_dependency_is_unconfigured_is_blocked_by_key_without_inventing_data(): void
    {
        $school = $this->createSchool();
        $this->createCampus($school);
        $this->createGradeLevel($school);
        $this->createSubject($school);
        $admin = $this->schoolAdminFor($school);

        $items = $this->items($school, $admin);
        $this->assertSame('not_configured', $items['academic.academic_year']['status']);
        $this->assertSame('blocked', $items['academic.structure']['status']);
        $this->assertSame(['academic.academic_year'], $items['academic.structure']['blockers']);
        $this->assertSame([2, 4], [$items['academic.structure']['completedChecks'], $items['academic.structure']['totalChecks']]);
        $this->assertSame(0, DB::table('academic_years')->where('school_id', $school->id)->count(), 'evaluation never creates configuration');
    }

    #[Test]
    public function a_configured_school_reports_its_core_areas_configured(): void
    {
        ['school' => $school, 'admin' => $admin] = $this->configuredWorld();
        $items = $this->items($school, $admin);

        foreach (['organization.school_profile', 'organization.campuses', 'academic.academic_year', 'academic.structure', 'students.student_records', 'students.guardian_identity', 'staff.staff_records', 'compliance.audit'] as $key) {
            $this->assertSame('configured', $items[$key]['status'], "{$key}: {$items[$key]['explanation']}");
            $this->assertSame([], $items[$key]['blockers']);
        }
        $this->assertSame('1 active Campus.', $items['organization.campuses']['explanation']);
        $this->assertSame('blocked', $items['attendance.student_attendance']['status'], 'no timetable yet');
        $this->assertSame(['academic.timetable'], $items['attendance.student_attendance']['blockers']);
    }

    #[Test]
    public function a_future_hire_and_an_inactive_guardian_never_count_as_current_setup(): void
    {
        $school = $this->createSchool();
        $admin = $this->schoolAdminFor($school);
        $this->createDepartment($school);
        $this->createPosition($school);
        $this->createEmploymentRecord($this->createEmployee($school), ['status' => 'active', 'starts_on' => now()->addMonth()->toDateString()]);
        $student = $this->createStudent($school);
        $this->createGuardian($school);
        $this->createStudentGuardianRelationship($student, $this->createGuardian($school, ['status' => 'inactive']), ['is_legal_guardian' => true]);

        $items = $this->items($school, $admin);
        $this->assertSame(['partially_configured', 2, 3], [$items['staff.staff_records']['status'], $items['staff.staff_records']['completedChecks'], $items['staff.staff_records']['totalChecks']], 'a future hire is not current staff');
        $this->assertSame('Some active Students have no legal guardian linked.', $items['students.guardian_identity']['explanation'], 'an inactive Guardian covers no Student');
    }

    #[Test]
    public function the_guardian_portal_is_blocked_outside_development_environments_whatever_the_entitlement(): void
    {
        ['school' => $school, 'admin' => $admin] = $this->configuredWorld();
        $this->assertSame('not_configured', $this->items($school, $admin)['portal.guardian_portal']['status']);

        $this->app['env'] = 'production';
        try {
            $this->assertSame('blocked', $this->items($school, $admin)['portal.guardian_portal']['status']);
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    // -- School isolation --------------------------------------------------------

    #[Test]
    public function one_schools_readiness_never_reflects_or_reaches_another_school(): void
    {
        ['school' => $a, 'admin' => $adminA] = $this->configuredWorld();
        $b = $this->createSchool();
        $adminB = $this->schoolAdminFor($b);

        $before = $this->stable($this->service()->evaluate($a, $adminA));
        $this->configuredWorld($b);
        $this->assertSame($before, $this->stable($this->service()->evaluate($a, $adminA)), 'configuring B never changes A');
        $this->assertSame('configured', $this->items($b, $adminB)['academic.structure']['status']);

        $emptyB = $this->createSchool();
        $this->assertSame('not_configured', $this->items($emptyB, $this->schoolAdminFor($emptyB))['organization.campuses']['status'], 'A\'s Campus is never counted for another School');

        try {
            $this->service()->evaluate($b, $adminA);
            $this->fail('School A\'s administrator must not read School B');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
    }

    #[Test]
    public function a_shared_user_never_collapses_school_context(): void
    {
        ['school' => $a] = $this->configuredWorld();
        $b = $this->createSchool();
        $shared = $this->schoolAdminFor($a);
        $this->assignSchoolRole($this->createMembership($shared, $b), 'school_admin');
        $context = app(TenantContext::class);

        $context->withSchool($b, function () use ($a, $b, $shared, $context): void {
            $items = $this->items($a, $shared);
            $this->assertSame('configured', $items['organization.campuses']['status'], 'A is evaluated as A inside B\'s ambient context');
            $this->assertSame($b->id, $context->school()?->id, 'the ambient context is restored');
            $this->assertSame('not_configured', $this->items($b, $shared)['organization.campuses']['status']);
        });
    }

    // -- Privacy ---------------------------------------------------------------

    #[Test]
    public function readiness_carries_facts_only_never_business_data(): void
    {
        ['school' => $school, 'admin' => $admin] = $this->configuredWorld();
        $report = $this->service()->evaluate($school, $admin);

        $this->assertSame(['schoolId', 'evaluatedAt', 'items'], array_keys($report));
        foreach ($report['items'] as $item) {
            $this->assertSame(self::ITEM_FIELDS, array_keys($item), "{$item['key']} has the fixed shape");
        }
        $json = (string) json_encode($report);
        foreach (['Xanthippe', 'Quorvald', 'Ulrikka', 'Brannoch', 'ulrikka.private', 'Ottoline', 'Vreeswijk', 'Zephyrine', 'zephyrine.example', '0000099999', 'Quillon', 'Vantar', '@'] as $planted) {
            $this->assertStringNotContainsString($planted, $json, "readiness never carries {$planted}");
        }
        $this->assertDoesNotMatchRegularExpression('/\d+\.\d{2}\b/', $json, 'no amount');
    }

    // -- Read-only and idempotent -----------------------------------------------

    #[Test]
    public function evaluating_twice_writes_nothing_and_answers_the_same(): void
    {
        ['school' => $school, 'admin' => $admin] = $this->configuredWorld();
        $ledgers = fn (): array => [
            DB::table('school_audit_events')->count(), DB::table('platform_audit_events')->count(), DB::table('domain_event_outbox')->count(),
        ];
        $before = $ledgers();

        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = strtolower(ltrim($query->sql));
        });
        $first = $this->service()->evaluate($school, $admin);
        $second = $this->service()->evaluate($school, $admin);

        $writes = array_filter($statements, fn (string $sql): bool => preg_match('/^(insert|update|delete|merge|truncate|alter|create|drop)\b/', $sql) === 1);
        $this->assertSame([], array_values($writes), 'readiness evaluation never writes');
        $this->assertSame($this->stable($first), $this->stable($second));
        $this->assertSame($before, $ledgers(), 'no audit row or event is written merely because readiness was read');
    }

    // -- Performance -------------------------------------------------------------

    #[Test]
    public function the_full_catalogue_is_evaluated_within_the_measured_query_budget(): void
    {
        ['school' => $school, 'admin' => $admin] = $this->configuredWorld();
        foreach (range(1, 30) as $i) {
            $student = $this->createStudent($school);
            $this->createStudentGuardianRelationship($student, $this->createGuardian($school), ['is_legal_guardian' => true]);
        }
        $this->service()->evaluate($school, $admin); // warm the grant and entitlement caches like a real page

        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });
        $this->service()->evaluate($school, $admin);

        $this->assertLessThanOrEqual(self::QUERY_BUDGET, $count, "full-catalogue evaluation used {$count} queries");
    }

    /** @return array<string, mixed> the report without its evaluation time */
    private function stable(array $report): array
    {
        unset($report['evaluatedAt']);

        return $report;
    }

    private function swapProvider(string $key, SetupReadinessProvider $replacement): void
    {
        $original = app(SetupReadinessProviders::class);
        $swapped = new SetupReadinessProviders;
        $swapped->register($replacement);
        foreach (app(OrganizationSetupRegistry::class)->all() as $definition) {
            if ($definition->key !== $key && ! in_array($definition->key, $swapped->keys(), true)) {
                $provider = $original->for($definition->key);
                $swapped->register(new class($provider, $definition->key) implements SetupReadinessProvider
                {
                    public function __construct(private readonly SetupReadinessProvider $inner, private readonly string $key) {}

                    public function keys(): array
                    {
                        return [$this->key];
                    }

                    public function evaluate(string $key, School $school): SetupReadiness
                    {
                        return $this->inner->evaluate($key, $school);
                    }
                });
            }
        }
        $this->app->instance(SetupReadinessProviders::class, $swapped);
    }
}
