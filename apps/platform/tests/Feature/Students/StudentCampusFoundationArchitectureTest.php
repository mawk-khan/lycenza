<?php

namespace Tests\Feature\Students;

use App\Domain\Students\Application\StudentLookupCandidate;
use App\Domain\Students\Application\StudentOperationalCampusResolver;
use App\Domain\Students\Application\StudentOperationalEligibility;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * ORG.7D-2 (ADR 0076 §38): the Student Campus foundation stays ONE
 * definition, owned by Students, and never becomes a Student authority.
 *
 * - "Current placement" is StudentEnrollmentReadService::constrainToCurrentPlacement():
 *   a class that resolves the School's active AcademicYear and also reads
 *   Enrollments must use it (or be a declared year-scoped history reader).
 * - Outside Students, nothing reads Enrollment storage except declared,
 *   semantically different readers (a named cohort, a register's own rows,
 *   retention/role infrastructure). Everything asking "current" calls Students.
 * - Student identity is School-wide: `students` has no Campus column.
 * - The purpose lookup is internal: no route, no controller, minimal DTO.
 */
class StudentCampusFoundationArchitectureTest extends TestCase
{
    /** Reads Enrollment storage directly. */
    private const ENROLLMENT_ACCESS = "/StudentEnrollment::(query|where|whereIn|find|firstWhere|all)\\b|(?<!\\\$this)->enrollments\\b|(with|load|loadMissing|whereHas|whereDoesntHave|orWhereHas|has|doesntHave|withCount)\\(\\s*\\[?\\s*['\"]enrollments\\b|['\"]enrollments['\"]\\s*=>\\s*fn|student_enrollments/";

    /** Resolves the School's active AcademicYear. */
    private const ACTIVE_YEAR = "/CurrentAcademicYearResolver|AcademicYear::(query\\(\\)|where)[^;]*'status',\\s*'active'/s";

    /**
     * Non-Students code that legitimately reads Enrollment storage, each with
     * a meaning other than "current placement".
     */
    private const OUTSIDE_STUDENTS = [
        'app/Domain/Attendance/Application/AttendanceSubmissionService.php' => 'locks the Enrollment rows of the register being written (Section-bound roster)',
        'app/Domain/Attendance/Application/Portal/GuardianAttendanceReadService.php' => 'joins a Student\'s attendance records to their own Enrollments (record history, Guardian-scoped)',
        'app/Domain/Attendance/Application/Retention/AttendanceRetentionService.php' => 'retention reference checks',
        'app/Domain/Communications/Application/Audience/GradeAudienceResolver.php' => 'a named cohort: the announcement\'s own academic year and Grade',
        'app/Domain/Communications/Application/Audience/SectionAudienceResolver.php' => 'a named cohort: the announcement\'s own academic year and Section',
        'app/Http/Controllers/App/StudentEnrollmentController.php' => 'the SIS Enrollment pages themselves (Students surface)',
        'app/Support/Operations/DatabaseRoleVerifier.php' => 'database privilege verification',
        'app/Support/Retention/ReferencingRows.php' => 'retention infrastructure',
        'app/Support/Retention/RetentionAnchors.php' => 'retention infrastructure',
        'app/Support/Retention/TenantRetentionCatalog.php' => 'retention infrastructure',
    ];

    /** Active-year + Enrollment readers that are NOT "current placement". */
    private const YEAR_SCOPED_HISTORY = [
        'app/Domain/Attendance/Application/Portal/GuardianAttendanceReadService.php' => 'this year\'s attendance records of one Student, joined by the record\'s own Enrollment',
    ];

