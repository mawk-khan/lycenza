<?php

namespace Tests\Feature\Fees;

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Canteen\Application\CanteenOrderService;
use App\Domain\Fees\Application\ChargeCampusAttribution;
use App\Domain\Fees\Application\ChargeCampusFilter;
use App\Domain\Fees\Infrastructure\Charge;
use App\Domain\Fees\Infrastructure\FeeAssessment;
use App\Domain\Library\Application\LibraryFinePolicyService;
use App\Domain\Library\Application\LibraryLoanService;
use App\Domain\Library\Infrastructure\LibraryFine;
use App\Domain\Payments\Application\Collections\OutstandingFeesReadService;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\Campus;
use App\Models\SchoolAuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCanteenFixtures;
use Tests\Feature\Fees\Concerns\CreatesLateFeeFixtures;
use Tests\TestCase;

/**
 * ORG.7G-1A (ADR 0076 §49): every Charge stores the historical Campus its
 * creator attributed it to -- from the source context that creator used,
 * never the Student's current Campus -- and nothing afterwards (a transfer, a
 * cancelled or later placement, inactivity, a deactivated Campus) moves it.
 * NULL is whole-School only and never matches a Campus filter.
 */
class ChargeCampusAttributionTest extends TestCase
{
    use CreatesCanteenFixtures;
    use CreatesLateFeeFixtures;

    private function at(string $day): void
    {
        $this->travelTo(CarbonImmutable::parse($day.'T06:00:00Z'));
    }

    /** @param  array<string, mixed>  $w */
    private function campusOf(array $w, string $chargeId): ?string
    {
        return $this->inSchool($w['school'], fn () => Charge::query()->findOrFail($chargeId)->campus_id);
    }

    /** @param  array<string, mixed>  $w */
    private function manualCharge(array $w, Student $student, string $day, string $amount = '100.00'): Charge
    {
        $this->at($day);

        return $this->assessCharge($w['school'], $student, $w['year'], $w['receivable'], $w['revenue'], $amount);
    }

    /** @param  array<string, mixed>  $w */
    private function section(array $w, Campus $campus, string $code): Section
    {
        return $this->createSection($w['year'], $campus, $w['grade'], ['code' => $code, 'status' => 'active']);
    }

    /** @param  array<string, mixed>  $w */
    private function transfer(array $w, StudentEnrollment $source, Section $to, string $effective): StudentEnrollment
    {
        return $this->inSchool($w['school'], fn () => app(StudentEnrollmentService::class)->transferPlacement($source->fresh(), $to, (string) random_int(100, 9999), $effective));
    }

    /**
     * @param  array<string, mixed>  $w
     * @return array<string, string> outstanding by Student id
     */
    private function outstanding(array $w, ?ChargeCampusFilter $campus): array
    {
        $viewer = $w['viewer'] ??= $this->createUserWithCapabilities($w['school'], ['finance.charges.view', 'finance.payments.view']);
        $list = $this->inSchool($w['school'], fn () => app(OutstandingFeesReadService::class)->list($w['school'], $viewer, campus: $campus));

        return collect($list->rows)->mapWithKeys(fn ($r) => [$r->studentId => $r->outstanding->amount()])->all();
    }

    /** The migration's backfill derivation reproduces every stored Campus (no ambiguity). @param  array<string, mixed>  $w */
    private function assertBackfillAgrees(array $w): void
    {
        $migration = require database_path('migrations/2026_12_26_090000_add_charge_campus_attribution.php');
        $derived = $this->inSchool($w['school'], fn () => collect(DB::select('SELECT * FROM ('.$migration->derivationSql().') r'))->keyBy('id'));
        foreach ($this->inSchool($w['school'], fn () => Charge::query()->pluck('campus_id', 'id')) as $id => $campus) {
            $this->assertNull($derived[$id]->ambiguity, "{$derived[$id]->source} {$id}");
            $this->assertSame($campus, $derived[$id]->campus_id, "{$derived[$id]->source} {$id}: the backfill rule equals the stored Campus");
        }
    }

