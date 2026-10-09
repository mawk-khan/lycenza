<?php

namespace Tests\Feature\Portal;

use App\Domain\Attendance\Application\AttendanceSubmissionService;
use App\Domain\Attendance\Application\Portal\GuardianAttendanceReadService;
use App\Domain\Identity\Application\AccountLinkService;
use App\Domain\Identity\Application\Portal\ActingGuardianResolver;
use App\Domain\Identity\Application\Portal\GuardianOffboardingService;
use App\Domain\Identity\Application\Staff\StaffAccessService;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Http\Middleware\EnsurePortalDevelopmentOnly;
use App\Models\User;
use App\Models\UserMfaFactor;
use App\Support\Portal\PortalUnavailableException;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesGuardianPortalFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Feature\Attendance\Concerns\CreatesAttendanceFixtures;
use Tests\Feature\Auth\Mfa\Concerns\CreatesMfaFixtures;
use Tests\TestCase;

/**
 * POR.2 (ADR 0070 §25): a linked Student's minimized Attendance through the
 * Guardian portal -- capability + ActingGuardian + live GuardianStudentScope +
 * current MFA + PortalAvailability; the active academic year only, bounded;
 * the same 404 for every inaccessible Student; one audit per read.
 */
class GuardianAttendancePortalTest extends TestCase
{
    use CreatesAttendanceFixtures, CreatesCommunicationFixtures, CreatesGuardianPortalFixtures, CreatesMfaFixtures, CreatesTenancyFixtures;

