<?php

namespace Tests\Feature\Hostel;

use App\Domain\Fees\Application\FeeOptionalSelectionService;
use App\Domain\Fees\Application\FeeStructureService;
use App\Domain\Fees\Infrastructure\Charge;
use App\Domain\Fees\Infrastructure\FeeOptionalSelection;
use App\Domain\Fees\Infrastructure\FeeStructure;
use App\Domain\Hostel\Application\Exceptions\HostelCarryForwardYearInvalidException;
use App\Domain\Hostel\Application\Exceptions\HostelFeeHeadNotSelectableException;
use App\Domain\Hostel\Application\HostelFeeSelectionService;
use App\Domain\Hostel\Application\HostelResidencyService;
use App\Domain\Hostel\Infrastructure\HostelFeeSelection;
use App\Domain\Hostel\Infrastructure\HostelResidencyAssignment;
use App\Domain\Students\Infrastructure\Student;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Fees\Concerns\CreatesFeeAssessmentFixtures;
use Tests\TestCase;

/**
 * OPF.2 (ADR 0067 §15, §28): Hostel records FEE optional-selection INTENT
 * from the residency lifecycle through the same trusted seam as Transport;
 * only Finance assessment runs make money. World: an active year (2026-27)
 * whose active structure has two OPTIONAL accommodation tiers -- STANDARD
 * (T1 5000.00, T2 7000.00) and PREMIUM (T1 8000.00, T2 9000.00) -- and a
 * required Tuition line; one Hostel (default STANDARD once mapped) with a
 * standard room and a premium room (room override PREMIUM once mapped).
 */
class HostelFeeSelectionTest extends TestCase
{
    use CreatesFeeAssessmentFixtures;

    /** @return array<string, mixed> */
    private function world(): array
    {
        $w = $this->feeWorld();
        $w['finance'] = $this->createUserWithCapabilities($w['school'], [
            ...self::FEE_SETUP_CAPABILITIES, 'finance.fee_assessments.run', 'finance.charges.view', 'finance.charges.manage',
        ]);
        $w['actor'] = $w['finance'];
        $w['hostelStaff'] = $this->createUserWithCapabilities($w['school'], [
            'hostel.directory.view', 'hostel.directory.manage', 'hostel.residency.view', 'hostel.residency.manage',
        ]);
        $w['section'] = $this->createSection($w['year'], $w['campus'], $w['grade']);
        $w['standard'] = $this->makeFeeHead($w, ['code' => 'HOSTEL-STD', 'name' => 'Hostel standard']);
        $w['premium'] = $this->makeFeeHead($w, ['code' => 'HOSTEL-PRM', 'name' => 'Hostel premium']);
        $w['tuition'] = $this->makeFeeHead($w);
        $w['structure'] = $this->activeStructure($w);

        $w['hostel'] = $this->createHostel($w['school'], $w['campus']);
        $w['standardRoom'] = $this->createHostelRoom($w['hostel'], ['code' => 'STD-1']);
        $w['premiumRoom'] = $this->createHostelRoom($w['hostel'], ['code' => 'PRM-1']);
        $w['beds'] = 0;

        return $w;
    }

    /** @param  array<string, mixed>  $w */
    private function activeStructure(array $w): FeeStructure
    {
        $service = app(FeeStructureService::class);
        $structure = $this->makeDraftStructure($w, ['code' => 'G-DEF']);
        foreach ([[$w['standard'], true, '5000.00', '7000.00'], [$w['premium'], true, '8000.00', '9000.00'], [$w['tuition'], false, '5000.00', '7000.00']] as [$head, $optional, $t1, $t2]) {
            $line = $service->addLine($w['school'], $structure->id, ['fee_head_id' => $head->id, 'amount' => bcadd($t1, $t2, 2), 'is_optional' => $optional], $w['actor']);
            $service->replaceInstallments($w['school'], $structure->id, $line->id, [
                ['label' => 'Term 1', 'billing_period_key' => 'T1', 'period_starts_on' => '2026-06-01', 'period_ends_on' => '2026-10-31', 'due_date' => '2026-06-10', 'amount' => $t1],
                ['label' => 'Term 2', 'billing_period_key' => 'T2', 'period_starts_on' => '2026-11-01', 'period_ends_on' => '2027-05-31', 'due_date' => '2026-11-10', 'amount' => $t2],
            ], $w['actor']);
        }

        return $service->activate($w['school'], $structure->id, $w['actor']);
    }

