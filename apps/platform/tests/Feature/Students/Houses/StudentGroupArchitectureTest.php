<?php

namespace Tests\Feature\Students\Houses;

use App\Domain\Students\Application\Groups\ActivityCategory;
use App\Domain\Students\Application\Groups\ClubResponsibility;
use App\Domain\Students\Application\Groups\CommunityMembershipReason;
use App\Domain\Students\Application\Groups\HouseMembershipReason;
use App\Domain\Students\Application\Groups\HouseResponsibility;
use App\Domain\Students\Application\Groups\StudentGroupType;
use App\Support\Authorization\Campus\CampusScopeCatalogue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * SIS 11.7 (OD-SIS-4) / SIS 11.8 (OD-SIS-5A, OD-SIS-5B): the Student
 * Communities structural contract.
 * - Exactly three closed group types (house, club, activity), equal in code
 *   and database; a new type is a reviewed migration plus code, never
 *   configuration. No tag, segment, custom group or rule engine exists.
 * - Membership and responsibility are DESCRIPTIVE: no authorization,
 *   tenancy, navigation, Campus or capability code reads them.
 * - Only the Groups services write them; lifecycle services (status,
 *   transfer, rollover, conversion, import) never touch them; nothing
 *   allocates automatically.
 * - Rosters are operational: no demographic column or breakdown.
 * - Least privilege: School Administrator and Principal only; no Teacher,
 *   Guardian, portal or API surface; only the `.view` keys are
 *   Campus-adopted.
 */
class StudentGroupArchitectureTest extends TestCase
{
    /** Every app file that may name the Student Group tables or classes. */
    private const ALLOWED = [
        'app/Domain/Students/Application/Exceptions/CommunityRefusedException.php',
        'app/Domain/Students/Application/Groups/ActivityCategory.php',
        'app/Domain/Students/Application/Groups/ClubResponsibility.php',
        'app/Domain/Students/Application/Groups/CommunityAdministrationService.php',
        'app/Domain/Students/Application/Groups/CommunityMembershipReason.php',
        'app/Domain/Students/Application/Groups/GuardsStudentGroupWrites.php',
        'app/Domain/Students/Application/Groups/HouseAdministrationService.php',
        'app/Domain/Students/Application/Groups/HouseMembershipReason.php',
        'app/Domain/Students/Application/Groups/HouseResponsibility.php',
        'app/Domain/Students/Application/Groups/StudentCommunityService.php',
        'app/Domain/Students/Application/Groups/StudentGroupCatalogue.php',
        'app/Domain/Students/Application/Groups/StudentGroupType.php',
        'app/Domain/Students/Application/Groups/StudentHouseService.php',
        'app/Domain/Students/Application/Groups/StudentHouseVersion.php',
        'app/Domain/Students/Application/Reports/ActivityRosterReport.php',
        'app/Domain/Students/Application/Reports/ClubRosterReport.php',
        'app/Domain/Students/Application/Reports/HouseRosterReport.php',
        'app/Domain/Students/Application/Reports/StudentGroupRosterReport.php',
        // Retention: the Student core cascade and the School configuration class.
        'app/Domain/Students/Application/Retention/StudentRecordRetentionService.php',
        'app/Domain/Students/Infrastructure/StudentGroup.php',
        'app/Domain/Students/Infrastructure/StudentGroupMembership.php',
        'app/Domain/Students/Infrastructure/StudentGroupResponsibility.php',
        'app/Http/Controllers/App/CommunityController.php',
        'app/Http/Controllers/App/HouseController.php',
        'app/Http/Controllers/App/PresentsCommunityDirectory.php',
        'app/Http/Controllers/App/StudentCommunityController.php',
        'app/Http/Controllers/App/StudentHouseController.php',
        'app/Support/Retention/Erasure/UserReferenceCatalog.php',
        'app/Support/Retention/TenantRetentionCatalog.php',
        // The Student 360 cards, through the two services' summary() only.
        'app/Support/StudentProfile/StudentProfileSections.php',
        'app/Support/StudentReports/StudentReportRegistry.php',
    ];

