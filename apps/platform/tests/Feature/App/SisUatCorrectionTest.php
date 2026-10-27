<?php

namespace Tests\Feature\App;

use App\Domain\Guardians\Application\GuardianContactService;
use App\Domain\Guardians\Infrastructure\ContactType;
use App\Domain\Students\Application\Exceptions\InvalidEnrollmentDateRangeException;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Infrastructure\StudentLifecycleEvent;
use App\Http\Middleware\RequireSchoolContext;
use App\Models\SchoolAuditEvent;
use App\Support\Dates\CalendarDate;
use App\Support\Http\WebDomainRefusalResponder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Attendance\Concerns\CreatesAttendanceFixtures;
use Tests\Feature\Auth\Mfa\Concerns\CreatesMfaFixtures;
use Tests\TestCase;

/**
 * SIS 10.2: the five SIS 10.1 final-UAT findings, pinned.
 *
 * - D-1: a Student status change confirms the state it RESULTED in (the
 *   service's committed row), never the stale pre-change model.
 * - F-1: an Inertia visit is a browser request whatever its Accept header
 *   says, so a governed domain refusal never becomes a 500; API routes keep
 *   the JSON envelope.
 * - F-2: placement date refusals write dates as people read them.
 * - F-3: the current placement's roll-number editor opens once (the history
 *   row of the same placement never opens a second copy).
 * - F-4: Rollover Create's year options show readable dates.
 */
class SisUatCorrectionTest extends TestCase
{
    use CreatesAttendanceFixtures, CreatesMfaFixtures;

    // --- D-1 ----------------------------------------------------------------------------------

    #[Test]
    public function a_status_change_confirms_the_state_it_resulted_in_both_ways(): void
    {
        $w = $this->attendanceWorld();
        $admin = $this->createUserWithCapabilities($w['school'], ['students.view', 'students.manage']);
        $student = $this->createStudent($w['school']);
        $this->enrollActiveMfaFactor($admin);
        $codes = $this->issueRecoveryCodes($admin, 4);
        $this->actingAs($admin)->withSession([RequireSchoolContext::SESSION_KEY => $w['school']->id]);
        $url = "/app/students/{$student->id}/status";
        $state = fn () => $this->inSchool($w['school'], fn () => [
            $student->fresh()->status,
            StudentLifecycleEvent::query()->where('student_id', $student->id)->latest('id')->value('action'),
            SchoolAuditEvent::query()->where('event_type', 'student.status_changed')->where('subject_id', $student->id)->latest('id')->first()?->metadata['newStatus'] ?? null,
        ]);

        $this->post($url, ['status' => 'inactive', 'reason_code' => 'left_school', 'confirm' => '1', 'mfa_code' => $codes[0]])
            ->assertSessionHasNoErrors()->assertRedirect("/app/students/{$student->id}")
            ->assertSessionHas('flash', 'Student marked inactive.');
        $this->assertSame(['inactive', 'student_deactivated', 'inactive'], $state());

        $this->post($url, ['status' => 'active', 'reason_code' => 'returned_to_school', 'confirm' => '1', 'mfa_code' => $codes[1]])
            ->assertSessionHasNoErrors()->assertSessionHas('flash', 'Student marked active.');
        $this->assertSame(['active', 'student_reactivated', 'active'], $state());
    }

