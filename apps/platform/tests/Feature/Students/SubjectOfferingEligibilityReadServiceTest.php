<?php

namespace Tests\Feature\Students;

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Application\StudentSubjectEnrollmentService;
use App\Domain\Students\Application\SubjectOfferingEligibility as E;
use App\Domain\Students\Application\SubjectOfferingEligibilityReadService;
use App\Domain\Students\Application\SubjectOfferingRosterReadService;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Domain\Students\Infrastructure\StudentSubjectEnrollment;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * RES.1 (ADR 0068 §5, "P3"): the Students-owned as-of-date SubjectOffering
 * eligibility seam. Every lifecycle row is written through the real
 * Students services (enroll, transfer, complete, withdraw, cancel, elective
 * switch); raw SQL is used only to plant the corrupt or legacy shapes the
 * services can no longer produce. World: one School, 2026-27 (active) and
 * 2027-28 (draft); campuses A and B; Grades 5 and 6; required and elective
 * Offerings.
 */
class SubjectOfferingEligibilityReadServiceTest extends TestCase
{
    use CreatesTenancyFixtures;

    /** @return array<string, mixed> */
    private function world(): array
    {
        $school = $this->createSchool();
        $w = ['school' => $school, 'campusA' => $this->createCampus($school), 'campusB' => $this->createCampus($school)];
        $w['year'] = $this->createAcademicYear($school, ['code' => 'Y26', 'status' => 'active', 'starts_on' => '2026-06-01', 'ends_on' => '2027-03-31']);
        $w['next'] = $this->createAcademicYear($school, ['code' => 'Y27', 'status' => 'draft', 'starts_on' => '2027-06-01', 'ends_on' => '2028-03-31']);
        $w['g5'] = $this->createGradeLevel($school, ['code' => 'G5', 'name' => 'Grade 5', 'sequence' => 5]);
        $w['g6'] = $this->createGradeLevel($school, ['code' => 'G6', 'name' => 'Grade 6', 'sequence' => 6]);
        $w['a1'] = $this->createSection($w['year'], $w['campusA'], $w['g5'], ['code' => 'A1', 'name' => '5 A1']);
        $w['a2'] = $this->createSection($w['year'], $w['campusA'], $w['g5'], ['code' => 'A2', 'name' => '5 A2']);
        $w['b1'] = $this->createSection($w['year'], $w['campusB'], $w['g5'], ['code' => 'B1', 'name' => '5 B1']);
        $w['a6'] = $this->createSection($w['year'], $w['campusA'], $w['g6'], ['code' => 'A6', 'name' => '6 A6']);
        $w['n1'] = $this->createSection($w['next'], $w['campusA'], $w['g6'], ['code' => 'N1', 'name' => '6 N1']);
        $offer = fn ($year, $campus, $grade, bool $required) => $this->createSubjectOffering($year, $campus, $grade, $this->createSubject($school), ['is_required' => $required, 'status' => 'active']);
        $w['reqA'] = $offer($w['year'], $w['campusA'], $w['g5'], true);
        $w['reqB'] = $offer($w['year'], $w['campusB'], $w['g5'], true);
        $w['reqNext'] = $offer($w['next'], $w['campusA'], $w['g6'], true);
        $w['elA'] = $offer($w['year'], $w['campusA'], $w['g5'], false);
        $w['elA2'] = $offer($w['year'], $w['campusA'], $w['g5'], false);
        $w['student'] = $this->createStudent($school);

        return $w;
    }

    private function p3(): SubjectOfferingEligibilityReadService
    {
        return app(SubjectOfferingEligibilityReadService::class);
    }

    /** @param  array<string, mixed>  $w */
    private function ask(array $w, SubjectOffering $offering, string $date, ?Student $student = null, ?School $as = null): E
    {
        return $this->p3()->eligibilityAsOf($as ?? $w['school'], ($student ?? $w['student'])->id, $offering->id, $date);
    }