    private const PATTERN = '/student_group|Students\\\\Application\\\\Groups|StudentGroup(Membership|Responsibility)?\b|(House|Club|Activity)RosterReport/';

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

    /** @param  list<\BackedEnum>  $cases */
    private function values(array $cases): array
    {
        return array_values(array_unique(array_map(fn (\BackedEnum $c) => $c->value, $cases)));
    }

    #[Test]
    public function the_group_types_and_vocabularies_are_closed_and_equal_to_the_database(): void
    {
        $this->assertSame(['house', 'club', 'activity'], $this->values(StudentGroupType::cases()));
        $checks = collect(DB::select("SELECT conname, pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname IN ('student_groups_type_check', 'student_group_memberships_type_check', 'student_group_memberships_reason_check', 'student_group_responsibilities_kind_check', 'student_groups_activity_category_check')"))
            ->pluck('def', 'conname');
        $quoted = fn (string $def): array => (preg_match_all("/'([a-z_]+)'::/", $def, $m) ? array_values(array_unique($m[1])) : []);

        $this->assertEqualsCanonicalizing(['house', 'club', 'activity'], $quoted($checks['student_groups_type_check']));
        $this->assertEqualsCanonicalizing(['house', 'club', 'activity'], $quoted($checks['student_group_memberships_type_check']));
        $this->assertEqualsCanonicalizing(
            [...$this->values([...HouseMembershipReason::cases(), ...CommunityMembershipReason::cases()]), 'house', 'club', 'activity'],
            $quoted($checks['student_group_memberships_reason_check']),
        );
        $this->assertEqualsCanonicalizing($this->values([...HouseResponsibility::cases(), ...ClubResponsibility::cases()]), $quoted($checks['student_group_responsibilities_kind_check']));
        $this->assertEqualsCanonicalizing([...$this->values(ActivityCategory::cases()), 'activity'], $quoted($checks['student_groups_activity_category_check']));
        $this->assertSame([], array_intersect($this->values(HouseResponsibility::cases()), $this->values(ClubResponsibility::cases())), 'responsibility codes are disjoint per type');
    }

    #[Test]
    public function the_uniqueness_rules_differ_by_type_in_the_database(): void
    {
        $defs = collect(DB::select("SELECT indexname, indexdef FROM pg_indexes WHERE tablename IN ('student_group_memberships', 'student_group_responsibilities')"))->pluck('indexdef', 'indexname');
        $this->assertStringContainsString("(school_id, student_id, group_type) WHERE ((ends_on IS NULL) AND ((group_type)::text = 'house'::text))", $defs['student_group_memberships_one_current'], 'one current House per Student');
        $this->assertStringContainsString('(school_id, student_id, student_group_id) WHERE (ends_on IS NULL)', $defs['student_group_memberships_one_current_per_group'], 'one current membership per Student per group');
        foreach (['house_captain', 'vice_captain', 'club_president', 'club_vice_president', 'club_secretary'] as $unique) {
            $this->assertStringContainsString("'{$unique}'", $defs['student_group_responsibilities_unique_holder']);
        }
        foreach (['prefect', 'member_representative'] as $several) {
            $this->assertStringNotContainsString("'{$several}'", $defs['student_group_responsibilities_unique_holder']);
        }
    }

