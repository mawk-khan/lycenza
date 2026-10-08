<?php

namespace Tests\Feature\Examinations;

use App\Domain\Examinations\Infrastructure\StudentMarkCorrection;
use App\Http\Middleware\EnsureStudentMarksDevelopmentOnly;
use App\Models\School;
use App\Models\User;
use App\Models\UserMfaFactor;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\CapturesStructuredLogs;
use Tests\Feature\Examinations\Concerns\CreatesStudentMarkFixtures;
use Tests\TestCase;

/**
 * RES.3 (ADR 0068 §7, §21): the lock and correction routes over HTTP --
 * session JSON only, `capability:examinations.marks.*` + `mfa` on every
 * route, a fresh MFA code to lock and to decide, maker/checker, another
 * School concealed (404), no unlock route, and no value ever echoed or logged.
 */
class StudentMarkCorrectionHttpTest extends TestCase
{
    use CapturesStructuredLogs, CreatesStudentMarkFixtures;

    /** @var array<string, string> TOTP secrets by user id */
    private array $secrets = [];

    private function as(User $user, School $school, bool $mfa = true): static
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
        if ($mfa) {
            session(['mfa_verified_at' => now()->toIso8601String()]);
        } else {
            session()->forget('mfa_verified_at'); // the test session outlives an actingAs() switch
        }

