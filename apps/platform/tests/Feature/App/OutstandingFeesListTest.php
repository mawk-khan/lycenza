<?php

namespace Tests\Feature\App;

use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Fees\Concerns\CreatesReceiptFixtures;
use Tests\TestCase;

/**
 * UAT-C3 (UAT-FIN-08): Finance -> Outstanding fees answers "which Students
 * owe money, and how much" without opening each statement. Balances come
 * from the authoritative charge state (charges - payments - live
 * concessions); overdue uses the School's own date.
 */
class OutstandingFeesListTest extends TestCase
{
    use CreatesReceiptFixtures;

    private function viewer(School $school, array $extra = []): User
    {
        $user = $this->createUserWithCapabilities($school, ['finance.charges.view', 'finance.payments.view', ...$extra]);
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");

        return $user;
    }

    /** @return array<string, array<string, mixed>> rows keyed by Student id */
    private function rows(string $query = ''): array
    {
        $props = $this->get('/app/finance/outstanding'.$query)->assertOk()->viewData('page')['props'];

        return collect($props['rows']['data'])->keyBy('studentId')->all() + ['_totals' => $props['totals']];
    }

    /** A second Student with one charge of $amount due $due. */
    private function owing(array $w, string $amount, ?string $due, string $first = 'Second'): array
    {
        $student = $this->createStudent($w['school'], ['first_name' => $first, 'last_name' => 'Owing']);
        $charge = $this->assessCharge($w['school'], $student, $w['year'], $w['receivable'], $w['revenue'], $amount, dueDate: $due);

        return [$student, $charge];
    }

    #[Test]
    public function owing_students_are_listed_with_partial_payments_and_concessions_reflected_and_paid_students_left_out(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15T06:00:00Z'));
        $w = $this->concessionWorld();                       // Student A owes 1000.00 (no due date)
        $this->pay($w, '300.00', '2026-10-01');              // A: 700.00 left
        $concession = $this->requestTargeted($w, '200.00');  // A: 500.00 left after approval
        $this->concessions()->approve($w['school'], $concession->id, $w['checker']);
        [$paidUp, $paidCharge] = $this->owing($w, '400.00', '2026-09-01', 'Paid');
        $this->pay($w, '400.00', '2026-10-02', $paidCharge);
        [$b] = $this->owing($w, '250.00', '2026-10-01', 'Late');

        $this->viewer($w['school']);
        $rows = $this->rows();

        $this->assertSame('500.00', $rows[$w['student']->id]['outstanding']);
        $this->assertSame('0.00', $rows[$w['student']->id]['overdue'], 'No due date: outstanding, never overdue.');
        $this->assertSame('250.00', $rows[$b->id]['overdue']);
        $this->assertArrayNotHasKey($paidUp->id, $rows, 'A Student who owes nothing is not listed.');
        $this->assertSame(['outstanding' => '750.00', 'overdue' => '250.00', 'currency' => 'INR'], $rows['_totals']);
        $this->assertSame($b->id, array_key_first($rows), 'Most overdue first.');
    }

    #[Test]
    public function overdue_follows_the_school_date_not_utc(): void
    {
        $w = $this->concessionWorld();
        [$student] = $this->owing($w, '100.00', '2026-10-15');
        $this->viewer($w['school']);

        // 2026-10-15 19:00 UTC = 16 Oct 00:30 in Asia/Kolkata: the 15 Oct due date has passed.
        $this->travelTo(CarbonImmutable::parse('2026-10-15T19:00:00Z'));
        $this->assertSame('100.00', $this->rows()[$student->id]['overdue']);

        // 2026-10-15 18:00 UTC = 15 Oct 23:30 there: due today, not yet overdue.
        $this->travelTo(CarbonImmutable::parse('2026-10-15T18:00:00Z'));
        $row = $this->rows()[$student->id];
        $this->assertSame('0.00', $row['overdue']);
        $this->assertSame('2026-10-15', $row['nextDueDate']);
    }

