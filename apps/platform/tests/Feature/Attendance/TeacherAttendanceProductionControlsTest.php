<?php

namespace Tests\Feature\Attendance;

use App\Domain\Attendance\Application\TeacherAttendanceReadAudit;
use App\Domain\Attendance\Infrastructure\AttendanceRecord;
use App\Domain\Attendance\Infrastructure\AttendanceSession;
use App\Domain\Identity\Application\Staff\StaffAccessService;
use App\Domain\TeachingAssignments\Application\TeachingAssignmentService;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Models\UserMfaFactor;
use App\Support\Auth\Mfa\MfaAdminResetService;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\CapturesStructuredLogs;
use Tests\Concerns\CreatesTeacherAttendanceFixtures;
use Tests\Concerns\CreatesTeacherDeliveryFixtures;
use Tests\Concerns\CreatesTeachingAssignmentFixtures;
use Tests\Feature\Attendance\Concerns\CreatesAttendanceFixtures;
use Tests\Feature\Auth\Mfa\Concerns\CreatesMfaFixtures;
use Tests\TestCase;

/**
 * E33 / TCH-L1 (APPROVED WITH CONDITIONS, 7 October 2026; ADR 0063 section
 * 43): the production controls for teacher Attendance -- MFA on every
 * session page and post (never a substitute for ownership), an audit row for
 * every successful owned read (identifiers and counts, never Student data),
 * the bearer-token surface refused outside development, revocation ending
 * future authority while keeping history, and per-School authority for a
 * multi-School identity.
 */
class TeacherAttendanceProductionControlsTest extends TestCase
{
    use CapturesStructuredLogs, CreatesAttendanceFixtures, CreatesMfaFixtures, CreatesTeacherAttendanceFixtures, CreatesTeacherDeliveryFixtures, CreatesTeachingAssignmentFixtures;

    private const string DATE = '2026-09-07';

    private function web(array $w, User $user, bool $enrolled = true, bool $assured = true): static
    {
        if ($enrolled && ! $user->mfaFactors()->where('status', 'active')->exists()) {
            $this->enrollActiveMfaFactor($user);
        }
        $assured ? session(['mfa_verified_at' => now()->toIso8601String()]) : session()->forget('mfa_verified_at');

        return $this->actingAs($user)->withHeader('X-School-Id', $w['school']->id);
    }

