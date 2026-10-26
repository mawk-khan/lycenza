<?php

namespace Tests\Feature\Admissions;

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Admissions\Application\Exceptions\AdmissionCorrectionNotPermittedException;
use App\Domain\Admissions\Infrastructure\AdmissionApplication;
use App\Domain\Admissions\Infrastructure\Applicant;
use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CommitsRetentionFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * SIS 3.1: an Applicant correction and a conversion of one of the Applicant's
 * applications, in two real processes with a forced, observed overlap, never
 * leave the Student and the Applicant disagreeing. Whichever commits first
 * decides: correction first -> the Student is created from the corrected
 * identity; conversion first -> the correction is refused.
 */
class AdmissionCorrectionConcurrencyTest extends TestCase
{
    use CommitsRetentionFixtures, CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @return array{school: School, applicant: Applicant, application: AdmissionApplication, section: Section} */
    private function world(): array
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school, ['code' => 'AY-RACE']);
        $grade = $this->createGradeLevel($school);
        $section = $this->createSection($year, $campus, $grade);
        $applicant = $this->createApplicant($school, ['first_name' => 'Asha', 'last_name' => 'Verma']);
        $application = $this->createAdmissionApplication($applicant, $year, $campus, $grade, ['status' => 'accepted']);

        return compact('school', 'applicant', 'application', 'section');
    }

    /** @return array<int, string> */
    private function op(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/admission-correction-op.php', ...$args];
    }

    /** @return array{0: string, 1: string} [Applicant first name, Student first name] */
    private function names(array $w): array
    {
        return app(TenantContext::class)->withSchool($w['school'], fn () => [
            Applicant::query()->findOrFail($w['applicant']->id)->first_name,
            Student::query()->where('school_id', $w['school']->id)->sole()->first_name,
        ]);
    }

    #[Test]
    public function a_conversion_that_commits_first_refuses_the_waiting_correction(): void
    {
        $w = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('convert', $w['school']->id, $w['application']->id, $w['section']->id, 'S-RACE-1'),
            $this->op('correct', $w['school']->id, $w['applicant']->id, 'Aasha'),
        );

        $this->assertSame('converted', $holder);
        $this->assertSame('rejected:'.AdmissionCorrectionNotPermittedException::class, $contender);
        $this->assertSame(['Asha', 'Asha'], $this->names($w));
    }

    #[Test]
    public function a_correction_that_commits_first_is_what_the_waiting_conversion_admits(): void
    {
        $w = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('correct', $w['school']->id, $w['applicant']->id, 'Aasha'),
            $this->op('convert', $w['school']->id, $w['application']->id, $w['section']->id, 'S-RACE-2'),
        );

        $this->assertSame('corrected', $holder);
        $this->assertSame('converted', $contender);
        $this->assertSame(['Aasha', 'Aasha'], $this->names($w));
    }
}