    #[Test]
    public function fee_run_and_late_fee_charges_keep_their_source_campus_through_a_transfer(): void
    {
        $this->at('2026-06-05');
        $w = $this->lateFeeWorld();
        $a = $w['campus'];
        $this->assertSame($a->id, $w['source']->campus_id, 'a fee-run Charge takes its stored assessment Enrollment\'s Campus');

        $this->at('2026-06-11');
        $this->executedLateRun($w, $this->activeRule($w));
        $lateFee = $this->lateFees($w)->sole();
        $this->assertSame($a->id, $this->campusOf($w, $lateFee->charge_id), 'a late fee inherits its source Charge\'s Campus');

        $b = $this->createCampus($w['school'], ['name' => 'South']);
        $placement = $this->inSchool($w['school'], fn () => StudentEnrollment::query()->where('student_id', $w['sourceStudent'])->sole());
        $this->at('2026-09-01');
        $this->transfer($w, $placement, $this->section($w, $b, 'SB'), '2026-09-01');

        $this->at('2026-11-02');
        $this->executedRun($w, 'T2');
        $t2 = $this->inSchool($w['school'], fn () => FeeAssessment::query()->where('billing_period_key', 'T2')->sole());

        $this->assertSame($b->id, $this->campusOf($w, $t2->charge_id), 'the later run charges the destination placement');
        $this->assertSame($a->id, $this->campusOf($w, $w['source']->id), 'the transfer never moves the earlier Charge');
        $this->assertSame($a->id, $this->campusOf($w, $lateFee->charge_id));
    }

    #[Test]
    public function a_manual_charge_never_moves_after_a_transfer_cancellation_later_placement_inactivity_or_campus_deactivation(): void
    {
        $this->at('2026-06-01');
        $w = $this->concessionWorld();
        $a = $w['campus'];
        $b = $this->createCampus($w['school'], ['name' => 'South']);
        $sectionB = $this->section($w, $b, 'SB');
        $student = $this->createStudent($w['school']);
        $onA = $this->createStudentEnrollment($student, $this->section($w, $a, 'SA'), ['starts_on' => '2026-06-01', 'status' => 'active']);

        $chargeA = $this->manualCharge($w, $student, '2026-07-01');
        $this->assertSame($a->id, $chargeA->campus_id, 'the placement on the creation date');

        $this->at('2026-08-01');
        $onB = $this->transfer($w, $onA, $sectionB, '2026-08-01');
        $chargeB = $this->manualCharge($w, $student, '2026-09-01');
        $this->assertSame($b->id, $chargeB->campus_id);

        // G0 F-1: cancelling the B placement no longer re-homes the B debt to A.
        $this->at('2026-09-15');
        $this->cancelEnrollment($this->inSchool($w['school'], fn () => $onB->fresh()), '2026-09-15');
        $this->assertSame($b->id, $this->campusOf($w, $chargeB->id), 'never falls back to A after the B placement is cancelled');
        $this->assertSame($a->id, $this->campusOf($w, $chargeA->id));

        // A later placement in the same year changes nothing either.
        $this->at('2026-10-01');
        $this->inSchool($w['school'], fn () => app(StudentEnrollmentService::class)->enroll($student->fresh(), $this->section($w, $a, 'SA2'), '77', '2026-10-01'));
        $this->assertSame($b->id, $this->campusOf($w, $chargeB->id));

        // A leaver keeps it, and so does a deactivated Campus.
        $this->forceStudentStatusOf($w['school'], $student->id, 'inactive');
        $this->inSchool($w['school'], fn () => DB::table('campuses')->where('id', $b->id)->update(['status' => 'inactive']));
        $this->assertSame($b->id, $this->campusOf($w, $chargeB->id));
        $this->assertSame($a->id, $this->campusOf($w, $chargeA->id));

        $this->assertSame([$student->id => '100.00'], $this->outstanding($w, ChargeCampusFilter::campus($b->id)), 'the leaver\'s debt stays discoverable by its stored Campus, inactive Campus included');
        $this->assertSame([$student->id => '100.00'], $this->outstanding($w, ChargeCampusFilter::campus($a->id)));
    }

    #[Test]
    public function an_unattributable_charge_is_whole_school_only_and_never_matches_a_campus(): void
    {
        $this->at('2026-06-01');
        $w = $this->concessionWorld(); // its own Student owes 1000.00 and has no placement
        $this->assertNull($w['charge']->campus_id);
        $placed = $this->createStudent($w['school']);
        $this->createStudentEnrollment($placed, $this->section($w, $w['campus'], 'SA'), ['starts_on' => '2026-06-01', 'status' => 'active']);
        $this->manualCharge($w, $placed, '2026-07-01', '40.00');

        $this->assertSame([$placed->id => '40.00'], $this->outstanding($w, ChargeCampusFilter::campus($w['campus']->id)), 'NULL is never "every Campus"');
        $this->assertSame([$w['student']->id => '1000.00'], $this->outstanding($w, ChargeCampusFilter::unattributed()));
        $this->assertEqualsCanonicalizing([$w['student']->id => '1000.00', $placed->id => '40.00'], $this->outstanding($w, null), 'the whole-School list still sees both');
    }

