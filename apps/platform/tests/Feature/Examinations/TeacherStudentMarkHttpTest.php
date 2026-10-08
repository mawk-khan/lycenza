<?php

namespace Tests\Feature\Examinations;

use App\Http\Middleware\EnsureStudentMarksDevelopmentOnly;
use App\Http\Middleware\EnsureTeacherStudentMarksDevelopmentOnly;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CapturesStructuredLogs;
use Tests\Feature\Examinations\Concerns\CreatesTeacherStudentMarkFixtures;
use Tests\TestCase;

/**
 * RES.4 (ADR 0068 §25): the owned teacher marks surface over HTTP --
 * session JSON only, `capability:examinations.marks.teacher` + `mfa` + the
 * development-only block on both routes; every paper miss (unknown, another
 * School's, not owned) the identical 404 body, a malformed id the route's
 * plain 404; the administrative
 * grid, entry, lock and correction routes still refused to a teacher; no
 * submitted value echoed, flashed or logged; production refused first.
 */
class TeacherStudentMarkHttpTest extends TestCase
{
    use CapturesStructuredLogs, CreatesTeacherStudentMarkFixtures;

    private function as(User $user, School $school, bool $mfa = true): static
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
        if ($mfa) {
            session(['mfa_verified_at' => now()->toIso8601String()]);
        } else {
            session()->forget('mfa_verified_at');
        }