    /** @param  array<string, mixed>  $w */
    private function place(array $w, Section $section, string $startsOn, ?Student $student = null): StudentEnrollment
    {
        static $roll = 0;

        return app(StudentEnrollmentService::class)->enroll($student ?? $w['student'], $section, (string) ++$roll, $startsOn);
    }

    /** @param  array<string, mixed>  $w */
    private function elect(array $w, SubjectOffering $offering, string $startsOn): StudentSubjectEnrollment
    {
        return app(StudentSubjectEnrollmentService::class)->enroll($w['student'], $offering, $startsOn);
    }

    private function inSchool(School $school, callable $callback): mixed
    {
        return app(TenantContext::class)->withSchool($school, $callback);
    }

    /** Plants a row the services can no longer write (legacy or corrupt), through the runtime role under RLS. @param  array<string, mixed>  $w */
    private function plant(array $w, string $table, array $row): string
    {
        $id = (string) Str::uuid7();
        $this->inSchool($w['school'], fn () => DB::table($table)->insert(['id' => $id, 'school_id' => $w['school']->id, 'created_at' => now(), 'updated_at' => now(), ...$row]));

        return $id;
    }

    private function assertEligible(E $answer, string $source, string $placementId, string $sectionId, ?string $electiveRowId = null): void
    {
        $this->assertTrue($answer->eligible, 'expected eligible, got '.$answer->reason);
        $this->assertSame([$source, $placementId, $sectionId, $electiveRowId, null], [$answer->source, $answer->studentEnrollmentId, $answer->sectionId, $answer->studentSubjectEnrollmentId, $answer->reason]);
    }

    private function assertNotEligible(E $answer, string $reason): void
    {
        $this->assertFalse($answer->eligible);
        $this->assertSame([$reason, null, null, null, null], [$answer->reason, $answer->source, $answer->studentEnrollmentId, $answer->sectionId, $answer->studentSubjectEnrollmentId]);
    }

    #[Test]
    public function a_required_offering_is_eligible_on_its_grade_placement_with_the_section_as_evidence_only(): void
    {
        $w = $this->world();
        $placement = $this->place($w, $w['a1'], '2026-06-10');

        $this->assertEligible($this->ask($w, $w['reqA'], '2026-06-10'), E::SOURCE_REQUIRED, $placement->id, $w['a1']->id);
        // Not Section-filtered: the Offering is grade-wide, and a Section A2 classmate is eligible the same way.
        $classmate = $this->createStudent($w['school']);
        $other = $this->place($w, $w['a2'], '2026-06-01', $classmate);
        $this->assertEligible($this->ask($w, $w['reqA'], '2026-07-01', $classmate), E::SOURCE_REQUIRED, $other->id, $w['a2']->id);

        // Exact start boundary; outside the Offering's campus or grade the Student is simply not placed.
        $this->assertNotEligible($this->ask($w, $w['reqA'], '2026-06-09'), E::NO_PLACEMENT);
        $this->assertNotEligible($this->ask($w, $w['reqB'], '2026-07-01'), E::NO_PLACEMENT);
        $grade6 = $this->createSubjectOffering($w['year'], $w['campusA'], $w['g6'], $this->createSubject($w['school']), ['is_required' => true]);
        $this->assertNotEligible($this->ask($w, $grade6, '2026-07-01'), E::NO_PLACEMENT);
    }

    #[Test]
    public function a_section_transfer_never_rewrites_an_earlier_date_and_the_boundary_is_exact(): void
    {
        $w = $this->world();
        $source = $this->place($w, $w['a1'], '2026-06-01');
        $target = app(StudentEnrollmentService::class)->transferPlacement($source, $w['a2'], '77', '2026-09-01');

        $this->assertEligible($this->ask($w, $w['reqA'], '2026-08-31'), E::SOURCE_REQUIRED, $source->id, $w['a1']->id);
        $this->assertEligible($this->ask($w, $w['reqA'], '2026-09-01'), E::SOURCE_REQUIRED, $target->id, $w['a2']->id);
    }

