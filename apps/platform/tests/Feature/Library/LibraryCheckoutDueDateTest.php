<?php

namespace Tests\Feature\Library;

use App\Domain\Library\Infrastructure\LibraryLoan;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * UAT-C1 (UAT-LIB-01): a checkout with the due date set to today returned a
 * 500 -- the form's calendar date became 00:00 UTC, earlier than the
 * checkout, and hit `library_loans_due_after_checkout_check`. The Library
 * contract only requires due >= checkout, so a same-day loan is valid:
 *
 * - the form's date means "due back by the end of that day" in the School's
 *   timezone (23:59:59 local);
 * - a due time before the checkout (any path, including the API) is a 422
 *   refusal before anything is written -- never a 500.
 *
 * Clocks are fixed with travelTo().
 */
class LibraryCheckoutDueDateTest extends TestCase
{
    use CreatesTenancyFixtures;

    /** @return array{User, School, string, string} admin, school, copy id, student id */
    private function circulation(string $timezone = 'Asia/Kolkata'): array
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $school->forceFill(['timezone' => $timezone])->save();
        $copy = $this->createLibraryCopy($this->createLibraryTitle($school));
        $student = $this->createStudent($school, ['status' => 'active']);
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");

        return [$user, $school, $copy->id, $student->id];
    }

    private function loan(School $school, string $copyId): ?LibraryLoan
    {
        return app(TenantContext::class)->withSchool($school, fn () => LibraryLoan::query()->where('library_copy_id', $copyId)->first());
    }

    private function checkout(string $copyId, string $studentId, string $due)
    {
        return $this->post('/app/library/circulation', ['library_copy_id' => $copyId, 'student_id' => $studentId, 'due_at' => $due]);
    }

    #[Test]
    public function the_uat_case_a_loan_due_today_is_accepted_and_due_at_the_end_of_the_school_day(): void
    {
        [, $school, $copyId, $studentId] = $this->circulation();

        // 2026-10-15 13:00 UTC = 18:30 on 15 October in Asia/Kolkata.
        $this->travelTo(CarbonImmutable::parse('2026-10-15T13:00:00Z'));

        $response = $this->checkout($copyId, $studentId, '2026-10-15');
        $this->assertNotSame(500, $response->getStatusCode());
        $response->assertSessionHasNoErrors()->assertRedirect('/app/library/circulation');

        $loan = $this->loan($school, $copyId);
        $this->assertSame('2026-10-15T18:29:59+00:00', $loan->due_at->toIso8601String());
        $this->assertFalse($loan->isOverdue());

        $row = $this->get('/app/library/circulation')->viewData('page')['props']['loans']['data'][0];
        $this->assertSame('2026-10-15', $row['dueAt']);
    }

    #[Test]
    public function today_means_the_schools_today_when_utc_is_still_the_previous_day(): void
    {
        [, $school, $copyId, $studentId] = $this->circulation();

        // 2026-10-15 20:04 UTC = 01:34 on 16 October in Asia/Kolkata (the UAT clock).
        $this->travelTo(CarbonImmutable::parse('2026-10-15T20:04:00Z'));
        $this->assertSame('2026-10-16', $this->get('/app/library/circulation/create')->viewData('page')['props']['today']);

        $this->checkout($copyId, $studentId, '2026-10-16')->assertSessionHasNoErrors();
        $this->assertSame('2026-10-16T18:29:59+00:00', $this->loan($school, $copyId)->due_at->toIso8601String());
    }

    #[Test]
    public function a_school_west_of_utc_can_lend_due_today_and_tomorrow_in_its_own_calendar(): void
    {
        [, $school, $copyId, $studentId] = $this->circulation('America/Los_Angeles');
        $secondCopy = $this->createLibraryCopy($this->createLibraryTitle($school))->id;

        // 2026-10-16 03:00 UTC = 20:00 on 15 October in Los Angeles.
        $this->travelTo(CarbonImmutable::parse('2026-10-16T03:00:00Z'));

        $this->checkout($copyId, $studentId, '2026-10-15')->assertSessionHasNoErrors();
        $this->assertSame('2026-10-16T06:59:59+00:00', $this->loan($school, $copyId)->due_at->toIso8601String());

        $this->checkout($secondCopy, $studentId, '2026-10-16')->assertSessionHasNoErrors();
        $this->assertSame('2026-10-17T06:59:59+00:00', $this->loan($school, $secondCopy)->due_at->toIso8601String());
    }

    #[Test]
    public function a_past_due_date_is_a_form_error_and_writes_nothing(): void
    {
        [, $school, $copyId, $studentId] = $this->circulation();
        $this->travelTo(CarbonImmutable::parse('2026-10-15T13:00:00Z'));

        $response = $this->checkout($copyId, $studentId, '2026-10-14');

        $this->assertNotSame(500, $response->getStatusCode());
        $response->assertSessionHasErrors(['due_at' => 'The due date cannot be earlier than the checkout. Choose today or a later date.']);
        $this->assertNull($this->loan($school, $copyId));
    }

    #[Test]
    public function the_api_refuses_a_due_time_before_the_checkout_with_a_422_not_a_500(): void
    {
        [$user, $school, $copyId, $studentId] = $this->circulation();
        $this->travelTo(CarbonImmutable::parse('2026-10-15T13:00:00Z'));

        $this->withHeader('Authorization', 'Bearer '.$user->createToken('t')->plainTextToken)
            ->withHeader('Idempotency-Key', 'lib01-past-001')
            ->postJson("/api/v1/schools/{$school->id}/library-loans", [
                'library_copy_id' => $copyId, 'student_id' => $studentId, 'due_at' => '2026-10-15T12:00:00Z',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'LIBRARY_DUE_BEFORE_CHECKOUT');

        $this->assertNull($this->loan($school, $copyId));
    }

    #[Test]
    public function checking_out_still_requires_the_manage_capability(): void
    {
        [, $school, $copyId, $studentId] = $this->circulation();
        $viewer = $this->createUserWithCapabilities($school, ['library.circulation.view']);
        $this->actingAs($viewer)->post("/app/schools/{$school->id}/activate");

        $this->checkout($copyId, $studentId, '2026-12-01')->assertForbidden();
        $this->assertNull($this->loan($school, $copyId));
    }
}
