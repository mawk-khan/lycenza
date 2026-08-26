<?php

namespace Tests\Feature\Admissions;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Admissions\Infrastructure\AdmissionApplication;
use App\Domain\Admissions\Infrastructure\Applicant;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\Campus;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * REQUIRED real-concurrency proof (this checkpoint's item 71,
 * mirroring `AcademicYearActivationConcurrencyTest`'s established
 * pattern, Phase 0D section 80): two GENUINELY separate OS processes --
 * not two sequential calls in one PHP process -- both attempt to
 * convert the SAME `accepted` AdmissionApplication at the same time
 * against real PostgreSQL. `AdmissionConversionService::convert()`'s
 * `lockForUpdate()` on the AdmissionApplication row is what makes this
 * safe: exactly one process's transaction proceeds to create the
 * Student/Enrollment/provenance and commit; the other blocks on the
 * row lock until the first commits, then observes `status =
 * 'converted'` and throws `AdmissionApplicationAlreadyConvertedException`
 * WITHOUT ever creating a Student.
 *
 * Deliberately does NOT use DatabaseTransactions for the fixtures this
 * test creates (see $connectionsToTransact) -- the two subprocesses
 * are separate PostgreSQL sessions and can never see this test
 * process's uncommitted rows, mirroring
 * AcademicYearActivationConcurrencyTest's identical reasoning.
 */
class AdmissionConversionConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            $this->school->delete(); // cascades applicants/admission_applications/students/...
        }

        parent::tearDown();
    }

    #[Test]
    public function two_real_concurrent_processes_converting_the_same_application_leave_exactly_one_student_and_enrollment(): void
    {
        $this->school = $this->createSchool();
        $context = app(TenantContext::class);

        $applicant = $context->withSchool($this->school, fn () => Applicant::factory()->for($this->school, 'school')->create());
        $year = $context->withSchool($this->school, fn () => AcademicYear::factory()->for($this->school, 'school')->create(['code' => 'AY-CONC']));
        $campus = $context->withSchool($this->school, fn () => Campus::factory()->for($this->school, 'school')->create());
        $gradeLevel = $context->withSchool($this->school, fn () => GradeLevel::factory()->for($this->school, 'school')->create());
        $section = $context->withSchool($this->school, fn () => Section::factory()->create([
            'school_id' => $this->school->id,
            'academic_year_id' => $year->id,
            'campus_id' => $campus->id,
            'grade_level_id' => $gradeLevel->id,
        ]));
        $application = $context->withSchool($this->school, fn () => AdmissionApplication::factory()->create([
            'school_id' => $this->school->id,
            'applicant_id' => $applicant->id,
            'academic_year_id' => $year->id,
            'campus_id' => $campus->id,
            'grade_level_id' => $gradeLevel->id,
            'status' => 'accepted',
        ]));

        $script = __DIR__.'/../../Support/convert-admission-application.php';
        $processA = new Process(['php', $script, $this->school->id, $application->id, $section->id, 'S-CONC-A', '01', '2026-06-01']);
        $processB = new Process(['php', $script, $this->school->id, $application->id, $section->id, 'S-CONC-B', '02', '2026-06-01']);
        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        $outputs = [$processA->getOutput(), $processB->getOutput()];
        $convertedCount = count(array_filter($outputs, fn ($o) => $o === 'converted'));
        $alreadyConvertedCount = count(array_filter($outputs, fn ($o) => str_contains($o, 'AdmissionApplicationAlreadyConvertedException')));

        $this->assertSame(1, $convertedCount, 'Exactly one of the two concurrent conversions must succeed. Outputs: '.implode(' | ', $outputs));
        $this->assertSame(1, $alreadyConvertedCount, 'The other must observe already-converted state after locking, not silently succeed or fail some other way.');

        $studentCount = $context->withSchool($this->school, fn () => Student::query()->where('school_id', $this->school->id)->count());
        $enrollmentCount = $context->withSchool($this->school, fn () => StudentEnrollment::query()->where('school_id', $this->school->id)->count());
        $this->assertSame(1, $studentCount, 'Exactly one Student must exist after both processes finish.');
        $this->assertSame(1, $enrollmentCount, 'Exactly one StudentEnrollment must exist after both processes finish.');

        $freshApplication = $context->withSchool($this->school, fn () => AdmissionApplication::query()->findOrFail($application->id));
        $this->assertSame('converted', $freshApplication->status);
        $this->assertNotNull($freshApplication->converted_student_id);
    }
}
