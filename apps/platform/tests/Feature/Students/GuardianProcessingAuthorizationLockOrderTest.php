<?php

namespace Tests\Feature\Students;

use App\Domain\Guardians\Application\StudentGuardianRelationshipService;
use App\Domain\Guardians\Infrastructure\RelationshipType;
use App\Domain\Guardians\Infrastructure\StudentGuardianRelationship;
use App\Domain\Students\Application\StudentProcessingAuthorizationService;
use App\Domain\Students\Domain\ProcessingAuthorizationPurpose;
use App\Domain\Students\Infrastructure\StudentProcessingAuthorization;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\CommitsRetentionFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\Feature\Examinations\Concerns\CreatesTeacherStudentMarkFixtures;
use Tests\TestCase;

/**
 * S5 (ADR 0038 lock-order amendment; ADR 0068 §27.11): the ADR 0038
 * processing-authorization seam locks Student -> its grants -> each consent
 * grant's guardian relationship. Guardian writers used to take a relationship
 * first:
 * - unlink: DELETE relationship (row lock), then its RESTRICT foreign-key
 *   check takes KEY SHARE on the consent grant that references it;
 * - setPrimary: the previous primary relationship, then the target.
 *
 * Each of these is a cycle with the seam. Here each path's statements are
 * replayed in their real order in separate PostgreSQL sessions, with the
 * Guardian writer observed blocked between the seam's two lock phases; a cycle
 * surfaces as `deadlock detected`. After the alignment (Guardian writers take
 * the Student first), the writer waits at the Student and there is no cycle.
 */
class GuardianProcessingAuthorizationLockOrderTest extends TestCase
{
    use CommitsRetentionFixtures, CreatesTeacherStudentMarkFixtures, ForcesConcurrentOverlap;

    /** @return list<string> */
    private function op(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/guardian-lock-order-op.php', ...$args];
    }

    /**
     * The holder takes its first locks, the contender is observed blocked, the holder takes its next locks, then both
     * finish. @return array{0: string, 1: string}
     */
    private function steppedRace(array $holderCommand, array $contenderCommand): array
    {
        $dir = sys_get_temp_dir().'/lockorder_'.bin2hex(random_bytes(8));
        mkdir($dir);
        $session = 'lockorder_'.bin2hex(random_bytes(6));
        $holder = new Process($holderCommand, null, ['CONCURRENCY_HOLD_DIR' => $dir]);
        $holder->setTimeout(180);
        $contender = new Process($contenderCommand, null, ['CONCURRENCY_SESSION_NAME' => $session]);
        $contender->setTimeout(180);
        try {
            $holder->start();
            $this->waitUntil(fn () => file_exists("{$dir}/acted"), $holder, 'the holder never took its first locks');
            $contender->start();
            $this->waitUntil(fn () => DB::connection('pgsql_admin')->selectOne('select wait_event_type from pg_stat_activity where application_name = ?', [$session])?->wait_event_type === 'Lock',
                $contender, 'the contender was never observed blocked');
            touch("{$dir}/step2");
            $this->waitUntil(fn () => file_exists("{$dir}/step2done"), $holder, 'the holder never finished its second step');
        } finally {
            touch("{$dir}/release");
            $holder->wait();
            $contender->wait();
            array_map('unlink', glob("{$dir}/*") ?: []);
            @rmdir($dir);
        }

        return [trim($holder->getOutput()), trim($contender->getOutput())];
    }

    private function waitUntil(callable $condition, Process $process, string $failure): void
    {
        $deadline = microtime(true) + 60;
        while (! $condition()) {
            if (! $process->isRunning() && ! $condition()) {
                $this->fail("{$failure}.\nOutput: ".$process->getOutput()."\nError: ".$process->getErrorOutput());
            }
            if (microtime(true) > $deadline) {
                $this->fail($failure);
            }
            usleep(2_000);
        }
    }

    /** A Student with two legal-guardian relationships (R1 primary) and a consent grant on each (R2's newer). @return array<string, mixed> */
    private function family(): array
    {
        $w = $this->teacherMarksWorld();
        $student = $this->markStudent($w, 'a1', authorised: false);
        $links = app(StudentGuardianRelationshipService::class);
        $r1 = $links->link($student, $this->createGuardian($w['school']), RelationshipType::Mother, ['is_legal_guardian' => true], $w['admin']);
        $r2 = $links->link($student, $this->createGuardian($w['school']), RelationshipType::Father, ['is_legal_guardian' => true], $w['admin']);
        $links->setPrimary($r1, $w['admin']);
        $grants = app(StudentProcessingAuthorizationService::class);
        $g1 = $grants->recordGuardianConsent($w['school'], $student, ProcessingAuthorizationPurpose::AcademicRecords, $r1, $w['admin']);
        $g2 = $grants->recordGuardianConsent($w['school'], $student, ProcessingAuthorizationPurpose::AcademicRecords, $r2, $w['admin']);

        // r3: a third relationship no grant references (unlinking it succeeds).
        $r3 = $links->link($student, $this->createGuardian($w['school']), RelationshipType::GenericParent, [], $w['admin']);
        [$teacher, $employee] = $this->markTeacher($w);
        $this->ownSection($w, $employee, 'a1');

        return [...$w, 'student' => $student, 'r1' => $r1, 'r2' => $r2, 'r3' => $r3, 'g1' => $g1, 'g2' => $g2, 'teacher' => $teacher];
    }

