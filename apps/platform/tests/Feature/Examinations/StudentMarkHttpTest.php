<?php

namespace Tests\Feature\Examinations;

use App\Domain\Examinations\Application\Exceptions\StudentMarkRetryRequiredException;
use App\Domain\Examinations\Application\Exceptions\StudentMarksUnavailableException;
use App\Domain\Examinations\Application\Marks\StudentMarkReadService;
use App\Domain\Examinations\Application\Marks\StudentMarkService;
use App\Http\Middleware\EnsureStudentMarksDevelopmentOnly;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\CapturesStructuredLogs;
use Tests\Feature\Examinations\Concerns\CreatesStudentMarkFixtures;
use Tests\TestCase;

/**
 * RES.2 (ADR 0068 §4.1, §9, §19): the per-paper marks surface over HTTP --
 * session JSON only, `capability:examinations.marks.*` + `mfa` on both
 * routes, another School's paper concealed (404), and no submitted value ever
 * echoed in a response, a session flash or a log line.
 */
class StudentMarkHttpTest extends TestCase
{
    use CapturesStructuredLogs, CreatesStudentMarkFixtures;

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

    private function auditCount(School $school, string $type): int
    {
        return $this->inMarksSchool($school, fn () => SchoolAuditEvent::query()->where('event_type', $type)->count());
    }

    /** @param  array<string, mixed>  $w */
    private function url(array $w, ?string $paperId = null): string
    {
        return '/app/examination-papers/'.($paperId ?? $w['paper']->id).'/marks';
    }

    #[Test]
    public function an_administrator_with_mfa_reads_the_grid_and_writes_a_batch(): void
    {
        $w = $this->marksWorld();
        $student = $this->markStudent($w);
        $this->as($w['admin'], $w['school']);

        $this->putJson($this->url($w), ['marks' => [['student_id' => $student->id, 'status' => 'present', 'value' => '64.5', 'expected_version' => null]]])
            ->assertOk()->assertExactJson(['data' => ['marks' => [['studentId' => $student->id, 'studentMarkId' => $this->markOf($w, $student)->id, 'version' => 1]]]]);
        $this->getJson($this->url($w))->assertOk()
            ->assertJsonPath('data.rows.0.studentId', $student->id)
            ->assertJsonPath('data.rows.0.mark.value', '64.50')
            ->assertJsonPath('data.rows.0.processingBasis', 'available');
    }

    #[Test]
    public function the_marks_capability_and_the_mfa_window_are_both_required(): void
    {
        $w = $this->marksWorld();
        $student = $this->markStudent($w);
        $body = ['marks' => [['student_id' => $student->id, 'status' => 'absent', 'value' => null, 'expected_version' => null]]];

        // Paper administration does not imply marks.
        $paperAdmin = $this->createUserWithCapabilities($w['school'], ['examinations.papers.view', 'examinations.papers.manage', 'examinations.definitions.view']);
        $this->enrollActiveMfaFactor($paperAdmin);
        $this->as($paperAdmin, $w['school'])->getJson($this->url($w))->assertForbidden();
        $this->putJson($this->url($w), $body)->assertForbidden();

        // Viewing does not imply entering.
        $viewer = $this->createUserWithCapabilities($w['school'], ['examinations.marks.view']);
        $this->enrollActiveMfaFactor($viewer);
        $this->as($viewer, $w['school'])->getJson($this->url($w))->assertOk();
        $this->putJson($this->url($w), $body)->assertForbidden();

        // A capability without the MFA window is refused (RequireMfa: 401 mfa_step_up_required, ADR 0037).
        $this->as($w['admin'], $w['school'], mfa: false)->getJson($this->url($w))->assertUnauthorized()->assertJsonPath('error.code', 'mfa_step_up_required');
        $this->putJson($this->url($w), $body)->assertUnauthorized()->assertJsonPath('error.code', 'mfa_step_up_required');
        $this->assertNull($this->markOf($w, $student));
    }

    #[Test]
    public function a_teacher_is_denied_and_another_school_sees_nothing(): void
    {
        $w = $this->marksWorld();
        $student = $this->markStudent($w);
        $this->recordMarks($w, [$this->entry($student, 'present', '50')]);

        $teacher = $this->createUser();
        $this->assignSchoolRole($this->createMembership($teacher, $w['school']), 'teacher');
        $this->enrollActiveMfaFactor($teacher);
        $this->as($teacher, $w['school'])->getJson($this->url($w))->assertForbidden();

        $other = $this->marksWorld();
        $this->as($other['admin'], $other['school'])->getJson($this->url($w))->assertNotFound();
        $this->putJson($this->url($w), ['marks' => [['student_id' => $student->id, 'status' => 'absent', 'value' => null, 'expected_version' => 1]]])->assertNotFound();
        $this->assertSame(['present', 1], [$this->markOf($w, $student)->status, $this->markOf($w, $student)->version]);
    }

