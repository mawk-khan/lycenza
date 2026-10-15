<?php

namespace Tests\Feature\AcademicStructure;

use App\Domain\AcademicStructure\Application\AcademicYearService;
use App\Domain\AcademicStructure\Application\Exceptions\AcademicYearNotStartedException;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\DomainEventOutbox;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * UAT-C1 (UAT-YR-07; owner decision "0 days early"): a draft Academic Year
 * may be activated only when the School's own calendar date has reached its
 * start date. Activating closes the current Year, so an early activation
 * used to end the real school year with no way back. Clocks are fixed with
 * travelTo(); every case names the UTC instant and the School-local date.
 */
class AcademicYearActivationDateTest extends TestCase
{
    use CreatesTenancyFixtures;

    /** @return array{School, AcademicYear, AcademicYear, string} school, current, next, current placement id */
    private function schoolWithCurrentAndNextYear(string $timezone = 'Asia/Kolkata'): array
    {
        $school = $this->createSchool(['timezone' => $timezone]);
        $current = $this->createAcademicYear($school, ['code' => 'AY25', 'starts_on' => '2025-04-01', 'ends_on' => '2026-03-31']);
        $next = $this->createAcademicYear($school, ['code' => 'AY26', 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31']);

        $this->travelTo(CarbonImmutable::parse('2025-06-01T06:00:00Z'));
        app(AcademicYearService::class)->activate($current);

        $section = $this->createSection($current, $this->createCampus($school), $this->createGradeLevel($school));
        $placement = $this->createStudentEnrollment($this->createStudent($school), $section, ['status' => 'active']);

        return [$school, $this->in($school, fn () => $current->fresh()), $this->in($school, fn () => $next->fresh()), $placement->id];
    }

    private function in(School $school, \Closure $fn): mixed
    {
        return app(TenantContext::class)->withSchool($school, $fn);
    }

    /** Asserts a refused activation left every row exactly as it was. */
    private function assertNothingChanged(School $school, AcademicYear $current, AcademicYear $next, string $placementId): void
    {
        $this->in($school, function () use ($school, $current, $next, $placementId) {
            $this->assertSame('active', AcademicYear::query()->findOrFail($current->id)->status);
            $this->assertSame('draft', AcademicYear::query()->findOrFail($next->id)->status);
            $this->assertEquals($current->updated_at, AcademicYear::query()->findOrFail($current->id)->updated_at);
            $this->assertSame('active', StudentEnrollment::query()->findOrFail($placementId)->status);
            $this->assertSame(0, SchoolAuditEvent::query()->where('school_id', $school->id)->where('event_type', 'academic_year.activated')->where('subject_id', $next->id)->count());
            $this->assertSame(0, DomainEventOutbox::query()->where('school_id', $school->id)->where('event_type', 'academic_year.activated.v1')->where('payload->academicYearId', $next->id)->count());
        });
    }

    #[Test]
    public function a_year_cannot_be_activated_before_its_school_local_start_date_and_nothing_changes(): void
    {
        [$school, $current, $next, $placementId] = $this->schoolWithCurrentAndNextYear();

        // 2026-03-31 18:00 UTC = 23:30 on 31 March in Asia/Kolkata: one day early.
        $this->travelTo(CarbonImmutable::parse('2026-03-31T18:00:00Z'));

        try {
            app(AcademicYearService::class)->activate($next);
            $this->fail('An early activation must be refused.');
        } catch (AcademicYearNotStartedException $e) {
            $this->assertSame('ACADEMIC_YEAR_NOT_STARTED', $e->errorCode());
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('2026-04-01', $e->getMessage());
        }

        $this->assertNothingChanged($school, $current, $next, $placementId);
    }

    #[Test]
    public function activation_is_allowed_on_the_school_local_start_date_even_while_utc_is_still_the_day_before(): void
    {
        [$school, $current, $next] = $this->schoolWithCurrentAndNextYear();

        // 2026-03-31 19:00 UTC = 00:30 on 1 April in Asia/Kolkata.
        $this->travelTo(CarbonImmutable::parse('2026-03-31T19:00:00Z'));
        $this->assertTrue(AcademicYearService::hasStarted($next));

        $this->assertSame('active', app(AcademicYearService::class)->activate($next)->status);
        $this->assertSame('closed', $this->in($school, fn () => AcademicYear::query()->findOrFail($current->id)->status));
    }

    #[Test]
    public function activation_is_allowed_after_the_start_date(): void
    {
        [, , $next] = $this->schoolWithCurrentAndNextYear();

        $this->travelTo(CarbonImmutable::parse('2026-05-15T06:00:00Z'));

        $this->assertSame('active', app(AcademicYearService::class)->activate($next)->status);
    }

    #[Test]
    public function a_school_west_of_utc_uses_its_own_date_when_utc_has_already_reached_the_start_date(): void
    {
        [$school, $current, $next, $placementId] = $this->schoolWithCurrentAndNextYear('America/Los_Angeles');

        // 2026-04-01 03:00 UTC = 20:00 on 31 March in Los Angeles: still one day early there.
        $this->travelTo(CarbonImmutable::parse('2026-04-01T03:00:00Z'));
        $this->assertFalse(AcademicYearService::hasStarted($next));

        $this->expectException(AcademicYearNotStartedException::class);

        try {
            app(AcademicYearService::class)->activate($next);
        } finally {
            $this->assertNothingChanged($school, $current, $next, $placementId);
        }
    }

    #[Test]
    public function another_schools_timezone_does_not_decide_this_schools_activation(): void
    {
        [, , $kolkataNext] = $this->schoolWithCurrentAndNextYear('Asia/Kolkata');
        [, , $angelesNext] = $this->schoolWithCurrentAndNextYear('America/Los_Angeles');

        // 2026-03-31 20:00 UTC: 1 April 01:30 in Kolkata, 31 March 13:00 in Los Angeles.
        $this->travelTo(CarbonImmutable::parse('2026-03-31T20:00:00Z'));

        $this->assertTrue(AcademicYearService::hasStarted($kolkataNext));
        $this->assertFalse(AcademicYearService::hasStarted($angelesNext));
    }

    #[Test]
    public function the_web_action_refuses_an_early_activation_as_a_form_error_and_the_page_withholds_it(): void
    {
        [$school, $current, $next, $placementId] = $this->schoolWithCurrentAndNextYear();
        $admin = $this->createUserWithCapabilities($school, ['academics.years.view', 'academics.years.manage']);
        $this->actingAs($admin)->post("/app/schools/{$school->id}/activate");

        $this->travelTo(CarbonImmutable::parse('2026-03-31T18:00:00Z'));

        $years = collect($this->get('/app/school-setup/academic-years')->assertOk()->viewData('page')['props']['academicYears'])->keyBy('id');
        $this->assertFalse($years[$next->id]['hasStarted']);

        $response = $this->post("/app/school-setup/academic-years/{$next->id}/activate");
        $this->assertNotSame(500, $response->getStatusCode());
        $response->assertSessionHasErrors(['academic_year' => 'This Academic Year starts on 2026-04-01 and cannot be activated before that date.']);
        $this->assertNothingChanged($school, $current, $next, $placementId);

        $this->travelTo(CarbonImmutable::parse('2026-03-31T19:00:00Z'));
        $years = collect($this->get('/app/school-setup/academic-years')->viewData('page')['props']['academicYears'])->keyBy('id');
        $this->assertTrue($years[$next->id]['hasStarted']);
    }

    #[Test]
    public function the_api_refuses_a_direct_early_activation_with_a_422_and_no_change(): void
    {
        [$school, $current, $next, $placementId] = $this->schoolWithCurrentAndNextYear();
        $admin = $this->createUserWithCapabilities($school, ['academics.years.view', 'academics.years.manage']);

        $this->travelTo(CarbonImmutable::parse('2026-03-31T18:00:00Z'));
        $token = $admin->createToken('test-device')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")->withHeader('Idempotency-Key', 'early-activate-001')
            ->postJson("/api/v1/schools/{$school->id}/academic-years/{$next->id}/activate")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ACADEMIC_YEAR_NOT_STARTED');

        $this->assertNothingChanged($school, $current, $next, $placementId);
    }

    #[Test]
    public function activation_still_requires_the_manage_capability(): void
    {
        [$school, $current, $next, $placementId] = $this->schoolWithCurrentAndNextYear();
        $viewer = $this->createUserWithCapabilities($school, ['academics.years.view']);
        $this->actingAs($viewer)->post("/app/schools/{$school->id}/activate");

        $this->travelTo(CarbonImmutable::parse('2026-05-15T06:00:00Z'));

        $this->post("/app/school-setup/academic-years/{$next->id}/activate")->assertForbidden();
        $this->assertNothingChanged($school, $current, $next, $placementId);
    }
}