    #[Test]
    public function d1_unlink_no_longer_cycles_with_the_processing_authorization_seam(): void
    {
        $f = $this->family();

        // Seam: Student, grant g1 | (unlink blocked) | relationship r1. Unlink of r1 is refused anyway (g1 references it).
        [$seam, $unlink] = $this->steppedRace(
            $this->op('seam-steps', $f['school']->id, $f['student']->id, $f['g1']->id, '-', $f['r1']->id),
            $this->op('unlink', $f['school']->id, $f['r1']->id, $f['admin']->id),
        );

        $this->assertSame(['ok', 'refused:fk'], [$seam, $unlink], 'no deadlock: the unlink waited at the Student, then met its RESTRICT');
        $this->assertTrue($this->inMarksSchool($f['school'], fn () => StudentGuardianRelationship::query()->whereKey($f['r1']->id)->exists()));
    }

    #[Test]
    public function d2_set_primary_no_longer_cycles_with_the_processing_authorization_seam(): void
    {
        $f = $this->family();
        // r2 stops being a legal guardian: the seam locks r2 (newest grant), finds it does not qualify, then locks r1.
        $this->inMarksSchool($f['school'], fn () => app(StudentGuardianRelationshipService::class)->update($f['r2'], ['is_legal_guardian' => false], $f['admin']));

        [$seam, $setPrimary] = $this->steppedRace(
            $this->op('seam-steps', $f['school']->id, $f['student']->id, $f['g1']->id.','.$f['g2']->id, $f['r2']->id, $f['r1']->id),
            $this->op('set-primary', $f['school']->id, $f['r2']->id, $f['admin']->id),
        );

        $this->assertSame(['ok', 'primary:yes'], [$seam, $setPrimary], 'no deadlock: setPrimary (r1 then r2) waited at the Student');
        $this->assertSame([false, true], $this->inMarksSchool($f['school'], fn () => [
            (bool) StudentGuardianRelationship::query()->whereKey($f['r1']->id)->value('is_primary'),
            (bool) StudentGuardianRelationship::query()->whereKey($f['r2']->id)->value('is_primary'),
        ]));
        $this->assertSame(2, $this->inMarksSchool($f['school'], fn () => StudentProcessingAuthorization::query()->where('student_id', $f['student']->id)->count()));
    }

    /** @return list<string> */
    private function marks(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/student-mark-op.php', ...$args];
    }

    /** @param  array<string, mixed>  $f @return list<string> */
    private function record(array $f, string $value, bool $teacher = false): array
    {
        return $this->marks($teacher ? 'teacher-record' : 'record', $f['school']->id, $f['paper']->id, ($teacher ? $f['teacher'] : $f['admin'])->id, $f['student']->id, 'present', $value, '-');
    }

    private function relationshipCount(array $f): int
    {
        return $this->inMarksSchool($f['school'], fn () => StudentGuardianRelationship::query()->where('student_id', $f['student']->id)->count());
    }

    #[Test]
    public function o1_mark_entry_and_unlink_serialize_at_the_student_in_either_order(): void
    {
        $f = $this->family();

        // Entry first (seam: Student, grants, r2): the unlink of r1 waits at the Student, then meets its RESTRICT.
        [$entry, $unlink] = $this->raceWithHeldHolder($this->record($f, '40'), $this->op('unlink', $f['school']->id, $f['r1']->id, $f['admin']->id));
        $this->assertSame(['recorded:v1', 'refused:fk'], [$entry, $unlink]);

        // Unlink first (an unreferenced relationship): the entry waits at the Student, then records.
        [$unlink, $entry] = $this->raceWithHeldHolder($this->op('unlink', $f['school']->id, $f['r3']->id, $f['admin']->id),
            $this->marks('record', $f['school']->id, $f['paper']->id, $f['admin']->id, $f['student']->id, 'present', '41', '1'));
        $this->assertSame(['unlinked', 'recorded:v2'], [$unlink, $entry]);
        $this->assertSame(2, $this->relationshipCount($f));
    }