        return $this;
    }

    private function url(string $paperId): string
    {
        return "/app/my-examination-papers/{$paperId}/marks";
    }

    /** @param  array<string, mixed>  $w @return array{0: User, 1: \App\Domain\Students\Infrastructure\Student} */
    private function owner(array $w): array
    {
        [$teacher, $employee] = $this->markTeacher($w);
        $this->ownSection($w, $employee, 'a1');

        return [$teacher, $this->markStudent($w, 'a1')];
    }

    #[Test]
    public function an_owning_teacher_with_mfa_reads_and_writes_their_students(): void
    {
        $w = $this->teacherMarksWorld();
        [$teacher, $student] = $this->owner($w);
        $this->markStudent($w, 'a2');
        $this->as($teacher, $w['school']);

        $this->putJson($this->url($w['paper']->id), ['marks' => [['student_id' => $student->id, 'status' => 'present', 'value' => '64.5', 'expected_version' => null]]])
            ->assertOk()->assertExactJson(['data' => ['marks' => [['studentId' => $student->id, 'studentMarkId' => $this->markOf($w, $student)->id, 'version' => 1]]]]);
        $this->getJson($this->url($w['paper']->id))->assertOk()
            ->assertJsonCount(1, 'data.rows')
            ->assertJsonPath('data.rows.0.studentId', $student->id)
            ->assertJsonPath('data.rows.0.mark.value', '64.50')
            ->assertJsonPath('data.unavailableCount', 0)
            ->assertJsonPath('data.paper.marksState', 'open')
            ->assertJsonMissingPath('data.rows.0.pendingCorrection')
            ->assertJsonMissingPath('data.rows.0.processingBasis');
    }

    #[Test]
    public function the_capability_and_the_mfa_window_are_both_required(): void
    {
        $w = $this->teacherMarksWorld();
        [$teacher, $student] = $this->owner($w);
        $body = ['marks' => [['student_id' => $student->id, 'status' => 'absent', 'value' => null, 'expected_version' => null]]];

        $this->as($teacher, $w['school'], mfa: false)->getJson($this->url($w['paper']->id))->assertUnauthorized()->assertJsonPath('error.code', 'mfa_step_up_required');
        $this->putJson($this->url($w['paper']->id), $body)->assertUnauthorized();

        [$noFactor, $noFactorEmployee] = $this->markTeacher($w, mfa: false);
        $this->ownSection($w, $noFactorEmployee, 'a1');
        $this->as($noFactor, $w['school'])->getJson($this->url($w['paper']->id))->assertForbidden();
        $this->putJson($this->url($w['paper']->id), $body)->assertForbidden();

        // Same ownership, no teacher marks capability (no role): refused by the route.
        [$roleless, $rolelessEmployee] = $this->markTeacher($w, roleKey: null);
        $this->ownSection($w, $rolelessEmployee, 'a1');
        $this->as($roleless, $w['school'])->getJson($this->url($w['paper']->id))->assertForbidden();
        $this->putJson($this->url($w['paper']->id), $body)->assertForbidden();

        // An administrator's marks keys do not open the teacher surface.
        $this->as($w['admin'], $w['school'])->getJson($this->url($w['paper']->id))->assertForbidden();

        // The unlinked identity: one fixed 403, no hint which link failed.
        [$unlinked, $unlinkedEmployee] = $this->markTeacher($w, linked: false);
        $this->ownSection($w, $unlinkedEmployee, 'a1');
        $this->as($unlinked, $w['school'])->getJson($this->url($w['paper']->id))->assertForbidden()
            ->assertExactJson(['error' => ['code' => 'HR_ACTING_EMPLOYEE_UNAVAILABLE', 'message' => 'You are not an eligible Employee of this School.', 'status' => 403]]);
        $this->assertNull($this->markOf($w, $student));
    }

    #[Test]
    public function every_paper_miss_is_the_same_404(): void
    {
        $w = $this->teacherMarksWorld();
        [$teacher] = $this->owner($w);
        $other = $this->teacherMarksWorld();
        $this->as($teacher, $w['school']);

        $bodies = [];
        foreach ([(string) Str::uuid7(), $other['paper']->id, $w['electivePaper']->id] as $paperId) {
            $bodies[] = $this->getJson($this->url($paperId))->assertNotFound()->getContent();
            $bodies[] = $this->putJson($this->url($paperId), ['marks' => [['student_id' => (string) Str::uuid7(), 'status' => 'absent', 'value' => null, 'expected_version' => null]]])
                ->assertNotFound()->getContent();
        }
        $this->assertCount(1, array_unique($bodies), 'unknown, another School\'s and not-owned papers are indistinguishable');
        $this->assertSame(['error' => ['code' => 'STUDENT_MARK_PAPER_NOT_FOUND', 'message' => 'Examination paper not found.', 'status' => 404]], json_decode($bodies[0], true));
        $this->getJson($this->url('not-a-uuid'))->assertNotFound();
    }

    #[Test]
    public function a_student_outside_the_scope_answers_without_a_reason(): void
    {
        $w = $this->teacherMarksWorld();
        [$teacher, $student] = $this->owner($w);
        $foreign = $this->markStudent($w, 'a2');
        $this->as($teacher, $w['school']);

        $this->putJson($this->url($w['paper']->id), ['marks' => [
            ['student_id' => $student->id, 'status' => 'present', 'value' => '10', 'expected_version' => null],
            ['student_id' => $foreign->id, 'status' => 'present', 'value' => '10', 'expected_version' => null],
        ]])->assertNotFound()->assertExactJson(['error' => [
            'code' => 'STUDENT_MARK_STUDENT_NOT_FOUND', 'message' => 'A Student in this request was not found; nothing was saved.', 'status' => 404, 'studentId' => $foreign->id,
        ]]);
        $this->assertNull($this->markOf($w, $student), 'atomic: nothing saved');
    }

    #[Test]
    public function the_teacher_reaches_no_administrative_marks_route(): void
    {
        $w = $this->teacherMarksWorld();
        [$teacher, $student] = $this->owner($w);
        $this->recordMarks($w, [$this->entry($student, 'present', '50')]);
        $this->lockMarks($w);
        $mark = $this->markOf($w, $student);
        $correction = $this->requestCorrection($w, $mark, 'present', '51');
        $this->as($teacher, $w['school']);

        $this->getJson("/app/examination-papers/{$w['paper']->id}/marks")->assertForbidden();
        $this->putJson("/app/examination-papers/{$w['paper']->id}/marks", ['marks' => [['student_id' => $student->id, 'status' => 'absent', 'value' => null, 'expected_version' => 1]]])->assertForbidden();
        $this->postJson("/app/examination-papers/{$w['paper']->id}/marks/lock")->assertForbidden();
        $this->postJson("/app/examination-papers/{$w['paper']->id}/marks/{$mark->id}/corrections", ['expected_version' => 1, 'status' => 'absent', 'value' => null, 'reason_code' => 'entry_error'])->assertForbidden();
        $this->postJson("/app/student-mark-corrections/{$correction->id}/approve")->assertForbidden();
        $this->postJson("/app/student-mark-corrections/{$correction->id}/reject")->assertForbidden();

        // On the teacher surface a locked paper is readable and refuses entry.
        $this->getJson($this->url($w['paper']->id))->assertOk()->assertJsonPath('data.paper.marksState', 'locked');
        $this->putJson($this->url($w['paper']->id), ['marks' => [['student_id' => $student->id, 'status' => 'absent', 'value' => null, 'expected_version' => 1]]])
            ->assertStatus(409)->assertJsonPath('error.code', 'STUDENT_MARK_PAPER_LOCKED');
        $this->assertSame(['present', 1, 'pending'], [$this->markOf($w, $student)->status, $this->markOf($w, $student)->version, $this->freshCorrection($w, $correction)->status]);
    }

    #[Test]
    public function production_answers_a_fixed_403_before_anything_else(): void
    {
        $w = $this->teacherMarksWorld();
        [$teacher, $student] = $this->owner($w);
        $this->as($teacher, $w['school']);
        $this->app['env'] = 'production';
        // Outside `testing` CSRF verification is live; the block itself is what this proves.
        $this->withoutMiddleware(PreventRequestForgery::class);

        foreach ([$w['paper']->id, (string) Str::uuid7()] as $paperId) {
            $this->getJson($this->url($paperId))->assertForbidden()->assertExactJson(['error' => [
                'code' => 'TEACHER_STUDENT_MARKS_UNAVAILABLE', 'message' => 'Teacher marks entry is not available in this environment.', 'status' => 403,
            ]]);
            $this->putJson($this->url($paperId), ['marks' => [['student_id' => $student->id, 'status' => 'absent', 'value' => null, 'expected_version' => null]]])
                ->assertForbidden()->assertJsonPath('error.code', 'TEACHER_STUDENT_MARKS_UNAVAILABLE');
        }
        $this->app['env'] = 'testing';
        $this->assertNull($this->markOf($w, $student));
    }

    /**
     * S8 (ADR 0068 §27.11): the teacher surface keeps its own block. With BOTH availability middlewares bypassed the
     * services still refuse with the teacher's fixed 403 (TeacherStudentMarkAvailability first) -- never the
     * administrative code, never a 500, nothing written.
     */
    #[Test]
    public function the_teacher_service_block_keeps_its_own_fixed_403_without_its_middleware(): void
    {
        $w = $this->teacherMarksWorld();
        [$teacher, $student] = $this->owner($w);
        $this->as($teacher, $w['school']);
        $this->withoutMiddleware([PreventRequestForgery::class, EnsureTeacherStudentMarksDevelopmentOnly::class, EnsureStudentMarksDevelopmentOnly::class]);
        $fixed = ['error' => ['code' => 'TEACHER_STUDENT_MARKS_UNAVAILABLE', 'message' => 'Teacher marks entry is not available in this environment.', 'status' => 403]];

        foreach (['production', 'staging'] as $environment) {
            $this->app['env'] = $environment;
            $this->getJson('/app/my-examination-papers')->assertForbidden()->assertExactJson($fixed);
            $this->getJson($this->url($w['paper']->id))->assertForbidden()->assertExactJson($fixed);
            $this->putJson($this->url($w['paper']->id), ['marks' => [['student_id' => $student->id, 'status' => 'absent', 'value' => null, 'expected_version' => null]]])
                ->assertForbidden()->assertExactJson($fixed);
            $this->app['env'] = 'testing';
        }
        $this->assertNull($this->markOf($w, $student));
    }

    #[Test]
    public function no_submitted_value_is_echoed_flashed_or_logged(): void
    {
        $w = $this->teacherMarksWorld();
        [$teacher, $student] = $this->owner($w);
        $this->captureLogs();
        $this->as($teacher, $w['school']);

        $response = $this->put($this->url($w['paper']->id), ['marks' => [['student_id' => $student->id, 'status' => 'present', 'value' => '987.654', 'expected_version' => null]]])
            ->assertStatus(422)->assertJsonPath('error.code', 'STUDENT_MARK_VALIDATION_FAILED');
        $this->assertStringNotContainsString('987.654', (string) $response->getContent());
        $this->assertStringNotContainsString('987.654', json_encode(session()->all()));

        $response = $this->putJson($this->url($w['paper']->id), ['marks' => [['student_id' => $student->id, 'status' => 'present', 'value' => '99.99', 'expected_version' => null]]])
            ->assertStatus(422)->assertJsonPath('error.code', 'STUDENT_MARK_INVALID_VALUE');
        $this->assertStringNotContainsString('99.99', (string) $response->getContent());

        $this->putJson($this->url($w['paper']->id), ['marks' => [['student_id' => $student->id, 'status' => 'present', 'value' => '77.25', 'expected_version' => null]]])->assertOk();
        $this->getJson($this->url($w['paper']->id))->assertOk();

        foreach (['987.654', '99.99', '77.25', $student->first_name ?? 'no-name'] as $canary) {
            $this->assertStringNotContainsString((string) $canary, $this->capturedOutput());
        }
    }
}
