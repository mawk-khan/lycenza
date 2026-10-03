<?php

namespace Tests\Feature\Leave;

use App\Domain\Leave\Application\Exceptions\LeaveException;
use App\Domain\Leave\Application\LeaveLedgerService;
use App\Domain\Leave\Application\LeaveReadService;
use App\Domain\Leave\Application\LeaveYearBounds;
use App\Domain\Leave\Application\LeaveYearService;
use App\Models\School;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Leave\Concerns\CreatesLeaveFixtures;
use Tests\TestCase;

/**
 * HRX.1 correction (ADR 0065 §22.1): the leave-year start month is
 * PROSPECTIVELY configurable, and materialized leave years are immutable.
 * A change takes effect on the first day of the new month, in the future,
 * strictly after every materialized year and earlier change; the year
 * bridging to it is an explicit transition year. Nothing historical moves.
 */
class LeaveYearConfigurationTest extends TestCase
{
    use CreatesLeaveFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-03 12:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function code(callable $call): string
    {
        try {
            DB::transaction($call);

            return 'ok';
        } catch (LeaveException $e) {
            return $e->errorCode();
        } catch (QueryException $e) {
            return 'db:'.substr($e->getMessage(), 0, 200);
        } catch (AuthorizationException) {
            return 'forbidden';
        }
    }

    /** @return list<string> every materialized year of the School, exactly as stored */
    private function storedYears(School $school): array
    {
        return $this->inSchool($school, fn () => array_map(fn ($r) => $r->j, DB::select(
            'select row_to_json(y)::text as j from leave_years y where school_id = ? order by starts_on', [$school->id],
        )));
    }

    private function schedule(School $school, int $month, string $from, User $actor): string
    {
        return $this->code(fn () => app(LeaveYearService::class)->scheduleStartChange($school, $month, $from, $actor));
    }

    #[Test]
    public function a_prospective_change_never_alters_moves_or_reinterprets_historical_years_or_evidence(): void
    {
        $w = $this->leaveWorld();
        $years = app(LeaveYearService::class);
        $entry = app(LeaveLedgerService::class)->allocate($w['school'], $w['employment']->id, $w['type']->id, $w['year']->id, 6, $w['admin']);
        $before = $this->storedYears($w['school']);
        $this->assertSame(['2026-04-01', '2027-03-31', 4, false], [$w['year']->starts_on->toDateString(), $w['year']->ends_on->toDateString(), $w['year']->start_month, $w['year']->is_transition]);

        $this->assertSame('ok', $this->schedule($w['school'], 1, '2028-01-01', $w['admin']));
        $this->assertSame($before, $this->storedYears($w['school']), 'the existing year is byte-for-byte unchanged');
        $this->assertSame($w['year']->id, $years->open($w['school'], '2026-12-25', $w['admin'])->id, 'a historical date still resolves to its own frozen year');

        // The runtime role can never edit or delete a materialized year.
        $this->assertStringContainsString('permission denied', $this->code(fn () => $this->inSchool($w['school'], fn () => DB::table('leave_years')->where('id', $w['year']->id)->update(['ends_on' => '2026-12-31']))));
        $this->assertStringContainsString('permission denied', $this->code(fn () => $this->inSchool($w['school'], fn () => DB::table('leave_years')->where('id', $w['year']->id)->update(['starts_on' => '2026-01-01']))));
        $this->assertStringContainsString('permission denied', $this->code(fn () => $this->inSchool($w['school'], fn () => DB::table('leave_years')->where('id', $w['year']->id)->delete())));
        $this->assertStringContainsString('permission denied', $this->code(fn () => $this->inSchool($w['school'], fn () => DB::table('leave_year_start_changes')->delete())), 'a scheduled change is append-only');

        // New years follow the schedule; the evidence stays where it was recorded.
        $years->open($w['school'], '2027-06-01', $w['admin']);
        $years->open($w['school'], '2028-02-01', $w['admin']);
        $this->assertSame($before[0], $this->storedYears($w['school'])[0]);
        $this->assertSame($w['year']->id, $this->inSchool($w['school'], fn () => DB::table('leave_ledger_entries')->where('id', $entry->id)->value('leave_year_id')));
        $balances = app(LeaveReadService::class)->balances($w['school'], $w['employment']->id, $w['year']->id, $w['admin']);
        $this->assertSame(6, $balances[0]['availableUnits'], 'the historical balance is unchanged');
    }