    /** A Monday in the fixture year (2026-06-01 .. 2027-03-31); the clock is pinned after it. */
    private const EARLIER_MONDAY = '2026-08-03';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-10 12:00:00'));
    }

    /**
     * The Guardian's child and a classmate in one Section, both with registers
     * on two Mondays; the Guardian holds a current MFA assurance.
     *
     * @return array<string, mixed>
     */
    private function world(): array
    {
        $w = $this->attendanceWorld();
        $p = $this->portalGuardian($w['school']);
        $enrollment = app(StudentEnrollmentService::class)->enroll($p['student'], $w['section'], '1', '2026-06-01', $w['actor']);
        $classmate = $this->enrollStudent($w['section'], '2', '2026-06-01', $w['actor']);
        foreach ([self::MONDAY => ['present', 'absent'], self::EARLIER_MONDAY => ['late', 'present']] as $date => [$mine, $theirs]) {
            $this->inSchool($w['school'], fn () => DB::transaction(fn () => app(AttendanceSubmissionService::class)->submit($w['school'], $w['entry']->id, $date,
                $this->registerPayload([$enrollment->id => $mine, $classmate->id => $theirs]), $w['actor'])));
        }
        $this->enrollActiveMfaFactor($p['user']);

        return [...$w, 'guardian' => $p, 'enrollment' => $enrollment, 'classmate' => $classmate];
    }

    private function asGuardian(User $user, $school, bool $mfa = true): static
    {
        $this->signInTo($user, $school);
        $mfa ? session(['mfa_verified_at' => now()->toIso8601String()]) : session()->forget('mfa_verified_at');

        return $this;
    }

    private function url(string $studentId, string $query = ''): string
    {
        return "/app/portal/attendance/students/{$studentId}".($query === '' ? '' : "?{$query}");
    }

    private function audits($school): Collection
    {
        return app(TenantContext::class)->withSchool($school, fn () => DB::table('school_audit_events')
            ->where('event_type', GuardianAttendanceReadService::AUDIT_EVENT)->get());
    }

    #[Test]
    public function a_guardian_sees_only_their_childs_minimized_attendance(): void
    {
        $w = $this->world();
        $student = $w['guardian']['student'];

        $this->asGuardian($w['guardian']['user'], $w['school'])->get($this->url($student->id))->assertOk()
            ->assertInertia(fn ($page) => $page->component('App/Portal/Attendance/Show')
                ->where('attendance.student.id', $student->id)
                ->where('attendance.window', ['from' => '2026-08-12', 'to' => '2026-09-10'])
                ->where('attendance.records', [['date' => self::MONDAY, 'periodStart' => '09:00', 'periodEnd' => '10:00', 'status' => 'present']]));

        // One audit: ids, window and a count -- never a status.
        $audit = $this->audits($w['school'])->sole();
        $metadata = json_decode($audit->metadata, true);
        $this->assertSame([
            'academicYearId' => $w['year']->id, 'accountLinkId' => $w['guardian']['link']->id, 'from' => '2026-08-12',
            'guardianId' => $w['guardian']['guardian']->id, 'recordCount' => 1, 'studentId' => $student->id, 'surface' => 'guardian_portal', 'to' => '2026-09-10',
        ], collect($metadata)->sortKeys()->all());
        $this->assertSame($w['guardian']['user']->id, $audit->actor_user_id);
        $this->assertStringNotContainsString('present', (string) $audit->metadata);
    }

    #[Test]
    public function the_window_is_the_active_year_only_bounded_and_clamped(): void
    {
        $w = $this->world();
        $this->asGuardian($w['guardian']['user'], $w['school']);
        $student = $w['guardian']['student']->id;

        // An explicit range reaches the earlier Monday.
        $this->get($this->url($student, 'from=2026-08-01&to=2026-08-31'))->assertInertia(fn ($page) => $page
            ->where('attendance.window', ['from' => '2026-08-01', 'to' => '2026-08-31'])->has('attendance.records', 2)
            ->where('attendance.records.0.date', self::MONDAY)->where('attendance.records.1.date', self::EARLIER_MONDAY));

        // Before the year starts / after today: clamped to the year start and to School-local today.
        $this->get($this->url($student, 'from=2025-01-01&to=2030-01-01'))->assertInertia(fn ($page) => $page
            ->where('attendance.window', ['from' => '2026-07-11', 'to' => '2026-09-10']));
        $this->get($this->url($student, 'from=2026-05-01&to=2026-06-10'))->assertInertia(fn ($page) => $page
            ->where('attendance.window', ['from' => '2026-06-01', 'to' => '2026-06-10']));

        // Entirely before the active year: nothing at all.
        $this->get($this->url($student, 'from=2026-01-01&to=2026-05-01'))->assertInertia(fn ($page) => $page
            ->where('attendance.window', null)->where('attendance.records', []));

        // A malformed range is one fixed validation error, never echoed.
        $response = $this->get($this->url($student, 'from=not-a-date'));
        $response->assertSessionHasErrors('range');
        $this->assertStringNotContainsString('not-a-date', json_encode(session('errors')?->getMessages()));
    }

    #[Test]
    public function no_active_year_shows_nothing(): void
    {
        $w = $this->world();
        app(TenantContext::class)->withSchool($w['school'], fn () => DB::table('academic_years')->where('id', $w['year']->id)->update(['status' => 'closed']));

        $this->asGuardian($w['guardian']['user'], $w['school'])->get($this->url($w['guardian']['student']->id))->assertOk()
            ->assertInertia(fn ($page) => $page->where('attendance.academicYear', null)->where('attendance.window', null)->where('attendance.records', []));
    }

    #[Test]
    public function every_inaccessible_student_is_the_same_404(): void
    {
        $w = $this->world();
        $g = $w['guardian'];
        $school = $w['school'];

        $nonLegal = $this->createStudent($school);
        $this->createStudentGuardianRelationship($nonLegal, $g['guardian'], ['is_legal_guardian' => false]);
        $withdrawn = $this->createStudent($school, ['status' => 'withdrawn']);
        $this->createStudentGuardianRelationship($withdrawn, $g['guardian'], ['is_legal_guardian' => true]);
        $revoked = $this->createStudent($school);
        $this->createStudentGuardianRelationship($revoked, $g['guardian'], ['is_legal_guardian' => true]);
        app(TenantContext::class)->withSchool($school, fn () => DB::table('student_guardian_relationships')->where('student_id', $revoked->id)->delete());
        $otherGuardians = $this->portalGuardian($school)['student'];
        $elsewhere = $this->createSchool();
        $otherSchools = $this->portalGuardian($elsewhere)['student'];

        $this->asGuardian($g['user'], $school);
        $bodies = [];
        foreach ([$w['classmate']->student_id, $nonLegal->id, $withdrawn->id, $revoked->id, $otherGuardians->id, $otherSchools->id, (string) Str::uuid7()] as $id) {
            $bodies[] = $this->get($this->url($id))->assertNotFound()->getContent();
        }
        $this->assertCount(1, array_unique($bodies), 'Unknown, classmate, non-legal, withdrawn, revoked, another Guardian\'s and another School\'s are indistinguishable.');
        $this->assertCount(0, $this->audits($school), 'A denied read writes no audit.');
        $this->get('/app/portal/attendance/students/not-a-uuid')->assertNotFound();
    }

    #[Test]
    public function the_chooser_lists_only_the_guardians_own_eligible_students(): void
    {
        $w = $this->world();
        $g = $w['guardian'];
        $this->asGuardian($g['user'], $w['school']);

        // One eligible Student: straight to that Student.
        $this->get('/app/portal/attendance')->assertRedirect($this->url($g['student']->id));

        $sibling = $this->createStudent($w['school'], ['first_name' => 'Zara']);
        $this->createStudentGuardianRelationship($sibling, $g['guardian'], ['is_legal_guardian' => true]);
        $notMine = $this->createStudent($w['school'], ['first_name' => 'Nobody']);
        $this->createStudentGuardianRelationship($notMine, $g['guardian'], ['is_legal_guardian' => false]);

        $this->get('/app/portal/attendance')->assertOk()->assertInertia(fn ($page) => $page->component('App/Portal/Attendance/Index')
            ->has('students', 2)->where('students', fn ($students) => collect($students)->pluck('id')->sort()->values()->all() === collect([$g['student']->id, $sibling->id])->sort()->values()->all()));
        $this->get($this->url($sibling->id))->assertOk()->assertInertia(fn ($page) => $page->where('attendance.records', []));

        // Removing one relationship leaves the other Student reachable; removing the last ends the portal.
        app(TenantContext::class)->withSchool($w['school'], fn () => DB::table('student_guardian_relationships')->where('student_id', $sibling->id)->delete());
        $this->get($this->url($sibling->id))->assertNotFound();
        $this->get($this->url($g['student']->id))->assertOk();
        app(TenantContext::class)->withSchool($w['school'], fn () => DB::table('student_guardian_relationships')->where('student_id', $g['student']->id)->update(['is_legal_guardian' => false]));
        $this->get($this->url($g['student']->id))->assertForbidden();
    }

    #[Test]
    public function mfa_needs_an_enrolled_factor_and_a_current_assurance(): void
    {
        $w = $this->world();
        $g = $w['guardian'];
        $url = $this->url($g['student']->id);

        $this->asGuardian($g['user'], $w['school'])->get($url)->assertOk();

        // Stale window (60 minutes by default) -> step-up.
        $this->travel(61)->minutes();
        $this->get($url)->assertStatus(401)->assertInertia(fn ($page) => $page->component('App/Platform/MfaRequired')->where('code', 'mfa_step_up_required'));
        $this->get('/app/portal/attendance')->assertStatus(401);

        // Re-verified -> allowed; the factor then reset (confirmed after the verification) -> step-up again.
        session(['mfa_verified_at' => now()->toIso8601String()]);
        $this->get($url)->assertOk();
        $this->travel(5)->minutes();
        UserMfaFactor::query()->where('user_id', $g['user']->id)->update(['confirmed_at' => now()]);
        $this->get($url)->assertStatus(401);

        // No factor at all -> enrolment required (403), whatever the session says.
        UserMfaFactor::query()->where('user_id', $g['user']->id)->delete();
        session(['mfa_verified_at' => now()->toIso8601String()]);
        $this->get($url)->assertForbidden()->assertInertia(fn ($page) => $page->where('code', 'mfa_required_not_enrolled'));

        // The inbox (no MFA by contract) still works.
        $this->get('/app/portal/communications')->assertOk();
    }

    #[Test]
    public function production_refuses_first_and_the_service_refuses_without_its_middleware(): void
    {
        $w = $this->world();
        $this->asGuardian($w['guardian']['user'], $w['school']);
        $this->withoutMiddleware(PreventRequestForgery::class);

        foreach (['production', 'staging'] as $environment) {
            $this->app['env'] = $environment;
            $this->get($this->url($w['guardian']['student']->id))->assertForbidden()->assertSee(PortalUnavailableException::MESSAGE);
            $this->get('/app/portal/attendance')->assertForbidden();
            $this->app['env'] = 'testing';
        }

        $this->withoutMiddleware([EnsurePortalDevelopmentOnly::class]);
        $this->app['env'] = 'production';
        $this->get($this->url($w['guardian']['student']->id))->assertForbidden()->assertSee(PortalUnavailableException::MESSAGE);
        $this->app['env'] = 'testing';
        $this->assertCount(0, $this->audits($w['school']));
    }

    #[Test]
    public function staff_and_guardian_attendance_authorities_never_cross(): void
    {
        $w = $this->world();

        // Staff Attendance authority never opens the portal.
        $staff = $w['actor'];
        $this->enrollActiveMfaFactor($staff);
        $this->asGuardian($staff, $w['school'])->get($this->url($w['guardian']['student']->id))->assertForbidden();

        // The portal capability never opens staff Attendance.
        $this->asGuardian($w['guardian']['user'], $w['school'])->get('/app/attendance')->assertForbidden();
    }

    #[Test]
    public function dual_persona_lifecycles_stay_independent_for_attendance(): void
    {
        $w = $this->world();
        $school = $w['school'];
        $admin = $this->createUser();
        $this->assignSchoolRole($this->createMembership($admin, $school), 'school_admin');

        $user = $this->createUser();
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'principal');
        $p = $this->portalGuardian($school, user: $user, membership: $membership);
        $this->enrollActiveMfaFactor($user);

        // The Guardian's own child only -- principal authority does not widen the portal.
        $this->asGuardian($user, $school)->get($this->url($p['student']->id))->assertOk();
        $this->get($this->url($w['guardian']['student']->id))->assertNotFound();

        app(StaffAccessService::class)->suspend($school, $admin, $membership->id);
        $this->get($this->url($p['student']->id))->assertOk();
        $this->get('/app/attendance')->assertForbidden();

        app(StaffAccessService::class)->reactivate($school, $admin, $membership->id, ['principal']);
        app(GuardianOffboardingService::class)->offboard($school, $this->portalAdmin($school), $p['guardian']);
        $this->get($this->url($p['student']->id))->assertForbidden();
        $this->get('/app/students')->assertOk();
    }

    #[Test]
    public function a_stale_url_after_a_school_switch_or_off_boarding_is_denied(): void
    {
        $w = $this->world();
        $g = $w['guardian'];
        $b = $this->createSchool();
        $this->portalGuardian($b, user: $g['user']);

        $this->asGuardian($g['user'], $w['school'])->get($this->url($g['student']->id))->assertOk();
        $this->post("/app/schools/{$b->id}/activate");
        $this->get($this->url($g['student']->id))->assertNotFound();

        $this->post("/app/schools/{$w['school']->id}/activate");
        app(AccountLinkService::class)->unlinkGuardian($w['school'], $g['guardian'], $this->portalAdmin($w['school']));
        $this->get($this->url($g['student']->id))->assertForbidden();
    }

    #[Test]
    public function each_read_service_method_refuses_outside_development_on_its_own(): void
    {
        $w = $this->world();
        $g = $w['guardian'];
        $guardian = app(ActingGuardianResolver::class)->require($g['user'], $w['school']);
        $service = app(GuardianAttendanceReadService::class);

        $this->app['env'] = 'production';
        try {
            $this->assertThrows(fn () => $service->students($w['school'], $guardian), PortalUnavailableException::class);
            $this->assertThrows(fn () => $service->history($w['school'], $guardian, $g['user'], $g['student']->id), PortalUnavailableException::class);
        } finally {
            $this->app['env'] = 'testing';
        }
        $this->assertCount(0, $this->audits($w['school']));
    }

    #[Test]
    public function the_window_uses_the_schools_own_calendar_day(): void
    {
        $w = $this->world();
        app(TenantContext::class)->withSchool($w['school'], fn () => DB::table('schools')->where('id', $w['school']->id)->update(['timezone' => 'Asia/Kolkata']));
        $w['school']->refresh();
        // 2026-09-10 12:00 UTC is 17:30 in Kolkata; at 20:00 UTC it is already 2026-09-11 there.
        $this->asGuardian($w['guardian']['user'], $w['school']);
        $this->get($this->url($w['guardian']['student']->id, 'from=2026-09-10&to=2026-09-10'))->assertInertia(fn ($page) => $page
            ->where('attendance.window', ['from' => '2026-09-10', 'to' => '2026-09-10']));

        $this->travelTo(CarbonImmutable::parse('2026-09-10 20:00:00'));
        session(['mfa_verified_at' => now()->toIso8601String()]);
        $this->get($this->url($w['guardian']['student']->id, 'from=2026-09-11'))->assertInertia(fn ($page) => $page
            ->where('attendance.window', ['from' => '2026-09-11', 'to' => '2026-09-11']));
    }
}