    #[Test]
    public function o2_mark_entry_and_set_primary_serialize_at_the_student_in_either_order(): void
    {
        $f = $this->family();

        [$entry, $primary] = $this->raceWithHeldHolder($this->record($f, '40'), $this->op('set-primary', $f['school']->id, $f['r2']->id, $f['admin']->id));
        $this->assertSame(['recorded:v1', 'primary:yes'], [$entry, $primary]);

        [$primary, $entry] = $this->raceWithHeldHolder($this->op('set-primary', $f['school']->id, $f['r1']->id, $f['admin']->id),
            $this->marks('record', $f['school']->id, $f['paper']->id, $f['admin']->id, $f['student']->id, 'present', '42', '1'));
        $this->assertSame(['primary:yes', 'recorded:v2'], [$primary, $entry]);
    }

    #[Test]
    public function o3_authorization_withdrawal_and_unlink_serialize_at_the_student(): void
    {
        $f = $this->family();

        [$withdrawal, $unlink] = $this->raceWithHeldHolder(
            $this->marks('withdraw-authorization', $f['school']->id, $f['g2']->id, $f['admin']->id),
            $this->op('unlink', $f['school']->id, $f['r3']->id, $f['admin']->id),
        );
        $this->assertSame(['withdrawn', 'unlinked'], [$withdrawal, $unlink]);

        // Unlink first (an unreferenced relationship): the withdrawal waits at the Student, then completes.
        $r4 = app(StudentGuardianRelationshipService::class)->link($f['student'], $this->createGuardian($f['school']), RelationshipType::StepParent, [], $f['admin']);
        [$unlink, $withdrawal] = $this->raceWithHeldHolder(
            $this->op('unlink', $f['school']->id, $r4->id, $f['admin']->id),
            $this->marks('withdraw-authorization', $f['school']->id, $f['g1']->id, $f['admin']->id),
        );
        $this->assertSame(['unlinked', 'withdrawn'], [$unlink, $withdrawal]);
    }

    #[Test]
    public function o4_teacher_entry_uses_the_same_order(): void
    {
        $f = $this->family();

        [$entry, $primary] = $this->raceWithHeldHolder($this->record($f, '40', teacher: true), $this->op('set-primary', $f['school']->id, $f['r2']->id, $f['admin']->id));
        $this->assertSame(['recorded:v1', 'primary:yes'], [$entry, $primary]);

        [$unlink, $entry] = $this->raceWithHeldHolder($this->op('unlink', $f['school']->id, $f['r3']->id, $f['admin']->id),
            $this->marks('teacher-record', $f['school']->id, $f['paper']->id, $f['teacher']->id, $f['student']->id, 'present', '41', '1'));
        $this->assertSame(['unlinked', 'recorded:v2'], [$unlink, $entry]);
    }

    #[Test]
    public function o5_two_guardian_writers_serialize_at_the_student(): void
    {
        $f = $this->family();

        [$primary, $unlink] = $this->raceWithHeldHolder($this->op('set-primary', $f['school']->id, $f['r2']->id, $f['admin']->id), $this->op('unlink', $f['school']->id, $f['r3']->id, $f['admin']->id));
        $this->assertSame(['primary:yes', 'unlinked'], [$primary, $unlink]);
        $this->assertSame(2, $this->relationshipCount($f));
    }

    /**
     * P: a TRUE PostgreSQL deadlock against a StudentMark write. The mark holds the paper FOR SHARE and waits for the
     * Student; the other session holds the Student and then asks for the paper FOR UPDATE. PostgreSQL aborts the mark
     * (the earlier waiter) -- the boundary answers the fixed retryable code, nothing partial commits, and a retry
     * re-runs every check and succeeds.
     */
    #[Test]
    public function p_a_deadlock_victim_mark_write_is_a_clean_retryable_refusal(): void
    {
        $f = $this->family();
        $events = fn () => $this->inMarksSchool($f['school'], fn () => DB::table('school_audit_events')->where('event_type', 'like', 'examinations.student_mark.%')->count());

        [$other, $entry] = $this->steppedRace(
            $this->op('paper-cycle', $f['school']->id, $f['student']->id, $f['paper']->id),
            $this->record($f, '55.5'),
        );

        $this->assertSame(['ok', 'refused:STUDENT_MARK_RETRY_REQUIRED'], [$other, $entry]);
        $this->assertNull($this->markOf($f, $f['student']), 'no mark');
        $this->assertSame(0, $this->inMarksSchool($f['school'], fn () => DB::table('student_mark_revisions')->count()), 'no revision');
        $this->assertSame(0, $events(), 'no audit row');

        $this->recordMarks($f, [$this->entry($f['student'], 'present', '55.5')]);
        $this->assertSame(['55.50', 1], [(string) $this->markOf($f, $f['student'])->value, $this->markOf($f, $f['student'])->version]);
        $this->assertSame(1, $events());
    }
}