    #[Test]
    public function a_campus_transfer_keeps_the_old_date_on_the_old_campus_offering(): void
    {
        $w = $this->world();
        $source = $this->place($w, $w['a1'], '2026-06-01');
        $target = app(StudentEnrollmentService::class)->transferPlacement($source, $w['b1'], '78', '2026-09-01');

        $this->assertEligible($this->ask($w, $w['reqA'], '2026-08-31'), E::SOURCE_REQUIRED, $source->id, $w['a1']->id);
        $this->assertNotEligible($this->ask($w, $w['reqA'], '2026-09-01'), E::NO_PLACEMENT);
        $this->assertNotEligible($this->ask($w, $w['reqB'], '2026-08-31'), E::NO_PLACEMENT);
        $this->assertEligible($this->ask($w, $w['reqB'], '2026-09-01'), E::SOURCE_REQUIRED, $target->id, $w['b1']->id);
    }

    #[Test]
    public function a_rollover_leaves_the_prior_year_answer_unchanged_and_years_bound_the_date(): void
    {
        $w = $this->world();
        $prior = $this->place($w, $w['a1'], '2026-06-01');
        app(StudentEnrollmentService::class)->complete($prior, '2027-03-31');
        $promoted = $this->place($w, $w['n1'], '2027-06-01');

        $this->assertEligible($this->ask($w, $w['reqA'], '2026-10-01'), E::SOURCE_REQUIRED, $prior->id, $w['a1']->id);
        $this->assertEligible($this->ask($w, $w['reqA'], '2027-03-31'), E::SOURCE_REQUIRED, $prior->id, $w['a1']->id);
        $this->assertEligible($this->ask($w, $w['reqNext'], '2027-06-01'), E::SOURCE_REQUIRED, $promoted->id, $w['n1']->id);
        // A year's Offering is never answered for a date outside that year, on either side.
        $this->assertNotEligible($this->ask($w, $w['reqA'], '2027-06-01'), E::OUTSIDE_ACADEMIC_YEAR);
        $this->assertNotEligible($this->ask($w, $w['reqA'], '2026-05-31'), E::OUTSIDE_ACADEMIC_YEAR);
        $this->assertNotEligible($this->ask($w, $w['reqNext'], '2027-05-31'), E::OUTSIDE_ACADEMIC_YEAR);
    }

    #[Test]
    public function a_withdrawal_ends_eligibility_after_its_inclusive_end_date_and_a_cancelled_interval_still_counts(): void
    {
        $w = $this->world();
        $withdrawn = $this->place($w, $w['a1'], '2026-06-01');
        app(StudentEnrollmentService::class)->withdraw($withdrawn, '2026-10-15');
        $this->assertEligible($this->ask($w, $w['reqA'], '2026-10-15'), E::SOURCE_REQUIRED, $withdrawn->id, $w['a1']->id);
        $this->assertNotEligible($this->ask($w, $w['reqA'], '2026-10-16'), E::NO_PLACEMENT);

        // ADR 0068 §5.5, confirmed: a cancelled placement counts for its interval, exactly as membersAsOf() does.
        $other = $this->createStudent($w['school']);
        $cancelled = $this->place($w, $w['a1'], '2026-06-01', $other);
        app(StudentEnrollmentService::class)->cancel($cancelled, '2026-07-31');
        $this->assertEligible($this->ask($w, $w['reqA'], '2026-07-31', $other), E::SOURCE_REQUIRED, $cancelled->id, $w['a1']->id);
        $this->assertNotEligible($this->ask($w, $w['reqA'], '2026-08-01', $other), E::NO_PLACEMENT);
    }