    #[Test]
    public function only_the_declared_files_name_student_groups_so_nothing_authorizes_by_community(): void
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
                $this->assertDoesNotMatchRegularExpression(self::PATTERN, $file->getContents(), "{$file->getRelativePathname()} must never read community membership or responsibility");
            }
        }
    }

    #[Test]
    public function only_the_groups_services_write_and_lifecycle_services_never_touch_communities(): void
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
                $this->assertDoesNotMatchRegularExpression('/house|club|activit|student_group|communit/i', $this->code($path), "{$path} never changes a community");
            }
        }
        $lifecycle = (new Finder)->files()->in(base_path('app/Domain'))->name('*.php')
            ->filter(fn ($f) => str_contains($f->getPathname(), '/Admissions/') || str_contains($f->getPathname(), '/DataHealth/'));
        foreach ($lifecycle as $file) {
            $this->assertDoesNotMatchRegularExpression('/student_group|StudentHouse|StudentCommunity/', $file->getContents(), 'conversion and Data Health never touch a community');
        }

        // No automatic allocation: one explicit insert per writer, no rule input.
        foreach (['StudentHouseService', 'StudentCommunityService'] as $writer) {
            $service = $this->code("app/Domain/Students/Application/Groups/{$writer}.php");
            $this->assertSame(1, substr_count($service, 'new StudentGroupMembership'), "{$writer}: one explicit membership insert");
            $this->assertDoesNotMatchRegularExpression('/sibling|grade_level|campus_id|random|shuffle|least|foreach \(\$students/i', $service);
        }
        $this->assertSame(1, substr_count($this->code('app/Domain/Students/Application/Groups/StudentGroupCatalogue.php'), 'new StudentGroup;'));
    }

    #[Test]
    public function there_is_no_tag_segment_or_demographic_community_engine(): void
    {
        foreach (['student_tags', 'student_segments', 'student_group_rules', 'student_group_tags', 'student_custom_groups', 'student_group_mentors'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} must not exist");
        }
        $roster = $this->code('app/Domain/Students/Application/Reports/StudentGroupRosterReport.php');
        foreach (['gender', 'date_of_birth', 'religion', 'caste', 'nationality', 'percent', 'count('] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, strtolower($roster), "rosters carry no demographic or aggregate ({$forbidden})");
        }
        $this->assertFalse(Schema::hasColumn('student_groups', 'schedule'), 'scheduling belongs to Timetable');
        $this->assertFalse(Schema::hasColumn('student_groups', 'fee_amount'), 'money belongs to Finance');
        $this->assertFalse(Schema::hasColumn('student_group_memberships', 'level'), 'participation levels are deferred');
    }

    #[Test]
    public function the_capabilities_are_least_privilege_and_only_view_is_campus_adopted(): void
    {
        $holders = collect(DB::select("SELECT r.key AS role, rc.capability_key AS capability FROM role_capabilities rc JOIN roles r ON r.id = rc.role_id
            WHERE (rc.capability_key LIKE 'students.houses.%' OR rc.capability_key LIKE 'students.communities.%') AND r.is_system ORDER BY r.key, rc.capability_key"))
            ->groupBy('role')->map(fn ($rows) => $rows->pluck('capability')->all())->all();
        $all = ['students.communities.assign', 'students.communities.manage', 'students.communities.view', 'students.houses.assign', 'students.houses.manage', 'students.houses.view'];
        $this->assertSame(['principal' => $all, 'school_admin' => $all], $holders, 'never a Teacher, operational, Guardian or platform role');

        foreach (['students.houses.view', 'students.communities.view'] as $view) {
            $this->assertSame('students', CampusScopeCatalogue::ADOPTED[$view]);
        }
        foreach (['students.houses.manage', 'students.houses.assign', 'students.communities.manage', 'students.communities.assign'] as $whole) {
            $this->assertArrayNotHasKey($whole, CampusScopeCatalogue::ADOPTED);
        }

        $surfaces = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => preg_match('#house|club|activit|communit#', $r->uri()) === 1 && $r->uri() !== 'api/v1/schools/{school}/employees/{employee}/activity')
            ->map(fn ($r) => $r->uri())->unique()->sort()->values()->all();
        $this->assertSame([
            'app/students/activities',
            'app/students/activities/{group}',
            'app/students/activities/{group}/status',
            'app/students/clubs',
            'app/students/clubs/{group}',
            'app/students/clubs/{group}/status',
            'app/students/communities',
            'app/students/houses',
            'app/students/houses/{house}',
            'app/students/houses/{house}/status',
            'app/students/{student}/communities',
            'app/students/{student}/communities/responsibilities/{responsibility}/end',
            'app/students/{student}/communities/{membership}/end',
            'app/students/{student}/communities/{membership}/responsibilities',
            'app/students/{student}/house',
            'app/students/{student}/house/end',
            'app/students/{student}/house/responsibilities',
            'app/students/{student}/house/responsibilities/{responsibility}/end',
        ], $surfaces, 'staff web only: no API, portal or export route');
    }
}
