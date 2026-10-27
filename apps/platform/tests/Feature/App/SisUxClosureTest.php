<?php

namespace Tests\Feature\App;

use App\Domain\AcademicStructure\Application\Exceptions\InvalidAcademicYearTransitionException;
use App\Domain\Admissions\Application\ApplicantReadService;
use App\Domain\Attendance\Application\Exceptions\AcademicYearNotActiveException;
use App\Domain\Attendance\Application\Exceptions\AttendanceCorrectionNoOpException;
use App\Domain\Guardians\Application\StudentGuardianRelationshipService;
use App\Domain\Guardians\Infrastructure\RelationshipType;
use App\Domain\Students\Application\Exceptions\InvalidEnrollmentTransitionException;
use App\Domain\Students\Application\Exceptions\InvalidSubjectEnrollmentTransitionException;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Finder\Finder;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * SIS 9.1: the SIS UX / accessibility closure, pinned.
 *
 * - SIS pages ask through the accessible ConfirmDialog, never
 *   `window.confirm`, and are inside the accessibility lint.
 * - Date-only values and times of day go through the shared formatters
 *   (no raw ISO date or "08:30:00").
 * - Refusals read in the operator's words, never status keys.
 * - Applicant search is the shared full-name rule; Student history names
 *   the Guardian; SIS mutations say they succeeded.
 */
class SisUxClosureTest extends TestCase
{
    use CreatesTenancyFixtures;

    private const array SIS_PAGES = ['Students', 'Guardians', 'Admissions', 'Enrollments', 'EnrollmentRollovers', 'Attendance', 'DataHealth'];

    /** @return array<string, string> relative path => source */
    private function sisPages(): array
    {
        $out = [];
        foreach (Finder::create()->files()->in(resource_path('js/Pages/App'))->name('*.vue')
            ->path('/^('.implode('|', [...self::SIS_PAGES, 'SchoolSetup', 'Timetable']).')\//') as $file) {
            $out[$file->getRelativePathname()] = $file->getContents();
        }

        return $out;
    }

    #[Test]
    public function sis_pages_confirm_accessibly_and_are_linted_for_accessibility(): void
    {
        $pages = $this->sisPages();
        $this->assertGreaterThan(40, count($pages));
        foreach ($pages as $path => $source) {
            $code = (string) preg_replace('#^\s*//.*$#m', '', $source);
            $this->assertStringNotContainsString('window.confirm(', $code, "{$path}: use ConfirmDialog (useConfirmation), not window.confirm");
        }

        $eslint = (string) file_get_contents(base_path('eslint.config.js'));
        foreach (self::SIS_PAGES as $dir) {
            $this->assertStringContainsString("'resources/js/Pages/App/{$dir}/**/*.vue'", $eslint, "{$dir} pages are in the accessibility lint");
        }
    }

    #[Test]
    public function sis_pages_format_dates_and_times_for_people(): void
    {
        foreach ($this->sisPages() as $path => $source) {
            $this->assertDoesNotMatchRegularExpression(
                '/\{\{ *[a-zA-Z_][a-zA-Z_.]*\.(startsOn|endsOn|dateOfBirth|attendanceDate|effectiveOn) *\}\}/',
                $source,
                "{$path}: a date-only value goes through formatCalendarDate",
            );
            $this->assertDoesNotMatchRegularExpression(
                '/\{\{ *[a-zA-Z_][a-zA-Z_.]*\.(periodStartTime|periodEndTime|startTime|endTime) *\}\}/',
                $source,
                "{$path}: a time of day goes through formatClockTime / formatTimeRange",
            );
        }
        $history = $this->sisPages()['Attendance/StudentHistory.vue'];
        $this->assertStringNotContainsString('Attendance exceptions</a>', $history, 'the history page is reached from registers and the Student page too');
    }

    #[Test]
    public function sis_refusals_read_in_the_operators_words(): void
    {
        foreach ([
            new InvalidAcademicYearTransitionException('closed', 'active'),
            new InvalidEnrollmentTransitionException('completed', 'withdrawn'),
            new InvalidSubjectEnrollmentTransitionException('withdrawn', 'completed'),
            new AttendanceCorrectionNoOpException('present'),
            new AcademicYearNotActiveException('draft'),
        ] as $e) {
            $this->assertDoesNotMatchRegularExpression("/'[a-z_]+'/", $e->getMessage(), $e::class.' quotes a status key');
            $this->assertDoesNotMatchRegularExpression('/AcademicYear\b|Exception|_/', $e->getMessage(), $e::class.' uses an internal term');
        }
        $this->assertSame("This academic year is closed, so it can't be activated.", (new InvalidAcademicYearTransitionException('closed', 'active'))->getMessage());
    }

    #[Test]
    public function applicant_search_matches_a_full_name_in_any_order(): void
    {
        $school = $this->createSchool();
        $asha = $this->createApplicant($school, ['first_name' => 'Asha', 'middle_name' => 'Devi', 'last_name' => 'Verma']);
        $this->createApplicant($school, ['first_name' => 'Asha', 'middle_name' => null, 'last_name' => 'Rao']);
        $this->createApplicant($this->createSchool(), ['first_name' => 'Asha', 'last_name' => 'Verma']);

        $found = fn (string $name) => app(TenantContext::class)->withSchool($school, fn () => collect(app(ApplicantReadService::class)->search($name, 20)->items())->map(fn ($a) => (string) $a->id)->all());
        foreach (['Asha Verma', 'verma asha', '  ASHA  devi ', 'Devi'] as $term) {
            $this->assertSame([(string) $asha->id], $found($term), $term);
        }
        $this->assertSame([], $found('Asha Gupta'));
        $this->assertSame([], $found('%'), 'wildcards stay literal');

        $this->actingAs($this->createUserWithCapabilities($school, ['admissions.view']))->post("/app/schools/{$school->id}/activate");
        $application = $this->createAdmissionApplication($asha, $this->createAcademicYear($school), $this->createCampus($school), $this->createGradeLevel($school));
        $this->get('/app/admissions?applicant_name='.urlencode('verma asha'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('applications.data.0.id', $application->id)->has('applications.data', 1));
    }

    #[Test]
    public function student_history_names_the_guardian_and_mutations_say_they_succeeded(): void
    {
        $school = $this->createSchool();
        $admin = $this->createUserWithCapabilities($school, ['students.view', 'students.manage', 'guardians.view', 'guardians.manage']);
        $student = $this->createStudent($school);
        $guardian = $this->createGuardian($school, ['first_name' => 'Meera', 'middle_name' => null, 'last_name' => 'Nair']);
        app(TenantContext::class)->withSchool($school, fn () => app(StudentGuardianRelationshipService::class)->link($student, $guardian, RelationshipType::cases()[0], [], $admin));
        $this->actingAs($admin)->post("/app/schools/{$school->id}/activate");

        $this->get("/app/students/{$student->id}/history")->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('entries.0.guardianName', 'Meera Nair')->where('entries.0.guardianId', $guardian->id));

        $this->post("/app/guardians/{$guardian->id}/status", ['status' => 'inactive'])->assertSessionHas('flash', 'Guardian marked inactive.');
        $this->post("/app/guardians/{$guardian->id}/contacts", ['type' => 'email', 'value' => 'meera@example.com'])->assertSessionHas('flash', 'Contact added.');
        $this->assertInstanceOf(School::class, $school);
    }
}