    #[Test]
    public function no_submitted_value_is_echoed_flashed_or_logged(): void
    {
        $w = $this->marksWorld();
        $student = $this->markStudent($w);
        $this->captureLogs();
        $this->as($w['admin'], $w['school']);

        // Shape validation: field names only, and no session flash even for a non-JSON request.
        $invalid = ['marks' => [['student_id' => $student->id, 'status' => 'present', 'value' => '987.654', 'expected_version' => null]]];
        $response = $this->put($this->url($w), $invalid)->assertStatus(422)->assertJsonPath('error.code', 'STUDENT_MARK_VALIDATION_FAILED');
        $this->assertStringNotContainsString('987.654', (string) $response->getContent());
        $this->assertStringNotContainsString('987.654', json_encode(session()->all()));

        // A domain refusal (above the paper's maximum of 80): a fixed message and the Student id only.
        $response = $this->putJson($this->url($w), ['marks' => [['student_id' => $student->id, 'status' => 'present', 'value' => '99.99', 'expected_version' => null]]])
            ->assertStatus(422)->assertJsonPath('error.code', 'STUDENT_MARK_INVALID_VALUE')->assertJsonPath('error.studentId', $student->id);
        $this->assertStringNotContainsString('99.99', (string) $response->getContent());

        $this->assertStringNotContainsString('987.654', $this->capturedOutput());
        $this->assertStringNotContainsString('99.99', $this->capturedOutput());
        $this->assertNull($this->markOf($w, $student));
    }

    #[Test]
    public function there_is_no_generic_marks_list_search_export_or_bearer_api(): void
    {
        $w = $this->marksWorld();
        $this->as($w['admin'], $w['school']);
        foreach (['/app/student-marks', '/app/examination-papers/marks', '/app/examination-papers/'.$w['paper']->id.'/marks/export'] as $path) {
            $this->getJson($path)->assertNotFound();
        }
        $this->getJson("/api/v1/schools/{$w['school']->id}/examination-papers/{$w['paper']->id}/marks")->assertNotFound();
    }

    /** RES.5 (ADR 0068 §21.6, §27): the paper id is UUID-constrained -- a malformed id is a 404, never a 500. */
    #[Test]
    public function a_malformed_paper_id_is_a_404(): void
    {
        $w = $this->marksWorld();
        $this->as($w['admin'], $w['school']);

        $this->getJson('/app/examination-papers/not-a-uuid/marks')->assertNotFound();
        $this->putJson('/app/examination-papers/not-a-uuid/marks', ['marks' => []])->assertNotFound();
        $this->postJson('/app/examination-papers/not-a-uuid/marks/lock')->assertNotFound();
    }

    /**
     * RES.5 (ADR 0068 §27): administrative StudentMark is development only until RES-L1 (E36) -- refused in code
     * outside local/testing, at the route and in every service, whatever the grants. Nothing is read or written.
     */
    #[Test]
    public function production_and_staging_refuse_every_marks_route_and_service(): void
    {
        $w = $this->marksWorld();
        $student = $this->markStudent($w);
        $this->recordMarks($w, [$this->entry($student, 'present', '50')]);
        $this->lockMarks($w);
        $mark = $this->markOf($w, $student);
        $correction = $this->requestCorrection($w, $mark, 'present', '51');
        $this->as($w['admin'], $w['school']);
        $this->withoutMiddleware(PreventRequestForgery::class);
        $fixed = ['error' => ['code' => 'STUDENT_MARKS_UNAVAILABLE', 'message' => 'Student marks are not available in this environment.', 'status' => 403]];

        foreach (['production', 'staging'] as $environment) {
            $this->app['env'] = $environment;
            $this->getJson($this->url($w))->assertForbidden()->assertExactJson($fixed);
            $this->putJson($this->url($w), ['marks' => [['student_id' => $student->id, 'status' => 'absent', 'value' => null, 'expected_version' => 1]]])->assertForbidden()->assertExactJson($fixed);
            $this->postJson($this->url($w).'/lock')->assertForbidden()->assertExactJson($fixed);
            $this->postJson($this->url($w)."/{$mark->id}/corrections", ['expected_version' => 1, 'status' => 'absent', 'value' => null, 'reason_code' => 'entry_error'])->assertForbidden()->assertExactJson($fixed);
            $this->postJson("/app/student-mark-corrections/{$correction->id}/approve")->assertForbidden()->assertExactJson($fixed);
            $this->postJson("/app/student-mark-corrections/{$correction->id}/reject")->assertForbidden()->assertExactJson($fixed);

            $this->assertThrows(fn () => $this->grid($w), StudentMarksUnavailableException::class);
            $this->assertThrows(fn () => $this->recordMarks($w, [$this->entry($this->markStudent($w), 'present', '1')]), StudentMarksUnavailableException::class);
            $this->assertThrows(fn () => $this->approveCorrection($w, $correction, $this->checker($w)), StudentMarksUnavailableException::class);
            $this->app['env'] = 'testing';
        }
        $this->assertSame(['50.00', 1, 'pending'], [(string) $this->markOf($w, $student)->value, $this->markOf($w, $student)->version, $this->freshCorrection($w, $correction)->status]);
    }