    #[Test]
    public function future_years_follow_the_new_start_month_through_one_explicit_transition_year(): void
    {
        $w = $this->leaveWorld();
        $years = app(LeaveYearService::class);
        $this->assertSame(4, $years->startMonth($w['school']), 'April remains the product default');

        $this->schedule($w['school'], 1, '2028-01-01', $w['admin']);
        $settings = app(LeaveReadService::class)->settings($w['school'], $w['admin']);
        $this->assertSame([1, 4, 1], [$settings['leaveYearStartMonth'], $settings['baseStartMonth'], count($settings['startChanges'])]);
        $this->assertSame(['previousStartMonth' => 4, 'startMonth' => 1, 'effectiveFrom' => '2028-01-01'], array_intersect_key($settings['startChanges'][0], array_flip(['previousStartMonth', 'startMonth', 'effectiveFrom'])));

        $bridge = $years->open($w['school'], '2027-06-01', $w['admin']);
        $this->assertSame(['2027-04-01', '2027-12-31', 4, true, '2027-04/2027-12'], [$bridge->starts_on->toDateString(), $bridge->ends_on->toDateString(), $bridge->start_month, $bridge->is_transition, $bridge->label], 'the old schedule runs up to the day before the change, explicitly marked');
        $next = $years->open($w['school'], '2028-02-01', $w['admin']);
        $this->assertSame(['2028-01-01', '2028-12-31', 1, false, '2028'], [$next->starts_on->toDateString(), $next->ends_on->toDateString(), $next->start_month, $next->is_transition, $next->label]);
        $this->assertSame($bridge->id, $years->open($w['school'], '2027-12-31', $w['admin'])->id);

        // Contiguous: no gap and no overlap between consecutive years.
        $stored = $this->inSchool($w['school'], fn () => DB::table('leave_years')->where('school_id', $w['school']->id)->orderBy('starts_on')->get(['starts_on', 'ends_on']));
        foreach ($stored->slice(1)->values() as $i => $year) {
            $this->assertSame(CarbonImmutable::parse($stored[$i]->ends_on)->addDay()->toDateString(), CarbonImmutable::parse($year->starts_on)->toDateString());
        }

        // A change aligned with an old boundary needs no transition year.
        $this->assertSame('ok', $this->schedule($w['school'], 7, '2030-07-01', $w['admin']));
        $this->assertSame(['2029-01-01', '2029-12-31', false], (fn ($y) => [$y->startsOn, $y->endsOn, $y->isTransition])(LeaveYearBounds::inSchedule(4, [['effective_from' => '2028-01-01', 'start_month' => 1], ['effective_from' => '2030-07-01', 'start_month' => 7]], '2029-06-01')));
        $this->assertSame(['2030-01-01', '2030-06-30', true], (fn ($y) => [$y->startsOn, $y->endsOn, $y->isTransition])(LeaveYearBounds::inSchedule(4, [['effective_from' => '2028-01-01', 'start_month' => 1], ['effective_from' => '2030-07-01', 'start_month' => 7]], '2030-03-01')));

        $audit = $this->inSchool($w['school'], fn () => DB::table('school_audit_events')->where('school_id', $w['school']->id)->where('event_type', 'leave.year_start.change_scheduled')->orderBy('occurred_at')->first());
        $this->assertNotNull($audit);
        $this->assertSame($w['admin']->id, $audit->actor_user_id);
        $this->assertNotNull($audit->occurred_at);
        $this->assertEqualsCanonicalizing(['before' => 4, 'after' => 1, 'effectiveFrom' => '2028-01-01'], json_decode($audit->metadata, true));
    }

