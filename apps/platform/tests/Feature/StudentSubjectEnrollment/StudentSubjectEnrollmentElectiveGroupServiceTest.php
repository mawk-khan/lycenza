<?php

namespace Tests\Feature\StudentSubjectEnrollment;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\ElectiveGroup;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Students\Application\Exceptions\ActiveSubjectEnrollmentConflictException;
use App\Domain\Students\Application\Exceptions\ElectiveGroupConflictException;
use App\Domain\Students\Application\Exceptions\InactiveSubjectOfferingException;
use App\Domain\Students\Application\StudentSubjectEnrollmentService;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Domain\Students\Infrastructure\StudentSubjectEnrollment;
use App\Models\Campus;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1F.2 (checkpoint brief §51): the canonical
 * StudentSubjectEnrollmentService write path is now elective-group-aware
 * -- every NEW row persists its authoritative `student_enrollment_id`
 * and `elective_group_id`, derived server-side from a LOCKED
 * SubjectOffering, never accepted as caller input. Distinct from
 * StudentSubjectEnrollmentServiceTest.php (pre-1F.2 behavior, unchanged)
 * and StudentSubjectEnrollmentElectiveGroupConcurrencyTest.php (real
 * multi-process races).
 */
class StudentSubjectEnrollmentElectiveGroupServiceTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function service(): StudentSubjectEnrollmentService
    {
        return app(StudentSubjectEnrollmentService::class);
    }

    /**
     * @return array{
     *     school: School, campus: Campus, year: AcademicYear, grade: GradeLevel,
     *     group: ElectiveGroup, offeringA: SubjectOffering, offeringB: SubjectOffering,
     *     ungroupedOffering: SubjectOffering, student: Student, studentEnrollment: StudentEnrollment,
     * }
     */
    private function buildGroupedContext(): array
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $group = $this->createElectiveGroup($year, $campus, $grade);
        $offeringA = $this->createSubjectOffering($year, $campus, $grade, $this->createSubject($school, ['code' => 'FR']), [
            'is_required' => false, 'elective_group_id' => $group->id,
        ]);
        $offeringB = $this->createSubjectOffering($year, $campus, $grade, $this->createSubject($school, ['code' => 'ES']), [
            'is_required' => false, 'elective_group_id' => $group->id,
        ]);
        $ungroupedOffering = $this->createSubjectOffering($year, $campus, $grade, $this->createSubject($school, ['code' => 'PE']), [
            'is_required' => false,
        ]);
        $section = $this->createSection($year, $campus, $grade);
        $student = $this->createStudent($school, ['student_number' => 'SG-0001']);
        $studentEnrollment = $this->createStudentEnrollment($student, $section);

        return compact('school', 'campus', 'year', 'grade', 'group', 'offeringA', 'offeringB', 'ungroupedOffering', 'student', 'studentEnrollment');
    }

    // --- ENROLL: placement anchor + group snapshot -------------------------

    #[Test]
    public function a_grouped_enrollment_stores_the_placement_anchor(): void
    {
        ['student' => $student, 'offeringA' => $offering, 'studentEnrollment' => $studentEnrollment] = $this->buildGroupedContext();

        $row = $this->service()->enroll($student, $offering, '2026-06-01');

        $this->assertSame($studentEnrollment->id, $row->student_enrollment_id);
    }

    #[Test]
    public function a_grouped_enrollment_stores_the_correct_group_snapshot(): void
    {
        ['student' => $student, 'offeringA' => $offering, 'group' => $group] = $this->buildGroupedContext();

        $row = $this->service()->enroll($student, $offering, '2026-06-01');

        $this->assertSame($group->id, $row->elective_group_id);
    }

    #[Test]
    public function an_ungrouped_enrollment_stores_the_placement_anchor_and_a_null_group(): void
    {
        ['student' => $student, 'ungroupedOffering' => $offering, 'studentEnrollment' => $studentEnrollment] = $this->buildGroupedContext();

        $row = $this->service()->enroll($student, $offering, '2026-06-01');

        $this->assertSame($studentEnrollment->id, $row->student_enrollment_id);
        $this->assertNull($row->elective_group_id);
    }

    #[Test]
    public function multiple_ungrouped_electives_are_allowed(): void
    {
        ['school' => $school, 'student' => $student, 'ungroupedOffering' => $offeringC, 'year' => $year, 'campus' => $campus, 'grade' => $grade] = $this->buildGroupedContext();
        $offeringD = $this->createSubjectOffering($year, $campus, $grade, $this->createSubject($school, ['code' => 'ART']), ['is_required' => false]);

        $this->service()->enroll($student, $offeringC, '2026-06-01');
        $this->service()->enroll($student, $offeringD, '2026-06-01');

        $count = app(TenantContext::class)->withSchool($school, fn () => StudentSubjectEnrollment::query()
            ->where('student_id', $student->id)->where('status', 'active')->whereNull('elective_group_id')->count());
        $this->assertSame(2, $count);
    }

    #[Test]
    public function a_second_active_offering_in_the_same_group_is_rejected(): void
    {
        ['student' => $student, 'offeringA' => $offeringA, 'offeringB' => $offeringB] = $this->buildGroupedContext();
        $this->service()->enroll($student, $offeringA, '2026-06-01');

        $this->expectException(ElectiveGroupConflictException::class);

        $this->service()->enroll($student, $offeringB, '2026-06-01');
    }

    #[Test]
    public function different_groups_are_allowed(): void
    {
        ['school' => $school, 'year' => $year, 'campus' => $campus, 'grade' => $grade, 'student' => $student, 'offeringA' => $offeringA] = $this->buildGroupedContext();
        $groupY = $this->createElectiveGroup($year, $campus, $grade, ['code' => 'GROUPY']);
        $offeringY = $this->createSubjectOffering($year, $campus, $grade, $this->createSubject($school, ['code' => 'MU']), [
            'is_required' => false, 'elective_group_id' => $groupY->id,
        ]);

        $this->service()->enroll($student, $offeringA, '2026-06-01');
        $this->service()->enroll($student, $offeringY, '2026-06-01');

        $count = app(TenantContext::class)->withSchool($school, fn () => StudentSubjectEnrollment::query()
            ->where('student_id', $student->id)->where('status', 'active')->count());
        $this->assertSame(2, $count);
    }

    #[Test]
    public function same_offering_duplicate_and_group_conflict_are_distinct_exceptions(): void
    {
        ['student' => $student, 'offeringA' => $offeringA, 'offeringB' => $offeringB] = $this->buildGroupedContext();

        $this->service()->enroll($student, $offeringA, '2026-06-01');

        try {
            $this->service()->enroll($student, $offeringA, '2026-06-02');
            $this->fail('Expected ActiveSubjectEnrollmentConflictException');
        } catch (ActiveSubjectEnrollmentConflictException $e) {
            $this->assertInstanceOf(ActiveSubjectEnrollmentConflictException::class, $e, 'same-offering invariant, unchanged');
        }

        try {
            $this->service()->enroll($student, $offeringB, '2026-06-02');
            $this->fail('Expected ElectiveGroupConflictException');
        } catch (ElectiveGroupConflictException $e) {
            $this->assertInstanceOf(ElectiveGroupConflictException::class, $e, 'group invariant, distinct from the same-offering one');
        }
    }

    // --- ENROLL: withdraw/cancel release the group slot ---------------------

    #[Test]
    public function withdrawing_releases_the_group_slot(): void
    {
        ['student' => $student, 'offeringA' => $offeringA, 'offeringB' => $offeringB] = $this->buildGroupedContext();
        $enrollment = $this->service()->enroll($student, $offeringA, '2026-06-01');

        $this->service()->withdraw($enrollment, '2026-07-01');
        $row = $this->service()->enroll($student, $offeringB, '2026-07-02');

        $this->assertSame('active', $row->status);
    }

    #[Test]
    public function cancelling_releases_the_group_slot(): void
    {
        ['student' => $student, 'offeringA' => $offeringA, 'offeringB' => $offeringB] = $this->buildGroupedContext();
        $enrollment = $this->service()->enroll($student, $offeringA, '2026-06-01');

        $this->service()->cancel($enrollment, '2026-06-05');
        $row = $this->service()->enroll($student, $offeringB, '2026-06-06');

        $this->assertSame('active', $row->status);
    }

    // --- ENROLL: stale caller model safety (§31/§32) ------------------------

    #[Test]
    public function enroll_reloads_the_offerings_group_rather_than_trusting_a_stale_caller_model(): void
    {
        ['school' => $school, 'student' => $student, 'ungroupedOffering' => $staleOffering, 'group' => $group] = $this->buildGroupedContext();

        // $staleOffering was loaded BEFORE this out-of-band update -- its
        // own in-memory elective_group_id attribute is still null.
        app(TenantContext::class)->withSchool($school, fn () => SubjectOffering::query()->whereKey($staleOffering->id)->update(['elective_group_id' => $group->id]));
        $this->assertNull($staleOffering->elective_group_id, 'sanity: the caller-held model must still be stale in memory');

        $row = $this->service()->enroll($student, $staleOffering, '2026-06-01');

        $this->assertSame($group->id, $row->elective_group_id, 'the service must persist the CURRENT database value, not the stale in-memory one');
    }

    #[Test]
    public function enroll_reloads_the_offerings_status_rather_than_trusting_a_stale_caller_model(): void
    {
        ['school' => $school, 'student' => $student, 'ungroupedOffering' => $staleOffering] = $this->buildGroupedContext();

        app(TenantContext::class)->withSchool($school, fn () => SubjectOffering::query()->whereKey($staleOffering->id)->update(['status' => 'inactive']));
        $this->assertTrue($staleOffering->isActive(), 'sanity: the caller-held model must still be stale in memory');

        $this->expectException(InactiveSubjectOfferingException::class);

        $this->service()->enroll($student, $staleOffering, '2026-06-01');
    }

    // --- ENROLL: legacy rows are unaffected ---------------------------------

    #[Test]
    public function a_legacy_style_row_with_no_anchor_or_group_remains_withdrawable(): void
    {
        ['school' => $school, 'student' => $student, 'ungroupedOffering' => $offering] = $this->buildGroupedContext();

        $legacyRow = app(TenantContext::class)->withSchool($school, fn () => StudentSubjectEnrollment::query()->create([
            'school_id' => $school->id,
            'student_id' => $student->id,
            'student_enrollment_id' => null,
            'subject_offering_id' => $offering->id,
            'elective_group_id' => null,
            'academic_year_id' => $offering->academic_year_id,
            'status' => 'active',
            'starts_on' => '2026-01-01',
        ]));

        $withdrawn = $this->service()->withdraw($legacyRow, '2026-02-01');

        $this->assertSame('withdrawn', $withdrawn->status);
        $this->assertNull($withdrawn->student_enrollment_id, 'withdraw() must not retroactively backfill a legacy anchor');
    }

    // --- TRANSFER: placement + snapshot on the target row -------------------

    #[Test]
    public function same_group_transfer_succeeds_and_persists_the_targets_anchor_and_snapshot(): void
    {
        ['student' => $student, 'offeringA' => $offeringA, 'offeringB' => $offeringB, 'group' => $group, 'studentEnrollment' => $studentEnrollment] = $this->buildGroupedContext();
        $source = $this->service()->enroll($student, $offeringA, '2026-06-01');

        $target = $this->service()->transfer($source, $offeringB, '2026-09-01');

        $this->assertSame('active', $target->status);
        $this->assertSame($group->id, $target->elective_group_id);
        $this->assertSame($studentEnrollment->id, $target->student_enrollment_id);
        $refreshedSource = app(TenantContext::class)->withSchool($target->school, fn () => $source->fresh());
        $this->assertSame('transferred', $refreshedSource->status);
    }

    #[Test]
    public function different_group_transfer_to_a_free_target_succeeds(): void
    {
        ['school' => $school, 'year' => $year, 'campus' => $campus, 'grade' => $grade, 'student' => $student, 'offeringA' => $offeringA] = $this->buildGroupedContext();
        $groupY = $this->createElectiveGroup($year, $campus, $grade, ['code' => 'GROUPY']);
        $offeringY = $this->createSubjectOffering($year, $campus, $grade, $this->createSubject($school, ['code' => 'MU']), [
            'is_required' => false, 'elective_group_id' => $groupY->id,
        ]);
        $source = $this->service()->enroll($student, $offeringA, '2026-06-01');

        $target = $this->service()->transfer($source, $offeringY, '2026-09-01');

        $this->assertSame($groupY->id, $target->elective_group_id);
    }

    #[Test]
    public function different_group_transfer_to_an_occupied_target_is_rejected_and_rolls_back(): void
    {
        ['school' => $school, 'year' => $year, 'campus' => $campus, 'grade' => $grade, 'student' => $student, 'offeringA' => $offeringA] = $this->buildGroupedContext();
        $groupY = $this->createElectiveGroup($year, $campus, $grade, ['code' => 'GROUPY']);
        $offeringY1 = $this->createSubjectOffering($year, $campus, $grade, $this->createSubject($school, ['code' => 'MU']), [
            'is_required' => false, 'elective_group_id' => $groupY->id,
        ]);
        $offeringY2 = $this->createSubjectOffering($year, $campus, $grade, $this->createSubject($school, ['code' => 'DR']), [
            'is_required' => false, 'elective_group_id' => $groupY->id,
        ]);
        $source = $this->service()->enroll($student, $offeringA, '2026-06-01');
        $this->service()->enroll($student, $offeringY1, '2026-06-01'); // occupies Group Y already

        try {
            $this->service()->transfer($source, $offeringY2, '2026-09-01');
            $this->fail('Expected ElectiveGroupConflictException');
        } catch (ElectiveGroupConflictException) {
            $refreshedSource = app(TenantContext::class)->withSchool($school, fn () => $source->fresh());
            $this->assertSame('active', $refreshedSource->status, 'a rejected transfer must leave the source row untouched');
            $targetCount = app(TenantContext::class)->withSchool($school, fn () => StudentSubjectEnrollment::query()
                ->where('subject_offering_id', $offeringY2->id)->count());
            $this->assertSame(0, $targetCount, 'a rejected transfer must never leave a target row behind');
        }
    }

    #[Test]
    public function grouped_to_ungrouped_transfer_releases_the_source_group_slot(): void
    {
        ['student' => $student, 'offeringA' => $offeringA, 'ungroupedOffering' => $ungrouped] = $this->buildGroupedContext();
        $source = $this->service()->enroll($student, $offeringA, '2026-06-01');

        $target = $this->service()->transfer($source, $ungrouped, '2026-09-01');

        $this->assertNull($target->elective_group_id);
    }

    #[Test]
    public function ungrouped_to_grouped_transfer_snapshots_the_new_group(): void
    {
        ['student' => $student, 'ungroupedOffering' => $ungrouped, 'offeringA' => $offeringA, 'group' => $group] = $this->buildGroupedContext();
        $source = $this->service()->enroll($student, $ungrouped, '2026-06-01');

        $target = $this->service()->transfer($source, $offeringA, '2026-09-01');

        $this->assertSame($group->id, $target->elective_group_id);
    }

    #[Test]
    public function ungrouped_to_ungrouped_transfer_now_also_stores_the_targets_placement_anchor(): void
    {
        ['school' => $school, 'year' => $year, 'campus' => $campus, 'grade' => $grade, 'student' => $student, 'ungroupedOffering' => $ungroupedC, 'studentEnrollment' => $studentEnrollment] = $this->buildGroupedContext();
        $ungroupedD = $this->createSubjectOffering($year, $campus, $grade, $this->createSubject($school, ['code' => 'ART']), ['is_required' => false]);
        $source = $this->service()->enroll($student, $ungroupedC, '2026-06-01');

        $target = $this->service()->transfer($source, $ungroupedD, '2026-09-01');

        $this->assertNull($target->elective_group_id);
        $this->assertSame($studentEnrollment->id, $target->student_enrollment_id);
    }

    #[Test]
    public function transferring_a_legacy_null_anchor_source_still_persists_the_current_anchor_on_the_target(): void
    {
        ['school' => $school, 'student' => $student, 'ungroupedOffering' => $sourceOffering, 'offeringA' => $targetOffering, 'group' => $group, 'studentEnrollment' => $studentEnrollment] = $this->buildGroupedContext();

        $legacySource = app(TenantContext::class)->withSchool($school, fn () => StudentSubjectEnrollment::query()->create([
            'school_id' => $school->id,
            'student_id' => $student->id,
            'student_enrollment_id' => null,
            'subject_offering_id' => $sourceOffering->id,
            'elective_group_id' => null,
            'academic_year_id' => $sourceOffering->academic_year_id,
            'status' => 'active',
            'starts_on' => '2026-01-01',
        ]));

        $target = $this->service()->transfer($legacySource, $targetOffering, '2026-09-01');

        $this->assertSame($studentEnrollment->id, $target->student_enrollment_id, 'the NEW target row must carry the CURRENT authoritative anchor, regardless of the legacy source');
        $this->assertSame($group->id, $target->elective_group_id);
    }
}
