<?php

namespace Tests\Feature\StudentGuardianIdentity;

use App\Domain\Guardians\Application\Exceptions\GuardianRelationshipInUseException;
use App\Domain\Guardians\Application\StudentGuardianRelationshipService;
use App\Domain\Guardians\Infrastructure\RelationshipType;
use App\Domain\Guardians\Infrastructure\StudentGuardianRelationship;
use App\Domain\Students\Application\StudentProcessingAuthorizationReadService;
use App\Domain\Students\Application\StudentProcessingAuthorizationService;
use App\Domain\Students\Domain\ProcessingAuthorizationPurpose;
use App\Domain\Students\Infrastructure\StudentProcessingAuthorization;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Testing\TestResponse;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Feature\Examinations\Concerns\CreatesStudentMarkFixtures;
use Tests\TestCase;

/**
 * Guardian unlink (2026-10-07; ADR 0038 note): a relationship still referenced
 * by retained processing-authorization evidence -- a recorded grant or a
 * terminal withdrawn / revoked / superseded row of the append-only ledger -- is
 * not deleted. The service refuses it deliberately (409
 * GUARDIAN_RELATIONSHIP_IN_USE) under the S5 Student lock; the RESTRICT
 * foreign key stays the authority and only ITS violation is translated. An
 * unreferenced relationship unlinks exactly as before; set-primary and
 * attribute updates are unaffected; nothing about the evidence leaks.
 */
class GuardianRelationshipUnlinkConflictTest extends TestCase
{
    use CreatesStudentMarkFixtures;

    /** @return array<string, mixed> a Student with a consent-referenced relationship (r1) and an unreferenced one (r2) */
    private function family(): array
    {
        $w = $this->marksWorld();
        $student = $this->markStudent($w, authorised: false);
        $links = app(StudentGuardianRelationshipService::class);
        $r1 = $links->link($student, $this->createGuardian($w['school']), RelationshipType::Mother, ['is_legal_guardian' => true], $w['admin']);
        $r2 = $links->link($student, $this->createGuardian($w['school']), RelationshipType::Father, [], $w['admin']);
        $grant = app(StudentProcessingAuthorizationService::class)->recordGuardianConsent($w['school'], $student, ProcessingAuthorizationPurpose::AcademicRecords, $r1, $w['admin']);
        $w['guardianAdmin'] = $this->createUserWithCapabilities($w['school'], ['students.view', 'students.manage', 'guardians.view', 'guardians.manage']);

        return [...$w, 'student' => $student, 'r1' => $r1, 'r2' => $r2, 'grant' => $grant];
    }

    private function exists(array $f, StudentGuardianRelationship $relationship): bool
    {
        return $this->inMarksSchool($f['school'], fn () => StudentGuardianRelationship::query()->whereKey($relationship->id)->exists());
    }

    private function unlinkedEvents(array $f): int
    {
        return $this->inMarksSchool($f['school'], fn () => SchoolAuditEvent::query()->where('event_type', 'student_guardian.unlinked')->count());
    }

    private function api(array $f, User $user, StudentGuardianRelationship $relationship): TestResponse
    {
        return $this->actingAs($user)->withHeader('X-School-Id', $f['school']->id)
            ->deleteJson("/api/v1/schools/{$f['school']->id}/student-guardian-relationships/{$relationship->id}");
    }

    #[Test]
    public function a_relationship_with_retained_consent_evidence_is_refused_over_the_api_and_the_web(): void
    {
        $f = $this->family();

        $response = $this->api($f, $f['guardianAdmin'], $f['r1'])->assertStatus(409)
            ->assertJsonPath('error.code', 'GUARDIAN_RELATIONSHIP_IN_USE')
            ->assertJsonPath('error.message', 'This Guardian relationship cannot be removed because retained authorization evidence still depends on it.');
        foreach (['spa_', 'student_processing_authorizations', 'SQLSTATE', 'foreign key', $f['grant']->id, $f['student']->id] as $leak) {
            $this->assertStringNotContainsString($leak, (string) $response->getContent());
        }

        $this->actingAs($f['guardianAdmin'])->withHeader('X-School-Id', $f['school']->id)->delete("/app/relationships/{$f['r1']->id}")
            ->assertRedirect("/app/students/{$f['student']->id}")
            ->assertSessionHasErrors(['relationship' => 'This Guardian relationship cannot be removed because retained authorization evidence still depends on it.']);

        $this->assertTrue($this->exists($f, $f['r1']), 'nothing deleted');
        $this->assertSame(0, $this->unlinkedEvents($f), 'a refused unlink records no unlink');
        $this->assertSame(1, $this->inMarksSchool($f['school'], fn () => StudentProcessingAuthorization::query()->where('student_guardian_relationship_id', $f['r1']->id)->count()), 'the evidence is untouched');
    }

