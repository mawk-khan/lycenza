<?php

namespace Tests\Feature\Students\Houses;

use App\Domain\Students\Application\Groups\HouseMembershipReason;
use App\Domain\Students\Application\Groups\HouseResponsibility;
use App\Domain\Students\Application\Groups\StudentGroupType;
use App\Support\Authorization\Campus\CampusScopeCatalogue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * SIS 11.7 (OD-SIS-4): the House pilot's structural contract.
 * - One closed group type (`house`), equal in code and database; a new type
 *   is a reviewed migration plus code, never configuration.
 * - Membership and responsibility are DESCRIPTIVE: no authorization,
 *   tenancy, navigation, Campus or capability code reads them.
 * - Only the two Groups services write them; lifecycle services (status,
 *   transfer, rollover, conversion) never touch them; nothing allocates
 *   automatically.
 * - Least privilege: School Administrator and Principal only; no Teacher,
 *   Guardian, portal or API surface; only `.view` is Campus-adopted.
 */
class StudentGroupArchitectureTest extends TestCase
{
    /** Every app file that may name the Student Group tables or classes. */
    private const ALLOWED = [
        'app/Domain/Students/Application/Groups/HouseAdministrationService.php',
        'app/Domain/Students/Application/Groups/HouseMembershipReason.php',
        'app/Domain/Students/Application/Groups/HouseResponsibility.php',
        'app/Domain/Students/Application/Groups/StudentGroupType.php',
        'app/Domain/Students/Application/Groups/StudentHouseService.php',
        'app/Domain/Students/Application/Groups/StudentHouseVersion.php',
        'app/Domain/Students/Application/Reports/HouseRosterReport.php',
        // Retention: the Student core cascade and the School configuration class.
        'app/Domain/Students/Application/Retention/StudentRecordRetentionService.php',
        'app/Domain/Students/Infrastructure/StudentGroup.php',
        'app/Domain/Students/Infrastructure/StudentGroupMembership.php',
        'app/Domain/Students/Infrastructure/StudentGroupResponsibility.php',
        'app/Http/Controllers/App/HouseController.php',
        'app/Http/Controllers/App/StudentHouseController.php',
        'app/Support/Retention/Erasure/UserReferenceCatalog.php',
        'app/Support/Retention/TenantRetentionCatalog.php',
        // The Student 360 card, through StudentHouseService::summary() only.
        'app/Support/StudentProfile/StudentProfileSections.php',
        'app/Support/StudentReports/StudentReportRegistry.php',
    ];

    private const PATTERN = '/student_group|Students\\\\Application\\\\Groups|StudentGroup(Membership|Responsibility)?\b|HouseRosterReport/';

    private function source(string $path): string
    {
        return (string) file_get_contents(base_path($path));
    }