        return $this;
    }

    /** @param  list<string>  $capabilities */
    private function staff(School $school, array $capabilities = self::MARKS_CAPABILITIES): User
    {
        $user = $this->createUserWithCapabilities($school, $capabilities);
        $this->secrets[$user->id] = app(Google2FA::class)->generateSecretKey();
        $this->enrollActiveMfaFactor($user, $this->secrets[$user->id]);

        return $user;
    }

    /** A current code; codes are single-use per TOTP step, so the step floor is cleared to reuse a step in-test. */
    private function code(User $user): string
    {
        UserMfaFactor::query()->where('user_id', $user->id)->update(['last_used_totp_step' => null]);

        return $this->currentTotpCodeFor($this->secrets[$user->id]);
    }

    /** @param  array<string, mixed>  $w */
    private function lockUrl(array $w): string
    {
        return '/app/examination-papers/'.$w['paper']->id.'/marks/lock';
    }

    /** @param  array<string, mixed>  $w */
    private function requestUrl(array $w, string $markId): string
    {
        return '/app/examination-papers/'.$w['paper']->id.'/marks/'.$markId.'/corrections';
    }

    /** @return array<string, mixed> */
    private function body(string $status = 'present', ?string $value = '45.5', int $version = 1, string $reason = 'entry_error'): array
    {
        return ['expected_version' => $version, 'status' => $status, 'value' => $value, 'reason_code' => $reason];
    }

    #[Test]
    public function an_administrator_locks_with_a_fresh_code_and_a_second_administrator_approves(): void
    {
        $w = $this->marksWorld();
        $student = $this->markStudent($w);
        $this->recordMarks($w, [$this->entry($student, 'present', '40')]);
        $maker = $this->staff($w['school']);
        $checker = $this->staff($w['school']);
        $mark = $this->markOf($w, $student);

        // Locking: the MFA window alone is not enough -- a fresh code is re-verified.
        $this->as($maker, $w['school'])->postJson($this->lockUrl($w))->assertStatus(422)->assertJsonValidationErrors('mfa_code');
        $this->postJson($this->lockUrl($w), ['mfa_code' => '000000'])->assertStatus(422)->assertJsonValidationErrors('mfa_code');
        $this->postJson($this->lockUrl($w), ['mfa_code' => $this->code($maker)])->assertOk()
            ->assertExactJson(['data' => ['examinationPaperId' => $w['paper']->id, 'marksState' => 'locked']]);
        $this->getJson('/app/examination-papers/'.$w['paper']->id.'/marks')->assertOk()->assertJsonPath('data.paper.marksState', 'locked');
        $this->putJson('/app/examination-papers/'.$w['paper']->id.'/marks', ['marks' => [['student_id' => $student->id, 'status' => 'absent', 'value' => null, 'expected_version' => 1]]])
            ->assertStatus(409)->assertJsonPath('error.code', 'STUDENT_MARK_PAPER_LOCKED');

        // Requesting needs no fresh code; the response carries ids and state only.
        $response = $this->postJson($this->requestUrl($w, $mark->id), $this->body())->assertCreated()
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.baseVersion', 1)->assertJsonPath('data.studentMarkId', $mark->id);
        $this->assertStringNotContainsString('45.5', (string) $response->getContent());
        $correctionId = $response->json('data.studentMarkCorrectionId');

        // The requester cannot decide it, even with a fresh code.
        $this->postJson("/app/student-mark-corrections/{$correctionId}/approve", ['mfa_code' => $this->code($maker)])
            ->assertForbidden()->assertJsonPath('error.code', 'STUDENT_MARK_CORRECTION_SELF_DECISION');

        $this->as($checker, $w['school'])->postJson("/app/student-mark-corrections/{$correctionId}/approve")->assertStatus(422)->assertJsonValidationErrors('mfa_code');
        $this->postJson("/app/student-mark-corrections/{$correctionId}/approve", ['mfa_code' => $this->code($checker)])->assertOk()
            ->assertJsonPath('data.status', 'approved');
        $this->assertSame(['45.50', 2], [(string) $this->markOf($w, $student)->value, $this->markOf($w, $student)->version]);
        $this->postJson("/app/student-mark-corrections/{$correctionId}/reject", ['mfa_code' => $this->code($checker)])
            ->assertStatus(409)->assertJsonPath('error.code', 'STUDENT_MARK_CORRECTION_ALREADY_DECIDED');
    }

    #[Test]
    public function each_route_needs_its_own_capability_and_the_mfa_window(): void
    {
        $w = $this->marksWorld();
        $student = $this->markStudent($w);
        $this->recordMarks($w, [$this->entry($student, 'present', '40')]);
        $mark = $this->markOf($w, $student);

        // Ordinary marks entry implies neither locking nor corrections.
        $enterer = $this->staff($w['school'], ['examinations.marks.view', 'examinations.marks.manage']);
        $this->as($enterer, $w['school'])->postJson($this->lockUrl($w), ['mfa_code' => $this->code($enterer)])->assertForbidden();
        $this->lockMarks($w);
        $this->postJson($this->requestUrl($w, $mark->id), $this->body())->assertForbidden();

        // Requesting does not imply deciding.
        $requester = $this->staff($w['school'], ['examinations.marks.view', 'examinations.marks.correction.request']);
        $correctionId = $this->as($requester, $w['school'])->postJson($this->requestUrl($w, $mark->id), $this->body())->assertCreated()->json('data.studentMarkCorrectionId');
        $this->postJson("/app/student-mark-corrections/{$correctionId}/approve", ['mfa_code' => $this->code($requester)])->assertForbidden();
        $this->postJson("/app/student-mark-corrections/{$correctionId}/reject", ['mfa_code' => $this->code($requester)])->assertForbidden();

        // A capability without the MFA window is refused before anything else (401 mfa_step_up_required).
        $checker = $this->staff($w['school']);
        $this->as($checker, $w['school'], mfa: false)->postJson("/app/student-mark-corrections/{$correctionId}/approve", ['mfa_code' => $this->code($checker)])
            ->assertUnauthorized()->assertJsonPath('error.code', 'mfa_step_up_required');
        $this->postJson($this->lockUrl($w), ['mfa_code' => $this->code($checker)])->assertUnauthorized();
        $this->postJson($this->requestUrl($w, $mark->id), $this->body())->assertUnauthorized();

        // A teacher holds none of it.
        $teacher = $this->createUser();
        $this->assignSchoolRole($this->createMembership($teacher, $w['school']), 'teacher');
        $this->enrollActiveMfaFactor($teacher);
        $this->as($teacher, $w['school'])->postJson($this->requestUrl($w, $mark->id), $this->body())->assertForbidden();
        $this->postJson("/app/student-mark-corrections/{$correctionId}/approve")->assertForbidden();

        $this->assertSame(StudentMarkCorrection::STATUS_PENDING, $this->inMarksSchool($w['school'], fn () => StudentMarkCorrection::query()->findOrFail($correctionId))->status);
        $this->assertSame(1, $this->markOf($w, $student)->version);
    }

    #[Test]
    public function another_schools_paper_mark_or_correction_is_not_found_and_there_is_no_unlock(): void
    {
        $w = $this->marksWorld();
        $student = $this->markStudent($w);
        $this->recordMarks($w, [$this->entry($student, 'present', '40')]);
        $this->lockMarks($w);
        $mark = $this->markOf($w, $student);
        $correction = $this->requestCorrection($w, $mark, 'absent', null);

        $other = $this->marksWorld();
        $outsider = $this->staff($other['school']);
        $this->as($outsider, $other['school'])->postJson($this->lockUrl($w), ['mfa_code' => $this->code($outsider)])->assertNotFound();
        $this->postJson($this->requestUrl($w, $mark->id), $this->body())->assertNotFound();
        $this->postJson("/app/student-mark-corrections/{$correction->id}/approve", ['mfa_code' => $this->code($outsider)])->assertNotFound();
        $this->postJson('/app/student-mark-corrections/not-a-uuid/approve')->assertNotFound();

        // The same School's other (locked) paper does not own this mark.
        $this->lockMarks($w, $w['electivePaper']);
        $insider = $this->staff($w['school']);
        $this->as($insider, $w['school'])->postJson('/app/examination-papers/'.$w['electivePaper']->id.'/marks/'.$mark->id.'/corrections', $this->body())->assertNotFound();

        foreach (['unlock', 'reopen', 'open'] as $verb) {
            $this->postJson('/app/examination-papers/'.$w['paper']->id.'/marks/'.$verb)->assertNotFound();
        }
        $this->getJson('/app/student-mark-corrections')->assertNotFound();
        $this->assertSame(StudentMarkCorrection::STATUS_PENDING, $this->freshCorrection($w, $correction)->status);
    }

    #[Test]
    public function no_proposed_value_is_echoed_flashed_or_logged(): void
    {
        $w = $this->marksWorld();
        $student = $this->markStudent($w);
        $this->recordMarks($w, [$this->entry($student, 'present', '40')]);
        $this->lockMarks($w);
        $mark = $this->markOf($w, $student);
        $maker = $this->staff($w['school']);
        $this->captureLogs();

        $response = $this->as($maker, $w['school'])->post($this->requestUrl($w, $mark->id), $this->body(value: '987.654'))
            ->assertStatus(422)->assertJsonPath('error.code', 'STUDENT_MARK_CORRECTION_VALIDATION_FAILED')->assertJsonPath('error.fields', ['value']);
        $this->assertStringNotContainsString('987.654', (string) $response->getContent());
        $this->assertStringNotContainsString('987.654', json_encode(session()->all()));

        $response = $this->postJson($this->requestUrl($w, $mark->id), $this->body(value: '99.99'))
            ->assertStatus(422)->assertJsonPath('error.code', 'STUDENT_MARK_INVALID_VALUE')->assertJsonPath('error.studentId', $student->id);
        $this->assertStringNotContainsString('99.99', (string) $response->getContent());
        $this->postJson($this->requestUrl($w, $mark->id), $this->body(reason: 'free text reason'))->assertStatus(422)->assertJsonPath('error.fields', ['reason_code']);
        $this->postJson($this->requestUrl($w, $mark->id), $this->body(version: 3))->assertStatus(409)->assertJsonPath('error.code', 'STUDENT_MARK_VERSION_CONFLICT');

        $this->postJson($this->requestUrl($w, $mark->id), $this->body(value: '41.25'))->assertCreated();
        $this->postJson($this->requestUrl($w, $mark->id), $this->body(value: '42.75'))->assertStatus(409)->assertJsonPath('error.code', 'STUDENT_MARK_CORRECTION_PENDING_EXISTS');

        foreach (['987.654', '99.99', '41.25', '42.75'] as $value) {
            $this->assertStringNotContainsString($value, $this->capturedOutput());
        }
        $this->assertSame(1, $this->inMarksSchool($w['school'], fn () => StudentMarkCorrection::query()->count()));
    }

    /**
     * S8 (ADR 0068 §27.11): with ONLY the availability middleware bypassed, the lock, request, approve and reject
     * actions already translate the services' own block into the same fixed 403 (capability, `mfa` and the fresh
     * code still run). Nothing changes state.
     */
    #[Test]
    public function the_service_level_block_answers_the_same_fixed_403_without_its_middleware(): void
    {
        $w = $this->marksWorld();
        $student = $this->markStudent($w);
        $this->recordMarks($w, [$this->entry($student, 'present', '40')]);
        $other = $this->marksWorld();
        $maker = $this->staff($w['school']);
        $checker = $this->staff($w['school']);
        $this->lockMarks($w);
        $mark = $this->markOf($w, $student);
        $correction = $this->requestCorrection($w, $mark, 'present', '41', actor: $maker);
        $this->withoutMiddleware([PreventRequestForgery::class, EnsureStudentMarksDevelopmentOnly::class]);
        $fixed = ['error' => ['code' => 'STUDENT_MARKS_UNAVAILABLE', 'message' => 'Student marks are not available in this environment.', 'status' => 403]];

        foreach (['production', 'staging'] as $environment) {
            $this->as($maker, $w['school']);
            $this->app['env'] = $environment;
            $this->postJson('/app/examination-papers/'.$other['paper']->id.'/marks/lock', ['mfa_code' => $this->code($maker)])->assertNotFound();
            $this->postJson($this->lockUrl($w), ['mfa_code' => $this->code($maker)])->assertForbidden()->assertExactJson($fixed);
            $this->postJson($this->requestUrl($w, $mark->id), $this->body())->assertForbidden()->assertExactJson($fixed);
            $this->app['env'] = 'testing';
            $this->as($checker, $w['school']);
            $this->app['env'] = $environment;
            $this->postJson("/app/student-mark-corrections/{$correction->id}/approve", ['mfa_code' => $this->code($checker)])->assertForbidden()->assertExactJson($fixed);
            $this->postJson("/app/student-mark-corrections/{$correction->id}/reject", ['mfa_code' => $this->code($checker)])->assertForbidden()->assertExactJson($fixed);
            $this->app['env'] = 'testing';
        }

        $this->assertSame(['40.00', 1, 'pending'], [(string) $this->markOf($w, $student)->value, $this->markOf($w, $student)->version, $this->freshCorrection($w, $correction)->status]);
        $this->assertSame(1, $this->inMarksSchool($w['school'], fn () => StudentMarkCorrection::query()->count()));
    }
}