    private function fees(): HostelFeeSelectionService
    {
        return app(HostelFeeSelectionService::class);
    }

    /** The Hostel default is STANDARD and the premium room overrides it with PREMIUM. @param  array<string, mixed>  $w */
    private function map(array $w): void
    {
        $this->fees()->setHostelFeeHead($w['hostel'], $w['standard']->id, $w['hostelStaff']);
        $this->fees()->setRoomFeeHead($w['premiumRoom'], $w['premium']->id, $w['hostelStaff']);
    }

    /** @param  array<string, mixed>  $w */
    private function assign(array &$w, Student $student, string $room = 'standardRoom'): HostelResidencyAssignment
    {
        $bed = $this->createHostelBed($w[$room], ['code' => 'BED-'.(++$w['beds'])]);

        return $this->inSchool($w['school'], fn () => app(HostelResidencyService::class)->assign($student, $bed, $w['hostelStaff']));
    }

    /** @param  array<string, mixed>  $w */
    private function end(array $w, HostelResidencyAssignment $residency): void
    {
        $this->inSchool($w['school'], fn () => app(HostelResidencyService::class)->end($residency, $w['hostelStaff']));
    }

    /** @param  array<string, mixed>  $w */
    private function student(array $w): Student
    {
        return $this->inSchool($w['school'], fn () => Student::query()->findOrFail($this->enroll($w)->student_id));
    }

    /** @param  array<string, mixed>  $w */
    private function selections(array $w, Student $student): Collection
    {
        return $this->inSchool($w['school'], fn () => FeeOptionalSelection::query()->where('student_id', $student->id)->orderBy('created_at')->orderBy('id')->get());
    }

    /** @param  array<string, mixed>  $w */
    private function links(array $w, ?HostelResidencyAssignment $residency = null): Collection
    {
        return $this->inSchool($w['school'], fn () => HostelFeeSelection::query()
            ->when($residency !== null, fn ($q) => $q->where('hostel_residency_assignment_id', $residency->id))->orderBy('created_at')->orderBy('id')->get());
    }

    /** @param  array<string, mixed>  $w */
    private function charges(array $w, Student $student): Collection
    {
        return $this->inSchool($w['school'], fn () => Charge::query()->where('student_id', $student->id)->orderBy('created_at')->get());
    }

    /** @return array<string, list<string>> charged amounts by fee head id @param  array<string, mixed>  $w */
    private function chargedByHead(array $w, Student $student): array
    {
        $out = [];
        foreach ($this->charges($w, $student) as $charge) {
            $head = $this->inSchool($w['school'], fn () => DB::table('fee_assessments')->where('charge_id', $charge->id)->value('fee_head_id'));
            $out[$head][] = $charge->amount;
        }

        return $out;
    }

