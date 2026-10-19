<?php

namespace Tests\Feature\Authorization\Campus;

use App\Support\Authorization\Campus\CampusScopeCatalogue;
use App\Support\Navigation\StaffNavigation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * ORG.7A (ADR 0076 §11.3, §33; CLAUDE.md rule 101): Campus adoption is
 * declared, centralized and fail-closed.
 *
 * - Every adopted capability is a real School capability of a declared
 *   domain with its NULL-Campus / inactive-Campus / lookup policy, and is
 *   never ORG-C2 whole-School authority.
 * - An adopted capability is never checked with the whole-School Gate in
 *   application code (that would silently ignore Campus grants) -- only
 *   through authorizeCampusScope / `,campus`. A whole-School `capability:`
 *   ROUTE floor on an adopted key is allowed only on a declared
 *   whole-School-only operation (ORG.7D-0, ADR 0076 §36): the registered
 *   route list is checked by CoverageGuard::adoptedWholeSchool().
 * - The adopted domains' controllers never query their Campus-bound models
 *   directly: the predicate lives in the domain access class.
 * - Campus scope is computed only by CampusScopeResolver, and
 *   TenantContext::campus() is never consulted for authority.
 */
class CampusScopeAdoptionArchitectureTest extends TestCase
{
    /** Domain => [access class file, controllers that must go through it, models they must not query]. */
    private const DOMAIN_PATHS = [
        'visitor' => [
            'access' => 'app/Domain/Visitor/Application/VisitorVisitAccess.php',
            'controllers' => ['app/Domain/Visitor/Http/Controllers/VisitorVisitController.php', 'app/Http/Controllers/App/VisitorVisitController.php'],
            'models' => ['VisitorVisit::query(', 'Campus::query('],
        ],
        'inventory' => [
            'access' => 'app/Domain/Inventory/Application/InventoryStockAccess.php',
            'controllers' => ['app/Domain/Inventory/Http/Controllers/InventoryStockController.php', 'app/Http/Controllers/App/InventoryStockController.php'],
            'models' => ['InventoryStockBalance::query(', 'StockMovement::query(', 'InventoryLocation::query('],
        ],
        'timetable' => [
            'access' => 'app/Domain/Timetable/Application/TimetableScheduleAccess.php',
            'controllers' => ['app/Domain/Timetable/Http/Controllers/TimetableEntryController.php', 'app/Http/Controllers/App/Timetable/TimetableEntryController.php'],
            'models' => ['TimetableEntry::query(', 'SubjectOffering::query(', 'Section::query(', 'Room::query('],
        ],
        'attendance' => [
            'access' => 'app/Domain/Attendance/Application/AttendanceAdministrationAccess.php',
            'controllers' => ['app/Domain/Attendance/Http/Controllers/AttendanceSessionController.php', 'app/Http/Controllers/App/Attendance/AttendanceController.php', 'app/Http/Controllers/App/Attendance/AttendanceExceptionController.php'],
            'models' => ['AttendanceSession::query(', 'AttendanceRecord::query(', 'TimetableEntry::query('],
        ],
        'curriculum_delivery' => [
            'access' => 'app/Domain/CurriculumDelivery/Application/CurriculumDeliveryAccess.php',
            'controllers' => ['app/Domain/CurriculumDelivery/Http/Controllers/CurriculumDeliveryController.php', 'app/Http/Controllers/App/CurriculumDelivery/CurriculumDeliveryController.php'],
            'models' => ['CurriculumDelivery::query(', 'SubjectOffering::query(', 'Section::query('],
        ],
        'lms' => [
            'access' => 'app/Domain/LMS/Application/LmsAdministrationAccess.php',
            'controllers' => ['app/Domain/LMS/Http/Controllers/LearningContentController.php', 'app/Domain/LMS/Http/Controllers/AssignmentController.php', 'app/Http/Controllers/App/LMS/LearningContentController.php', 'app/Http/Controllers/App/LMS/AssignmentController.php'],
            'models' => ['LearningContent::query(', 'Assignment::query(', 'SubjectOffering::query('],
        ],
        'examination_papers' => [
            'access' => 'app/Domain/Examinations/Application/ExaminationPaperAccess.php',
            'controllers' => ['app/Domain/Examinations/Http/Controllers/ExaminationPaperController.php', 'app/Http/Controllers/App/Examinations/ExaminationPaperController.php'],
            'models' => ['ExaminationPaper::query(', 'SubjectOffering::query('],
        ],
        'hostel' => [
            'access' => 'app/Domain/Hostel/Application/HostelResidencyAccess.php',
            'controllers' => ['app/Domain/Hostel/Http/Controllers/HostelResidencyAssignmentController.php', 'app/Http/Controllers/App/HostelResidencyController.php'],
            'models' => ['HostelResidencyAssignment::query(', 'HostelBed::query(', 'Student::query('],
        ],
        'canteen' => [
            'access' => 'app/Domain/Canteen/Application/CanteenOrderAccess.php',
            'controllers' => ['app/Domain/Canteen/Http/Controllers/CanteenOrderController.php', 'app/Http/Controllers/App/Canteen/CanteenOrderController.php'],
            'models' => ['CanteenOrder::query(', 'CanteenOutlet::query(', 'Student::query('],
        ],
        'admissions' => [
            'access' => 'app/Domain/Admissions/Application/AdmissionApplicationAccess.php',
            'controllers' => ['app/Domain/Admissions/Http/Controllers/AdmissionApplicationController.php', 'app/Http/Controllers/App/AdmissionApplicationController.php'],
            'models' => ['AdmissionApplication::query('],
        ],
    ];

