<?php

namespace Tests\Feature\StaffSelfService;

use App\Domain\Leave\Application\LeaveRequestService;
use App\Models\School;
use App\Support\Tenancy\SchoolTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\Concerns\PurgesCommittedHrxFixtures;
use Tests\Feature\StaffSelfService\Concerns\CreatesSelfServiceFixtures;
use Tests\TestCase;

/**
 * HRX.4 (ADR 0065 §25.9): self-service has no race semantics of its own. Its
 * commands run the same locked HRX.2 operations, so a self-service action
 * racing a manager or administrative one ends in exactly the existing
 * lifecycle outcome. Genuine two-process races: the contender is observed
 * BLOCKED on a lock before the holder commits.
 *
 * Child processes use the real clock, so the leave dates are in the future,
 * which also lets the requester cancel before the start. They are taken from
 * the School's own today (rule 141): fixed November 2026 dates went stale on
 * 2026-11-16 and refused the self-cancellation (HR.R1-C35 WO-23, WO23-E2).
 */
class StaffSelfServiceConcurrencyTest extends TestCase
{
    use CreatesSelfServiceFixtures, ForcesConcurrentOverlap, PurgesCommittedHrxFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    /** @var list<School> */
    private array $schools = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->snapshotDurableFixtures();
    }

    protected function tearDown(): void
    {
        // Committed evidence is append-only and RESTRICT: it goes with its School, the test's Users
        // and its ad hoc capability roles (HRX.6: hermetic; never the canonical seed's).
        $this->purgeCommittedHrxSchools($this->schools);
        try {
            $this->assertDurableFixturesRestored();
        } finally {
            parent::tearDown();
        }
    }

    private function op(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/leave-op.php', ...$args];
    }

    private function world(): array
    {
        $w = $this->attendanceWorld();
        $this->schools[] = $w['school'];
        $w['me'] = $this->selfMember($w);

        return $w;
    }

    /**
     * The School's next full working week (Monday to Friday), at least a week after its today, inside the fixture's
     * leave year.
     *
     * @return list<string>
     */
    private function week(array $w): array
    {
        $monday = CarbonImmutable::createFromFormat('!Y-m-d', SchoolTimezone::today($w['school']))->addDays(7);
        while (! $monday->isMonday()) {
            $monday = $monday->addDay();
        }
        $days = array_map(fn (int $n) => $monday->addDays($n)->toDateString(), range(0, 4));
        $this->assertLessThanOrEqual($w['year']->ends_on->toDateString(), $days[4], 'the fixture opens one leave year; move it forward with the clock');

        return $days;
    }

    private function requestStatus(string $id): string
    {
        return (string) DB::connection('pgsql_admin')->table('leave_requests')->where('id', $id)->value('status');
    }

    private function rows(array $w, string $table, array $where = []): int
    {
        return DB::connection('pgsql_admin')->table($table)->where('school_id', $w['school']->id)->where($where)->count();
    }

    #[Test]
    public function my_withdrawal_racing_a_manager_or_administrative_approval_has_exactly_one_outcome(): void
    {
        $w = $this->world();
        $d = $this->week($w);
        $manager = $this->staffMember($w['school'], ['hr.leave.approve']);
        $this->reportTo($w['me']['assignment'], $manager['assignment']);

        $first = $this->submitLeave($w, $d[0], $d[0], employment: $w['me']['employment'])->id;
        [$holder, $contender] = $this->raceWithHeldHolder($this->op('withdraw-own', $w['school']->id, $first, $w['me']['user']->id), $this->op('manager-approve', $w['school']->id, $first, $manager['user']->id));
        $this->assertSame(['ok:withdrawn', 'rejected:LEAVE_REQUEST_NOT_SUBMITTED'], [$holder, $contender]);

        $second = $this->submitLeave($w, $d[1], $d[1], employment: $w['me']['employment'])->id;
        [$holder, $contender] = $this->raceWithHeldHolder($this->op('approve', $w['school']->id, $second, $w['admin']->id), $this->op('withdraw-own', $w['school']->id, $second, $w['me']['user']->id));
        $this->assertSame(['ok:approved', 'rejected:LEAVE_REQUEST_NOT_SUBMITTED'], [$holder, $contender]);

        $this->assertSame(['withdrawn', 'approved'], [$this->requestStatus($first), $this->requestStatus($second)]);
        $this->assertSame(0, $this->rows($w, 'leave_ledger_entries', ['leave_request_id' => $first]), 'the withdrawn request consumed nothing');
    }

    #[Test]
    public function my_cancellation_racing_an_administrative_cancellation_reverses_once(): void
    {
        $w = $this->world();
        $d = $this->week($w);
        $id = $this->submitLeave($w, $d[0], $d[1], employment: $w['me']['employment'])->id;
        app(LeaveRequestService::class)->approve($w['school'], $id, $w['admin']);

        [$holder, $contender] = $this->raceWithHeldHolder($this->op('cancel-own', $w['school']->id, $id, $w['me']['user']->id), $this->op('cancel', $w['school']->id, $id, $w['admin']->id));

        $this->assertSame(['ok:cancelled', 'rejected:LEAVE_REQUEST_NOT_APPROVED'], [$holder, $contender]);
        $this->assertSame(1, $this->rows($w, 'leave_ledger_entries', ['leave_request_id' => $id, 'kind' => 'reversal']));
        $this->assertSame('self', DB::connection('pgsql_admin')->table('leave_decisions')->where('leave_request_id', $id)->where('decision', 'cancelled')->value('path'));
        $this->assertSame(1, $this->rows($w, 'domain_event_outbox', ['event_type' => 'leave.request.cancelled.v1']));
    }

    #[Test]
    public function my_submission_racing_an_overlapping_administrative_submission_cannot_both_succeed(): void
    {
        $w = $this->world();
        $d = $this->week($w);

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('submit-own', $w['school']->id, $w['type']->id, $d[0], $d[2], $w['me']['user']->id),
            $this->op('submit', $w['school']->id, $w['me']['employment']->id, $w['type']->id, $d[2], $d[4], $w['admin']->id),
        );

        $this->assertSame(['ok:submitted', 'rejected:LEAVE_REQUEST_OVERLAP'], [$holder, $contender]);
        $this->assertSame(1, $this->rows($w, 'leave_requests'));
    }
}