    #[Test]
    public function a_refused_status_change_never_confirms_success(): void
    {
        $w = $this->attendanceWorld();
        $admin = $this->createUserWithCapabilities($w['school'], ['students.view', 'students.manage']);
        $student = $this->createStudent($w['school']);
        $this->enrollActiveMfaFactor($admin);
        $codes = $this->issueRecoveryCodes($admin, 2);
        $this->actingAs($admin)->withSession([RequireSchoolContext::SESSION_KEY => $w['school']->id]);
        $url = "/app/students/{$student->id}/status";

        // A wrong code, then an unchanged status: refused, no success message, nothing recorded.
        $this->post($url, ['status' => 'inactive', 'reason_code' => 'left_school', 'confirm' => '1', 'mfa_code' => '000000'])
            ->assertSessionHasErrors('mfa_code')->assertSessionMissing('flash');
        $this->post($url, ['status' => 'active', 'reason_code' => 'returned_to_school', 'confirm' => '1', 'mfa_code' => $codes[0]])
            ->assertSessionHasErrors('status')->assertSessionMissing('flash');
        $this->assertSame(['active', 0], $this->inSchool($w['school'], fn () => [$student->fresh()->status, StudentLifecycleEvent::query()->where('student_id', $student->id)->count()]));

        // Another School's Student is unknown here.
        $foreign = $this->createStudent($this->createSchool());
        $this->post("/app/students/{$foreign->id}/status", ['status' => 'inactive', 'reason_code' => 'left_school', 'confirm' => '1', 'mfa_code' => $codes[1]])
            ->assertNotFound();
    }

    // --- F-1 ----------------------------------------------------------------------------------

    #[Test]
    public function an_inertia_refusal_is_governed_with_or_without_an_accept_header_and_the_api_keeps_its_envelope(): void
    {
        $school = $this->createSchool();
        $guardian = $this->createGuardian($school);
        $this->createGuardianContact($guardian, ContactType::Mobile, '+919800000001', ['is_primary' => true]);
        $spare = $this->createGuardianContact($guardian, ContactType::Mobile, '+919800000002');
        app(GuardianContactService::class)->deactivate($this->inSchool($school, fn () => $spare->fresh()));
        $message = 'An inactive contact cannot be made primary. Reactivate it first, then make it primary.';
        $user = $this->createUserWithCapabilities($school, ['guardians.view', 'guardians.manage']);
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
        $inertia = ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'];

        // The real client (Accept: text/html): the form error the shared layout shows.
        $this->withHeaders([...$inertia, 'Accept' => 'text/html, application/xhtml+xml'])->post("/app/contacts/{$spare->id}/primary")
            ->assertRedirect()->assertSessionHasErrors([WebDomainRefusalResponder::ERROR_KEY => $message]);
        // A crafted one without Accept: still the governed refusal, never a 500.
        $this->flushHeaders();
        $this->withHeaders([...$inertia, 'Accept' => ''])->post("/app/contacts/{$spare->id}/primary")
            ->assertStatus(422)->assertJsonPath('errors.'.WebDomainRefusalResponder::ERROR_KEY.'.0', $message);
        $this->assertFalse($this->inSchool($school, fn () => $spare->fresh()->is_primary));

        // An API route stays an API refusal, even if it claims to be Inertia.
        $this->flushHeaders();
        $token = $this->createUserWithCapabilities($school, ['guardians.view', 'guardians.manage'])->createToken('t')->plainTextToken;
        $this->withHeaders([...$inertia, 'Accept' => '', 'Authorization' => "Bearer {$token}", 'Idempotency-Key' => (string) Str::uuid()])
            ->post("/api/v1/schools/{$school->id}/guardian-contacts/{$spare->id}/primary")
            ->assertStatus(422)->assertJsonPath('error.code', 'GUARDIAN_CONTACT_INACTIVE');
    }

    // --- F-2 ----------------------------------------------------------------------------------

