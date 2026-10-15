<?php

namespace Tests\Feature\App;

use App\Domain\Guardians\Application\StudentGuardianRelationshipService;
use App\Domain\Guardians\Infrastructure\RelationshipType;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Application\StudentService;
use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * V11.2 (ADR 0075): /app/students/{student}/history -- a read projection
 * over the audit ledger (StudentActivityHistoryService through
 * SchoolAuditSubjectHistoryReader). Each category only for holders of its
 * source capability; only allow-listed metadata; no actor; never another
 * Student's or another School's events.
 */
class StudentHistoryUiTest extends TestCase
{
    use CreatesTenancyFixtures;

    private const array EVERY_SOURCE = ['students.view', 'enrollments.view', 'guardians.view', 'admissions.view'];

    private function activate(User $user, School $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    /**
     * A Student with real, service-written history: created, placed in 5 A,
     * moved to 5 B, a Guardian linked, marked inactive, then withdrawn.
     *
     * @return array{school: School, student: Student, writer: User, sections: array<string, mixed>}
     */
    private function studentWithHistory(?School $school = null, string $number = 'H-1', string $yearStatus = 'active'): array
    {
        $school ??= $this->createSchool();
        $writer = $this->createUserWithCapabilities($school, ['students.manage', 'enrollments.manage', 'guardians.manage']);
        $campus = $this->createCampus($school);
        $grade = $this->createGradeLevel($school);
        $year = $this->createAcademicYear($school, ['code' => 'Y'.Str::random(5), 'name' => '2026-27', 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'status' => $yearStatus]);
        $a = $this->createSection($year, $campus, $grade, ['name' => '5 A', 'code' => 'A'.Str::random(5)]);
        $b = $this->createSection($year, $campus, $grade, ['name' => '5 B', 'code' => 'B'.Str::random(5)]);

        $student = app(StudentService::class)->create($school, [
            'student_number' => $number, 'first_name' => 'Ira', 'last_name' => 'Mehta', 'date_of_birth' => '2016-05-05',
        ], $writer);
        $enrollments = app(StudentEnrollmentService::class);
        $first = $enrollments->enroll($student, $a, '4', '2026-06-01', $writer);
        $second = $enrollments->transferPlacement($first, $b, '9', '2026-08-01', $writer);
        app(StudentGuardianRelationshipService::class)->link($student, $this->createGuardian($school), RelationshipType::Mother, [], $writer);
        app(StudentService::class)->changeStatus($student, 'inactive', $writer);
        $enrollments->withdraw($second, '2026-09-30', $writer);

        return ['school' => $school, 'student' => $student, 'writer' => $writer, 'sections' => compact('a', 'b')];
    }

    /** @return array<string, mixed> */
    private function history(Student $student): array
    {
        return $this->get("/app/students/{$student->id}/history")->assertOk()->viewData('page')['props'];
    }

    #[Test]
    public function a_full_viewer_sees_every_category_newest_first_with_effective_dates_and_names(): void
    {
        $h = $this->studentWithHistory();
        $viewer = $this->createUserWithCapabilities($h['school'], self::EVERY_SOURCE);
        $this->activate($viewer, $h['school']);

        $props = $this->history($h['student']);
        $types = array_column($props['entries'], 'eventType');

        $this->assertSame(['record', 'placement', 'guardian', 'admission'], $props['categories']);
        $this->assertSame('student.created', end($types), 'Newest first: creation is last.');
        $this->assertSame('student_enrollment.withdrawn', $types[0]);
        $this->assertContains('student_enrollment.transferred', $types);
        $this->assertContains('student_guardian.linked', $types);

        $byType = collect($props['entries'])->keyBy('eventType');
        $this->assertSame('2026-09-30', $byType['student_enrollment.withdrawn']['effectiveOn']);
        $this->assertSame('5 B', $byType['student_enrollment.withdrawn']['section']);
        $this->assertSame('5 A', $byType['student_enrollment.transferred']['fromSection']);
        $this->assertSame('5 B', $byType['student_enrollment.transferred']['toSection']);
        $this->assertSame('2026-27', $byType['student_enrollment.created']['academicYear']);
        $this->assertSame(['active', 'inactive'], [$byType['student.status_changed']['previousStatus'], $byType['student.status_changed']['newStatus']]);
        $this->assertNull($byType['student.status_changed']['effectiveOn'], 'A status change has no effective date; none is invented.');
        $this->assertSame('mother', $byType['student_guardian.linked']['relationshipType']);
        $this->assertFalse($props['truncated']);
    }

    #[Test]
    public function categories_follow_their_source_capabilities(): void
    {
        $h = $this->studentWithHistory();
        $viewer = $this->createUserWithCapabilities($h['school'], ['students.view']);
        $this->activate($viewer, $h['school']);

        $props = $this->history($h['student']);

        $this->assertSame(['record'], $props['categories']);
        $this->assertSame(['record'], array_values(array_unique(array_column($props['entries'], 'category'))));
        $this->assertNotContains('student_enrollment.created', array_column($props['entries'], 'eventType'));
        $this->assertNotContains('student_guardian.linked', array_column($props['entries'], 'eventType'));
    }

    #[Test]
    public function the_payload_carries_no_actor_and_no_unlisted_metadata(): void
    {
        $h = $this->studentWithHistory();
        $viewer = $this->createUserWithCapabilities($h['school'], self::EVERY_SOURCE);
        $this->activate($viewer, $h['school']);

        $json = json_encode($this->history($h['student']));

        foreach (['actorUserId', 'actor_user_id', $h['writer']->id, $h['writer']->name, 'campusId', 'gradeLevelId', 'isLegalGuardian', 'date_of_birth', '2016-05-05'] as $never) {
            $this->assertStringNotContainsString($never, (string) $json, "History must never expose {$never}.");
        }
    }

    #[Test]
    public function another_students_and_another_schools_events_never_appear(): void
    {
        $h = $this->studentWithHistory();
        // Same School: one active year per School, so the second uses a draft year.
        $sibling = $this->studentWithHistory($h['school'], 'H-2', 'draft');
        $foreign = $this->studentWithHistory(null, 'H-3');
        $viewer = $this->createUserWithCapabilities($h['school'], self::EVERY_SOURCE);
        $this->activate($viewer, $h['school']);

        $entries = $this->history($h['student'])['entries'];
        $this->assertCount(1, array_filter($entries, fn ($e) => $e['eventType'] === 'student.created'));
        $this->assertCount(1, array_filter($entries, fn ($e) => $e['eventType'] === 'student_enrollment.withdrawn'));

        $this->get("/app/students/{$foreign['student']->id}/history")->assertNotFound();
        $this->assertNotSame($sibling['student']->id, $h['student']->id);
    }

    #[Test]
    public function without_students_view_the_history_is_forbidden(): void
    {
        $h = $this->studentWithHistory();
        $enrollmentsOnly = $this->createUserWithCapabilities($h['school'], ['enrollments.view', 'guardians.view']);
        $this->activate($enrollmentsOnly, $h['school']);

        $this->get("/app/students/{$h['student']->id}/history")->assertForbidden();
    }

    #[Test]
    public function an_unknown_student_is_not_found(): void
    {
        $school = $this->createSchool();
        $viewer = $this->createUserWithCapabilities($school, ['students.view']);
        $this->activate($viewer, $school);

        $this->get('/app/students/'.Str::uuid().'/history')->assertNotFound();
    }
}