    #[Test]
    public function withdrawn_and_revoked_evidence_still_keeps_the_relationship(): void
    {
        $f = $this->family();
        app(StudentProcessingAuthorizationService::class)->withdraw($f['school'], $f['grant'], $f['admin']);

        $this->assertThrows(fn () => app(StudentGuardianRelationshipService::class)->unlink($f['r1'], $f['admin']), GuardianRelationshipInUseException::class);
        $this->assertTrue($this->exists($f, $f['r1']));
        $this->assertSame(2, $this->inMarksSchool($f['school'], fn () => StudentProcessingAuthorization::query()->where('student_guardian_relationship_id', $f['r1']->id)->count()),
            'the recorded grant and its terminal row both keep naming the relationship');
    }

    #[Test]
    public function an_unreferenced_relationship_unlinks_exactly_as_before_and_set_primary_and_updates_are_unaffected(): void
    {
        $f = $this->family();

        $this->api($f, $f['guardianAdmin'], $f['r2'])->assertNoContent();
        $this->assertFalse($this->exists($f, $f['r2']));
        $this->assertSame(1, $this->unlinkedEvents($f));

        // The referenced relationship can still be made primary and edited (only destructive unlink is refused).
        $links = app(StudentGuardianRelationshipService::class);
        $this->assertTrue($links->setPrimary($f['r1'], $f['admin'])->is_primary);
        $this->assertTrue($links->update($f['r1'], ['is_emergency_contact' => true], $f['admin'])->is_emergency_contact);
    }

    #[Test]
    public function another_school_learns_nothing_about_the_relationship_or_its_evidence(): void
    {
        $f = $this->family();
        $other = $this->marksWorld();
        $outsider = $this->createUserWithCapabilities($other['school'], ['students.view', 'students.manage', 'guardians.view', 'guardians.manage']);

        $this->actingAs($outsider)->withHeader('X-School-Id', $other['school']->id)
            ->deleteJson("/api/v1/schools/{$other['school']->id}/student-guardian-relationships/{$f['r1']->id}")->assertNotFound();
        $this->assertFalse(app(StudentProcessingAuthorizationReadService::class)->isGuardianRelationshipReferenced($other['school'], $f['r1']->id),
            'the reference check is School-scoped: it cannot probe another School');
        $this->assertTrue($this->exists($f, $f['r1']));
    }

    #[Test]
    public function only_the_retained_evidence_foreign_key_is_translated(): void
    {
        $violation = function (string $sqlstate, string $message): QueryException {
            $pdo = new PDOException($message);
            $pdo->errorInfo = [$sqlstate, 7, $message];

            return new QueryException('pgsql', 'delete from "student_guardian_relationships" where "id" = ?', ['x'], $pdo);
        };
        $ours = 'update or delete on table "student_guardian_relationships" violates foreign key constraint "spa_guardian_relationship_context_foreign" on table "student_processing_authorizations"';

        $this->assertTrue(GuardianRelationshipInUseException::isViolation($violation('23503', $ours)));
        $this->assertTrue(GuardianRelationshipInUseException::isViolation(new RuntimeException('wrapped', 0, $violation('23503', $ours))), 'through the previous-exception chain');

        $this->assertFalse(GuardianRelationshipInUseException::isViolation($violation('23503', 'violates foreign key constraint "student_guardian_relationships_guardian_fk"')), 'another foreign key');
        $this->assertFalse(GuardianRelationshipInUseException::isViolation($violation('23505', $ours)), 'another SQLSTATE with the same text');
        $this->assertFalse(GuardianRelationshipInUseException::isViolation(new RuntimeException($ours)), 'text alone never counts');
    }
}