    /** The PHP code without comments (a docblock may name what the code never does). */
    private function code(string $path): string
    {
        return implode('', array_map(
            fn ($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t,
            token_get_all($this->source($path)),
        ));
    }

    #[Test]
    public function the_group_type_and_vocabularies_are_closed_and_equal_to_the_database(): void
    {
        $this->assertSame(['house'], array_map(fn (StudentGroupType $t) => $t->value, StudentGroupType::cases()));
        $checks = collect(DB::select("SELECT conname, pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname IN ('student_groups_type_check', 'student_group_memberships_type_check', 'student_group_memberships_reason_check', 'student_group_responsibilities_kind_check')"))
            ->pluck('def', 'conname');
        $quoted = fn (string $def): array => (preg_match_all("/'([a-z_]+)'::/", $def, $m) ? array_values(array_unique($m[1])) : []);

        $this->assertSame(['house'], $quoted($checks['student_groups_type_check']));
        $this->assertSame(['house'], $quoted($checks['student_group_memberships_type_check']));
        $this->assertEqualsCanonicalizing(array_map(fn ($r) => $r->value, HouseMembershipReason::cases()), $quoted($checks['student_group_memberships_reason_check']));
        $this->assertEqualsCanonicalizing(array_map(fn ($r) => $r->value, HouseResponsibility::cases()), $quoted($checks['student_group_responsibilities_kind_check']));
    }

    #[Test]
    public function only_the_declared_files_name_student_groups_so_nothing_authorizes_by_house(): void
    {
        $found = [];
        foreach ((new Finder)->files()->in([base_path('app'), base_path('routes'), base_path('database/seeders')])->name('*.php') as $file) {
            if (preg_match(self::PATTERN, $file->getContents()) === 1) {
                $found[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }
        sort($found);
        $this->assertSame(self::ALLOWED, array_values(array_diff($found, ['routes/web.php'])), 'a new reader of Student Groups is a reviewed change');

        foreach (['app/Support/Authorization', 'app/Support/Tenancy', 'app/Support/Navigation', 'app/Http/Middleware', 'app/Domain/Portal', 'app/Http/Controllers/Api'] as $dir) {
            if (! is_dir(base_path($dir))) {
                continue;
            }
            foreach ((new Finder)->files()->in(base_path($dir))->name('*.php') as $file) {
                $this->assertDoesNotMatchRegularExpression(self::PATTERN, $file->getContents(), "{$file->getRelativePathname()} must never read House membership or responsibility");
            }
        }
    }

    #[Test]
    public function only_the_groups_services_write_and_lifecycle_services_never_touch_houses(): void
    {
        foreach (self::ALLOWED as $path) {
            if (str_contains($path, '/Groups/') || str_contains($path, '/Infrastructure/')) {
                continue;
            }
            $source = $this->source($path);
            foreach (['->forceFill(', '->save(', 'new StudentGroup'] as $write) {
                $this->assertStringNotContainsString($write, $source, "{$path} never writes Student Groups ({$write})");
            }
            $this->assertDoesNotMatchRegularExpression("/table\\('student_group[a-z_]*'\\)[^;]*->(insert|update|delete|upsert)\\(/", $source, "{$path} never writes Student Groups");
        }

        foreach ([
            'app/Domain/Students/Application/StudentService.php',
            'app/Domain/Students/Application/StudentEnrollmentService.php',
            'app/Domain/Students/Application/CampusStudentTransferService.php',
            'app/Domain/Students/Application/EnrollmentRolloverItemExecutionService.php',
            'app/Domain/Students/Application/EnrollmentRolloverExecutionService.php',
            'app/Domain/Students/Application/Import/StudentGuardianImport.php',
        ] as $path) {
            if (is_file(base_path($path))) {
                $this->assertDoesNotMatchRegularExpression('/house|student_group/i', $this->code($path), "{$path} never changes a House");
            }
        }
        foreach ((new Finder)->files()->in(base_path('app/Domain/Admissions'))->name('*.php') as $file) {
            $this->assertDoesNotMatchRegularExpression('/student_group|StudentHouse/', $file->getContents(), 'conversion never assigns a House');
        }

        // No automatic allocation: only the explicit assign() inserts a membership.
        $service = $this->code('app/Domain/Students/Application/Groups/StudentHouseService.php');
        $this->assertSame(1, substr_count($service, 'new StudentGroupMembership'));
        $this->assertDoesNotMatchRegularExpression('/sibling|grade_level|campus_id|random|shuffle|least/i', $service);
    }

    #[Test]
    public function the_capabilities_are_least_privilege_and_only_view_is_campus_adopted(): void
    {
        $holders = collect(DB::select("SELECT r.key AS role, rc.capability_key AS capability FROM role_capabilities rc JOIN roles r ON r.id = rc.role_id
            WHERE rc.capability_key LIKE 'students.houses.%' AND r.is_system ORDER BY r.key, rc.capability_key"))
            ->groupBy('role')->map(fn ($rows) => $rows->pluck('capability')->all())->all();
        $all = ['students.houses.assign', 'students.houses.manage', 'students.houses.view'];
        $this->assertSame(['principal' => $all, 'school_admin' => $all], $holders, 'never a Teacher, operational, Guardian or platform role');

        $this->assertSame('students', CampusScopeCatalogue::ADOPTED['students.houses.view']);
        $this->assertArrayNotHasKey('students.houses.manage', CampusScopeCatalogue::ADOPTED);
        $this->assertArrayNotHasKey('students.houses.assign', CampusScopeCatalogue::ADOPTED);

        $surfaces = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_contains($r->uri(), 'house'))->map(fn ($r) => $r->uri())->unique()->sort()->values()->all();
        $this->assertSame([
            'app/students/houses',
            'app/students/houses/{house}',
            'app/students/houses/{house}/status',
            'app/students/{student}/house',
            'app/students/{student}/house/end',
            'app/students/{student}/house/responsibilities',
            'app/students/{student}/house/responsibilities/{responsibility}/end',
        ], $surfaces, 'staff web only: no API, portal or export route');
    }
}
