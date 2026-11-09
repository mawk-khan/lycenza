<?php

namespace Tests\Feature\App;

use App\Domain\Leave\Application\Calendar\StaffCalendarHistory;
use App\Domain\Leave\Application\DayPortion;
use App\Domain\StaffAttendance\Application\StaffAttendanceService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\Feature\StaffAttendance\Concerns\CreatesStaffAttendanceFixtures;
use Tests\TestCase;

/**
 * HR.R1-C22 (WO-5, D-6): the staff calendar through the real web and API routes. Today and later is an
 * ordinary change; a past date is a correction that needs the capability, a closed reason and the
 * actor's own fresh code (rule 95: capability first); a holiday is withdrawn, never deleted.
 */
class StaffCalendarUiTest extends TestCase
{
    use CreatesStaffAttendanceFixtures, ProvidesSensitiveActionMfa;

    /** @var array<string, mixed> */
    private array $w;

    protected function setUp(): void
    {
        parent::setUp();
        $this->w = $this->attendanceWorld();
    }

    private function web(?User $user = null): static
    {
        return $this->actingAs($user ?? $this->w['admin'])->withHeader('X-School-Id', $this->w['school']->id);
    }

    private function plus(int $days): string
    {
        return CarbonImmutable::parse(app(StaffCalendarHistory::class)->today($this->w['school']))->addDays($days)->toDateString();
    }

    private function holidays(): array
    {
        return $this->inSchool($this->w['school'], fn () => DB::table('staff_holidays')->orderBy('holiday_on')->get(['holiday_on', 'status', 'recorded_reason_code', 'withdrawn_reason_code'])->map(fn ($r) => (array) $r)->all());
    }

