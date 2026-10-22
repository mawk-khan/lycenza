<?php

namespace Tests\Feature\Students;

use App\Domain\Students\Application\Exceptions\InvalidEnrollmentTransitionException;
use App\Domain\Students\Application\Exceptions\StudentLifecycleReasonException;
use App\Domain\Students\Application\Exceptions\StudentStatusUnchangedException;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Application\StudentService;
use App\Domain\Students\Infrastructure\StudentLifecycleEvent;
use App\Http\Middleware\RequireSchoolContext;
use App\Models\SchoolAuditEvent;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Auth\Mfa\FreshMfaProof;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Attendance\Concerns\CreatesAttendanceFixtures;
use Tests\Feature\Auth\Mfa\Concerns\CreatesMfaFixtures;
use Tests\TestCase;

/**
 * ORG.8E (ADR 0076 §63, owner decision 8E-D1): deactivating or reactivating
 * a Student and withdrawing or cancelling an Enrollment are direct,
 * whole-School, high-impact actions -- no request/approve workflow. Each
 * needs a closed, code-owned reason, a fresh MFA code, the actor's CURRENT
 * whole-School authority and (web) an explicit confirmation; each writes an
 * immutable `student_lifecycle_events` row. Completing an Enrollment stays
 * ordinary. A Campus grant never reaches them.
 */
class StudentLifecycleAssuranceTest extends TestCase
{
    use CreatesAttendanceFixtures, CreatesMfaFixtures;

    private function proof(User $user): FreshMfaProof
    {
        return new FreshMfaProof($user->id, CarbonImmutable::now());
    }

    /** @return array<string, mixed> */
    private function world(): array
    {
        $w = $this->attendanceWorld();
        $w['admin'] = $this->createUserWithCapabilities($w['school'], ['students.view', 'students.manage', 'enrollments.view', 'enrollments.manage']);
        $w['enrollment'] = $this->enrollStudent($w['section'], '1', '2026-06-01');
        $w['student'] = $this->inSchool($w['school'], fn () => $w['enrollment']->student()->firstOrFail());

        return $w;
    }

    private function events(array $w): int
    {
        return $this->inSchool($w['school'], fn () => StudentLifecycleEvent::query()->count());
    }