    #[Test]
    public function a_change_that_would_overlap_hide_a_gap_or_blur_year_ownership_is_refused(): void
    {
        $w = $this->leaveWorld();
        $s = $w['school'];
        $a = $w['admin'];

        $this->assertSame('LEAVE_YEAR_MONTH_INVALID', $this->schedule($s, 13, '2028-01-01', $a));
        $this->assertSame('LEAVE_YEAR_MONTH_INVALID', $this->schedule($s, 0, '2028-01-01', $a));
        $this->assertSame('LEAVE_YEAR_EFFECTIVE_FROM_INVALID', $this->schedule($s, 1, '2028-02-01', $a), 'the boundary is the first day of the new start month');
        $this->assertSame('LEAVE_YEAR_EFFECTIVE_FROM_INVALID', $this->schedule($s, 1, '2028-01-15', $a));
        $this->assertSame('LEAVE_YEAR_EFFECTIVE_FROM_INVALID', $this->schedule($s, 1, '2026-01-01', $a), 'never in the past');
        $this->assertSame('LEAVE_YEAR_START_UNCHANGED', $this->schedule($s, 4, '2028-04-01', $a));
        $this->assertSame('LEAVE_YEAR_BOUNDARY_INVALID', $this->schedule($s, 1, '2027-01-01', $a), 'inside a materialized year');
        $this->assertSame('LEAVE_YEAR_LOCKED', $this->code(fn () => app(LeaveYearService::class)->setStartMonth($s, 1, $a)), 'the base month never changes once a year exists');

        $this->assertSame('ok', $this->schedule($s, 1, '2028-01-01', $a));
        $this->assertSame('LEAVE_YEAR_BOUNDARY_INVALID', $this->schedule($s, 7, '2027-07-01', $a), 'never before an earlier change');
        $this->assertSame('LEAVE_YEAR_START_UNCHANGED', $this->schedule($s, 1, '2029-01-01', $a), 'compared with the latest configured month');

        // The database refuses the same shapes on its own (raw SQL, runtime role).
        $raw = fn (string $sql, array $bindings) => $this->code(fn () => $this->inSchool($s, fn () => DB::insert($sql, $bindings)));
        $change = 'insert into leave_year_start_changes (id, school_id, previous_start_month, start_month, effective_from, created_by_user_id) values (?, ?, ?, ?, ?, ?)';
        $this->assertStringContainsString('leave_year_boundary_invalid', $raw($change, [(string) Str::uuid7(), $s->id, 1, 7, '2027-07-01', $a->id]));
        $this->assertStringContainsString('leave_year_start_unchanged', $raw($change, [(string) Str::uuid7(), $s->id, 4, 7, '2029-07-01', $a->id]), 'a stale previous month');
        $this->assertStringContainsString('leave_year_start_changes_shape_check', $raw($change, [(string) Str::uuid7(), $s->id, 1, 7, '2029-08-01', $a->id]));

        $year = 'insert into leave_years (id, school_id, label, starts_on, ends_on, start_month, is_transition) values (?, ?, ?, ?, ?, ?, ?)';
        $this->assertStringContainsString('leave_year_schedule_mismatch', $raw($year, [(string) Str::uuid7(), $s->id, '2027-28', '2027-04-01', '2028-03-31', 4, false]), 'a full year across the change');
        $this->assertStringContainsString('leave_year_schedule_mismatch', $raw($year, [(string) Str::uuid7(), $s->id, '2028-29', '2028-04-01', '2029-03-31', 4, false]), 'the old month after the change');
        $this->assertStringContainsString('leave_year_schedule_mismatch', $raw($year, [(string) Str::uuid7(), $s->id, '2027-04/2027-09', '2027-04-01', '2027-09-30', 4, true]), 'a transition must end at a change');
        $this->assertStringContainsString('leave_years_shape_check', $raw($year, [(string) Str::uuid7(), $s->id, 'X', '2027-04-01', '2027-03-31', 4, false]), 'never zero-length or inverted');
        $this->assertMatchesRegularExpression('/leave_years_shape_check|leave_year_schedule_mismatch/', $raw($year, [(string) Str::uuid7(), $s->id, 'Y', '2027-04-01', '2028-03-31', 4, true]), 'a transition year is shorter than a full year and ends at a change');
    }