    #[Test]
    public function an_elective_needs_a_dated_subject_enrollment_covering_the_date(): void
    {
        $w = $this->world();
        $placement = $this->place($w, $w['a1'], '2026-06-01');
        $row = $this->elect($w, $w['elA'], '2026-07-01');
        app(StudentSubjectEnrollmentService::class)->withdraw($row, '2026-11-30');

        $this->assertNotEligible($this->ask($w, $w['elA'], '2026-06-30'), E::NO_ELECTIVE_ENROLLMENT);
        $this->assertEligible($this->ask($w, $w['elA'], '2026-07-01'), E::SOURCE_ELECTIVE, $placement->id, $w['a1']->id, $row->id);
        $this->assertEligible($this->ask($w, $w['elA'], '2026-11-30'), E::SOURCE_ELECTIVE, $placement->id, $w['a1']->id, $row->id);
        $this->assertNotEligible($this->ask($w, $w['elA'], '2026-12-01'), E::NO_ELECTIVE_ENROLLMENT);
        // The placement alone never makes a Student eligible for an elective they did not take.
        $this->assertNotEligible($this->ask($w, $w['elA2'], '2026-07-01'), E::NO_ELECTIVE_ENROLLMENT);
    }

    #[Test]
    public function an_elective_switch_moves_eligibility_exactly_at_its_effective_date(): void
    {
        $w = $this->world();
        $placement = $this->place($w, $w['a1'], '2026-06-01');
        $first = $this->elect($w, $w['elA'], '2026-06-01');
        $second = app(StudentSubjectEnrollmentService::class)->transfer($first, $w['elA2'], '2026-10-01');

        $this->assertEligible($this->ask($w, $w['elA'], '2026-09-30'), E::SOURCE_ELECTIVE, $placement->id, $w['a1']->id, $first->id);
        $this->assertNotEligible($this->ask($w, $w['elA'], '2026-10-01'), E::NO_ELECTIVE_ENROLLMENT);
        $this->assertNotEligible($this->ask($w, $w['elA2'], '2026-09-30'), E::NO_ELECTIVE_ENROLLMENT);
        $this->assertEligible($this->ask($w, $w['elA2'], '2026-10-01'), E::SOURCE_ELECTIVE, $placement->id, $w['a1']->id, $second->id);
    }

    #[Test]
    public function a_placement_transfer_never_re_anchors_an_elective_and_the_covering_placement_is_returned(): void
    {
        $w = $this->world();
        $source = $this->place($w, $w['a1'], '2026-06-01');
        $row = $this->elect($w, $w['elA'], '2026-06-01');
        $target = app(StudentEnrollmentService::class)->transferPlacement($source, $w['a2'], '79', '2026-09-01');

        // ADR 0068 §5.5, confirmed: the elective row keeps its original anchor (the services never re-anchor it).
        $this->assertSame($source->id, $this->inSchool($w['school'], fn () => StudentSubjectEnrollment::query()->findOrFail($row->id))->student_enrollment_id);
        $this->assertEligible($this->ask($w, $w['elA'], '2026-08-31'), E::SOURCE_ELECTIVE, $source->id, $w['a1']->id, $row->id);
        $this->assertEligible($this->ask($w, $w['elA'], '2026-09-01'), E::SOURCE_ELECTIVE, $target->id, $w['a2']->id, $row->id);

        // A campus transfer leaves the (still active) elective row behind: the Student is no longer in that campus.
        $moved = app(StudentEnrollmentService::class)->transferPlacement($target, $w['b1'], '80', '2026-11-01');
        $this->assertSame('active', $this->inSchool($w['school'], fn () => StudentSubjectEnrollment::query()->findOrFail($row->id))->status);
        $this->assertNotEligible($this->ask($w, $w['elA'], '2026-11-01'), E::NO_PLACEMENT);
        $this->assertSame($moved->id, $this->ask($w, $w['reqB'], '2026-11-01')->studentEnrollmentId);
    }

