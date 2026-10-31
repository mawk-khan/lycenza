<?php

namespace Tests\Feature\StudentReports;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\Admissions\Infrastructure\AdmissionApplication;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use App\Support\Testing\LocalCatalogueFixtures;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * SIS 11.2: the Admissions application list as a governed report
 * (`admissions.applications`). Every lifecycle status from the canonical
 * table; context from the APPLICATION (never the converted Student's
 * current class); Campus-aware through AdmissionApplicationAccess; School-local
 * created dates; a closed sort; a converted Student link only for a
 * whole-School `students.view` holder; no fee, date of birth or contact.
 */
class AdmissionApplicationsReportTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private User $admin;

    private Campus $north;

    private Campus $south;

    private AcademicYear $year;

    private GradeLevel $g1;

    private GradeLevel $g2;

    /** @var array<string, AdmissionApplication> */
    private array $apps = [];

    protected function setUp(): void
    {
        parent::setUp();
        [$this->admin, $this->school] = $this->createSchoolAdmin();
        $this->north = $this->createCampus($this->school, ['name' => 'North', 'code' => 'N']);
        $this->south = $this->createCampus($this->school, ['name' => 'South', 'code' => 'S']);
        $this->year = $this->createAcademicYear($this->school, ['name' => '2026-27', 'code' => 'AY26', 'status' => 'active', 'starts_on' => '2026-06-01', 'ends_on' => '2027-04-30']);
        $this->g1 = $this->createGradeLevel($this->school, ['name' => 'Grade 1', 'code' => 'G1', 'sequence' => 1]);
        $this->g2 = $this->createGradeLevel($this->school, ['name' => 'Grade 2', 'code' => 'G2', 'sequence' => 2]);

        $day = 0;
        foreach (['draft', 'submitted', 'accepted', 'rejected', 'withdrawn'] as $status) {
            $this->apps[$status] = $this->application(ucfirst($status), 'Kumar', $status, $status === 'withdrawn' ? $this->south : $this->north, '2026-09-0'.(++$day).' 06:00:00');
        }
        // A converted application keeps ITS context (North, Grade 1) though the
        // Student was placed in South / Grade 2.
        $student = $this->createStudent($this->school, ['first_name' => 'Converted', 'last_name' => 'Kumar', 'student_number' => 'S-77']);
        $enrollment = $this->createStudentEnrollment($student, $this->createSection($this->year, $this->south, $this->g2, ['name' => 'B', 'code' => 'B']));
        $this->apps['converted'] = $this->application('Converted', 'Kumar', 'converted', $this->north, '2026-09-06 06:00:00', [
            'converted_student_id' => $student->id, 'converted_student_enrollment_id' => $enrollment->id, 'converted_at' => now(),
        ]);
    }

    /** @param  array<string, mixed>  $extra */
    private function application(string $first, string $last, string $status, Campus $campus, string $createdAtUtc, array $extra = [], ?AcademicYear $year = null, ?GradeLevel $grade = null): AdmissionApplication
    {
        $applicant = $this->createApplicant($campus->school, ['first_name' => $first, 'last_name' => $last]);

        return $this->createAdmissionApplication($applicant, $year ?? $this->year, $campus, $grade ?? $this->g1, ['status' => $status, 'created_at' => $createdAtUtc, 'updated_at' => $createdAtUtc, ...$extra]);
    }

    /** @param  list<string>  $capabilities */
    private function holder(array $capabilities, ?string $campusId = null): User
    {
        $user = $this->createUser();
        LocalCatalogueFixtures::grantRole($this->createMembership($user, $this->school), $this->createFixtureRole($capabilities), $campusId);

        return $user;
    }

    /** @return array<string, mixed> */
    private function props(array $query = [], ?User $as = null, ?School $school = null): array
    {
        app('auth')->forgetGuards();
        $this->actingAs($as ?? $this->admin)->post('/app/schools/'.($school ?? $this->school)->id.'/activate');

        return $this->get('/app/admissions?'.http_build_query($query))->assertOk()->viewData('page')['props'];
    }

    /** @return list<string> "status|first name" per row */
    private function rows(array $query = [], ?User $as = null): array
    {
        return array_map(fn (array $r) => "{$r['status']}|{$r['applicant']['firstName']}", $this->props($query, $as)['applications']['data']);
    }

    #[Test]
    public function every_lifecycle_status_is_listed_with_the_application_s_own_context(): void
    {
        $this->assertSame(['converted|Converted', 'withdrawn|Withdrawn', 'rejected|Rejected', 'accepted|Accepted', 'submitted|Submitted', 'draft|Draft'], $this->rows());

        $converted = collect($this->props(['status' => 'converted'])['applications']['data'])->sole();
        $this->assertSame(['North', 'Grade 1', '2026-27'], [$converted['campus']['name'], $converted['gradeLevel']['name'], $converted['academicYear']['name']], 'never the Student\'s current South / Grade 2 placement');
        $this->assertSame('/app/students/'.$this->apps['converted']->converted_student_id, $converted['convertedStudentUrl']);

        // Minimal rows: no date of birth, contact, fee or Student name/number.
        $this->assertSame(['id', 'status', 'applicant', 'academicYear', 'campus', 'gradeLevel', 'createdAt', 'convertedAt', 'convertedStudentUrl'], array_keys($converted));
        $this->assertSame(['id', 'firstName', 'middleName', 'lastName'], array_keys($converted['applicant']));
        $this->assertStringNotContainsString('S-77', json_encode($converted));
    }

    #[Test]
    public function the_student_link_needs_whole_school_students_view(): void
    {
        $admissionsOnly = $this->holder(['admissions.view']);
        $row = collect($this->props(['status' => 'converted'], $admissionsOnly)['applications']['data'])->sole();
        $this->assertNull($row['convertedStudentUrl']);
        $this->get('/app/students/'.$this->apps['converted']->converted_student_id)->assertForbidden();

        // A Campus-only Student reader gets no Profile link either (the Profile is whole-School).
        $campusStudents = $this->createUser();
        $membership = $this->createMembership($campusStudents, $this->school);
        LocalCatalogueFixtures::grantRole($membership, $this->createFixtureRole(['admissions.view']));
        LocalCatalogueFixtures::grantRole($membership, $this->createFixtureRole(['students.view']), $this->north->id);
        $this->assertNull(collect($this->props(['status' => 'converted'], $campusStudents)['applications']['data'])->sole()['convertedStudentUrl']);
    }

    #[Test]
    public function filters_narrow_and_dates_are_school_local_calendar_days(): void
    {
        $this->assertSame(['withdrawn|Withdrawn'], $this->rows(['campus_id' => $this->south->id]));
        $this->assertSame(['accepted|Accepted'], $this->rows(['status' => 'accepted']));
        $this->assertCount(6, $this->rows(['academic_year_id' => $this->year->id, 'grade_level_id' => $this->g1->id]));
        $this->assertSame([], $this->rows(['grade_level_id' => $this->g2->id]), 'the converted Student\'s Grade 2 never matches its application');
        $this->assertSame(['submitted|Submitted'], $this->rows(['applicant_name' => 'kumar  SUBMITTED']), 'every word, any order, case-insensitive');
        $this->assertSame([], $this->rows(['applicant_name' => '%']), 'a wildcard is literal');

        // 20:00 UTC on 3 Sep is a different School-local day in a zone east of UTC.
        $zone = SchoolTimezone::resolve($this->school);
        $this->application('Late', 'Evening', 'submitted', $this->north, '2026-09-03 20:00:00');
        $localDay = CarbonImmutable::parse('2026-09-03 20:00:00', 'UTC')->setTimezone($zone)->toDateString();
        $this->assertContains('submitted|Late', $this->rows(['created_from' => $localDay, 'created_to' => $localDay]));
        $this->assertSame(['submitted|Submitted', 'draft|Draft'], $this->rows(['created_from' => '2026-09-01', 'created_to' => '2026-09-02']));
    }

    #[Test]
    public function sorting_is_a_closed_list_and_malformed_input_is_a_validation_error(): void
    {
        $this->assertSame(['draft|Draft', 'submitted|Submitted', 'accepted|Accepted', 'rejected|Rejected', 'withdrawn|Withdrawn', 'converted|Converted'], $this->rows(['sort' => 'created_asc']));
        $this->assertSame(['accepted|Accepted', 'converted|Converted', 'draft|Draft', 'rejected|Rejected', 'submitted|Submitted', 'withdrawn|Withdrawn'], $this->rows(['sort' => 'status']));
        $this->assertSame('Accepted', $this->props(['sort' => 'applicant'])['applications']['data'][0]['applicant']['firstName']);

        $this->props();
        foreach ([['sort' => 'applicants.date_of_birth'], ['created_from' => '2026-13-01'], ['created_from' => '2026-09-05', 'created_to' => '2026-09-01'], ['campus_id' => 'x'], ['page' => 0]] as $query) {
            $this->get('/app/admissions?'.http_build_query($query))->assertStatus(302)->assertSessionHasErrors();
        }
    }

    #[Test]
    public function a_campus_scoped_officer_sees_only_their_campus_and_cannot_forge_another(): void
    {
        $officer = $this->holder(['admissions.view'], $this->north->id);
        $props = $this->props([], $officer);
        $this->assertSame(5, $props['applications']['total']);
        $this->assertSame(['North'], array_column($props['campuses'], 'name'));
        $this->assertSame([], $this->rows(['campus_id' => $this->south->id], $officer), 'a forged Campus never broadens');
        $this->assertSame([], $this->rows(['applicant_name' => 'Withdrawn'], $officer), 'search never reaches another Campus');

        $hub = collect($this->get('/app/students/reports')->assertOk()->viewData('page')['props']['categories'])->flatMap(fn ($c) => array_column($c['reports'], 'key'))->all();
        $this->assertSame(['admissions.applications'], $hub);
    }

    #[Test]
    public function an_application_on_a_campus_that_became_inactive_stays_listed_and_labelled(): void
    {
        app(TenantContext::class)->withSchool($this->school, fn () => Campus::query()->whereKey($this->south->id)->update(['status' => 'inactive']));

        $row = collect($this->props(['status' => 'withdrawn'])['applications']['data'])->sole();
        $this->assertSame('South (inactive)', $row['campus']['name']);
        $this->assertContains('South (inactive)', array_column($this->props()['campuses'], 'name'));
    }

    #[Test]
    public function another_school_never_counts_or_matches(): void
    {
        [$otherAdmin, $other] = $this->createSchoolAdmin();
        $this->application('Submitted', 'Kumar', 'submitted', $this->createCampus($other, ['name' => 'North', 'code' => 'N']), '2026-09-02 06:00:00',
            year: $this->createAcademicYear($other, ['name' => '2026-27', 'code' => 'AY26', 'status' => 'active']),
            grade: $this->createGradeLevel($other, ['name' => 'Grade 1', 'code' => 'G1', 'sequence' => 1]));

        $this->assertSame(6, $this->props()['applications']['total']);
        $this->assertSame(['submitted|Submitted'], $this->rows(['applicant_name' => 'Submitted Kumar']));
        $this->assertSame(1, $this->props([], $otherAdmin, $other)['applications']['total']);
    }

    #[Test]
    public function readers_without_admissions_view_are_refused_and_never_see_the_report(): void
    {
        $studentsOnly = $this->holder(['students.view']);
        app('auth')->forgetGuards();
        $this->actingAs($studentsOnly)->post("/app/schools/{$this->school->id}/activate");
        $this->get('/app/admissions')->assertForbidden();
        $hub = collect($this->get('/app/students/reports')->assertOk()->viewData('page')['props']['categories'])->flatMap(fn ($c) => array_column($c['reports'], 'key'))->all();
        $this->assertNotContains('admissions.applications', $hub);

        $teacher = $this->createUser();
        $this->assignSchoolRole($this->createMembership($teacher, $this->school), 'teacher');
        app('auth')->forgetGuards();
        $this->actingAs($teacher)->post("/app/schools/{$this->school->id}/activate");
        $this->get('/app/admissions')->assertForbidden();
    }

    #[Test]
    public function the_query_count_does_not_grow_with_the_applications(): void
    {
        $count = function (): int {
            $this->props();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get('/app/admissions')->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        $count();
        $small = $count();
        for ($i = 0; $i < 15; $i++) {
            $this->application('Extra'.$i, 'Batch', 'submitted', $i % 2 ? $this->north : $this->south, '2026-10-01 06:00:00');
        }
        $this->assertSame($small, $count(), 'eager-loaded context, one count and one page query');
    }
}