    #[Test]
    public function one_school_cannot_change_another_schools_leave_year_configuration(): void
    {
        $a = $this->leaveWorld();
        $b = $this->leaveWorld();

        $this->assertSame('forbidden', $this->schedule($b['school'], 1, '2028-01-01', $a['admin']), 'School A authority is not School B authority');
        $this->assertSame('forbidden', $this->schedule($a['school'], 1, '2028-01-01', $this->createUserWithCapabilities($a['school'], ['hr.leave.manage', 'hr.leave.view'])), 'configure, not manage');

        $this->assertSame('ok', $this->schedule($a['school'], 1, '2028-01-01', $a['admin']));
        $this->assertSame(4, app(LeaveYearService::class)->startMonth($b['school']), "A's change never reaches B");
        $this->assertSame(0, $this->inSchool($b['school'], fn () => DB::table('leave_year_start_changes')->count()), 'RLS: B sees none of A\'s changes');
        $this->assertNotSame('ok', $this->code(fn () => $this->inSchool($b['school'], fn () => DB::insert(
            'insert into leave_year_start_changes (id, school_id, previous_start_month, start_month, effective_from, created_by_user_id) values (?, ?, 1, 7, ?, ?)',
            [(string) Str::uuid7(), $a['school']->id, '2029-07-01', $b['admin']->id],
        ))), 'a row naming another School is refused by RLS');

        // Through the API: allowed with configure, idempotent, and refused for another School.
        $headers = fn (User $u, string $key) => ['Authorization' => 'Bearer '.$u->createToken('t')->plainTextToken, 'Idempotency-Key' => $key];
        $key = (string) Str::uuid();
        $first = $this->withHeaders($headers($b['admin'], $key))->postJson("/api/v1/schools/{$b['school']->id}/leave/year-start-changes", ['start_month' => 7, 'effective_from' => '2027-07-01'])
            ->assertCreated()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.leaveYearStartMonth', 7);
        $this->app['auth']->forgetGuards();
        $this->withHeaders($headers($b['admin'], $key))->postJson("/api/v1/schools/{$b['school']->id}/leave/year-start-changes", ['start_month' => 7, 'effective_from' => '2027-07-01'])
            ->assertCreated()->assertJsonPath('data.startChanges.0.id', $first->json('data.startChanges.0.id'));
        $this->assertSame(1, $this->inSchool($b['school'], fn () => DB::table('leave_year_start_changes')->count()), 'a retry never schedules twice');
        $this->app['auth']->forgetGuards();
        $this->withHeaders($headers($a['admin'], (string) Str::uuid()))->postJson("/api/v1/schools/{$b['school']->id}/leave/year-start-changes", ['start_month' => 1, 'effective_from' => '2029-01-01'])
            ->assertNotFound(); // not a member of School B: the membership gate's tenant-safe 404
        $this->app['auth']->forgetGuards();
        $this->withHeaders($headers($b['admin'], (string) Str::uuid()))->postJson("/api/v1/schools/{$b['school']->id}/leave/year-start-changes", ['start_month' => 7, 'effective_from' => 'soon'])
            ->assertStatus(422);

        // A configure-only holder sees its own committed write, never a 403 after the fact.
        $configurer = $this->createUserWithCapabilities($b['school'], ['hr.leave.configure']);
        $this->app['auth']->forgetGuards();
        $this->withHeaders($headers($configurer, (string) Str::uuid()))->postJson("/api/v1/schools/{$b['school']->id}/leave/year-start-changes", ['start_month' => 1, 'effective_from' => '2029-01-01'])
            ->assertCreated()->assertJsonPath('data.leaveYearStartMonth', 1);
        $fresh = $this->createSchool();
        $freshConfigurer = $this->createUserWithCapabilities($fresh, ['hr.leave.configure']);
        $this->app['auth']->forgetGuards();
        $this->withHeaders($headers($freshConfigurer, (string) Str::uuid()))->putJson("/api/v1/schools/{$fresh->id}/leave/settings", ['leave_year_start_month' => 6])
            ->assertOk()->assertJsonPath('data.baseStartMonth', 6);
        $this->app['auth']->forgetGuards();
        $this->withHeaders($headers($freshConfigurer, (string) Str::uuid()))->getJson("/api/v1/schools/{$fresh->id}/leave/settings")->assertForbidden();
    }
}