    #[Test]
    public function deactivation_and_reactivation_need_a_direction_reason_and_record_an_immutable_event(): void
    {
        $w = $this->world();
        $students = app(StudentService::class);

        $this->assertThrows(fn () => $students->changeStatus($w['student'], 'inactive', 'returned_to_school', $w['admin'], $this->proof($w['admin'])), StudentLifecycleReasonException::class);
        $this->assertThrows(fn () => $students->changeStatus($w['student'], 'inactive', 'free text', $w['admin'], $this->proof($w['admin'])), StudentLifecycleReasonException::class);
        $this->assertThrows(fn () => $students->changeStatus($w['student'], 'inactive', 'left_school', $w['admin'], $this->proof($this->createUser())), LogicException::class);
        $this->assertSame(0, $this->events($w));

        $this->assertSame('inactive', $students->changeStatus($w['student'], 'inactive', 'transferred_out', $w['admin'], $this->proof($w['admin']))->status);
        $this->assertThrows(fn () => $students->changeStatus($w['student'], 'inactive', 'left_school', $w['admin'], $this->proof($w['admin'])), StudentStatusUnchangedException::class);
        $this->assertSame('active', $students->changeStatus($w['student'], 'active', 'returned_to_school', $w['admin'], $this->proof($w['admin']))->status);

        $events = $this->inSchool($w['school'], fn () => StudentLifecycleEvent::query()->orderBy('occurred_at')->orderBy('id')->get());
        $this->assertSame([['student_deactivated', 'transferred_out'], ['student_reactivated', 'returned_to_school']], $events->map(fn ($e) => [$e->action, $e->reason_code])->all());
        $this->assertSame([$w['admin']->id], $events->pluck('acted_by_user_id')->unique()->values()->all());

        $audit = $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()->where('event_type', 'student.status_changed')->orderBy('id')->firstOrFail()->metadata);
        $this->assertSame(['active', 'inactive', 'transferred_out', true], [$audit['previousStatus'], $audit['newStatus'], $audit['reasonCode'], $audit['freshMfa']]);
        $this->assertStringNotContainsString((string) $w['student']->date_of_birth?->toDateString(), (string) json_encode($audit), 'no date of birth');
    }

    #[Test]
    public function withdrawal_and_cancellation_need_their_reasons_and_the_database_requires_the_event(): void
    {
        $w = $this->world();
        $enrollments = app(StudentEnrollmentService::class);

        $this->assertThrows(fn () => $enrollments->withdraw($w['enrollment'], '2026-09-30', 'did_not_join', $w['admin'], $this->proof($w['admin'])), StudentLifecycleReasonException::class);
        $withdrawn = $enrollments->withdraw($w['enrollment'], '2026-09-30', 'guardian_request', $w['admin'], $this->proof($w['admin']));
        $this->assertSame('withdrawn', $withdrawn->status);
        $this->assertThrows(fn () => $enrollments->withdraw($w['enrollment'], '2026-09-30', 'left_school', $w['admin'], $this->proof($w['admin'])), InvalidEnrollmentTransitionException::class);
        $this->assertSame(1, $this->events($w), 'never a second event for an ended placement');

        $other = $this->enrollStudent($w['section'], '2', '2026-06-01');
        $this->assertSame('cancelled', $enrollments->cancel($other, '2026-06-01', 'did_not_join', $w['admin'], $this->proof($w['admin']))->status);

        // Raw runtime UPDATE without the event: refused.
        $third = $this->enrollStudent($w['section'], '3', '2026-06-01');
        $this->assertSame('school_os_app', DB::selectOne('select current_user as u')->u);
        foreach (['withdrawn', 'cancelled'] as $status) {
            try {
                $this->inSchool($w['school'], fn () => DB::transaction(fn () => DB::table('student_enrollments')->where('id', $third->id)->update(['status' => $status, 'ends_on' => '2026-09-30'])));
                $this->fail("A {$status} placement needs its lifecycle event.");
            } catch (QueryException $e) {
                $this->assertStringContainsString('student_enrollments_lifecycle_evidence', $e->getMessage());
            }
        }

        // Completion stays an ordinary action (no reason, no event).
        $this->assertSame('completed', $enrollments->complete($third, '2027-03-31', $w['admin'])->status);
        $this->assertSame(2, $this->events($w));
    }

    #[Test]
    public function authority_is_read_fresh_and_a_campus_grant_never_reaches_a_lifecycle_action(): void
    {
        $w = $this->world();
        $this->assertTrue(Gate::forUser($w['admin'])->allows('capability', ['students.manage', $w['school']]));
        $this->inSchool($w['school'], fn () => DB::table('membership_role_assignments')
            ->whereIn('school_membership_id', DB::table('school_memberships')->where('user_id', $w['admin']->id)->pluck('id'))
            ->update(['revoked_at' => now(), 'revocation_reason' => 'revoked']));
        $this->assertThrows(fn () => app(StudentService::class)->changeStatus($w['student'], 'inactive', 'left_school', $w['admin'], $this->proof($w['admin'])), AuthorizationException::class);
        $this->assertThrows(fn () => app(StudentEnrollmentService::class)->withdraw($w['enrollment'], '2026-09-30', 'left_school', $w['admin'], $this->proof($w['admin'])), AuthorizationException::class);

        // principal at the Student's own Campus: Campus-adopted students.manage, but this is whole-School only.
        $principal = $this->createUserWithCapabilities($w['school'], []);
        $membership = $this->inSchool($w['school'], fn () => SchoolMembership::query()->where('user_id', $principal->id)->where('school_id', $w['school']->id)->sole());
        $this->assignSchoolRole($membership, 'principal', $w['campus']->id);
        $this->assertThrows(fn () => app(StudentService::class)->changeStatus($w['student'], 'inactive', 'left_school', $principal, $this->proof($principal)), AuthorizationException::class);
        $this->assertThrows(fn () => app(StudentEnrollmentService::class)->cancel($w['enrollment'], '2026-09-30', 'entered_in_error', $principal, $this->proof($principal)), AuthorizationException::class);

        $this->assertSame([0, 'active', 'active'], [$this->events($w), $this->inSchool($w['school'], fn () => $w['student']->fresh()->status), $this->inSchool($w['school'], fn () => $w['enrollment']->fresh()->status)]);
    }

    #[Test]
    public function the_web_needs_a_reason_a_confirmation_and_a_fresh_code(): void
    {
        $w = $this->world();
        $this->enrollActiveMfaFactor($w['admin']);
        $codes = $this->issueRecoveryCodes($w['admin'], 4);
        $this->actingAs($w['admin'])->withSession([RequireSchoolContext::SESSION_KEY => $w['school']->id]);
        $url = "/app/students/{$w['student']->id}/status";

        $this->post($url, ['status' => 'inactive', 'confirm' => '1', 'mfa_code' => $codes[0]])->assertSessionHasErrors('reason_code');
        $this->post($url, ['status' => 'inactive', 'reason_code' => 'left_school', 'mfa_code' => $codes[0]])->assertSessionHasErrors('confirm');
        $this->post($url, ['status' => 'inactive', 'reason_code' => 'left_school', 'confirm' => '1'])->assertSessionHasErrors('mfa_code');
        $this->assertSame(0, $this->events($w));
        $this->post($url, ['status' => 'inactive', 'reason_code' => 'left_school', 'confirm' => '1', 'mfa_code' => $codes[0]])->assertSessionHasNoErrors();
        $this->assertSame('inactive', $this->inSchool($w['school'], fn () => $w['student']->fresh()->status));

        $withdraw = "/app/enrollments/{$w['enrollment']->id}/withdraw";
        $this->post($withdraw, ['ends_on' => '2026-09-30', 'reason_code' => 'left_school', 'confirm' => '1', 'mfa_code' => $codes[0]])->assertSessionHasErrors('mfa_code');
        $this->post($withdraw, ['ends_on' => '2026-09-30', 'reason_code' => 'left_school', 'confirm' => '1', 'mfa_code' => $codes[1]])->assertSessionHasNoErrors();
        $this->assertSame([2, 'withdrawn'], [$this->events($w), $this->inSchool($w['school'], fn () => $w['enrollment']->fresh()->status)]);

        $this->get("/app/students/{$w['student']->id}")->assertInertia(fn ($page) => $page
            ->where('lifecycleReasons.student_deactivated', ['left_school', 'transferred_out', 'completed_studies', 'duplicate_record', 'entered_in_error'])
            ->where('hasMfaFactor', true));
    }

    #[Test]
    public function the_api_needs_a_reason_and_a_fresh_code_but_no_confirmation_flag(): void
    {
        $w = $this->world();
        $this->enrollActiveMfaFactor($w['admin']);
        $codes = $this->issueRecoveryCodes($w['admin'], 2);
        $token = $w['admin']->createToken('t')->plainTextToken;
        $api = fn (string $path, array $body) => $this->withHeader('Authorization', 'Bearer '.$token)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("/api/v1/schools/{$w['school']->id}{$path}", $body);

        $api("/students/{$w['student']->id}/status", ['status' => 'inactive', 'mfa_code' => $codes[0]])->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['reason_code']]]);
        $api("/students/{$w['student']->id}/status", ['status' => 'inactive', 'reason_code' => 'duplicate_record'])->assertStatus(422);
        $api("/students/{$w['student']->id}/status", ['status' => 'inactive', 'reason_code' => 'duplicate_record', 'mfa_code' => $codes[0]])->assertOk();
        $api("/enrollments/{$w['enrollment']->id}/cancel", ['ends_on' => '2026-06-01', 'reason_code' => 'duplicate_placement', 'mfa_code' => $codes[1]])->assertOk();
        $this->assertSame(2, $this->events($w));
    }

    #[Test]
    public function the_lifecycle_record_is_append_only_and_checked(): void
    {
        $w = $this->world();
        app(StudentService::class)->changeStatus($w['student'], 'inactive', 'left_school', $w['admin'], $this->proof($w['admin']));
        $event = $this->inSchool($w['school'], fn () => StudentLifecycleEvent::query()->sole());
        $refused = function (callable $statement): string {
            try {
                DB::transaction($statement);

                return '';
            } catch (QueryException $e) {
                return $e->getMessage();
            }
        };

        $this->inSchool($w['school'], function () use ($event, $refused, $w) {
            $this->assertStringContainsString('permission denied', $refused(fn () => DB::table('student_lifecycle_events')->where('id', $event->id)->update(['reason_code' => 'entered_in_error'])));
            $this->assertStringContainsString('permission denied', $refused(fn () => DB::table('student_lifecycle_events')->where('id', $event->id)->delete()));
            $this->assertStringContainsString('student_lifecycle_events_reason_check', $refused(fn () => DB::table('student_lifecycle_events')->insert([
                'id' => (string) Str::uuid7(), 'school_id' => $w['school']->id, 'student_id' => $w['student']->id,
                'action' => 'student_deactivated', 'reason_code' => 'because', 'acted_by_user_id' => $w['admin']->id,
            ])));
        });
    }
}