    #[Test]
    public function an_elective_anchored_to_a_placement_outside_the_offering_context_fails_closed(): void
    {
        $w = $this->world();
        // A brief, cancelled Grade 6 placement, then the real Grade 5 one; the planted elective names the wrong one.
        $wrong = $this->place($w, $w['a6'], '2026-06-01');
        app(StudentEnrollmentService::class)->cancel($wrong, '2026-06-02');
        $this->place($w, $w['a1'], '2026-06-03');
        $this->plant($w, 'student_subject_enrollments', [
            'student_id' => $w['student']->id, 'student_enrollment_id' => $wrong->id, 'subject_offering_id' => $w['elA']->id,
            'academic_year_id' => $w['year']->id, 'status' => 'active', 'starts_on' => '2026-07-01',
        ]);

        $answer = $this->ask($w, $w['elA'], '2026-07-01');
        $this->assertNotEligible($answer, E::INCONSISTENT_RECORD);
        $this->assertTrue($answer->isIntegrityFailure());
    }

    #[Test]
    public function a_legacy_unanchored_elective_fails_closed_and_the_current_placement_is_never_substituted(): void
    {
        $w = $this->world();
        $placement = $this->place($w, $w['a1'], '2026-06-01');
        $this->plant($w, 'student_subject_enrollments', [
            'student_id' => $w['student']->id, 'student_enrollment_id' => null, 'subject_offering_id' => $w['elA']->id,
            'academic_year_id' => $w['year']->id, 'status' => 'active', 'starts_on' => '2026-06-01',
        ]);

        // Today's roster still admits the Student (it re-validates against the current placement); P3 does not guess.
        $this->assertContains($w['student']->id, $this->inSchool($w['school'], fn () => app(SubjectOfferingRosterReadService::class)->currentRosterStudentIds($w['elA'])));
        $answer = $this->ask($w, $w['elA'], '2026-07-01');
        $this->assertNotEligible($answer, E::ELECTIVE_UNANCHORED);
        $this->assertTrue($answer->isIntegrityFailure());
        $this->assertEligible($this->ask($w, $w['reqA'], '2026-07-01'), E::SOURCE_REQUIRED, $placement->id, $w['a1']->id);
    }

    #[Test]
    public function an_offering_inactive_today_is_still_answered_for_its_historical_dates(): void
    {
        $w = $this->world();
        $placement = $this->place($w, $w['a1'], '2026-06-01');
        $row = $this->elect($w, $w['elA'], '2026-06-01');
        $this->inSchool($w['school'], fn () => SubjectOffering::query()->whereKey([$w['reqA']->id, $w['elA']->id])->update(['status' => 'inactive']));

        $this->assertSame([], $this->inSchool($w['school'], fn () => app(SubjectOfferingRosterReadService::class)->currentRosterStudentIds($w['reqA']->fresh())));
        $this->assertEligible($this->ask($w, $w['reqA'], '2026-07-01'), E::SOURCE_REQUIRED, $placement->id, $w['a1']->id);
        $this->assertEligible($this->ask($w, $w['elA'], '2026-07-01'), E::SOURCE_ELECTIVE, $placement->id, $w['a1']->id, $row->id);
    }

    #[Test]
    public function another_schools_student_or_offering_is_never_answered(): void
    {
        $w = $this->world();
        $this->place($w, $w['a1'], '2026-06-01');
        $o = $this->world();
        $this->place($o, $o['a1'], '2026-06-01');

        // School A's context never resolves School B's rows (application filter AND RLS), whichever id is foreign.
        $this->assertNotEligible($this->p3()->eligibilityAsOf($w['school'], $o['student']->id, $w['reqA']->id, '2026-07-01'), E::STUDENT_NOT_FOUND);
        $this->assertNotEligible($this->p3()->eligibilityAsOf($w['school'], $w['student']->id, $o['reqA']->id, '2026-07-01'), E::OFFERING_NOT_FOUND);
        $this->assertNotEligible($this->p3()->eligibilityAsOf($w['school'], $o['student']->id, $o['reqA']->id, '2026-07-01'), E::OFFERING_NOT_FOUND);
        $this->assertTrue($this->p3()->eligibilityAsOf($o['school'], $o['student']->id, $o['reqA']->id, '2026-07-01')->eligible);
    }