    #[Test]
    public function a_canteen_charge_takes_its_outlet_campus_never_the_students(): void
    {
        $this->at('2026-07-01');
        $w = $this->concessionWorld();
        $north = $w['campus'];
        $south = $this->createCampus($w['school'], ['name' => 'South']);
        $student = $this->createStudent($w['school']);
        $this->createStudentEnrollment($student, $this->section($w, $south, 'SS'), ['starts_on' => '2026-06-01', 'status' => 'active']);
        $this->configureCanteenBilling($w['school'], $w['receivable'], $w['revenue']);
        $item = $this->createCanteenItem($w['school'], ['price' => '25.00', 'status' => 'active']);

        $charges = [];
        foreach ([$north->id, null] as $campusId) {
            $location = $this->createInventoryLocation($w['school'], ['campus_id' => $campusId, 'status' => 'active']);
            $outlet = $this->createCanteenOutlet($w['school'], $location, ['campus_id' => $campusId, 'status' => 'active']);
            $placed = $this->placeCanteenOrder($w['school'], $student->id, $outlet->id, [['canteenItemId' => $item->id, 'quantity' => 1]]);
            $charges[] = $this->inSchool($w['school'], fn () => app(CanteenOrderService::class)->fulfill($w['school'], $placed->orderId))->chargeId;
        }

        $this->assertSame($north->id, $this->campusOf($w, (string) $charges[0]), 'the Outlet Campus, although the Student is on South');
        $this->assertNull($this->campusOf($w, (string) $charges[1]), 'a School-wide Outlet makes a whole-School Charge');
        $this->assertBackfillAgrees($w);
    }

    #[Test]
    public function a_library_fine_takes_the_loan_campus_frozen_at_checkout(): void
    {
        $this->at('2026-06-20');
        $w = $this->concessionWorld();
        $fineHead = $this->makeFeeHead($w, ['code' => 'LIBFINE', 'name' => 'Library fines']);
        $librarian = $this->createUserWithCapabilities($w['school'], ['library.circulation.view', 'library.circulation.manage', 'library.catalogue.view']);
        $finesOfficer = $this->createUserWithCapabilities($w['school'], ['library.fines.view', 'library.fines.manage', 'library.fines.void']);
        app(LibraryFinePolicyService::class)->publish($w['school'], [
            'status' => 'active', 'fee_head_id' => $fineHead->id, 'daily_rate' => '5.00', 'grace_days' => 0, 'max_amount' => '50.00',
        ], $finesOfficer);
        $south = $this->createCampus($w['school'], ['name' => 'South']);
        $copy = $this->createLibraryCopy($this->createLibraryTitle($w['school']), ['campus_id' => $south->id]);

        $this->travelTo(Carbon::parse('2026-07-01 10:00:00', 'UTC'));
        $loan = $this->inSchool($w['school'], fn () => app(LibraryLoanService::class)->checkout($copy, $w['student'], Carbon::parse('2026-07-08 10:00:00', 'UTC'), $librarian));
        $this->travelTo(Carbon::parse('2026-07-12 10:00:00', 'UTC'));
        $this->inSchool($w['school'], fn () => app(LibraryLoanService::class)->checkIn($loan, $librarian));

        $fine = $this->inSchool($w['school'], fn () => LibraryFine::query()->sole());
        $this->assertSame($south->id, $this->campusOf($w, (string) $fine->charge_id), 'the Loan Campus, although the Student has no placement at all');
        $this->assertBackfillAgrees($w);
    }

    #[Test]
    public function the_assessment_audit_records_the_campus_and_its_source(): void
    {
        $this->at('2026-06-01');
        $w = $this->concessionWorld();
        $event = $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()->where('event_type', 'charge.assessed')->where('subject_id', $w['charge']->id)->sole());

        $this->assertSame(['campusId' => null, 'campusSource' => ChargeCampusAttribution::SOURCE_PLACEMENT_ON_DATE], array_intersect_key($event->metadata, ['campusId' => 1, 'campusSource' => 1]));
    }
}