    #[Test]
    public function every_adopted_capability_is_a_declared_non_whole_school_school_capability(): void
    {
        $this->assertSame(array_keys(self::DOMAIN_PATHS), array_keys(CampusScopeCatalogue::DOMAINS));

        foreach (CampusScopeCatalogue::ADOPTED as $capability => $domain) {
            $this->assertArrayHasKey($domain, CampusScopeCatalogue::DOMAINS, "{$capability} names unknown domain {$domain}");
            $this->assertSame('school', DB::table('capabilities')->where('key', $capability)->value('namespace'), "{$capability} must be an existing School capability");
            foreach (['school.', 'integrations.', 'academics.years.', 'students.', 'enrollments.', 'guardians.', 'portal.'] as $forbidden) {
                $this->assertStringStartsNotWith($forbidden, $capability, "{$capability} is whole-School authority or an unadopted SIS family");
            }
        }
        foreach (CampusScopeCatalogue::DOMAINS as $domain => $policy) {
            foreach (['label', 'nullCampus', 'inactiveCampus', 'sharedLookups'] as $field) {
                $this->assertNotSame('', trim($policy[$field] ?? ''), "{$domain} lacks its {$field} policy");
            }
            $this->assertContains($domain, CampusScopeCatalogue::ADOPTED, "{$domain} adopts no capability");
        }
    }

    #[Test]
    public function an_adopted_capability_is_never_checked_with_a_whole_school_gate(): void
    {
        $hubExempt = $this->declaredWholeSchoolHubControllers();
        $offenders = [];
        foreach (Finder::create()->files()->in([app_path(), base_path('routes')])->name('*.php') as $file) {
            $source = $file->getContents();
            // Route floors are checked on the registered routes instead
            // (declared whole-School-only operations, ORG.7D-0).
            $routeFile = str_starts_with($file->getRealPath(), base_path('routes'));
            foreach (CampusScopeCatalogue::capabilities() as $capability) {
                // ORG.7D-1: a declared whole-School hub's own controller, for its declared keys only.
                if (in_array($capability, $hubExempt[$file->getRealPath()] ?? [], true)) {
                    continue;
                }
                $quoted = preg_quote($capability, '/');
                if (preg_match("/authorizeCapability(For)?\\(\\s*(\\\$[a-z]+,\\s*)?'{$quoted}'/", $source)
                    || (! $routeFile && preg_match("/'capability:{$quoted}'/", $source))
                    || preg_match("/->canInSchool\\([^)]*'{$quoted}'/", $source)) {
                    $offenders[] = $file->getRelativePathname()." checks {$capability} School-wide only";
                }
            }
        }

        $this->assertSame([], $offenders, 'Use authorizeCampusScope() / `capability:x,campus` for an adopted capability.');
    }

