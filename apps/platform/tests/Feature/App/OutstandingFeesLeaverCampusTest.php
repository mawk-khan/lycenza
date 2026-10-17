<?php

namespace Tests\Feature\App;

use App\Domain\Payments\Application\PaymentCorrectionData;
use App\Domain\Payments\Application\PaymentCorrectionService;
use App\Domain\Payments\Domain\ManualPaymentMethod;
use App\Domain\Payments\Infrastructure\PaymentCorrection;
use App\Models\School;
use App\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Fees\Concerns\CreatesReceiptFixtures;
use Tests\TestCase;

/**
 * LC-4 (SB-03b): Outstanding fees can isolate leavers (Students marked
 * inactive) and one Campus's debt. A charge belongs to the Campus of the
 * Student's placement in that charge's academic year -- the one in effect
 * when it was charged if they moved -- and lines are filtered before they
 * are added up. Balances stay the authoritative post-correction ones.
 */
class OutstandingFeesLeaverCampusTest extends TestCase
{
    use CreatesReceiptFixtures;

    private function viewer(School $school): User
    {
        $user = $this->createUserWithCapabilities($school, ['finance.charges.view', 'finance.payments.view']);
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");

        return $user;
    }

    /** @return array{rows: array<string, string>, total: string, leavers: list<string>} outstanding by Student id */
    private function outstanding(string $query = ''): array
    {
        $props = $this->get('/app/finance/outstanding'.($query === '' ? '' : '?'.$query))->assertOk()->viewData('page')['props'];
        $rows = collect($props['rows']['data']);

        return [
            'rows' => $rows->pluck('outstanding', 'studentId')->all(),
            'total' => $props['totals']['outstanding'],
            'leavers' => $rows->where('leaver', true)->pluck('studentId')->values()->all(),
        ];
    }

    private function chargeOn(array $w, $student, string $amount, string $on)
    {
        $this->travelTo(CarbonImmutable::parse($on.'T06:00:00Z'));

        return $this->assessCharge($w['school'], $student, $w['year'], $w['receivable'], $w['revenue'], $amount);
    }

    /** @return array<string, mixed> */
    private function world(): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-06-15T06:00:00Z'));
        $w = $this->concessionWorld(); // its own Student owes 1000.00 and has no placement
        $a = $w['campus'];
        $b = $this->createCampus($w['school'], ['name' => 'North Campus']);
        $secA = $this->createSection($w['year'], $a, $w['grade'], ['code' => 'LC4A']);
        $secB = $this->createSection($w['year'], $b, $w['grade'], ['code' => 'LC4B']);

        $current = $this->createStudent($w['school'], ['first_name' => 'Current', 'last_name' => 'Pupil']);
        $this->createStudentEnrollment($current, $secA, ['starts_on' => '2026-06-01', 'status' => 'active']);
        $this->chargeOn($w, $current, '300.00', '2026-07-01');

        $leaver = $this->createStudent($w['school'], ['first_name' => 'Gone', 'last_name' => 'Away', 'status' => 'inactive']);
        $this->createStudentEnrollment($leaver, $secB, ['starts_on' => '2026-06-01', 'ends_on' => '2026-09-30', 'status' => 'withdrawn']);
        $leaverCharge = $this->chargeOn($w, $leaver, '500.00', '2026-07-01');
        $this->travelTo(CarbonImmutable::parse('2026-08-01T06:00:00Z'));
        $paid = $this->pay($w, '200.00', '2026-08-01', $leaverCharge);
        $this->travelTo(CarbonImmutable::parse('2026-10-15T06:00:00Z'));
        app(PaymentCorrectionService::class)->correct($w['school'], new PaymentCorrectionData(
            paymentId: $paid->paymentId, kind: PaymentCorrection::REFUND, reason: 'Part refunded on leaving',
            idempotencyKey: (string) Str::uuid(), chargeId: $leaverCharge->id, amount: Money::of('50.00', 'INR'),
            accountId: $w['settlement']->id, method: ManualPaymentMethod::Cash, refundedOn: '2026-10-15',
        ), $w['recorder']);
        $this->inSchool($w['school'], function () {
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
        });