    #[Test]
    public function filters_combine_status_year_search_and_class(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15T06:00:00Z'));
        $w = $this->concessionWorld();
        [$late] = $this->owing($w, '250.00', '2026-10-01', 'Late');
        [$future] = $this->owing($w, '90.00', '2026-12-01', 'Future');
        $otherYear = $this->createAcademicYear($w['school'], ['code' => 'OLD', 'starts_on' => '2024-06-01', 'ends_on' => '2025-05-31']);
        $old = $this->createStudent($w['school'], ['first_name' => 'Old', 'last_name' => 'Year']);
        $this->assessCharge($w['school'], $old, $otherYear, $w['receivable'], $w['revenue'], '60.00');
        $this->inSchool($w['school'], fn () => $w['year']->update(['status' => 'active']));
        $this->createStudentEnrollment($late, $w['section'], ['status' => 'active']);
        $this->viewer($w['school'], ['enrollments.view']);

        $this->assertSame([$late->id], array_values(array_diff(array_keys($this->rows('?status=overdue')), ['_totals'])));
        $this->assertSame([$old->id], array_values(array_diff(array_keys($this->rows("?academic_year_id={$otherYear->id}")), ['_totals'])));
        $this->assertSame([$future->id], array_values(array_diff(array_keys($this->rows('?q=future+owing')), ['_totals'])), 'Full name search.');
        $this->assertSame([$late->id], array_values(array_diff(array_keys($this->rows("?section_id={$w['section']->id}")), ['_totals'])));
        $this->assertSame([], array_values(array_diff(array_keys($this->rows("?status=overdue&section_id={$w['section']->id}&q=nobody")), ['_totals'])));
        $this->assertNotNull($this->rows("?section_id={$w['section']->id}")[$late->id]['class']);
    }

    #[Test]
    public function class_is_shown_and_filterable_only_with_enrollments_view(): void
    {
        $w = $this->concessionWorld();
        $this->viewer($w['school']);

        $props = $this->get("/app/finance/outstanding?section_id={$w['section']->id}")->assertOk()->viewData('page')['props'];
        $this->assertFalse($props['canSeeClass']);
        $this->assertSame([], $props['sections']);
        $this->assertArrayHasKey($w['student']->id, collect($props['rows']['data'])->keyBy('studentId')->all(), 'The class filter is ignored without enrollments.view.');
    }

    #[Test]
    public function the_list_needs_both_finance_view_capabilities_and_never_shows_another_school(): void
    {
        $w = $this->concessionWorld();
        $other = $this->concessionWorld();

        foreach ([['finance.charges.view'], ['finance.payments.view'], ['students.view', 'enrollments.view']] as $caps) {
            $user = $this->createUserWithCapabilities($w['school'], $caps);
            $this->actingAs($user)->post("/app/schools/{$w['school']->id}/activate");
            $this->get('/app/finance/outstanding')->assertForbidden();
        }

        $this->viewer($w['school'], ['enrollments.view']);
        $rows = $this->rows();
        $this->assertArrayHasKey($w['student']->id, $rows);
        $this->assertArrayNotHasKey($other['student']->id, $rows);

        // Tampered filters: another School's year is refused; its Section narrows to nothing.
        $this->get("/app/finance/outstanding?academic_year_id={$other['year']->id}")->assertSessionHasErrors('academic_year_id');
        $this->inSchool($w['school'], fn () => $w['year']->update(['status' => 'active']));
        $this->assertSame([], array_values(array_diff(array_keys($this->rows("?section_id={$other['section']->id}")), ['_totals'])));
        $this->get('/app/finance/outstanding?section_id=not-a-uuid')->assertSessionHasErrors('section_id');
    }

    #[Test]
    public function each_view_is_audited_without_student_ids_or_amounts(): void
    {
        $w = $this->concessionWorld();
        $this->viewer($w['school']);
        $this->get('/app/finance/outstanding?status=overdue')->assertOk();

        $event = $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()->where('event_type', 'fee_outstanding_list.viewed')->sole());
        // jsonb does not keep key order.
        $this->assertEqualsCanonicalizing(['status' => 'overdue', 'academicYearId' => null, 'narrowed' => false], $event->metadata);
    }

    #[Test]
    public function the_list_reads_a_bounded_number_of_queries_whatever_the_number_of_students(): void
    {
        $w = $this->concessionWorld();
        $this->viewer($w['school']);
        $count = function () {
            $this->get('/app/finance/outstanding')->assertOk(); // warm-up: capability cache
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get('/app/finance/outstanding')->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        for ($i = 0; $i < 3; $i++) {
            $this->owing($w, '10.00', null, "S{$i}");
        }
        $few = $count();
        for ($i = 0; $i < 30; $i++) {
            $this->owing($w, '10.00', null, "T{$i}");
        }
        $this->assertSame($few, $count(), 'No query per Student or charge.');
    }
}