    #[Test]
    public function placement_date_refusals_write_dates_as_people_read_them(): void
    {
        $this->assertSame(['1 Apr 2026', '31 Mar 2027', '9 Jan 2027'], [
            CalendarDate::human('2026-04-01'), CalendarDate::human(CarbonImmutable::parse('2027-03-31')), CalendarDate::human(Carbon::parse('2027-01-09')),
        ]);

        $w = $this->attendanceWorld(); // year 1 Jun 2026 – 31 Mar 2027
        $admin = $this->createUserWithCapabilities($w['school'], ['students.view', 'enrollments.view', 'enrollments.manage']);
        $this->actingAs($admin)->withSession([RequireSchoolContext::SESSION_KEY => $w['school']->id]);
        $range = "The start date must fall within Academic Year {$w['year']->name} (1 Jun 2026 to 31 Mar 2027).";
        foreach (['2026-05-31', '2027-04-01'] as $outside) {
            $student = $this->createStudent($w['school']);
            $this->post("/app/students/{$student->id}/enrollments", ['section_id' => $w['section']->id, 'roll_number' => '9', 'starts_on' => $outside])
                ->assertSessionHasErrors(['starts_on' => $range]);
        }

        // The same refusal (same code) through the service; the rule itself is unchanged.
        $student = $this->createStudent($w['school']);
        try {
            $this->inSchool($w['school'], fn () => app(StudentEnrollmentService::class)->enroll($student, $w['section'], '9', '2027-04-01', $admin));
            $this->fail('An out-of-year start must be refused.');
        } catch (InvalidEnrollmentDateRangeException $e) {
            $this->assertSame(['INVALID_ENROLLMENT_DATE_RANGE', 422, $range], [$e->errorCode(), $e->getStatusCode(), $e->getMessage()]);
        }

        // Every placement date refusal goes through the shared formatter.
        foreach (['StudentEnrollmentService', 'StudentSubjectEnrollmentService'] as $service) {
            $source = (string) file_get_contents(app_path("Domain/Students/Application/{$service}.php"));
            preg_match_all('/new InvalidEnrollmentDateRangeException\((.*?)\);/s', $source, $m);
            foreach ($m[1] as $text) {
                $this->assertDoesNotMatchRegularExpression('/toDateString\(\)|\{\$(startsOn|yearStart|yearEnd|endedOn)\}/', $text, "{$service}: a refusal date goes through CalendarDate::human()");
            }
        }
    }

    // --- F-3 / F-4 ------------------------------------------------------------------------------

    #[Test]
    public function the_current_placement_has_one_roll_number_editor(): void
    {
        $page = (string) file_get_contents(resource_path('js/Pages/App/Students/Show.vue'));
        $this->assertSame(1, substr_count($page, 'v-if="rollTarget === currentEnrollment.id"'), 'the Current placement block owns its editor');
        $this->assertSame(1, substr_count($page, 'v-if="rollTarget === e.id && !isCurrentPlacement(e)"'), 'a history row never opens a second copy');
        $this->assertStringContainsString('return props.currentEnrollment?.id === e.id;', $page, 'decided by placement id, not position');

        // The page still gives the current placement and its history row the same id to compare.
        $w = $this->attendanceWorld();
        $admin = $this->createUserWithCapabilities($w['school'], ['students.view', 'enrollments.view', 'enrollments.manage']);
        $enrollment = $this->enrollStudent($w['section'], '1', '2026-06-01');
        $this->actingAs($admin)->withSession([RequireSchoolContext::SESSION_KEY => $w['school']->id]);
        $this->get("/app/students/{$enrollment->student_id}")->assertOk()
            ->assertInertia(fn ($p) => $p->where('currentEnrollment.id', $enrollment->id)->where('enrollmentHistory.0.id', $enrollment->id)->has('enrollmentHistory', 1));
    }

    #[Test]
    public function rollover_create_shows_readable_year_dates_and_offers_only_a_draft_target(): void
    {
        $page = (string) file_get_contents(resource_path('js/Pages/App/EnrollmentRollovers/Create.vue'));
        $this->assertStringContainsString('${formatCalendarDate(y.startsOn)} – ${formatCalendarDate(y.endsOn)}', $page);
        $this->assertDoesNotMatchRegularExpression('/\$\{[a-zA-Z_.]*\.(startsOn|endsOn)\}/', $page, 'no raw YYYY-MM-DD in an option label');
        $this->assertStringContainsString("props.academicYears.filter((y) => y.status === 'draft')", $page, 'only a draft year is a target');
    }
}