    #[Test]
    public function overlapping_history_or_a_year_mismatch_is_ambiguous_or_inconsistent_never_guessed(): void
    {
        $w = $this->world();
        $placement = $this->place($w, $w['a1'], '2026-06-01');
        // A backdated terminal placement overlapping the real one: two places on one date.
        $this->plant($w, 'student_enrollments', [
            'student_id' => $w['student']->id, 'academic_year_id' => $w['year']->id, 'campus_id' => $w['campusA']->id,
            'grade_level_id' => $w['g5']->id, 'section_id' => $w['a2']->id, 'roll_number' => '900', 'status' => 'cancelled',
            'starts_on' => '2026-08-01', 'ends_on' => '2026-08-31',
        ]);
        $this->assertNotEligible($this->ask($w, $w['reqA'], '2026-08-15'), E::AMBIGUOUS_HISTORY);
        $this->assertEligible($this->ask($w, $w['reqA'], '2026-09-01'), E::SOURCE_REQUIRED, $placement->id, $w['a1']->id);

        // An elective row whose year disagrees with its Offering's (the Offering FK does not pin the year).
        $nextPlacement = $this->place($w, $w['n1'], '2027-06-01');
        $this->plant($w, 'student_subject_enrollments', [
            'student_id' => $w['student']->id, 'student_enrollment_id' => $nextPlacement->id, 'subject_offering_id' => $w['elA']->id,
            'academic_year_id' => $w['next']->id, 'status' => 'withdrawn', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30',
        ]);
        $this->assertNotEligible($this->ask($w, $w['elA'], '2026-09-15'), E::INCONSISTENT_RECORD);
    }

    #[Test]
    public function the_lock_capable_variant_gives_the_same_answers_and_holds_share_locks_on_what_it_relied_on(): void
    {
        $w = $this->world();
        $placement = $this->place($w, $w['a1'], '2026-06-01');
        $row = $this->elect($w, $w['elA'], '2026-06-01');

        $answer = DB::transaction(function () use ($w) {
            $answer = $this->p3()->lockEligibilityAsOf($w['school'], $w['student']->id, $w['elA']->id, '2026-07-01');
            $locked = collect(DB::select(
                "select c.relname, l.mode from pg_locks l join pg_class c on c.oid = l.relation where l.pid = pg_backend_pid() and l.mode = 'RowShareLock' and c.relname in ('subject_offerings', 'student_enrollments', 'student_subject_enrollments') order by c.relname"
            ))->pluck('relname')->all();
            $this->assertSame(['student_enrollments', 'student_subject_enrollments', 'subject_offerings'], $locked, 'FOR SHARE taken on the Offering, the placement and the elective row');

            return $answer;
        });

        $this->assertEligible($answer, E::SOURCE_ELECTIVE, $placement->id, $w['a1']->id, $row->id);
        $this->assertEquals($this->ask($w, $w['elA'], '2026-07-01'), $answer);
        $this->assertNotEligible(DB::transaction(fn () => $this->p3()->lockEligibilityAsOf($w['school'], $w['student']->id, $w['elA2']->id, '2026-07-01')), E::NO_ELECTIVE_ENROLLMENT);
    }

    #[Test]
    public function the_date_must_be_a_calendar_date(): void
    {
        $w = $this->world();
        foreach (['2026-02-30', '2026-7-1', '2026-07-01 10:00:00', ''] as $bad) {
            $this->assertThrows(fn () => $this->ask($w, $w['reqA'], $bad), InvalidArgumentException::class);
        }
    }
}
