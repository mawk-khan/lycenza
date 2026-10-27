<?php

namespace Tests\Feature\Admissions;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\Admissions\Application\AdmissionApplicationService;
use App\Domain\Admissions\Application\AdmissionConversionService;
use App\Domain\Admissions\Application\ApplicantService;
use App\Domain\Admissions\Application\Exceptions\AdmissionCorrectionNotPermittedException;
use App\Domain\Admissions\Application\Exceptions\AdmissionRecordChangedException;
use App\Domain\Admissions\Application\Exceptions\CrossSchoolAdmissionReferenceException;
use App\Domain\Admissions\Application\Exceptions\InvalidAdmissionApplicationTransitionException;
use App\Domain\Admissions\Application\Exceptions\OpenAdmissionApplicationExistsException;
use App\Domain\Admissions\Infrastructure\AdmissionApplication;
use App\Domain\Admissions\Infrastructure\Applicant;
use App\Models\Campus;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * SIS 3.1 (MC-1): state-aware Applicant and Admission Application correction
 * and discarding an unused draft (docs/modules/ADMISSIONS.md §6).
 */
class AdmissionCorrectionServiceTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private Campus $campus;

    private AcademicYear $year;

    private GradeLevel $grade;

    private Applicant $applicant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->campus = $this->createCampus($this->school, ['name' => 'North']);
        $this->year = $this->createAcademicYear($this->school, ['code' => 'AY-COR', 'name' => '2026-27']);
        $this->grade = $this->createGradeLevel($this->school, ['name' => 'Grade 5', 'code' => 'G5', 'sequence' => 5]);
        $this->applicant = $this->createApplicant($this->school, ['first_name' => 'Asha', 'middle_name' => null, 'last_name' => 'Vrma', 'date_of_birth' => '2016-04-12']);
    }

    private function application(string $status, array $attributes = []): AdmissionApplication
    {
        return $this->createAdmissionApplication($this->applicant, $this->year, $this->campus, $this->grade, ['status' => $status, ...$attributes]);
    }

    private function fresh(AdmissionApplication|Applicant $model): AdmissionApplication|Applicant
    {
        return app(TenantContext::class)->withSchool($this->school, fn () => $model->fresh());
    }

    /** @return array<int, array<string, mixed>> */
    private function audits(string $eventType): array
    {
        return app(TenantContext::class)->withSchool($this->school, fn () => SchoolAuditEvent::query()->where('event_type', $eventType)->get()->map->toArray()->all());
    }

    private function version(AdmissionApplication|Applicant $model): string
    {
        return $this->fresh($model)->updated_at->toIso8601String();
    }

    private function applicants(): ApplicantService
    {
        return app(ApplicantService::class);
    }

    private function applications(): AdmissionApplicationService
    {
        return app(AdmissionApplicationService::class);
    }

    // --- Applicant identity ----------------------------------------------------

    #[Test]
    public function an_applicants_identity_is_corrected_and_the_audit_names_fields_never_values(): void
    {
        $this->application('submitted');

        $this->applicants()->correct($this->applicant, ['first_name' => 'Asha', 'middle_name' => 'K', 'last_name' => 'Verma', 'date_of_birth' => '2016-04-21'], $this->version($this->applicant));

        $fresh = $this->fresh($this->applicant);
        $this->assertSame(['K', 'Verma', '2016-04-21'], [$fresh->middle_name, $fresh->last_name, $fresh->date_of_birth->toDateString()]);
        $audits = $this->audits('admission_applicant.corrected');
        $this->assertCount(1, $audits);
        $this->assertSame(['middle_name', 'last_name', 'date_of_birth'], $audits[0]['metadata']['fields']);
        $this->assertStringNotContainsString('Verma', json_encode($audits[0]['metadata']));
        $this->assertStringNotContainsString('2016-04-21', json_encode($audits[0]['metadata']));
    }

    #[Test]
    public function an_unchanged_identity_writes_nothing_and_audits_nothing(): void
    {
        $this->applicants()->correct($this->applicant, ['first_name' => 'Asha', 'middle_name' => '', 'last_name' => 'Vrma', 'date_of_birth' => '2016-04-12'], $this->version($this->applicant));

        $this->assertSame([], $this->audits('admission_applicant.corrected'));
    }

    #[Test]
    public function an_admitted_applicants_identity_is_refused_and_the_student_is_untouched(): void
    {
        $section = $this->createSection($this->year, $this->campus, $this->grade);
        $application = $this->application('accepted');
        $result = app(AdmissionConversionService::class)->convert($application, $section, '01', '2026-06-01');

        try {
            $this->applicants()->correct($this->applicant, ['first_name' => 'Aasha', 'last_name' => 'Verma', 'date_of_birth' => '2016-04-12'], $this->version($this->applicant));
            $this->fail('An admitted applicant must not be corrected.');
        } catch (AdmissionCorrectionNotPermittedException $e) {
            $this->assertSame('ADMISSION_CORRECTION_NOT_PERMITTED', $e->errorCode());
        }

        $this->assertSame('Asha', $this->fresh($this->applicant)->first_name);
        $this->assertSame('Vrma', app(TenantContext::class)->withSchool($this->school, fn () => $result->student->fresh())->last_name);
    }

    #[Test]
    public function a_stale_applicant_version_is_refused_not_overwritten(): void
    {
        $stale = $this->version($this->applicant);
        $this->travel(2)->seconds();
        $this->applicants()->correct($this->applicant, ['first_name' => 'Asha', 'last_name' => 'Verma', 'date_of_birth' => '2016-04-12'], $stale);

        $this->expectException(AdmissionRecordChangedException::class);
        $this->applicants()->correct($this->applicant, ['first_name' => 'Asha', 'last_name' => 'Varma', 'date_of_birth' => '2016-04-12'], $stale);
    }

    // --- Application academic context -------------------------------------------

    #[Test]
    public function a_draft_or_submitted_applications_context_is_corrected_without_changing_status(): void
    {
        $south = $this->createCampus($this->school, ['name' => 'South']);
        $grade6 = $this->createGradeLevel($this->school, ['name' => 'Grade 6', 'code' => 'G6', 'sequence' => 6]);

        foreach (['draft', 'submitted'] as $status) {
            $application = $this->createAdmissionApplication($this->createApplicant($this->school), $this->year, $this->campus, $this->grade, ['status' => $status]);

            $this->applications()->correctAcademicContext($application, $this->year, $south, $grade6, $status, $this->version($application));

            $fresh = $this->fresh($application);
            $this->assertSame([$status, $south->id, $grade6->id], [$fresh->status, $fresh->campus_id, $fresh->grade_level_id]);
        }

        $audit = $this->audits('admission_application.corrected')[1];
        $this->assertSame(['campus_id', 'grade_level_id'], $audit['metadata']['fields']);
        $this->assertSame($this->campus->id, $audit['metadata']['previous']['campus_id']);
        $this->assertSame($grade6->id, $audit['metadata']['corrected']['grade_level_id']);
    }

    #[Test]
    public function the_context_of_a_decided_or_converted_application_is_refused(): void
    {
        $grade6 = $this->createGradeLevel($this->school, ['name' => 'Grade 6', 'code' => 'G6', 'sequence' => 6]);

        foreach (['accepted', 'rejected', 'withdrawn'] as $status) {
            $application = $this->createAdmissionApplication($this->createApplicant($this->school), $this->year, $this->campus, $this->grade, ['status' => $status]);
            try {
                $this->applications()->correctAcademicContext($application, $this->year, $this->campus, $grade6, $status, $this->version($application));
                $this->fail("A {$status} application's context must not be corrected.");
            } catch (AdmissionCorrectionNotPermittedException) {
                $this->assertSame($this->grade->id, $this->fresh($application)->grade_level_id);
            }
        }
    }

    #[Test]
    public function a_status_change_after_the_form_was_opened_is_refused(): void
    {
        $grade6 = $this->createGradeLevel($this->school, ['name' => 'Grade 6', 'code' => 'G6', 'sequence' => 6]);
        $application = $this->application('submitted');
        $opened = $this->version($application);
        $this->applications()->accept($application);

        $this->expectException(AdmissionRecordChangedException::class);
        $this->applications()->correctAcademicContext($application, $this->year, $this->campus, $grade6, 'submitted', $opened);
    }

    #[Test]
    public function a_correction_never_creates_a_second_open_application_for_one_context(): void
    {
        $grade6 = $this->createGradeLevel($this->school, ['name' => 'Grade 6', 'code' => 'G6', 'sequence' => 6]);
        $this->application('draft');
        $other = $this->createAdmissionApplication($this->applicant, $this->year, $this->campus, $grade6, ['status' => 'draft']);

        $this->expectException(OpenAdmissionApplicationExistsException::class);
        $this->applications()->correctAcademicContext($other, $this->year, $this->campus, $this->grade, 'draft', $this->version($other));
    }

    #[Test]
    public function an_application_never_moves_onto_an_inactive_campus(): void
    {
        $closed = $this->createCampus($this->school, ['name' => 'Closed', 'status' => 'inactive']);
        $application = $this->application('draft');

        $this->expectException(ValidationException::class);
        $this->applications()->correctAcademicContext($application, $this->year, $closed, $this->grade, 'draft', $this->version($application));
    }

    #[Test]
    public function another_schools_year_campus_or_grade_is_refused(): void
    {
        $other = $this->createSchool();
        $application = $this->application('draft');

        $this->expectException(CrossSchoolAdmissionReferenceException::class);
        $this->applications()->correctAcademicContext($application, $this->year, $this->createCampus($other), $this->grade, 'draft', $this->version($application));
    }

    // --- Decision note ---------------------------------------------------------

    #[Test]
    public function a_decided_applications_note_is_corrected_and_the_text_is_never_audited(): void
    {
        foreach (['accepted', 'rejected', 'withdrawn'] as $status) {
            $application = $this->createAdmissionApplication($this->createApplicant($this->school), $this->year, $this->campus, $this->grade, ['status' => $status, 'decision_note' => 'Sibling at schol']);

            $this->applications()->correctDecisionNote($application, '  Sibling at school ', $status, $this->version($application));

            $this->assertSame(['Sibling at school', $status], [$this->fresh($application)->decision_note, $this->fresh($application)->status]);
        }

        $audits = $this->audits('admission_application.decision_note_corrected');
        $this->assertCount(3, $audits);
        $this->assertStringNotContainsString('Sibling', json_encode(array_column($audits, 'metadata')));
    }

    #[Test]
    public function a_note_cannot_be_corrected_before_a_decision_or_after_conversion(): void
    {
        $section = $this->createSection($this->year, $this->campus, $this->grade);
        foreach (['draft', 'submitted', 'converted'] as $status) {
            $application = $this->createAdmissionApplication($this->createApplicant($this->school), $this->year, $this->campus, $this->grade, [
                'status' => $status === 'converted' ? 'accepted' : $status,
                'decision_note' => $status === 'converted' ? 'Note' : null,
            ]);
            if ($status === 'converted') {
                app(AdmissionConversionService::class)->convert($application, $section, '07', '2026-06-01');
            }

            try {
                $this->applications()->correctDecisionNote($application, 'Changed', $status, $this->version($application));
                $this->fail("A {$status} application's note must not be corrected.");
            } catch (AdmissionCorrectionNotPermittedException) {
                $this->assertNotSame('Changed', $this->fresh($application)->decision_note);
            }
        }
    }

    // --- Discard ---------------------------------------------------------------

    #[Test]
    public function an_unused_draft_is_discarded_as_withdrawn_and_frees_its_context(): void
    {
        $application = $this->application('draft');

        $this->applications()->discard($application);

        $fresh = $this->fresh($application);
        $this->assertSame('withdrawn', $fresh->status);
        $this->assertNotNull($fresh->terminal_at, 'The retention clock starts.');
        $this->assertCount(1, $this->audits('admission_application.discarded'));
        $this->assertSame([], $this->audits('admission_application.withdrawn'), 'Discarding is not a family withdrawal.');
        $this->assertNotNull($this->fresh($this->applicant), 'The applicant identity stays.');

        $again = $this->applications()->create($this->applicant, $this->year, $this->campus, $this->grade);
        $this->assertSame('draft', $again->status);
    }

    #[Test]
    public function only_a_draft_can_be_discarded(): void
    {
        foreach (['submitted', 'accepted', 'rejected', 'withdrawn'] as $status) {
            $application = $this->createAdmissionApplication($this->createApplicant($this->school), $this->year, $this->campus, $this->grade, ['status' => $status]);
            try {
                $this->applications()->discard($application);
                $this->fail("A {$status} application must not be discarded.");
            } catch (InvalidAdmissionApplicationTransitionException) {
                $this->assertSame($status, $this->fresh($application)->status);
            }
        }
    }
}