    #[Test]
    public function the_configuration_page_shows_versions_the_history_start_and_the_school_today(): void
    {
        $this->web()->get('/app/leave/configuration')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('calendar.historyStart', self::CALENDAR_HISTORY_START)
            ->where('calendar.today', $this->plus(0))
            ->has('calendar.versions', 1)
            ->where('calendar.versions.0.reasonCode', 'cutover')
            ->where('hasMfaFactor', false));
    }

    #[Test]
    public function before_saving_the_page_shows_what_a_change_would_touch_and_only_to_a_configurer(): void
    {
        $this->inSchool($this->w['school'], fn () => app(StaffAttendanceService::class)
            ->record($this->w['school'], $this->w['employment']->id, $this->plus(0), 'present', 'present', $this->w['clerk']));
        $url = '/app/leave/configuration?impact_from='.$this->plus(0).'&impact_to='.$this->plus(0);

        $this->web()->get($url)->assertOk()->assertInertia(fn (Assert $p) => $p->missing('calendarImpact'));

        $partial = ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'App/Leave/Configuration', 'X-Inertia-Partial-Data' => 'calendarImpact', 'X-Inertia-Version' => (string) Inertia::getVersion()];
        $this->web()->withHeaders($partial)->get($url)->assertOk()
            ->assertJsonPath('props.calendarImpact.from', $this->plus(0))
            ->assertJsonPath('props.calendarImpact.touched', [['label' => 'leave requests awaiting a decision', 'count' => 0], ['label' => 'staff attendance days already recorded', 'count' => 1]]);

        $viewer = $this->createUserWithCapabilities($this->w['school'], ['hr.leave.view']);
        $this->web($viewer)->withHeaders($partial)->get($url)->assertForbidden();
        $this->web()->withHeaders($partial)->get('/app/leave/configuration?impact_from=2026-01-01&impact_to=2027-06-01')->assertStatus(302);
    }

    #[Test]
    public function a_future_holiday_needs_only_the_capability_and_a_past_one_a_reason_and_a_fresh_code(): void
    {
        $this->web()->post('/app/leave/calendar/holidays', ['date' => $this->plus(15), 'portion' => 'full', 'name' => 'Founders'])->assertSessionHasNoErrors();

        $this->web()->post('/app/leave/calendar/holidays', ['date' => '2026-09-14', 'portion' => 'full', 'name' => 'Late'])->assertSessionHasErrors('reason_code');
        $this->web()->post('/app/leave/calendar/holidays', ['date' => '2026-09-14', 'portion' => 'full', 'name' => 'Late', 'reason_code' => 'late_declaration', 'mfa_code' => 'NOT-A-CODE'])->assertSessionHasErrors('mfa_code');
        $this->web()->post('/app/leave/calendar/holidays', ['date' => '2026-09-14', 'portion' => 'full', 'name' => 'Late', 'reason_code' => 'late_declaration', 'mfa_code' => $this->freshMfaCode($this->w['admin'])])->assertSessionHasNoErrors();

        $viewer = $this->createUserWithCapabilities($this->w['school'], ['hr.leave.view']);
        $this->web($viewer)->post('/app/leave/calendar/holidays', ['date' => '2026-09-15', 'portion' => 'full', 'name' => 'Forged', 'reason_code' => 'late_declaration', 'mfa_code' => 'X'])->assertForbidden();
        $this->assertSame([['holiday_on' => '2026-09-14', 'status' => 'active', 'recorded_reason_code' => 'late_declaration', 'withdrawn_reason_code' => null], ['holiday_on' => $this->plus(15), 'status' => 'active', 'recorded_reason_code' => null, 'withdrawn_reason_code' => null]], $this->holidays());
    }

    #[Test]
    public function a_holiday_is_withdrawn_never_deleted_and_one_in_effect_only_as_a_correction(): void
    {
        $future = $this->staffHoliday($this->w['school'], $this->plus(15), DayPortion::Full, 'Founders', $this->w['admin']);
        $past = $this->staffHoliday($this->w['school'], '2026-09-14', DayPortion::Full, 'Late', $this->w['admin']);

        $this->web()->post("/app/leave/calendar/holidays/{$future->id}/remove")->assertSessionHasNoErrors();
        $this->web()->post("/app/leave/calendar/holidays/{$past->id}/remove")->assertSessionHasErrors('reason_code');
        $this->web()->post("/app/leave/calendar/holidays/{$past->id}/remove", ['reason_code' => 'entered_in_error', 'mfa_code' => $this->freshMfaCode($this->w['admin'])])->assertSessionHasNoErrors();
        $viewer = $this->createUserWithCapabilities($this->w['school'], ['hr.leave.view']);
        $this->web($viewer)->post('/app/leave/calendar/holidays/'.Str::uuid().'/remove')->assertForbidden();

        $this->assertSame(['withdrawn', 'withdrawn'], array_column($this->holidays(), 'status'), 'both rows are kept');
        $this->assertSame('entered_in_error', $this->holidays()[0]['withdrawn_reason_code']);
    }

    #[Test]
    public function a_week_is_scheduled_withdrawn_and_corrected_through_the_page(): void
    {
        $this->web()->post('/app/leave/calendar/weekdays', ['weekdays' => [1 => 'full', 2 => 'full', 3 => 'full', 4 => 'full', 5 => 'off', 6 => 'off', 7 => 'off'], 'effective_from' => $this->plus(30)])
            ->assertSessionHasNoErrors();
        $scheduled = $this->inSchool($this->w['school'], fn () => DB::table('staff_working_week_versions')->where('effective_from', $this->plus(30))->value('id'));
        $this->web()->post("/app/leave/calendar/weeks/{$scheduled}/withdraw")->assertSessionHasNoErrors();

        $week = ['weekdays' => [1 => 'off', 2 => 'full', 3 => 'full', 4 => 'full', 5 => 'full', 6 => 'off', 7 => 'off'], 'effective_from' => '2026-10-01'];
        $this->web()->post('/app/leave/calendar/weekdays', $week)->assertSessionHasErrors('reason_code');
        $this->web()->post('/app/leave/calendar/weekdays', [...$week, 'reason_code' => 'administrative_review', 'mfa_code' => $this->freshMfaCode($this->w['admin'])])->assertSessionHasNoErrors();

        $this->assertSame([['effective_from' => self::CALENDAR_HISTORY_START, 'status' => 'active'], ['effective_from' => '2026-10-01', 'status' => 'active'], ['effective_from' => $this->plus(30), 'status' => 'withdrawn']],
            $this->inSchool($this->w['school'], fn () => DB::table('staff_working_week_versions')->orderBy('effective_from')->get(['effective_from', 'status'])->map(fn ($r) => (array) $r)->all()));
    }

    #[Test]
    public function the_api_withdraws_on_delete_and_asks_a_reason_and_a_fresh_code_for_the_past(): void
    {
        $token = $this->mfaToken($this->w['admin']);
        $api = fn () => $this->withHeader('Authorization', "Bearer {$token}")->withHeader('Idempotency-Key', (string) Str::uuid());
        $url = "/api/v1/schools/{$this->w['school']->id}/leave/calendar";

        $api()->postJson("{$url}/holidays", ['date' => '2026-09-14', 'portion' => 'full', 'name' => 'Late'])->assertStatus(422);
        $id = $api()->postJson("{$url}/holidays", ['date' => '2026-09-14', 'portion' => 'full', 'name' => 'Late', 'reason_code' => 'late_declaration', ...$this->mfaBody($token)])->assertCreated()
            ->assertJsonPath('data.status', 'active')->json('data.id');
        $api()->deleteJson("{$url}/holidays/{$id}", ['reason_code' => 'entered_in_error', ...$this->mfaBody($token)])->assertNoContent();
        $api()->getJson("{$url}?from=2026-09-01&to=2026-09-30")->assertOk()->assertJsonPath('data.holidays.0.status', 'withdrawn')->assertJsonPath('data.historyStart', self::CALENDAR_HISTORY_START);
        $this->assertSame(1, $this->inSchool($this->w['school'], fn () => DB::table('staff_holidays')->count()), 'withdrawn, not deleted');
    }
}