    /** @return array<string, string> relative path => source without comments */
    private function sources(string ...$dirs): array
    {
        $out = [];
        foreach (Finder::create()->files()->in(array_map(fn ($d) => base_path($d), $dirs))->name('*.php') as $file) {
            $code = '';
            foreach (token_get_all($file->getContents()) as $token) {
                $code .= is_array($token) ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1]) : $token;
            }
            $out[str_replace(base_path().'/', '', $file->getRealPath())] = $code;
        }

        return $out;
    }

    #[Test]
    public function every_current_placement_read_uses_the_one_definition(): void
    {
        $offenders = [];
        foreach ($this->sources('app') as $path => $code) {
            if (! preg_match(self::ACTIVE_YEAR, $code) || ! preg_match(self::ENROLLMENT_ACCESS, $code)) {
                continue;
            }
            if (isset(self::YEAR_SCOPED_HISTORY[$path]) || str_contains($code, 'constrainToCurrentPlacement(')) {
                continue;
            }
            $offenders[] = $path;
        }

        $this->assertSame([], $offenders, 'These resolve the active AcademicYear and read Enrollments without StudentEnrollmentReadService::constrainToCurrentPlacement() -- use the canonical current-placement / StudentOperationalCampusResolver instead of a new "current" rule (ADR 0076 §38).');
    }

    #[Test]
    public function only_declared_readers_outside_students_touch_enrollment_storage(): void
    {
        $found = [];
        foreach ($this->sources('app') as $path => $code) {
            if (! str_starts_with($path, 'app/Domain/Students/') && preg_match(self::ENROLLMENT_ACCESS, $code)) {
                $found[] = $path;
            }
        }
        sort($found);
        $declared = array_keys(self::OUTSIDE_STUDENTS);
        sort($declared);

        $this->assertSame($declared, $found, 'Outside Students, ask StudentEnrollmentReadService / StudentOperationalCampusResolver for current placement; a new direct reader needs a declared, non-"current" meaning here (ADR 0076 §38).');
        foreach (self::OUTSIDE_STUDENTS + self::YEAR_SCOPED_HISTORY as $path => $reason) {
            $this->assertNotSame('', trim($reason), "{$path} needs its reason");
        }
    }

    #[Test]
    public function the_guard_patterns_recognize_the_readers_they_exist_to_catch(): void
    {
        // The pre-ORG.7D-2 ad-hoc readers, and forms the security review listed.
        foreach ([
            "->with(['enrollments' => fn (\$query) => \$query->where('status', 'active')])",
            "StudentEnrollment::query()->where('academic_year_id', \$y)",
            "StudentEnrollment::where('status', 'active')",
            '$student->enrollments->first()',
            "->load('enrollments.section')",
            "->whereHas('enrollments', fn (\$q) => \$q)",
            "DB::table('student_enrollments')",
        ] as $reader) {
            $this->assertMatchesRegularExpression(self::ENROLLMENT_ACCESS, $reader, $reader);
        }
        foreach (["AcademicYear::query()->where('status', 'active')->first()", "AcademicYear::where('status', 'active')", 'app(CurrentAcademicYearResolver::class)'] as $year) {
            $this->assertMatchesRegularExpression(self::ACTIVE_YEAR, $year, $year);
        }
        // ... and not the ordinary words that merely mention enrollments.
        foreach (["'enrollments.view'", '$this->enrollments->currentForYear($s)', "new E('enrollments', 'people')"] as $other) {
            $this->assertDoesNotMatchRegularExpression(self::ENROLLMENT_ACCESS, $other, $other);
        }
    }

    #[Test]
    public function student_identity_has_no_campus(): void
    {
        foreach (['campus_id', 'current_campus_id', 'operational_campus_id'] as $column) {
            $this->assertFalse(Schema::hasColumn('students', $column), "students.{$column} would make identity Campus-owned (D2-1)");
        }
    }

    /**
     * ORG.7D-3 (ADR 0076 §39): the declared consumers -- each a domain access
     * class or write guard behind its OWN Campus-adopted capability. Never a
     * route, a controller or a generic Student endpoint.
     */
    private const CONSUMERS = [
        // SIS 6.1: names a current in-scope Student whose Attendance history is still empty.
        'app/Domain/Attendance/Application/AttendanceOperationsReadService.php',
        'app/Domain/Hostel/Application/HostelResidencyAccess.php',
        'app/Domain/Hostel/Application/HostelResidencyCampusGuard.php',
        'app/Domain/Canteen/Application/CanteenOrderAccess.php',
        'app/Domain/Canteen/Application/CanteenOrderCampusGuard.php',
        'app/Domain/Library/Application/LibraryCirculationAccess.php',
        'app/Domain/Library/Application/LibraryCirculationCampusGuard.php',
        'app/Domain/Transport/Application/TransportRiderAccess.php',
        'app/Domain/Transport/Application/TransportRiderCampusGuard.php',
    ];

    #[Test]
    public function the_purpose_lookup_is_internal_and_minimal(): void
    {
        // Nothing outside Students and the declared domain consumers (no route,
        // controller or other module) references the lookup or the resolver.
        foreach ($this->sources('routes', 'app') as $path => $code) {
            if (str_starts_with($path, 'app/Domain/Students/') || in_array($path, self::CONSUMERS, true)) {
                continue;
            }
            foreach (['StudentPurposeLookup', 'StudentOperationalCampusResolver', 'StudentOperationalEligibility', 'restrictToOperationalCampus'] as $class) {
                $this->assertStringNotContainsString($class, $code, "{$path} uses {$class}: only a declared domain access class or guard may consume the Student lookup (D2-8; ADR 0076 §39).");
            }
        }

        $fields = array_map(fn (ReflectionProperty $p) => $p->getName(), (new ReflectionClass(StudentLookupCandidate::class))->getProperties(ReflectionProperty::IS_PUBLIC));
        $this->assertSame(['id', 'displayName', 'studentNumber'], $fields, 'The lookup DTO is exactly id, displayName, studentNumber (D2-7).');
    }

    /**
     * ORG.7D-4 (ADR 0076 §40): the consumers' Campus write guards -- each the
     * write-time proof for NEW operational work on a Student.
     */
    private const WRITE_GUARDS = [
        'app/Domain/Hostel/Application/HostelResidencyCampusGuard.php',
        'app/Domain/Canteen/Application/CanteenOrderCampusGuard.php',
        'app/Domain/Library/Application/LibraryCirculationCampusGuard.php',
        'app/Domain/Transport/Application/TransportRiderCampusGuard.php',
    ];

    #[Test]
    public function student_dependent_writes_prove_eligibility_only_under_lock(): void
    {
        $sources = $this->sources('app');

        // A write guard proves eligibility with the locked assertion, never with
        // a picker answer or an unlocked read.
        foreach (self::WRITE_GUARDS as $guard) {
            $this->assertArrayHasKey($guard, $sources);
            $this->assertContains($guard, self::CONSUMERS);
            $this->assertStringContainsString('StudentOperationalEligibility::class)->lockOperationalOn(', $sources[$guard], "{$guard} proves eligibility under lock");
            foreach (['StudentPurposeLookup', 'StudentOperationalCampusResolver'] as $advisory) {
                $this->assertStringNotContainsString($advisory, $sources[$guard], "{$guard} uses {$advisory}: a lookup or an unlocked read is never the write-time proof");
            }
        }
        // ...and only a write guard calls it: never an access class, picker or route.
        foreach ($sources as $path => $code) {
            if (! str_starts_with($path, 'app/Domain/Students/') && str_contains($code, 'StudentOperationalEligibility')) {
                $this->assertContains($path, self::WRITE_GUARDS, "{$path} is not a declared Campus write guard");
            }
        }

        // Each proof runs inside its service method's own write transaction,
        // never before it (a proof outside the transaction proves nothing).
        foreach ([
            'app/Domain/Hostel/Application/HostelResidencyService.php' => '/\$guard\?->beforeAssign\(/',
            'app/Domain/Canteen/Application/CanteenOrderService.php' => '/\$guard\?->(atPlacement|beforeChange)\(/',
            'app/Domain/Library/Application/LibraryLoanService.php' => '/\$guard\?->(beforeCheckout|beforeCheckIn)\(/',
            'app/Domain/Transport/Application/TransportStudentAssignmentService.php' => '/\$guard\?->(beforeAssign|beforeEnd)\(/',
        ] as $service => $call) {
            $code = $sources[$service];
            preg_match_all($call, $code, $matches, PREG_OFFSET_CAPTURE);
            $this->assertNotEmpty($matches[0], "{$service} calls its write guard");
            foreach ($matches[0] as [, $offset]) {
                $before = substr($code, 0, $offset);
                $this->assertGreaterThan((int) strrpos($before, 'public function '), (int) strrpos($before, 'DB::transaction('), "{$service}: the guard call at offset {$offset} runs inside its method's DB::transaction");
            }
            // ORG.7D-4 §40.5: an exclusive Student lock in a guarded write is FOR NO
            // KEY UPDATE, never FOR UPDATE (which deadlocks against a transfer's
            // enrollment insert while the guard waits on the Enrollment).
            $this->assertDoesNotMatchRegularExpression('/Student::query\(\)[^;]*->lockForUpdate\(\)/s', $code, "{$service} locks the Student FOR UPDATE");
        }

        // The advisory resolver keeps no write-time answer; the assertion is one
        // boolean method (never a model for downstream use).
        $public = fn (string $class) => array_values(array_diff(array_map(fn (ReflectionMethod $m) => $m->getName(), (new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC)), ['__construct']));
        $this->assertSame(['for', 'forMany', 'restrictToOperationalCampus'], $public(StudentOperationalCampusResolver::class));
        $this->assertSame(['lockOperationalOn'], $public(StudentOperationalEligibility::class));
        $return = (new ReflectionMethod(StudentOperationalEligibility::class, 'lockOperationalOn'))->getReturnType();
        $this->assertInstanceOf(ReflectionNamedType::class, $return);
        $this->assertSame('bool', $return->getName());
    }
}