    /** @param  array<string, mixed>  $w */
    private function audits(array $w, string $type): Collection
    {
        return $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()->where('event_type', $type)->get());
    }

    #[Test]
    public function the_room_override_wins_over_the_hostel_default_and_records_one_selection_and_no_charge(): void
    {
        $w = $this->world();
        $this->map($w);
        $student = $this->student($w);

        $residency = $this->assign($w, $student, 'premiumRoom');

        $selection = $this->selections($w, $student)->sole();
        $this->assertSame([FeeOptionalSelection::STATUS_ACTIVE, $w['year']->id, $w['premium']->id, $w['hostelStaff']->id],
            [$selection->status, $selection->academic_year_id, $selection->fee_head_id, $selection->selected_by_user_id]);
        $link = $this->links($w, $residency)->sole();
        $this->assertSame([$w['year']->id, $w['premium']->id, $selection->id, 'room', 'residency', 'created'],
            [$link->academic_year_id, $link->fee_head_id, $link->fee_optional_selection_id, $link->mapping_scope, $link->link_reason, $link->selection_outcome]);
        $this->assertCount(0, $this->charges($w, $student), 'Hostel records intent only; it never assesses money');

        $fee = $this->audits($w, 'fee_optional_selection.created')->sole();
        $this->assertSame(['hostel', $residency->id], [$fee->metadata['sourceModule'], $fee->metadata['sourceId']], 'FEE audits the source');
        $this->assertSame($w['hostelStaff']->id, $fee->actor_user_id);
        $linked = $this->audits($w, 'hostel.fee_selection.linked')->sole();
        $this->assertSame([$selection->id, 'room'], [$linked->metadata['feeOptionalSelectionId'], $linked->metadata['mappingScope']]);

        // A retry records nothing new: one selection, one link.
        $this->inSchool($w['school'], fn () => DB::transaction(fn () => $this->fees()->recordForNewResidency($residency, $w['hostelStaff'])));
        $this->assertCount(1, $this->selections($w, $student));
        $this->assertCount(1, $this->links($w));
    }

    #[Test]
    public function the_hostel_default_applies_without_an_override_an_unmapped_hostel_records_nothing_and_a_manual_selection_is_reused(): void
    {
        $w = $this->world();
        $student = $this->student($w);
        $line = $this->inSchool($w['school'], fn () => DB::table('fee_structure_lines')->where('fee_structure_id', $w['structure']->id)->where('fee_head_id', $w['standard']->id)->value('id'));
        app(FeeOptionalSelectionService::class)->select($w['school'], $student->id, $line, $w['finance']);

        // Unmapped: the residency succeeds and nothing is recorded or audited as not applicable.
        $unmapped = $this->assign($w, $student);
        $this->assertCount(0, $this->links($w));
        $this->assertCount(0, $this->audits($w, 'hostel.fee_selection.not_applicable'));
        $this->end($w, $unmapped);
        $this->assertSame(FeeOptionalSelection::STATUS_ACTIVE, $this->selections($w, $student)->sole()->status, 'an unlinked manual selection is not Hostel\'s to withdraw');

        $this->map($w);
        $residency = $this->assign($w, $student);
        $link = $this->links($w, $residency)->sole();
        $this->assertSame(['hostel', 'reused', $w['standard']->id], [$link->mapping_scope, $link->selection_outcome, $link->fee_head_id]);
        $this->assertCount(1, $this->selections($w, $student));
    }

    #[Test]
    public function not_applicable_cases_are_audited_and_never_fail_the_residency(): void
    {
        $w = $this->world();
        $this->map($w);

        // A Student with no qualifying enrollment in the year.
        $unenrolled = $this->createStudent($w['school']);
        $this->assertSame('active', $this->assign($w, $unenrolled)->status);

        // A tier mapped to a fee head that is not an optional line of the Student's structure.
        $this->fees()->setRoomFeeHead($w['premiumRoom'], $w['tuition']->id, $w['hostelStaff']);
        $this->assertSame('active', $this->assign($w, $this->student($w), 'premiumRoom')->status);

        $this->assertCount(0, $this->links($w));
        $this->assertSame(['no_enrollment_in_year', 'no_optional_line'],
            $this->audits($w, 'hostel.fee_selection.not_applicable')->sortBy('created_at')->map(fn ($a) => $a->metadata['reason'])->values()->all());
    }

    #[Test]
    public function no_active_academic_year_is_audited_as_not_applicable(): void
    {
        $w = $this->world();
        $this->map($w);
        $student = $this->student($w);
        $this->inSchool($w['school'], fn () => DB::table('academic_years')->where('id', $w['year']->id)->update(['status' => 'closed']));

        $residency = $this->assign($w, $student);

        $this->assertSame('active', $residency->status);
        $this->assertCount(0, $this->links($w));
        $audit = $this->audits($w, 'hostel.fee_selection.not_applicable')->sole();
        $this->assertSame(['no_active_academic_year', null], [$audit->metadata['reason'], $audit->metadata['academicYearId']]);
    }

    #[Test]
    public function finance_assesses_the_selection_and_ending_the_residency_withdraws_future_intent_only(): void
    {
        $w = $this->world();
        $this->map($w);
        $student = $this->student($w);
        $residency = $this->assign($w, $student);

        // The authorized Finance run assesses the selected Hostel fee: the full T1 instalment, no proration.
        $this->executedRun($w, 'T1');
        $this->assertSame(['5000.00'], $this->chargedByHead($w, $student)[$w['standard']->id] ?? null, 'T1 STANDARD charged in full by the Finance run');
        $hostelCharge = $this->charges($w, $student)->firstWhere('amount', '5000.00');

        $this->end($w, $residency);
        $this->assertSame(FeeOptionalSelection::STATUS_WITHDRAWN, $this->selections($w, $student)->sole()->status);
        $this->assertNull($this->inSchool($w['school'], fn () => $hostelCharge->refresh()->cancelled_at), 'ending a residency never cancels an assessed charge');
        $this->assertCount(1, $this->audits($w, 'hostel.fee_selection.withdrawn'));

        // Withdrawal is idempotent.
        $this->inSchool($w['school'], fn () => DB::transaction(fn () => $this->fees()->withdrawForEndedResidency($residency->refresh(), $w['hostelStaff'])));
        $this->assertCount(1, $this->audits($w, 'hostel.fee_selection.withdrawn'));
        $this->assertCount(1, $this->audits($w, 'fee_optional_selection.withdrawn'));

        // A later period is not assessed for the withdrawn Hostel fee.
        $run = $this->executedRun($w, 'T2');
        $item = $this->items($w, $run)->where('student_id', $student->id)->firstWhere('fee_head_id', $w['standard']->id);
        $this->assertNotNull($item);
        $this->assertContains('optional_not_selected', [$item->preview_result, $item->reason, $item->failure_reason]);
        $this->assertSame(['5000.00'], $this->chargedByHead($w, $student)[$w['standard']->id]);
    }

    #[Test]
    public function a_move_is_end_plus_assign_with_no_proration_and_no_charge_mutation(): void
    {
        $w = $this->world();
        $this->map($w);
        $student = $this->student($w);
        $standard = $this->assign($w, $student);
        $this->executedRun($w, 'T1');
        $before = $this->charges($w, $student)->map(fn ($c) => [$c->id, $c->amount, $c->cancelled_at])->all();

        // The move: end the standard residency, then assign the premium room.
        $this->end($w, $standard);
        $premium = $this->assign($w, $student, 'premiumRoom');

        $byHead = $this->selections($w, $student)->keyBy('fee_head_id');
        $this->assertSame([FeeOptionalSelection::STATUS_WITHDRAWN, FeeOptionalSelection::STATUS_ACTIVE],
            [$byHead[$w['standard']->id]->status, $byHead[$w['premium']->id]->status]);
        $this->assertSame([[$w['standard']->id, 'hostel']], $this->links($w, $standard)->map(fn ($l) => [$l->fee_head_id, $l->mapping_scope])->all(), 'the old provenance is kept as recorded');
        $this->assertSame([[$w['premium']->id, 'room']], $this->links($w, $premium)->map(fn ($l) => [$l->fee_head_id, $l->mapping_scope])->all());
        $this->assertSame($before, $this->charges($w, $student)->map(fn ($c) => [$c->id, $c->amount, $c->cancelled_at])->all(), 'the move touches no charge');

        // The next period bills the new tier in full; nothing is prorated or credited.
        $this->executedRun($w, 'T2');
        $this->assertSame([$w['standard']->id => ['5000.00'], $w['premium']->id => ['9000.00']],
            array_intersect_key($this->chargedByHead($w, $student), [$w['standard']->id => 1, $w['premium']->id => 1]));
    }

    #[Test]
    public function carry_forward_records_next_years_intent_once_and_keeps_the_prior_year(): void
    {
        $w = $this->world();
        $this->map($w);
        $student = $this->student($w);
        $residency = $this->assign($w, $student, 'premiumRoom');
        $endedStudent = $this->student($w);
        $this->end($w, $this->assign($w, $endedStudent));

        $next = $this->createAcademicYear($w['school'], ['code' => 'AY2027', 'name' => '2027-28', 'starts_on' => '2027-06-01', 'ends_on' => '2028-05-31', 'status' => 'draft']);
        $nextSection = $this->createSection($next, $w['campus'], $w['grade'], ['code' => 'NEXT', 'name' => 'Next']);
        $this->activeNextYearStructure(['school' => $w['school'], 'actor' => $w['finance'], 'year' => $next, 'grade' => $w['grade']], [$w['standard'], $w['premium']]);
        $this->createStudentEnrollment($student, $nextSection, ['starts_on' => '2027-06-01']);
        $this->createStudentEnrollment($endedStudent, $nextSection, ['starts_on' => '2027-06-01']);

        $first = $this->inSchool($w['school'], fn () => $this->fees()->carryForward($w['school'], $next->id, $w['hostelStaff']));
        $this->assertSame(['linked' => 1, 'already_linked' => 0, 'unmapped' => 0, 'ended' => 0, 'not_applicable' => 0], $first, 'the ended residency is not carried');
        $again = $this->inSchool($w['school'], fn () => $this->fees()->carryForward($w['school'], $next->id, $w['hostelStaff']));
        $this->assertSame(['linked' => 0, 'already_linked' => 1, 'unmapped' => 0, 'ended' => 0, 'not_applicable' => 0], $again, 'a rerun is idempotent');

        $this->assertSame([[$w['year']->id, 'residency', 'room'], [$next->id, 'carry_forward', 'room']],
            $this->links($w, $residency)->map(fn ($l) => [$l->academic_year_id, $l->link_reason, $l->mapping_scope])->all());
        $byYear = $this->selections($w, $student)->keyBy('academic_year_id');
        $this->assertSame([FeeOptionalSelection::STATUS_ACTIVE, FeeOptionalSelection::STATUS_ACTIVE], [$byYear[$w['year']->id]->status, $byYear[$next->id]->status], 'the prior year is untouched');
        $this->assertSame($w['premium']->id, $byYear[$next->id]->fee_head_id);
        $this->assertCount(0, $this->selections($w, $endedStudent)->where('academic_year_id', $next->id));
        $this->assertCount(2, $this->audits($w, 'hostel.fee_selection.carried_forward'));

        // A closed year, or another School's year, is refused.
        $closed = $this->createAcademicYear($w['school'], ['code' => 'AY2020', 'status' => 'closed', 'starts_on' => '2020-06-01', 'ends_on' => '2021-05-31']);
        $this->assertThrows(fn () => $this->inSchool($w['school'], fn () => $this->fees()->carryForward($w['school'], $closed->id, $w['hostelStaff'])), HostelCarryForwardYearInvalidException::class);
        $this->expectException(HostelCarryForwardYearInvalidException::class);
        $other = $this->createAcademicYear($this->createSchool(), ['status' => 'draft']);
        $this->inSchool($w['school'], fn () => $this->fees()->carryForward($w['school'], $other->id, $w['hostelStaff']));
    }

    /** @param  array<string, mixed>  $nw  @param  list<\App\Domain\Fees\Infrastructure\FeeHead>  $heads */
    private function activeNextYearStructure(array $nw, array $heads): void
    {
        $service = app(FeeStructureService::class);
        $structure = $service->createDraft($nw['school'], ['academic_year_id' => $nw['year']->id, 'grade_level_id' => $nw['grade']->id, 'campus_id' => null, 'code' => 'G-NEXT', 'name' => 'Next'], $nw['actor']);
        foreach ($heads as $head) {
            $line = $service->addLine($nw['school'], $structure->id, ['fee_head_id' => $head->id, 'amount' => '1000.00', 'is_optional' => true], $nw['actor']);
            $service->replaceInstallments($nw['school'], $structure->id, $line->id, [
                ['label' => 'Year', 'billing_period_key' => 'Y1', 'period_starts_on' => '2027-06-01', 'period_ends_on' => '2028-05-31', 'due_date' => '2027-06-10', 'amount' => '1000.00'],
            ], $nw['actor']);
        }
        $service->activate($nw['school'], $structure->id, $nw['actor']);
    }

    #[Test]
    public function the_mapping_holds_no_amount_and_is_school_and_hostel_constrained_in_the_database(): void
    {
        $w = $this->world();
        $this->assertStringNotContainsString('amount', implode(',', DB::getSchemaBuilder()->getColumnListing('hostel_fee_heads')), 'FEE owns the amount');

        $this->map($w);
        $this->assertSame($w['standard']->id, $this->fees()->hostelFeeHead($w['hostel'])['feeHeadId']);
        $this->assertSame($w['premium']->id, $this->fees()->roomFeeHead($w['premiumRoom'])['feeHeadId']);
        $this->assertNull($this->fees()->roomFeeHead($w['standardRoom']), 'no override: the room follows the Hostel default');
        $this->fees()->setRoomFeeHead($w['premiumRoom'], null, $w['hostelStaff']);
        $this->assertNull($this->fees()->roomFeeHead($w['premiumRoom']));
        $this->assertSame([1, 1, 1], [$this->audits($w, 'hostel.hostel_fee_head.set')->count(), $this->audits($w, 'hostel.room_fee_head.set')->count(), $this->audits($w, 'hostel.room_fee_head.cleared')->count()]);

        $foreign = $this->makeFeeHead(['school' => $other = $this->createSchool(), 'actor' => $this->createUserWithCapabilities($other, self::FEE_SETUP_CAPABILITIES),
            'receivable' => $this->createLedgerAccount($other, ['code' => 'AR-X', 'type' => 'asset']), 'revenue' => $this->createLedgerAccount($other, ['code' => 'INC-X', 'type' => 'income'])]);
        $this->assertThrows(fn () => $this->fees()->setHostelFeeHead($w['hostel'], $foreign->id, $w['hostelStaff']), HostelFeeHeadNotSelectableException::class);
        $this->assertThrows(fn () => $this->fees()->setRoomFeeHead($w['premiumRoom'], $foreign->id, $w['hostelStaff']), HostelFeeHeadNotSelectableException::class);

        $insert = fn (array $over) => $this->inSchool($w['school'], fn () => DB::transaction(fn () => DB::table('hostel_fee_heads')->insert(array_merge([
            'id' => (string) Str::uuid7(), 'school_id' => $w['school']->id, 'hostel_id' => $w['hostel']->id, 'hostel_room_id' => null,
            'fee_head_id' => $w['premium']->id, 'created_at' => now(), 'updated_at' => now(),
        ], $over))));

        // The database refuses a cross-School fee head, a second Hostel default, and a room of another Hostel.
        $this->assertThrows(fn () => $insert(['hostel_room_id' => $w['standardRoom']->id, 'fee_head_id' => $foreign->id]), QueryException::class, 'hostel_fee_heads_fee_head_fk');
        $this->assertThrows(fn () => $insert([]), QueryException::class, 'hostel_fee_heads_one_per_hostel');
        $otherHostel = $this->createHostel($w['school'], $w['campus']);
        $this->assertThrows(fn () => $insert(['hostel_id' => $otherHostel->id, 'hostel_room_id' => $w['standardRoom']->id]), QueryException::class, 'hostel_fee_heads_room_fk');
        $insert(['hostel_room_id' => $w['standardRoom']->id]);
        $this->assertThrows(fn () => $insert(['hostel_room_id' => $w['standardRoom']->id]), QueryException::class, 'hostel_fee_heads_one_per_room');
    }

    #[Test]
    public function the_provenance_row_is_unique_consistent_and_insert_only_in_the_database(): void
    {
        $w = $this->world();
        $this->map($w);
        $student = $this->student($w);
        $residency = $this->assign($w, $student);
        $link = $this->links($w, $residency)->sole();
        $row = fn (array $over) => array_merge([
            'id' => (string) Str::uuid7(), 'school_id' => $w['school']->id, 'hostel_residency_assignment_id' => $residency->id,
            'academic_year_id' => $w['year']->id, 'fee_head_id' => $w['standard']->id, 'fee_optional_selection_id' => $link->fee_optional_selection_id,
            'mapping_scope' => 'hostel', 'link_reason' => 'residency', 'selection_outcome' => 'reused', 'created_at' => now(),
        ], $over);
        $insert = fn (array $over) => $this->inSchool($w['school'], fn () => DB::transaction(fn () => DB::table('hostel_fee_selections')->insert($row($over))));

        $this->assertThrows(fn () => $insert([]), QueryException::class, 'hostel_fee_selections_one_per_year');

        // Another Student's selection can never be recorded as this residency's provenance.
        $otherStudent = $this->student($w);
        $line = $this->inSchool($w['school'], fn () => DB::table('fee_structure_lines')->where('fee_structure_id', $w['structure']->id)->where('fee_head_id', $w['standard']->id)->value('id'));
        $foreignSelection = app(FeeOptionalSelectionService::class)->select($w['school'], $otherStudent->id, $line, $w['finance']);
        $next = $this->createAcademicYear($w['school'], ['code' => 'AY27', 'status' => 'draft', 'starts_on' => '2027-06-01', 'ends_on' => '2028-05-31']);
        $this->assertThrows(fn () => $insert(['academic_year_id' => $next->id, 'fee_optional_selection_id' => $foreignSelection->id]), QueryException::class, "not the residency Student's selection");
        $this->assertThrows(fn () => $insert(['mapping_scope' => 'bed']), QueryException::class, 'hostel_fee_selections_scope_check');

        // Insert-only: the runtime role can neither rewrite nor delete it.
        $this->assertThrows(fn () => $this->inSchool($w['school'], fn () => DB::transaction(fn () => DB::table('hostel_fee_selections')->where('id', $link->id)->update(['link_reason' => 'carry_forward']))), QueryException::class, 'permission denied');
        $this->assertThrows(fn () => $this->inSchool($w['school'], fn () => DB::transaction(fn () => DB::table('hostel_fee_selections')->where('id', $link->id)->delete())), QueryException::class, 'permission denied');

        // The provenance keeps its residency (and its selection): both foreign keys RESTRICT a delete.
        $this->assertSame(['r', 'r'], array_map(fn ($r) => $r->confdeltype, DB::select(
            "select confdeltype from pg_constraint where conname in ('hostel_fee_selections_residency_fk', 'hostel_fee_selections_selection_fk') order by conname",
        )));
    }

    #[Test]
    public function hostel_authority_records_intent_but_never_assesses_and_finance_authority_is_unchanged(): void
    {
        $w = $this->world();
        $base = "/api/v1/schools/{$w['school']->id}";
        $as = fn (User $u) => $this->actingAs($u)->withHeader('X-School-Id', $w['school']->id);

        $as($w['hostelStaff'])->putJson("{$base}/hostels/{$w['hostel']->id}/fee-head", ['fee_head_id' => $w['standard']->id])
            ->assertOk()->assertJsonPath('data.feeHeadId', $w['standard']->id);
        $as($w['hostelStaff'])->putJson("{$base}/hostel-rooms/{$w['premiumRoom']->id}/fee-head", ['fee_head_id' => $w['premium']->id])
            ->assertOk()->assertJsonPath('data.code', 'HOSTEL-PRM');
        $as($w['hostelStaff'])->getJson("{$base}/hostels/{$w['hostel']->id}/fee-head")->assertOk()->assertJsonPath('data.code', 'HOSTEL-STD');
        $as($w['hostelStaff'])->getJson("{$base}/hostel-rooms/{$w['standardRoom']->id}/fee-head")->assertOk()->assertJsonPath('data', null);

        // Hostel viewers without manage cannot configure; Finance staff without Hostel capabilities cannot either.
        $viewer = $this->createUserWithCapabilities($w['school'], ['hostel.directory.view', 'hostel.residency.view']);
        $as($viewer)->putJson("{$base}/hostels/{$w['hostel']->id}/fee-head", ['fee_head_id' => null])->assertForbidden();
        $as($viewer)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("{$base}/hostel-fee-selections/carry-forward", ['academic_year_id' => $w['year']->id])->assertForbidden();
        $as($w['finance'])->putJson("{$base}/hostels/{$w['hostel']->id}/fee-head", ['fee_head_id' => null])->assertForbidden();
        $as($w['finance'])->putJson("{$base}/hostel-rooms/{$w['premiumRoom']->id}/fee-head", ['fee_head_id' => null])->assertForbidden();
        $as($w['finance'])->getJson("{$base}/hostels/{$w['hostel']->id}/fee-head")->assertForbidden();
        $as($w['finance'])->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("{$base}/hostel-fee-selections/carry-forward", ['academic_year_id' => $w['year']->id])->assertForbidden();

        // Hostel staff can carry intent forward, but cannot run (or create) a Finance assessment.
        $as($w['hostelStaff'])->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("{$base}/hostel-fee-selections/carry-forward", ['academic_year_id' => $w['year']->id])->assertOk()->assertJsonPath('data.linked', 0);
        $this->assertThrows(fn () => $this->runs()->create($w['school'], $w['structure']->id, 'T1', $w['hostelStaff']), AuthorizationException::class);
        $as($w['hostelStaff'])->postJson("{$base}/fee-assessment-runs", ['fee_structure_id' => $w['structure']->id, 'billing_period_key' => 'T1'])->assertForbidden();
        $line = $this->inSchool($w['school'], fn () => DB::table('fee_structure_lines')->where('fee_structure_id', $w['structure']->id)->where('fee_head_id', $w['standard']->id)->value('id'));
        $this->assertThrows(fn () => app(FeeOptionalSelectionService::class)->select($w['school'], $this->student($w)->id, $line, $w['hostelStaff']), AuthorizationException::class);

        // A School's residency fee intent is readable with Hostel view, and not from another School.
        $student = $this->student($w);
        $residency = $this->assign($w, $student);
        $as($viewer)->getJson("{$base}/hostel-residency-assignments/{$residency->id}/fee-selections")->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.mappingScope', 'hostel');
        $as($w['finance'])->getJson("{$base}/hostel-residency-assignments/{$residency->id}/fee-selections")->assertForbidden();
        [$otherAdmin, $other] = $this->createSchoolAdmin();
        $this->actingAs($otherAdmin)->withHeader('X-School-Id', $other->id)
            ->getJson("/api/v1/schools/{$other->id}/hostel-residency-assignments/{$residency->id}/fee-selections")->assertNotFound();
        $this->actingAs($otherAdmin)->withHeader('X-School-Id', $other->id)
            ->getJson("/api/v1/schools/{$other->id}/hostels/{$w['hostel']->id}/fee-head")->assertNotFound();
    }
}
