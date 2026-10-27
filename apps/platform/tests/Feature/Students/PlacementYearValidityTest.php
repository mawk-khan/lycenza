<?php

namespace Tests\Feature\Students;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Admissions\Application\AdmissionConversionService;
use App\Domain\Admissions\Application\GuardianConversionInstruction;
use App\Domain\Admissions\Infrastructure\AdmissionApplication;
use App\Domain\Admissions\Infrastructure\AdmissionFeeSelection;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Guardians\Infrastructure\RelationshipType;
use App\Domain\Guardians\Infrastructure\StudentGuardianRelationship;
use App\Domain\Students\Application\CampusStudentOnboardingService;
use App\Domain\Students\Application\EnrollmentRolloverDryRunService;
use App\Domain\Students\Application\EnrollmentRolloverItemExecutionService;
use App\Domain\Students\Application\EnrollmentRolloverPlanService;
use App\Domain\Students\Application\Exceptions\CrossSchoolEnrollmentException;
use App\Domain\Students\Application\Exceptions\InvalidEnrollmentDateRangeException;
use App\Domain\Students\Application\Exceptions\PlacementTargetUnavailableException;
use App\Domain\Students\Application\Exceptions\StudentOnboardingUnavailableException;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Infrastructure\EnrollmentRolloverItem;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\Campus;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Authorization\Campus\CampusScope;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * SIS 4.1 (MC-2): every NEW placement starts on a calendar date inside its
 * Section's Academic Year (both ends inclusive) and never in a `closed`
 * year; a `draft` year stays placeable (future-year preparation, rollover).
 * One rule (StudentEnrollmentService::assertPlaceable) reached by every
 * creation path -- enroll, transfer, Admissions conversion, rollover
 * execution and Campus onboarding -- before anything is written.
 */
class PlacementYearValidityTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private Campus $campus;

    private GradeLevel $grade;

    private AcademicYear $active;

    private AcademicYear $draft;

    private AcademicYear $closed;

    private Section $section;

    private Section $draftSection;

    private Section $closedSection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->campus = $this->createCampus($this->school);
        $this->grade = $this->createGradeLevel($this->school, ['code' => 'G5', 'sequence' => 5]);
        $this->closed = $this->createAcademicYear($this->school, ['code' => 'Y0', 'name' => '2025-26', 'status' => 'closed', 'starts_on' => '2025-04-01', 'ends_on' => '2026-03-31']);
        $this->active = $this->createAcademicYear($this->school, ['code' => 'Y1', 'name' => '2026-27', 'status' => 'active', 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31']);
        $this->draft = $this->createAcademicYear($this->school, ['code' => 'Y2', 'name' => '2027-28', 'status' => 'draft', 'starts_on' => '2027-04-01', 'ends_on' => '2028-03-31']);
        $this->section = $this->createSection($this->active, $this->campus, $this->grade, ['code' => 'A', 'status' => 'active']);
        $this->draftSection = $this->createSection($this->draft, $this->campus, $this->grade, ['code' => 'D', 'status' => 'active']);
        $this->closedSection = $this->createSection($this->closed, $this->campus, $this->grade, ['code' => 'C', 'status' => 'active']);
    }

    private function inSchool(callable $fn): mixed
    {
        return app(TenantContext::class)->withSchool($this->school, $fn);
    }

    private function enroll(Student $student, Section $section, string $startsOn, string $roll = '1'): StudentEnrollment
    {
        return app(StudentEnrollmentService::class)->enroll($student, $section, $roll, $startsOn);
    }

    private function placements(): int
    {
        return $this->inSchool(fn () => StudentEnrollment::query()->count());
    }

    private function assertOutOfYear(callable $operation, string $expectedMessage): void
    {
        try {
            $operation();
            $this->fail('expected an out-of-year refusal');
        } catch (InvalidEnrollmentDateRangeException $e) {
            $this->assertSame($expectedMessage, $e->getMessage());
            $this->assertSame('INVALID_ENROLLMENT_DATE_RANGE', $e->errorCode());
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    private function assertYearClosed(callable $operation): void
    {
        try {
            $operation();
            $this->fail('expected a closed-year refusal');
        } catch (PlacementTargetUnavailableException $e) {
            $this->assertSame(PlacementTargetUnavailableException::YEAR_CLOSED, $e->reason);
            $this->assertSame('New placements cannot be added to a closed Academic Year.', $e->getMessage());
        }
    }

    private const string ACTIVE_RANGE = 'The start date must fall within Academic Year 2026-27 (2026-04-01 to 2027-03-31).';

    // --- Manual placement (enroll) -------------------------------------------

    #[Test]
    public function both_year_boundaries_are_valid_start_dates(): void
    {
        $first = $this->createStudent($this->school);
        $last = $this->createStudent($this->school);

        $this->assertSame('2026-04-01', $this->enroll($first, $this->section, '2026-04-01', '1')->starts_on->toDateString());
        $this->assertSame('2027-03-31', $this->enroll($last, $this->section, '2027-03-31', '2')->starts_on->toDateString());
    }

    #[Test]
    public function the_day_before_and_the_day_after_the_year_are_refused_with_nothing_written(): void
    {
        $student = $this->createStudent($this->school);

        $this->assertOutOfYear(fn () => $this->enroll($student, $this->section, '2026-03-31'), self::ACTIVE_RANGE);
        $this->assertOutOfYear(fn () => $this->enroll($student, $this->section, '2027-04-01'), self::ACTIVE_RANGE);
        $this->assertSame(0, $this->placements());
        $this->assertSame(0, $this->inSchool(fn () => SchoolAuditEvent::query()->where('event_type', 'student_enrollment.created')->count()));

        // The ordinary typo is then corrected and accepted.
        $this->assertSame('2026-04-02', $this->enroll($student, $this->section, '2026-04-02')->starts_on->toDateString());
    }

    #[Test]
    public function the_date_is_compared_as_a_calendar_date_in_any_input_format(): void
    {
        $student = $this->createStudent($this->school);

        $this->assertOutOfYear(fn () => $this->enroll($student, $this->section, '2026-03-31 23:59:59'), self::ACTIVE_RANGE);
        $this->assertSame('2027-03-31', $this->enroll($student, $this->section, '2027-03-31 23:59:59')->starts_on->toDateString());
    }

    #[Test]
    public function a_closed_year_section_is_refused_even_for_a_date_inside_that_year(): void
    {
        $student = $this->createStudent($this->school);

        $this->assertYearClosed(fn () => $this->enroll($student, $this->closedSection, '2025-06-01'));
        $this->assertSame(0, $this->placements());
    }

    #[Test]
    public function a_draft_future_year_stays_placeable_inside_its_own_dates(): void
    {
        $student = $this->createStudent($this->school);

        $this->assertOutOfYear(fn () => $this->enroll($student, $this->draftSection, '2027-03-31'), 'The start date must fall within Academic Year 2027-28 (2027-04-01 to 2028-03-31).');
        $this->assertSame($this->draft->id, $this->enroll($student, $this->draftSection, '2027-04-01')->academic_year_id);
    }

    #[Test]
    public function the_year_is_always_the_sections_own_never_the_active_one(): void
    {
        $student = $this->createStudent($this->school);

        // A date inside the ACTIVE year is still outside the draft Section's year.
        $this->assertOutOfYear(fn () => $this->enroll($student, $this->draftSection, '2026-06-01'), 'The start date must fall within Academic Year 2027-28 (2027-04-01 to 2028-03-31).');
    }

    #[Test]
    public function another_schools_section_is_refused_before_any_year_is_consulted(): void
    {
        $student = $this->createStudent($this->school);
        $other = $this->createSchool();
        $otherYear = $this->createAcademicYear($other, ['status' => 'closed']);
        $otherSection = $this->createSection($otherYear, $this->createCampus($other), $this->createGradeLevel($other));

        $this->expectException(CrossSchoolEnrollmentException::class);
        $this->enroll($student, $otherSection, '2026-06-01');
    }

    // --- Transfer --------------------------------------------------------------

    #[Test]
    public function a_transfer_cannot_start_its_new_placement_after_the_year(): void
    {
        $student = $this->createStudent($this->school);
        $placement = $this->enroll($student, $this->section, '2026-04-01');
        $target = $this->createSection($this->active, $this->campus, $this->grade, ['code' => 'B', 'status' => 'active']);

        // The source would end on the year's last day -- valid on its own --
        // but the new placement would begin the day after the year.
        $this->assertOutOfYear(fn () => app(StudentEnrollmentService::class)->transferPlacement($placement, $target, '2', '2027-04-01'), self::ACTIVE_RANGE);

        $this->assertSame(['active', null], $this->inSchool(fn () => [$placement->fresh()->status, $placement->fresh()->ends_on]));
        $this->assertSame(1, $this->placements());
    }

    #[Test]
    public function a_transfer_inside_a_closed_year_is_refused_and_the_source_untouched(): void
    {
        $student = $this->createStudent($this->school);
        $placement = $this->createStudentEnrollment($student, $this->closedSection, ['starts_on' => '2025-04-01', 'roll_number' => '1']);
        $target = $this->createSection($this->closed, $this->campus, $this->grade, ['code' => 'C2', 'status' => 'active']);

        $this->assertYearClosed(fn () => app(StudentEnrollmentService::class)->transferPlacement($placement, $target, '2', '2025-09-01'));
        $this->assertSame(['active', null], $this->inSchool(fn () => [$placement->fresh()->status, $placement->fresh()->ends_on]));
    }

    // --- Admissions conversion ---------------------------------------------------

    private function acceptedApplication(AcademicYear $year): AdmissionApplication
    {
        return $this->createAdmissionApplication($this->createApplicant($this->school), $year, $this->campus, $this->grade, ['status' => 'accepted']);
    }

    private function guardian(): GuardianConversionInstruction
    {
        return GuardianConversionInstruction::create('Ravi', null, 'Kumar', RelationshipType::Father, isLegalGuardian: true);
    }

    private function assertNothingConverted(AdmissionApplication $application, string $studentNumber): void
    {
        $this->inSchool(function () use ($application, $studentNumber): void {
            $this->assertSame('accepted', AdmissionApplication::query()->findOrFail($application->id)->status);
            $this->assertSame(0, Student::query()->where('student_number', $studentNumber)->count(), 'no Student');
            $this->assertSame(0, Guardian::query()->count(), 'no Guardian');
            $this->assertSame(0, StudentGuardianRelationship::query()->count(), 'no relationship');
            $this->assertSame(0, StudentEnrollment::query()->count(), 'no placement');
            $this->assertSame(0, AdmissionFeeSelection::query()->count(), 'no admission fee intent');
        });
    }

    #[Test]
    public function admissions_conversion_places_inside_the_year(): void
    {
        $application = $this->acceptedApplication($this->active);

        $result = app(AdmissionConversionService::class)->convert($application, $this->section, '1', '2026-04-01', $this->guardian());

        $this->assertSame('converted', $result->application->status);
        $this->assertSame('2026-04-01', $result->enrollment->starts_on->toDateString());
    }

    #[Test]
    public function an_out_of_year_conversion_rolls_back_entirely(): void
    {
        $application = $this->acceptedApplication($this->active);

        $this->assertOutOfYear(fn () => app(AdmissionConversionService::class)->convert($application, $this->section, '1', '2027-04-01', $this->guardian()), self::ACTIVE_RANGE);
        $this->assertNothingConverted($application, 'ADM-2');

        // Corrected in the same workflow, the conversion succeeds.
        $result = app(AdmissionConversionService::class)->convert($application, $this->section, '1', '2027-03-31', $this->guardian());
        $this->assertSame('converted', $result->application->status);
    }

    #[Test]
    public function a_closed_year_conversion_rolls_back_entirely(): void
    {
        $application = $this->acceptedApplication($this->closed);

        $this->assertYearClosed(fn () => app(AdmissionConversionService::class)->convert($application, $this->closedSection, '1', '2025-06-01', $this->guardian()));
        $this->assertNothingConverted($application, 'ADM-3');
    }

    // --- Rollover execution -------------------------------------------------------

    /** @return array{0: EnrollmentRolloverItem, 1: Section} */
    private function validatedRollover(): array
    {
        $student = $this->createStudent($this->school);
        $this->enroll($student, $this->section, '2026-04-01');
        $grade6 = $this->createGradeLevel($this->school, ['code' => 'G6', 'sequence' => 6]);
        $target = $this->createSection($this->draft, $this->campus, $grade6, ['code' => 'T', 'status' => 'active']);

        $plans = app(EnrollmentRolloverPlanService::class);
        $plan = $plans->createDraft($this->school, $this->active, $this->draft);
        $plans->upsertMapping($plan, $this->grade, null, $grade6, $target);
        app(EnrollmentRolloverDryRunService::class)->run($this->inSchool(fn () => $plan->fresh()));
        $item = $this->inSchool(fn () => EnrollmentRolloverItem::query()->where('plan_id', $plan->id)->sole());
        $plans->setItemDecision($this->inSchool(fn () => $plan->fresh()), $item, 'promote', null, 'preserve_source');
        app(EnrollmentRolloverDryRunService::class)->run($this->inSchool(fn () => $plan->fresh()));

        return [$this->inSchool(fn () => $item->fresh()), $target];
    }

    #[Test]
    public function rollover_into_a_draft_year_still_executes_on_the_years_first_day(): void
    {
        [$item, $target] = $this->validatedRollover();

        $executed = app(EnrollmentRolloverItemExecutionService::class)->execute($item);

        $this->assertSame('succeeded', $executed->execution_status);
        $placement = $this->inSchool(fn () => StudentEnrollment::query()->findOrFail($executed->target_enrollment_id));
        $this->assertSame([$target->id, '2027-04-01'], [$placement->section_id, $placement->starts_on->toDateString()]);
    }

    #[Test]
    public function rollover_into_a_target_year_closed_after_validation_is_drift_not_a_placement(): void
    {
        [$item] = $this->validatedRollover();
        $this->inSchool(fn () => AcademicYear::query()->whereKey($this->draft->id)->update(['status' => 'closed']));

        $executed = app(EnrollmentRolloverItemExecutionService::class)->execute($item);

        // SIS 5.2: the item's own target-year check sees the closed year first.
        $this->assertSame(['failed', 'blocked', 'target_year_not_draft'], [$executed->execution_status, $executed->validation_result, $executed->validation_reason]);
        $this->assertSame(0, $this->inSchool(fn () => StudentEnrollment::query()->where('academic_year_id', $this->draft->id)->count()));
    }

    // --- Campus onboarding -----------------------------------------------------------

    #[Test]
    public function campus_onboarding_after_the_active_years_end_is_the_generic_refusal(): void
    {
        $today = CarbonImmutable::parse(SchoolTimezone::today($this->school));
        $this->inSchool(function (): void {
            AcademicYear::query()->whereKey($this->active->id)->update(['status' => 'closed']);
            AcademicYear::query()->whereKey($this->draft->id)->update(['status' => 'closed']);
        });
        // An active year whose last day has passed, still not closed.
        $lapsed = $this->createAcademicYear($this->school, ['code' => 'LAPSED', 'name' => 'Lapsed', 'status' => 'active', 'starts_on' => $today->subYear()->toDateString(), 'ends_on' => $today->subDay()->toDateString()]);
        $section = $this->createSection($lapsed, $this->campus, $this->grade, ['code' => 'L', 'status' => 'active']);
        $scope = CampusScope::campuses([$this->campus->id]);

        $this->expectException(StudentOnboardingUnavailableException::class);
        try {
            app(CampusStudentOnboardingService::class)->onboard($this->school, $scope, $scope, [
                'first_name' => 'Late', 'date_of_birth' => '2016-01-01', 'section_id' => $section->id, 'roll_number' => '9',
            ]);
        } finally {
            $this->assertSame(0, $this->inSchool(fn () => Student::query()->where('first_name', 'Late')->count()), 'the onboarding rolled back');
        }
    }

    // --- Browser (session) and API surfaces ----------------------------------------

    private function admin(): User
    {
        [$user] = $this->createSchoolAdmin('school_admin');
        $this->assignSchoolRole($this->createMembership($user, $this->school), 'school_admin');
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");

        return $user;
    }

    #[Test]
    public function the_placement_form_shows_an_out_of_year_date_as_a_start_date_error_and_then_accepts_the_correction(): void
    {
        $student = $this->createStudent($this->school);
        $this->admin();
        $url = "/app/students/{$student->id}/enrollments";

        $this->from("{$url}/create")->post($url, ['section_id' => $this->section->id, 'roll_number' => '1', 'starts_on' => '2026-03-31'])
            ->assertRedirect("{$url}/create")
            ->assertSessionHasErrors(['starts_on' => self::ACTIVE_RANGE]);
        $this->assertSame(0, $this->placements());

        $this->from("{$url}/create")->post($url, ['section_id' => $this->section->id, 'roll_number' => '1', 'starts_on' => '2026-04-01'])
            ->assertRedirect("/app/students/{$student->id}")
            ->assertSessionHasNoErrors();
        $this->assertSame(1, $this->placements());
    }

    #[Test]
    public function the_placement_form_never_offers_a_closed_year_section_and_a_forged_one_is_a_visible_refusal(): void
    {
        $student = $this->createStudent($this->school);
        $this->admin();
        $url = "/app/students/{$student->id}/enrollments";

        $this->get("{$url}/create")->assertInertia(fn ($page) => $page
            ->component('App/Enrollments/Create')
            ->where('sections', fn ($sections) => collect($sections)->pluck('id')->sort()->values()->all() === collect([$this->section->id, $this->draftSection->id])->sort()->values()->all()));

        $this->from("{$url}/create")->post($url, ['section_id' => $this->closedSection->id, 'roll_number' => '1', 'starts_on' => '2025-06-01'])
            ->assertRedirect("{$url}/create")
            ->assertSessionHasErrors(['refusal' => 'New placements cannot be added to a closed Academic Year.']);
        $this->assertStringNotContainsString($this->closedSection->id, (string) json_encode(session('errors')->getMessages()));
        $this->assertSame(0, $this->placements());
    }

    #[Test]
    public function the_admissions_conversion_form_shows_an_out_of_year_date_on_the_start_date(): void
    {
        $application = $this->acceptedApplication($this->active);
        $this->admin();

        $this->from("/app/admissions/{$application->id}")->post("/app/admissions/{$application->id}/convert", [
            'section_id' => $this->section->id, 'roll_number' => '1', 'starts_on' => '2027-04-01',
        ])->assertSessionHasErrors(['starts_on' => self::ACTIVE_RANGE]);
        $this->assertNothingConverted($application, 'ADM-W');
    }

    #[Test]
    public function an_actor_without_enrollments_manage_is_still_forbidden_before_any_date_check(): void
    {
        $student = $this->createStudent($this->school);
        $user = $this->createUser();
        $this->createMembership($user, $this->school);
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");

        $this->post("/app/students/{$student->id}/enrollments", ['section_id' => $this->section->id, 'roll_number' => '1', 'starts_on' => '2026-03-31'])
            ->assertForbidden();
    }

    #[Test]
    public function the_api_answers_the_year_rules_with_their_stable_codes(): void
    {
        $student = $this->createStudent($this->school);
        $user = $this->admin();
        $api = fn (string $key, array $body) => $this->withHeader('Authorization', 'Bearer '.$user->createToken('t')->plainTextToken)
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/schools/{$this->school->id}/students/{$student->id}/enrollments", $body);

        $api('sis41-before', ['section_id' => $this->section->id, 'roll_number' => '1', 'starts_on' => '2026-03-31'])
            ->assertStatus(422)->assertJsonPath('error.code', 'INVALID_ENROLLMENT_DATE_RANGE');
        $api('sis41-closed', ['section_id' => $this->closedSection->id, 'roll_number' => '1', 'starts_on' => '2025-06-01'])
            ->assertStatus(422)->assertJsonPath('error.code', 'PLACEMENT_TARGET_UNAVAILABLE');
        $this->assertSame(0, $this->placements());
    }
}