    /**
     * S8 (ADR 0068 §27.11): the services' own block is defence in depth. With ONLY the availability middleware
     * bypassed (capability, `mfa` and the School context still run), the grid and the batch write answer the same
     * fixed 403 the middleware would -- never a 500 -- with no successful read audit, no value and no exception log.
     */
    #[Test]
    public function the_service_level_block_answers_the_same_fixed_403_without_its_middleware(): void
    {
        $w = $this->marksWorld();
        $student = $this->markStudent($w);
        $this->recordMarks($w, [$this->entry($student, 'present', '73.25')]);
        $this->as($w['admin'], $w['school']);
        $this->withoutMiddleware([PreventRequestForgery::class, EnsureStudentMarksDevelopmentOnly::class]);
        $this->captureLogs();
        $fixed = ['error' => ['code' => 'STUDENT_MARKS_UNAVAILABLE', 'message' => 'Student marks are not available in this environment.', 'status' => 403]];
        $viewed = fn (): int => $this->auditCount($w['school'], 'examinations.student_marks.viewed');
        $before = $viewed();

        foreach (['production', 'staging'] as $environment) {
            $this->app['env'] = $environment;
            $grid = $this->getJson($this->url($w))->assertForbidden()->assertExactJson($fixed);
            $write = $this->putJson($this->url($w), ['marks' => [['student_id' => $student->id, 'status' => 'absent', 'value' => null, 'expected_version' => 1]]])
                ->assertForbidden()->assertExactJson($fixed);
            $this->app['env'] = 'testing';

            foreach ([(string) $grid->getContent(), (string) $write->getContent()] as $body) {
                foreach ([$environment, '73.25', $student->id, 'Exception', 'trace', 'RES-L1', 'E36'] as $leak) {
                    $this->assertStringNotContainsString($leak, $body);
                }
            }
        }

        $this->assertSame($before, $viewed());
        $this->assertSame(['present', 1], [$this->markOf($w, $student)->status, $this->markOf($w, $student)->version]);
        $this->assertStringNotContainsString('StudentMarksUnavailableException', $this->capturedOutput());
        $this->assertStringNotContainsString('73.25', $this->capturedOutput());

        // The permitted environment still serves the same route.
        $this->getJson($this->url($w))->assertOk()->assertJsonPath('data.rows.0.mark.value', '73.25');
        $this->assertSame($before + 1, $viewed());
    }

    /** S8: only the availability refusal is translated -- any other failure in the grid keeps its existing behaviour. */
    #[Test]
    public function the_grid_does_not_translate_an_unrelated_failure(): void
    {
        $w = $this->marksWorld();
        $this->mock(StudentMarkReadService::class)->shouldReceive('grid')->andThrowExceptions([new RuntimeException('unrelated'), new AuthorizationException]);
        $this->as($w['admin'], $w['school']);

        $this->getJson($this->url($w))->assertStatus(500)->assertJsonMissingPath('error.code');
        $this->getJson($this->url($w))->assertForbidden()->assertJsonMissingPath('error.code')->assertJsonPath('message', 'This action is unauthorized.');
    }

    /** S5: a deadlock-victim write answers the fixed retryable 409 -- no SQLSTATE, SQL, table, value or Student id. */
    #[Test]
    public function a_retryable_abort_is_a_fixed_409_body(): void
    {
        $w = $this->marksWorld();
        $student = $this->markStudent($w);
        $this->mock(StudentMarkService::class)->shouldReceive('record')->andThrow(new StudentMarkRetryRequiredException);
        $this->as($w['admin'], $w['school']);

        $this->putJson($this->url($w), ['marks' => [['student_id' => $student->id, 'status' => 'present', 'value' => '77.25', 'expected_version' => null]]])
            ->assertStatus(409)
            ->assertExactJson(['error' => ['code' => 'STUDENT_MARK_RETRY_REQUIRED', 'message' => 'A concurrent change interrupted this request; nothing was saved. Please retry.', 'status' => 409]]);
    }
}