    private function bearer(User $user): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withHeader('Authorization', 'Bearer '.$user->createToken('test-device')->plainTextToken);
    }

    /** @return list<SchoolAuditEvent> */
    private function audits(array $w, string $type): array
    {
        return $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()->where('event_type', $type)->orderBy('occurred_at')->get()->all());
    }

    private function readAuditCount(array $w): int
    {
        return $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()->where('event_type', 'like', 'attendance.teacher.%')->count());
    }

    /** @return array{0: array<string, mixed>, 1: User, 2: AttendanceSession} a teacher owning Section A x the Offering, with one register of theirs */
    private function ownedWorld(): array
    {
        $w = $this->teacherAttendanceWorld();
        [$user, $employee, $membership] = $this->teacher($w);
        $w['employee'] = $employee;
        $w['membership'] = $membership;
        $w['assignment'] = $this->own($w, $employee, '2026-06-01');

        return [$w, $user, $this->adminRegister($w, $w['entry'], self::DATE)];
    }

    #[Test]
    public function every_session_page_and_post_needs_an_enrolled_factor_and_current_assurance(): void
    {
        [$w, $user, $mine] = $this->ownedWorld();
        $record = $this->inSchool($w['school'], fn () => $mine->records()->firstOrFail());
        $pages = ['/app/my-attendance', '/app/my-attendance/take?attendance_date='.self::DATE, "/app/my-attendance/{$mine->id}"];

        foreach ($pages as $page) {
            $this->web($w, $user, enrolled: false)->get($page)->assertForbidden()
                ->assertInertia(fn (AssertableInertia $p) => $p->component('App/Platform/MfaRequired')->where('code', 'mfa_required_not_enrolled'));
        }
        foreach ($pages as $page) {
            $this->web($w, $user, assured: false)->get($page)->assertUnauthorized()
                ->assertInertia(fn (AssertableInertia $p) => $p->component('App/Platform/MfaRequired')->where('code', 'mfa_step_up_required'));
        }

        $this->web($w, $user, assured: false)->post('/app/my-attendance', [
            'timetable_entry_id' => $w['entry']->id, 'attendance_date' => '2026-09-14', 'records' => $this->allPresent($w['studentsA']),
        ])->assertUnauthorized();
        $this->web($w, $user, assured: false)->post("/app/my-attendance/records/{$record->id}/correct", ['expected_status' => 'present', 'new_status' => 'late'])
            ->assertUnauthorized();

        $this->assertSame(1, $this->inSchool($w['school'], fn () => AttendanceSession::query()->count()), 'nothing was submitted without MFA');
        $this->assertSame('present', $this->inSchool($w['school'], fn () => AttendanceRecord::query()->findOrFail($record->id))->status);
        $this->assertSame(0, $this->readAuditCount($w), 'a refused read records no access');

        // With MFA the same teacher operates normally.
        $this->web($w, $user)->get("/app/my-attendance/{$mine->id}")->assertOk();
        $this->web($w, $user)->post("/app/my-attendance/records/{$record->id}/correct", ['expected_status' => 'present', 'new_status' => 'late'])
            ->assertRedirect("/app/my-attendance/{$mine->id}");
    }

    #[Test]
    public function mfa_never_substitutes_for_the_capability_identity_or_ownership(): void
    {
        [$w, , $mine] = $this->ownedWorld();
        $theirs = $this->adminRegister($w, $w['entryB'], self::DATE);

        // Capability missing: refused before MFA is even consulted.
        [$noRole] = $this->teacher($w, roleKey: null);
        $response = $this->web($w, $noRole)->get('/app/my-attendance')->assertForbidden();
        $this->assertStringNotContainsString('MfaRequired', (string) $response->getContent(), 'the capability refusal comes first');

        // Capability + MFA, no assignment: nothing owned.
        [$unrelated] = $this->teacher($w);
        $this->web($w, $unrelated)->get('/app/my-attendance')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->has('sessions.data', 0));
        $this->web($w, $unrelated)->get("/app/my-attendance/{$mine->id}")->assertNotFound();
        $this->web($w, $unrelated)->post('/app/my-attendance', [
            'timetable_entry_id' => $w['entry']->id, 'attendance_date' => '2026-09-14', 'records' => $this->allPresent($w['studentsA']),
        ])->assertNotFound();

        // Capability + MFA, not an eligible Employee: refused.
        [$unlinked] = $this->teacher($w, linked: false);
        $this->web($w, $unlinked)->get('/app/my-attendance/take')->assertForbidden();

        // An MFA reset (factor revoked) cannot ride on an older sign-in assurance.
        $owner = User::query()->findOrFail($w['employee']->user_id);
        $this->web($w, $owner)->get("/app/my-attendance/{$theirs->id}")->assertNotFound();
        UserMfaFactor::query()->where('user_id', $owner->id)->update(['status' => 'revoked']);
        $this->web($w, $owner, enrolled: false)->get("/app/my-attendance/{$mine->id}")->assertForbidden()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('App/Platform/MfaRequired'));
    }

    #[Test]
    public function every_successful_owned_read_is_audited_with_identifiers_only(): void
    {
        [$w, $user, $mine] = $this->ownedWorld();

        $this->web($w, $user)->get('/app/my-attendance?attendance_date='.self::DATE)->assertOk();
        $this->web($w, $user)->get('/app/my-attendance/take?attendance_date='.self::DATE.'&timetable_entry_id='.$w['entry']->id)->assertOk();
        $this->web($w, $user)->get("/app/my-attendance/{$mine->id}")->assertOk();

        $base = ['actingEmployeeId' => $w['employee']->id, 'surface' => 'web'];
        $listed = $this->audits($w, TeacherAttendanceReadAudit::SESSIONS_LISTED);
        $this->assertCount(1, $listed);
        $this->assertEquals($base + ['attendanceDate' => self::DATE, 'page' => 1, 'resultCount' => 1], $listed[0]->metadata);
        $this->assertSame($user->id, $listed[0]->actor_user_id);

        $this->assertEquals($base + ['attendanceDate' => self::DATE, 'classCount' => 1], $this->audits($w, TeacherAttendanceReadAudit::CLASSES_LISTED)[0]->metadata);
        $this->assertEquals($base + [
            'timetableEntryId' => $w['entry']->id, 'sectionId' => $w['section']->id, 'subjectOfferingId' => $w['offering']->id,
            'attendanceDate' => self::DATE, 'memberCount' => 2,
        ], $this->audits($w, TeacherAttendanceReadAudit::ROSTER_VIEWED)[0]->metadata);

        $viewed = $this->audits($w, TeacherAttendanceReadAudit::SESSION_VIEWED);
        $this->assertEquals($base + [
            'attendanceSessionId' => $mine->id, 'sectionId' => $w['section']->id, 'subjectOfferingId' => $w['offering']->id,
            'attendanceDate' => self::DATE, 'recordCount' => 2,
        ], $viewed[0]->metadata);
        $this->assertSame([AttendanceSession::class, $mine->id], [$viewed[0]->subject_type, $viewed[0]->subject_id]);

        // No Student identifier, name or status is copied into any read audit.
        $metadata = json_encode(array_map(fn (SchoolAuditEvent $e) => $e->metadata, $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()->where('event_type', 'like', 'attendance.teacher.%')->get()->all())));
        foreach ($w['studentsA'] as $enrollment) {
            $this->assertStringNotContainsString($enrollment->id, $metadata);
            $this->assertStringNotContainsString($enrollment->student_id, $metadata);
        }
        $this->assertStringNotContainsString('present', $metadata);
    }

    #[Test]
    public function a_refused_or_concealed_read_records_no_access(): void
    {
        [$w, $user] = $this->ownedWorld();
        $theirs = $this->adminRegister($w, $w['entryB'], self::DATE);
        $other = $this->teacherAttendanceWorld();
        $elsewhere = $this->adminRegister($other, $other['entry'], self::DATE);

        $this->web($w, $user)->get("/app/my-attendance/{$theirs->id}")->assertNotFound();
        $this->web($w, $user)->get("/app/my-attendance/{$elsewhere->id}")->assertNotFound();
        $this->web($w, $user)->get('/app/my-attendance/take?attendance_date='.self::DATE.'&timetable_entry_id='.$w['entryB']->id)
            ->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->has('roster', 0));

        $this->assertCount(0, $this->audits($w, TeacherAttendanceReadAudit::SESSION_VIEWED));
        $this->assertCount(0, $this->audits($w, TeacherAttendanceReadAudit::ROSTER_VIEWED));
        $this->assertSame(0, $this->inSchool($other['school'], fn () => SchoolAuditEvent::query()->where('event_type', 'like', 'attendance.teacher.%')->count()));
    }

    #[Test]
    public function the_bearer_surface_is_development_only_and_refused_before_any_work(): void
    {
        [$w, $user, $mine] = $this->ownedWorld();
        $base = "/api/v1/schools/{$w['school']->id}/my";

        // Development/testing with the flag: the owned API works, and its reads are audited too.
        $this->bearer($user)->getJson("{$base}/attendance-sessions/{$mine->id}")->assertOk();
        $this->assertSame('api', $this->audits($w, TeacherAttendanceReadAudit::SESSION_VIEWED)[0]->metadata['surface']);

        $refused = fn () => $this->bearer($user)->getJson("{$base}/attendance-sessions/{$mine->id}")
            ->assertForbidden()->assertExactJson(['error' => [
                'code' => 'TEACHER_ATTENDANCE_API_UNAVAILABLE',
                'message' => 'Teacher Attendance is available only in the signed-in web app with multi-factor authentication.',
                'status' => 403,
            ]]);

        // The flag alone off: refused.
        config(['attendance.teacher_api_development_enabled' => false]);
        $refused();
        $this->bearer($user)->postJson("{$base}/attendance-sessions", [
            'timetable_entry_id' => $w['entry']->id, 'attendance_date' => '2026-09-14', 'records' => $this->allPresent($w['studentsA']),
        ])->assertForbidden()->assertJsonPath('error.code', 'TEACHER_ATTENDANCE_API_UNAVAILABLE');

        // Production with the flag off: refused.
        $this->app['env'] = 'production';
        try {
            $refused();
        } finally {
            $this->app['env'] = 'testing';
        }

        // The flag on in a production environment: still refused (double guard).
        config(['attendance.teacher_api_development_enabled' => true]);
        $this->app['env'] = 'production';
        try {
            $refused();
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertSame(1, $this->inSchool($w['school'], fn () => AttendanceSession::query()->count()));
        $this->assertCount(1, $this->audits($w, TeacherAttendanceReadAudit::SESSION_VIEWED), 'a refused API call reads and records nothing');
    }

    #[Test]
    public function an_ended_assignment_ends_future_authority_and_keeps_the_history(): void
    {
        [$w, $user, $mine] = $this->ownedWorld();
        $this->web($w, $user)->post('/app/my-attendance', [
            'timetable_entry_id' => $w['entry']->id, 'attendance_date' => '2026-09-14', 'records' => $this->allPresent($w['studentsA']),
        ])->assertRedirect()->assertSessionHasNoErrors();

        // Ended (reassigned) on 2026-09-15: earlier registers stay owned and recorded; later dates are not.
        app(TeachingAssignmentService::class)->end($w['school'], $w['assignment']->id, '2026-09-15', 'reassigned', $w['admin']);
        $this->web($w, $user)->post('/app/my-attendance', [
            'timetable_entry_id' => $w['entry']->id, 'attendance_date' => '2026-09-21', 'records' => $this->allPresent($w['studentsA']),
        ])->assertSessionHasErrors('timetable_entry_id');
        $this->assertSame(2, $this->inSchool($w['school'], fn () => AttendanceSession::query()->count()), 'history is kept; nothing new after the end');
        $this->web($w, $user)->get("/app/my-attendance/{$mine->id}")->assertOk();

        // Revoking the role ends every future read and write at once.
        $schoolAdmin = $this->createUser();
        $this->assignSchoolRole($this->createMembership($schoolAdmin, $w['school']), 'school_admin');
        app(StaffAccessService::class)->revokeRole($w['school'], $schoolAdmin, $w['membership']->id, 'teacher');
        $this->web($w, $user)->get("/app/my-attendance/{$mine->id}")->assertForbidden();
        $this->assertSame(2, $this->inSchool($w['school'], fn () => AttendanceSession::query()->count()));
    }

    #[Test]
    public function a_multi_school_identity_is_authorised_in_each_school_independently(): void
    {
        [$w, $user, $mine] = $this->ownedWorld();
        $other = $this->teacherAttendanceWorld();
        $elsewhere = $this->adminRegister($other, $other['entry'], self::DATE);

        // The same person is a teacher and an eligible Employee in the second School -- but owns nothing there.
        $this->assignSchoolRole($this->createMembership($user, $other['school']), 'teacher');
        $employee = $this->createEmployee($other['school'], ['user_id' => $user->id]);
        $this->createEmploymentRecord($employee, ['starts_on' => '2026-01-01', 'ends_on' => null, 'status' => 'active']);

        $this->web($other, $user)->get('/app/my-attendance')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->has('sessions.data', 0));
        $this->web($other, $user)->get("/app/my-attendance/{$elsewhere->id}")->assertNotFound();
        $this->web($other, $user)->get("/app/my-attendance/{$mine->id}")->assertNotFound();

        // Back in the first School the original assignment still governs.
        $this->web($w, $user)->get("/app/my-attendance/{$mine->id}")->assertOk();
        $this->web($w, $user)->get("/app/my-attendance/{$elsewhere->id}")->assertNotFound();
    }

    #[Test]
    public function owned_reads_and_writes_log_no_student_data(): void
    {
        [$w, $user, $mine] = $this->ownedWorld();
        $names = $this->inSchool($w['school'], fn () => collect($w['studentsA'])->map(fn ($e) => $e->fresh()->student()->firstOrFail()->first_name)->all());
        $this->captureLogs();

        $this->web($w, $user)->get('/app/my-attendance/take?attendance_date=2026-09-14&timetable_entry_id='.$w['entry']->id)->assertOk();
        $this->web($w, $user)->get("/app/my-attendance/{$mine->id}")->assertOk();
        $this->web($w, $user)->post('/app/my-attendance', [
            'timetable_entry_id' => $w['entry']->id, 'attendance_date' => '2026-09-14', 'records' => $this->allPresent($w['studentsA']),
        ])->assertRedirect();
        $this->web($w, $user)->get("/app/my-attendance/{$this->bogusUuid()}")->assertNotFound();

        foreach ([...$names, ...array_map(fn ($e) => $e->id, $w['studentsA'])] as $value) {
            $this->assertStringNotContainsString((string) $value, $this->capturedOutput());
        }
    }

    private function bogusUuid(): string
    {
        return '01890000-0000-7000-8000-000000000000';
    }

    /** The real two-stage sign-in (password, then a TOTP or recovery code) -- the only way assurance is earned. */
    private function signIn(array $w, User $user, string $code): void
    {
        $user->forceFill(['password' => Hash::make('teacher-password-1')])->save();
        auth()->logout();
        $this->flushSession();
        $this->post('/login', ['email' => $user->email, 'password' => 'teacher-password-1'])->assertRedirect('/login/mfa');
        $this->post('/login/mfa', ['code' => $code])->assertRedirect('/app');
        $this->withHeader('X-School-Id', $w['school']->id);
    }

    #[Test]
    public function anonymous_and_expired_assurance_are_refused(): void
    {
        [$w, $user, $mine] = $this->ownedWorld();

        $this->withHeader('X-School-Id', $w['school']->id)->get('/app/my-attendance')->assertRedirect('/login');
        $this->withHeader('X-School-Id', $w['school']->id)->get("/app/my-attendance/{$mine->id}")->assertRedirect('/login');

        $this->enrollActiveMfaFactor($user);
        $this->travel(-((int) config('mfa.assurance_window_minutes') + 1))->minutes();
        $stale = now()->toIso8601String();
        $this->travelBack();
        $this->actingAs($user)->withSession(['mfa_verified_at' => $stale])->withHeader('X-School-Id', $w['school']->id)
            ->get("/app/my-attendance/{$mine->id}")->assertUnauthorized();
        $this->assertSame(0, $this->readAuditCount($w));
    }

    #[Test]
    public function an_mfa_reset_needs_a_new_sign_in_and_never_bypasses_ownership(): void
    {
        [$w, $user, $mine] = $this->ownedWorld();
        $theirs = $this->adminRegister($w, $w['entryB'], self::DATE);
        $this->travel(-10)->minutes();
        $this->enrollActiveMfaFactor($user);
        $this->travelBack();

        // Assurance earned a few minutes ago with the old factor -- still inside the window.
        $earlier = now()->subMinutes(5)->toIso8601String();
        $this->actingAs($user)->withSession(['mfa_verified_at' => $earlier])->withHeader('X-School-Id', $w['school']->id)
            ->get("/app/my-attendance/{$mine->id}")->assertOk();

        // An administrative reset revokes the factor: refused at once (not enrolled).
        app(MfaAdminResetService::class)->reset($this->createUser(), $user);
        $this->get("/app/my-attendance/{$mine->id}")->assertForbidden();

        // Re-enrolling a new factor does not revive the old assurance: a new sign-in is required.
        $secret = app(Google2FA::class)->generateSecretKey();
        $this->enrollActiveMfaFactor($user, $secret);
        $this->get("/app/my-attendance/{$mine->id}")->assertUnauthorized()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('App/Platform/MfaRequired')->where('code', 'mfa_step_up_required'));

        // After a real sign-in with the new factor: the owned register only; ownership still decides.
        $this->signIn($w, $user, $this->currentTotpCodeFor($secret));
        $this->get("/app/my-attendance/{$mine->id}")->assertOk();
        $this->get("/app/my-attendance/{$theirs->id}")->assertNotFound();
    }

    #[Test]
    public function a_recovery_code_sign_in_is_ordinary_assurance_and_still_needs_ownership(): void
    {
        [$w, , $mine] = $this->ownedWorld();
        [$unrelated] = $this->teacher($w);
        $this->enrollActiveMfaFactor($unrelated);
        [$code] = $this->issueRecoveryCodes($unrelated, 1);

        $this->signIn($w, $unrelated, $code);
        $this->get('/app/my-attendance')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->has('sessions.data', 0));
        $this->get("/app/my-attendance/{$mine->id}")->assertNotFound();
        $this->assertCount(0, $this->audits($w, TeacherAttendanceReadAudit::SESSION_VIEWED));
    }
}
