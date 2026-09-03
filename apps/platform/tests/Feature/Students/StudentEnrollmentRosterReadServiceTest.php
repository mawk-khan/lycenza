<?php

namespace Tests\Feature\Students;

use App\Domain\Students\Application\Exceptions\AmbiguousHistoricalEnrollmentException;
use App\Domain\Students\Application\StudentEnrollmentRosterReadService;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Attendance\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * Phase 0H.2: the Students/SIS-owned as-of-date Section roster read --
 * the single predicate both the Attendance preview and the
 * authoritative Attendance submission use. Attendance never reproduces
 * it, so its temporal semantics are proven here, once.
 */
class StudentEnrollmentRosterReadServiceTest extends TestCase
{
    use CreatesAttendanceFixtures;

    private function roster(array $w, string $date, ?string $sectionId = null): array
    {
        return $this->inSchool($w['school'], fn () => app(StudentEnrollmentRosterReadService::class)->membersAsOf(
            $w['school']->id,
            $w['year']->id,
            $w['campus']->id,
            $w['grade']->id,
            $sectionId ?? $w['section']->id,
            $date,
        ))->map(fn ($m) => $m->studentEnrollmentId)->all();
    }

    #[Test]
    public function the_predicate_is_temporal_not_status_based(): void
    {
        $w = $this->attendanceWorld();
        $active = $this->enrollStudent($w['section'], '1', '2026-06-01');
        $completed = $this->enrollStudent($w['section'], '2', '2026-06-01');
        $withdrawn = $this->enrollStudent($w['section'], '3', '2026-06-01');
        $cancelled = $this->enrollStudent($w['section'], '4', '2026-06-01');

        $service = app(StudentEnrollmentService::class);
        $service->complete($completed, '2026-10-01', $w['actor']);
        $service->withdraw($withdrawn, '2026-10-01', $w['actor']);
        $service->cancel($cancelled, '2026-10-01', $w['actor']);

        // All four intervals contain the date, so all four appear --
        // three of them are terminal.
        $roster = $this->roster($w, self::MONDAY);

        sort($roster);
        $expected = [$active->id, $completed->id, $withdrawn->id, $cancelled->id];
        sort($expected);
        $this->assertSame($expected, $roster);
    }

    #[Test]
    public function a_transfer_boundary_resolves_to_the_source_then_the_target_section(): void
    {
        $w = $this->attendanceWorld();
        $source = $this->enrollStudent($w['section'], '1', '2026-06-01');
        $target = $this->createSection($w['year'], $w['campus'], $w['grade'], ['status' => 'active', 'code' => 'B']);

        // Effective 2026-08-20: source ends_on becomes 2026-08-19
        // (inclusive), target starts_on becomes 2026-08-20.
        $created = app(StudentEnrollmentService::class)
            ->transferPlacement($source, $target, '9', '2026-08-20', $w['actor']);

        $this->assertSame('2026-08-19', $this->inSchool($w['school'], fn () => $source->fresh()->ends_on->toDateString()));
        $this->assertSame('2026-08-20', $created->starts_on->toDateString());

        // 19th -> source Section only.
        $this->assertSame([$source->id], $this->roster($w, '2026-08-19'));
        $this->assertSame([], $this->roster($w, '2026-08-19', $target->id));

        // 20th -> target Section only. No gap, no overlap.
        $this->assertSame([], $this->roster($w, '2026-08-20'));
        $this->assertSame([$created->id], $this->roster($w, '2026-08-20', $target->id));
    }

    #[Test]
    public function a_placement_starting_after_the_date_is_excluded(): void
    {
        $w = $this->attendanceWorld();
        $this->enrollStudent($w['section'], '1', '2026-10-01');

        $this->assertSame([], $this->roster($w, self::MONDAY));
    }

    #[Test]
    public function a_current_active_placement_elsewhere_never_rewrites_an_earlier_roster(): void
    {
        $w = $this->attendanceWorld();
        $source = $this->enrollStudent($w['section'], '1', '2026-06-01');
        $target = $this->createSection($w['year'], $w['campus'], $w['grade'], ['status' => 'active', 'code' => 'B']);

        app(StudentEnrollmentService::class)->transferPlacement($source, $target, '9', '2026-09-01', $w['actor']);

        // The Student is CURRENTLY in Section B, but on MONDAY they were
        // in Section A -- and that is what the historical roster says.
        $this->assertSame([$source->id], $this->roster($w, self::MONDAY));
        $this->assertSame([], $this->roster($w, self::MONDAY, $target->id));
    }

    #[Test]
    public function overlapping_placements_for_one_student_are_rejected_not_deduplicated(): void
    {
        $w = $this->attendanceWorld();
        $first = $this->enrollStudent($w['section'], '1', '2026-06-01');

        $this->inSchool($w['school'], fn () => StudentEnrollment::query()->create([
            'school_id' => $w['school']->id,
            'student_id' => $first->student_id,
            'academic_year_id' => $w['year']->id,
            'campus_id' => $w['campus']->id,
            'grade_level_id' => $w['grade']->id,
            'section_id' => $w['section']->id,
            'roll_number' => '99',
            'status' => 'transferred',
            'starts_on' => '2026-06-01',
            'ends_on' => '2026-12-31',
        ]));

        try {
            $this->roster($w, self::MONDAY);
            $this->fail('Expected AmbiguousHistoricalEnrollmentException.');
        } catch (AmbiguousHistoricalEnrollmentException $e) {
            $this->assertSame($first->student_id, $e->studentId);
            $this->assertSame(2, $e->qualifyingCount);
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    #[Test]
    public function a_row_whose_denormalized_context_disagrees_with_its_section_is_excluded(): void
    {
        $w = $this->attendanceWorld();
        $good = $this->enrollStudent($w['section'], '1', '2026-06-01');
        $otherCampus = $this->createCampus($w['school'], ['code' => 'C2']);

        // An internally inconsistent row (its campus_id disagrees with
        // its Section's). Only reachable by bypassing
        // StudentEnrollmentService -- the roster fails CLOSED rather
        // than importing the inconsistency into Attendance history.
        $student = $this->createStudentFor($w['school']);
        $this->inSchool($w['school'], fn () => StudentEnrollment::query()->create([
            'school_id' => $w['school']->id,
            'student_id' => $student->id,
            'academic_year_id' => $w['year']->id,
            'campus_id' => $otherCampus->id,
            'grade_level_id' => $w['grade']->id,
            'section_id' => $w['section']->id,
            'roll_number' => '77',
            'status' => 'active',
            'starts_on' => '2026-06-01',
        ]));

        $this->assertSame([$good->id], $this->roster($w, self::MONDAY));
    }

    #[Test]
    public function the_projection_is_minimal(): void
    {
        $w = $this->attendanceWorld();
        $this->enrollStudent($w['section'], '7', '2026-06-01');

        $members = $this->inSchool($w['school'], fn () => app(StudentEnrollmentRosterReadService::class)->membersAsOf(
            $w['school']->id, $w['year']->id, $w['campus']->id, $w['grade']->id, $w['section']->id, self::MONDAY,
        ));

        $this->assertCount(1, $members);
        $this->assertSame(
            ['studentEnrollmentId', 'studentId', 'rollNumber', 'fullName'],
            array_keys($members->first()->toArray()),
        );
        $this->assertSame('7', $members->first()->rollNumber);
        $this->assertNotSame('', $members->first()->fullName);
    }
}