    /**
     * ORG.7D-1 (ADR 0076 §37): every declared whole-School hub is a real
     * navigation hub whose requirement names the key, the key is adopted, the
     * reason is given -- and only the hub's own index controller is exempt.
     *
     * @return array<string, list<string>> controller real path => exempt keys
     */
    private function declaredWholeSchoolHubControllers(): array
    {
        $exempt = [];
        foreach (CampusScopeCatalogue::WHOLE_SCHOOL_HUBS as $hub => $keys) {
            $entry = collect(StaffNavigation::entries())->firstWhere('key', $hub);
            $this->assertNotNull($entry, "{$hub} is not a navigation entry");
            $requirement = StaffNavigation::hubRequirement($hub);
            $route = Route::getRoutes()->getByName($entry->routeName);
            $this->assertNotNull($route, "{$hub}'s route {$entry->routeName} does not exist");
            $controller = (new ReflectionClass(explode('@', $route->getActionName())[0]))->getFileName();
            foreach ($keys as $capability => $reason) {
                $this->assertTrue(CampusScopeCatalogue::isAdopted($capability), "{$hub} declares unadopted {$capability}");
                $this->assertContains($capability, $requirement, "{$hub}'s requirement does not name {$capability}");
                $this->assertNotSame('', trim($reason), "{$hub} declares {$capability} without a reason");
                $this->assertNotSame(CampusScopeCatalogue::adoptedAmong($requirement), $requirement, "{$hub} opens on adopted keys only: it is Campus-aware, not a whole-School hub");
                $exempt[(string) $controller][] = $capability;
            }
        }

        return $exempt;
    }

    #[Test]
    public function the_adopted_domains_query_only_through_their_access_class(): void
    {
        foreach (self::DOMAIN_PATHS as $domain => $paths) {
            $this->assertFileExists(base_path($paths['access']));
            $this->assertStringContainsString('->restrict(', (string) file_get_contents(base_path($paths['access'])), "{$domain} access class applies the CampusScope");
            foreach ($paths['controllers'] as $controller) {
                $source = (string) file_get_contents(base_path($controller));
                $this->assertStringContainsString('authorizeCampusScope(', $source, "{$controller} resolves the CampusScope");
                foreach ($paths['models'] as $direct) {
                    $this->assertStringNotContainsString($direct, $source, "{$controller} queries {$direct} directly instead of through its access class");
                }
            }
        }
    }

    #[Test]
    public function campus_scope_comes_only_from_the_resolver_and_never_from_context(): void
    {
        foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
            $path = $file->getRelativePathname();
            $source = $file->getContents();
            if (! str_ends_with($path, 'Campus/CampusScopeResolver.php') && ! str_ends_with($path, 'Campus/CampusScope.php')) {
                $this->assertDoesNotMatchRegularExpression('/CampusScope::(campuses|wholeSchool)\(/', $source, "{$path} builds a CampusScope itself");
            }
        }
        foreach (self::DOMAIN_PATHS as $paths) {
            foreach ([$paths['access'], ...$paths['controllers']] as $file) {
                $this->assertDoesNotMatchRegularExpression('/->campus\(\)|campusId\(\)/', (string) file_get_contents(base_path($file)), "{$file} reads a context Campus");
            }
        }
    }

    /**
     * ORG.7D-3 (ADR 0076 §39): a Student-placing write service takes its Campus
     * write guard as an optional parameter (null = the whole-School path), so a
     * caller that omits it would skip the Campus write check. Every call in app
     * code passes the access class's writeGuard() explicitly.
     */
    #[Test]
    public function every_student_placing_write_passes_its_campus_write_guard(): void
    {
        $services = [
            'HostelResidencyService' => ['assign'],
            'CanteenOrderService' => ['place', 'fulfill', 'cancel'],
        ];
        $calls = 0;
        foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
            $path = $file->getRelativePathname();
            $source = $file->getContents();
            foreach ($services as $service => $methods) {
                if (str_ends_with($path, "/{$service}.php") || ! str_contains($source, $service)) {
                    continue;
                }
                preg_match_all('/->('.implode('|', $methods).')\((?:[^;]*?)\);/s', $source, $matches);
                foreach ($matches[0] as $call) {
                    $calls++;
                    $this->assertStringContainsString('writeGuard(', $call, "{$path} calls {$service} without its Campus write guard: {$call}");
                }
            }
        }
        $this->assertGreaterThanOrEqual(8, $calls, 'the scan found the adopted write calls');
    }
}