        // Moved from Campus A to B on 1 Oct: each charge stays with the Campus it was charged under.
        $mover = $this->createStudent($w['school'], ['first_name' => 'Moved', 'last_name' => 'Pupil']);
        $this->createStudentEnrollment($mover, $secA, ['starts_on' => '2026-06-01', 'ends_on' => '2026-09-30', 'status' => 'transferred']);
        $this->createStudentEnrollment($mover, $secB, ['starts_on' => '2026-10-01', 'status' => 'active']);
        $this->chargeOn($w, $mover, '100.00', '2026-08-15');
        $this->chargeOn($w, $mover, '40.00', '2026-11-02');

        $unplaced = $this->createStudent($w['school'], ['first_name' => 'Never', 'last_name' => 'Placed']);
        $this->chargeOn($w, $unplaced, '70.00', '2026-07-01');

        return [...$w, 'a' => $a, 'b' => $b, 'current' => $current, 'leaver' => $leaver, 'mover' => $mover, 'unplaced' => $unplaced];
    }

    #[Test]
    public function leavers_are_the_students_marked_inactive_and_stay_in_everyone(): void
    {
        $w = $this->world();
        $this->viewer($w['school']);

        $all = $this->outstanding();
        $this->assertSame('350.00', $all['rows'][$w['leaver']->id], '500 - 200 paid + 50 refunded: post-correction.');
        $this->assertSame([$w['leaver']->id], $all['leavers']);
        $this->assertSame('1860.00', $all['total']);

        $leavers = $this->outstanding('standing=leavers');
        $this->assertSame([$w['leaver']->id => '350.00'], $leavers['rows']);
        $this->assertSame('350.00', $leavers['total']);

        $current = $this->outstanding('standing=current');
        $this->assertArrayNotHasKey($w['leaver']->id, $current['rows']);
        $this->assertSame('1510.00', $current['total']);

        // Search still finds a leaver, alone or with the Leavers filter.
        $this->assertSame([$w['leaver']->id], array_keys($this->outstanding('q=Gone&standing=leavers')['rows']));
        $this->assertSame([$w['leaver']->id], array_keys($this->outstanding('q=Gone')['rows']));
    }

    #[Test]
    public function a_campus_gets_only_its_own_obligations_including_a_movers_split_debt(): void
    {
        $w = $this->world();
        $this->viewer($w['school']);

        $a = $this->outstanding('campus_id='.$w['a']->id);
        $this->assertEqualsCanonicalizing([$w['current']->id => '300.00', $w['mover']->id => '100.00'], $a['rows']);
        $this->assertSame('400.00', $a['total']);

        $b = $this->outstanding('campus_id='.$w['b']->id);
        $this->assertEqualsCanonicalizing([$w['leaver']->id => '350.00', $w['mover']->id => '40.00'], $b['rows']);
        $this->assertSame('390.00', $b['total']);

        $none = $this->outstanding('campus_id=unavailable');
        $this->assertEqualsCanonicalizing([$w['student']->id => '1000.00', $w['unplaced']->id => '70.00'], $none['rows']);

        // The three Campus views add up to everything, with nothing counted twice.
        $this->assertSame('1860.00', bcadd(bcadd($a['total'], $b['total'], 2), $none['total'], 2));

        // Filters compose: leavers of Campus B; no leaver at Campus A.
        $this->assertSame([$w['leaver']->id => '350.00'], $this->outstanding('standing=leavers&campus_id='.$w['b']->id)['rows']);
        $this->assertSame([], $this->outstanding('standing=leavers&campus_id='.$w['a']->id)['rows']);
    }

    #[Test]
    public function another_schools_campus_is_refused_and_lends_nothing(): void
    {
        $w = $this->world();
        $foreign = $this->concessionWorld();
        $this->viewer($w['school']);

        $this->get('/app/finance/outstanding?campus_id='.$foreign['campus']->id)->assertSessionHasErrors('campus_id');
        $this->get('/app/finance/outstanding?standing=former')->assertSessionHasErrors('standing');
        $this->assertArrayNotHasKey($foreign['student']->id, $this->outstanding('campus_id=unavailable')['rows']);
        $props = $this->get('/app/finance/outstanding')->viewData('page')['props'];
        $this->assertNotContains($foreign['campus']->id, array_column($props['campuses'], 'id'));
    }
}
