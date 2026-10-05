<?php

namespace Tests\Feature\Transport;

use App\Domain\Fees\Application\FeeOptionalSelectionService;
use App\Domain\Fees\Application\FeeStructureService;
use App\Domain\Fees\Infrastructure\Charge;
use App\Domain\Fees\Infrastructure\FeeHead;
use App\Domain\Fees\Infrastructure\FeeOptionalSelection;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Transport\Application\Exceptions\TransportCarryForwardYearInvalidException;
use App\Domain\Transport\Application\Exceptions\TransportFeeHeadNotSelectableException;
use App\Domain\Transport\Application\TransportFeeSelectionService;
use App\Domain\Transport\Application\TransportStudentAssignmentService;
use App\Domain\Transport\Infrastructure\TransportFeeSelection;
use App\Domain\Transport\Infrastructure\TransportRoute;
use App\Domain\Transport\Infrastructure\TransportStudentAssignment;
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
 * OPF.1 (ADR 0067 §14): Transport records FEE optional-selection INTENT from
 * the Student assignment lifecycle; only Finance assessment runs make money.
 * World: an active year (2026-27) whose active structure has an OPTIONAL
 * Transport line (T1 5000.00, T2 7000.00) and a required Tuition line.
 */
class TransportFeeSelectionTest extends TestCase
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
        $w['transport'] = $this->createUserWithCapabilities($w['school'], [
            'transport.assignments.view', 'transport.assignments.manage', 'transport.routes.view', 'transport.routes.manage',
        ]);
        $w['section'] = $this->createSection($w['year'], $w['campus'], $w['grade']);
        $w['transportHead'] = $this->makeFeeHead($w, ['code' => 'TRANSPORT', 'name' => 'Transport']);
        $w['tuition'] = $this->makeFeeHead($w);
        $w['structure'] = $this->activeTwoTermStructure($w, ['code' => 'G-DEF'], $w['transportHead'], true, $w['tuition']);
        $w['route'] = $this->createTransportRoute($w['school']);

        return $w;
    }

    private function fees(): TransportFeeSelectionService
    {
        return app(TransportFeeSelectionService::class);
    }

    /** @param  array<string, mixed>  $w */
    private function map(array $w, ?TransportRoute $route = null, ?string $headId = null): void
    {
        $this->fees()->setRouteFeeHead($route ?? $w['route'], $headId ?? $w['transportHead']->id, $w['transport']);
    }

    /** @param  array<string, mixed>  $w */
    private function assign(array $w, Student $student, ?TransportRoute $route = null): TransportStudentAssignment
    {
        return $this->inSchool($w['school'], fn () => app(TransportStudentAssignmentService::class)->assign($student, $route ?? $w['route'], null, null, $w['transport']));
    }

    /** @param  array<string, mixed>  $w */
    private function end(array $w, TransportStudentAssignment $assignment): void
    {
        $this->inSchool($w['school'], fn () => app(TransportStudentAssignmentService::class)->end($assignment, $w['transport']));
    }

    /** @param  array<string, mixed>  $w */
    private function student(array $w): Student
    {
        return $this->inSchool($w['school'], fn () => Student::query()->findOrFail($this->enroll($w)->student_id));
    }

    /** @param  array<string, mixed>  $w */
    private function selections(array $w, Student $student): Collection
    {
        return $this->inSchool($w['school'], fn () => FeeOptionalSelection::query()->where('student_id', $student->id)->orderBy('created_at')->get());
    }

    /** @param  array<string, mixed>  $w */
    private function links(array $w, ?TransportStudentAssignment $assignment = null): Collection
    {
        return $this->inSchool($w['school'], fn () => TransportFeeSelection::query()
            ->when($assignment !== null, fn ($q) => $q->where('transport_student_assignment_id', $assignment->id))->orderBy('created_at')->get());
    }

    /** @param  array<string, mixed>  $w */
    private function charges(array $w, Student $student): Collection
    {
        return $this->inSchool($w['school'], fn () => Charge::query()->where('student_id', $student->id)->get());
    }

    /** @param  array<string, mixed>  $w */
    private function audits(array $w, string $type): Collection
    {
        return $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()->where('event_type', $type)->get());
    }

    #[Test]
    public function a_mapped_route_records_one_selection_for_the_students_year_and_head_and_no_charge(): void
    {
        $w = $this->world();
        $this->map($w);
        $student = $this->student($w);

        $assignment = $this->assign($w, $student);

        $selection = $this->selections($w, $student)->sole();
        $this->assertSame([FeeOptionalSelection::STATUS_ACTIVE, $w['year']->id, $w['transportHead']->id, $w['transport']->id],
            [$selection->status, $selection->academic_year_id, $selection->fee_head_id, $selection->selected_by_user_id]);
        $link = $this->links($w, $assignment)->sole();
        $this->assertSame([$w['year']->id, $w['transportHead']->id, $selection->id, 'assignment', 'created'],
            [$link->academic_year_id, $link->fee_head_id, $link->fee_optional_selection_id, $link->link_reason, $link->selection_outcome]);
        $this->assertCount(0, $this->charges($w, $student), 'Transport records intent only; it never assesses money');

        $fee = $this->audits($w, 'fee_optional_selection.created')->sole();
        $this->assertSame(['transport', $assignment->id], [$fee->metadata['sourceModule'], $fee->metadata['sourceId']], 'FEE audits the source');
        $this->assertSame($w['transport']->id, $fee->actor_user_id);
        $this->assertSame($selection->id, $this->audits($w, 'transport.fee_selection.linked')->sole()->metadata['feeOptionalSelectionId']);

        // A retry records nothing new: one selection, one link.
        $this->inSchool($w['school'], fn () => DB::transaction(fn () => $this->fees()->recordForNewAssignment($assignment, $w['transport'])));
        $this->assertCount(1, $this->selections($w, $student));
        $this->assertCount(1, $this->links($w));
    }

    #[Test]
    public function an_existing_manual_selection_is_reused_and_an_unmapped_route_records_nothing(): void
    {
        $w = $this->world();
        $student = $this->student($w);
        $line = $this->inSchool($w['school'], fn () => DB::table('fee_structure_lines')->where('fee_structure_id', $w['structure']->id)->where('fee_head_id', $w['transportHead']->id)->value('id'));
        app(FeeOptionalSelectionService::class)->select($w['school'], $student->id, $line, $w['finance']);

        // Unmapped: the assignment succeeds and nothing is recorded or audited as not applicable.
        $unmapped = $this->assign($w, $student);
        $this->assertCount(0, $this->links($w));
        $this->assertCount(0, $this->audits($w, 'transport.fee_selection.not_applicable'));
        $this->end($w, $unmapped);
        $this->assertSame(FeeOptionalSelection::STATUS_ACTIVE, $this->selections($w, $student)->sole()->status, 'an unlinked manual selection is not Transport\'s to withdraw');

        $this->map($w);
        $assignment = $this->assign($w, $student);
        $link = $this->links($w, $assignment)->sole();
        $this->assertSame('reused', $link->selection_outcome);
        $this->assertCount(1, $this->selections($w, $student));
    }

    #[Test]
    public function a_mapped_route_without_a_qualifying_enrollment_is_audited_and_never_fails_the_assignment(): void
    {
        $w = $this->world();
        $this->map($w);
        $student = $this->createStudent($w['school']); // not enrolled in the year

        $assignment = $this->assign($w, $student);

        $this->assertSame('active', $assignment->status);
        $this->assertCount(0, $this->links($w));
        $this->assertSame('no_enrollment_in_year', $this->audits($w, 'transport.fee_selection.not_applicable')->sole()->metadata['reason']);
    }

    #[Test]
    public function finance_assesses_the_selection_and_ending_transport_withdraws_future_intent_only(): void
    {
        $w = $this->world();
        $this->map($w);
        $student = $this->student($w);
        $assignment = $this->assign($w, $student);

        // The authorized Finance run assesses the selected Transport fee: the full T1 instalment, no proration.
        $this->executedRun($w, 'T1');
        $transport = $this->charges($w, $student)->firstWhere('amount', '5000.00');
        $this->assertNotNull($transport, 'T1 Transport charged in full (5000.00) by the Finance run');
        $this->assertCount(2, $this->charges($w, $student), 'Transport T1 and Tuition T1');

        $this->end($w, $assignment);
        $this->assertSame(FeeOptionalSelection::STATUS_WITHDRAWN, $this->selections($w, $student)->sole()->status);
        $this->assertNull($this->inSchool($w['school'], fn () => $transport->refresh()->cancelled_at), 'ending Transport never cancels an assessed charge');
        $this->assertCount(1, $this->audits($w, 'transport.fee_selection.withdrawn'));

        // Withdrawal is idempotent.
        $this->inSchool($w['school'], fn () => DB::transaction(fn () => $this->fees()->withdrawForEndedAssignment($assignment->refresh(), $w['transport'])));
        $this->assertCount(1, $this->audits($w, 'transport.fee_selection.withdrawn'));
        $this->assertCount(1, $this->audits($w, 'fee_optional_selection.withdrawn'));

        // A later period is not assessed for the withdrawn Transport fee.
        $run = $this->executedRun($w, 'T2');
        $item = $this->items($w, $run)->where('student_id', $student->id)->firstWhere('fee_head_id', $w['transportHead']->id);
        $this->assertNotNull($item);
        $this->assertContains('optional_not_selected', [$item->preview_result, $item->reason, $item->failure_reason]);
        $this->assertSame(['5000.00'], $this->charges($w, $student)->filter(fn ($c) => $this->inSchool($w['school'], fn () => DB::table('fee_assessments')->where('charge_id', $c->id)->value('fee_head_id')) === $w['transportHead']->id)->pluck('amount')->values()->all());
    }

    #[Test]
    public function withdrawing_before_a_run_means_that_run_never_assesses_transport(): void
    {
        $w = $this->world();
        $this->map($w);
        $student = $this->student($w);
        $this->end($w, $this->assign($w, $student));

        $this->executedRun($w, 'T1');

        $heads = $this->charges($w, $student)->map(fn ($c) => $this->inSchool($w['school'], fn () => DB::table('fee_assessments')->where('charge_id', $c->id)->value('fee_head_id')))->all();
        $this->assertSame([$w['tuition']->id], array_values($heads), 'only Tuition: Transport was withdrawn before the run executed');
    }

    #[Test]
    public function carry_forward_records_next_years_intent_once_and_keeps_the_prior_year(): void
    {
        $w = $this->world();
        $this->map($w);
        $student = $this->student($w);
        $assignment = $this->assign($w, $student);
        $endedStudent = $this->student($w);
        $this->end($w, $this->assign($w, $endedStudent));

        // Next year: draft, its own active structure with the optional Transport line, the Student enrolled.
        $next = $this->createAcademicYear($w['school'], ['code' => 'AY2027', 'name' => '2027-28', 'starts_on' => '2027-06-01', 'ends_on' => '2028-05-31', 'status' => 'draft']);
        $nextSection = $this->createSection($next, $w['campus'], $w['grade'], ['code' => 'NEXT', 'name' => 'Next']);
        $nextWorld = ['school' => $w['school'], 'actor' => $w['finance'], 'year' => $next, 'grade' => $w['grade']];
        $this->activeNextYearStructure($nextWorld, $w['transportHead'], $w['tuition']);
        $this->createStudentEnrollment($student, $nextSection, ['starts_on' => '2027-06-01']);
        $this->createStudentEnrollment($endedStudent, $nextSection, ['starts_on' => '2027-06-01']);

        $first = $this->inSchool($w['school'], fn () => $this->fees()->carryForward($w['school'], $next->id, $w['transport']));
        $this->assertSame(['linked' => 1, 'already_linked' => 0, 'unmapped' => 0, 'ended' => 0, 'not_applicable' => 0], $first, 'the ended assignment is not carried');
        $again = $this->inSchool($w['school'], fn () => $this->fees()->carryForward($w['school'], $next->id, $w['transport']));
        $this->assertSame(['linked' => 0, 'already_linked' => 1, 'unmapped' => 0, 'ended' => 0, 'not_applicable' => 0], $again, 'a rerun is idempotent');

        $links = $this->links($w, $assignment);
        $this->assertSame([[$w['year']->id, 'assignment'], [$next->id, 'carry_forward']], $links->map(fn ($l) => [$l->academic_year_id, $l->link_reason])->all());
        $byYear = $this->selections($w, $student)->keyBy('academic_year_id');
        $this->assertSame([FeeOptionalSelection::STATUS_ACTIVE, FeeOptionalSelection::STATUS_ACTIVE], [$byYear[$w['year']->id]->status, $byYear[$next->id]->status], 'the prior year is untouched');
        $this->assertCount(0, $this->selections($w, $endedStudent)->where('academic_year_id', $next->id));
        $this->assertCount(2, $this->audits($w, 'transport.fee_selection.carried_forward'));

        // A closed year, or another School's year, is refused.
        $closed = $this->createAcademicYear($w['school'], ['code' => 'AY2020', 'status' => 'closed', 'starts_on' => '2020-06-01', 'ends_on' => '2021-05-31']);
        $this->assertThrows(fn () => $this->inSchool($w['school'], fn () => $this->fees()->carryForward($w['school'], $closed->id, $w['transport'])), TransportCarryForwardYearInvalidException::class);
        $this->expectException(TransportCarryForwardYearInvalidException::class);
        $other = $this->createAcademicYear($this->createSchool(), ['status' => 'draft']);
        $this->inSchool($w['school'], fn () => $this->fees()->carryForward($w['school'], $other->id, $w['transport']));
    }

    /** @param  array<string, mixed>  $nw */
    private function activeNextYearStructure(array $nw, FeeHead $transport, FeeHead $tuition): void
    {
        $service = app(FeeStructureService::class);
        $structure = $service->createDraft($nw['school'], ['academic_year_id' => $nw['year']->id, 'grade_level_id' => $nw['grade']->id, 'campus_id' => null, 'code' => 'G-NEXT', 'name' => 'Next'], $nw['actor']);
        foreach ([[$transport, true], [$tuition, false]] as [$head, $optional]) {
            $line = $service->addLine($nw['school'], $structure->id, ['fee_head_id' => $head->id, 'amount' => '1000.00', 'is_optional' => $optional], $nw['actor']);
            $service->replaceInstallments($nw['school'], $structure->id, $line->id, [
                ['label' => 'Year', 'billing_period_key' => 'Y1', 'period_starts_on' => '2027-06-01', 'period_ends_on' => '2028-05-31', 'due_date' => '2027-06-10', 'amount' => '1000.00'],
            ], $nw['actor']);
        }
        $service->activate($nw['school'], $structure->id, $nw['actor']);
    }

    #[Test]
    public function the_mapping_names_only_an_active_fee_head_of_the_same_school_and_never_an_amount(): void
    {
        $w = $this->world();
        $this->assertStringNotContainsString('amount', implode(',', DB::getSchemaBuilder()->getColumnListing('transport_route_fee_heads')), 'FEE owns the amount');

        $this->map($w);
        $this->assertSame($w['transportHead']->id, $this->fees()->routeFeeHead($w['route'])['feeHeadId']);
        $this->fees()->setRouteFeeHead($w['route'], null, $w['transport']);
        $this->assertNull($this->fees()->routeFeeHead($w['route']));
        $this->assertCount(1, $this->audits($w, 'transport.route_fee_head.set'));
        $this->assertCount(1, $this->audits($w, 'transport.route_fee_head.cleared'));

        $foreign = $this->makeFeeHead(['school' => $other = $this->createSchool(), 'actor' => $this->createUserWithCapabilities($other, self::FEE_SETUP_CAPABILITIES),
            'receivable' => $this->createLedgerAccount($other, ['code' => 'AR-X', 'type' => 'asset']), 'revenue' => $this->createLedgerAccount($other, ['code' => 'INC-X', 'type' => 'income'])]);
        $this->assertThrows(fn () => $this->fees()->setRouteFeeHead($w['route'], $foreign->id, $w['transport']), TransportFeeHeadNotSelectableException::class);

        // The database refuses a cross-School mapping even without the service.
        $this->assertThrows(fn () => $this->inSchool($w['school'], fn () => DB::transaction(fn () => DB::table('transport_route_fee_heads')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $w['school']->id, 'route_id' => $w['route']->id, 'fee_head_id' => $foreign->id, 'created_at' => now(), 'updated_at' => now(),
        ]))), QueryException::class, 'transport_route_fee_heads_fee_head_fk');
    }

    #[Test]
    public function the_provenance_row_is_unique_consistent_and_insert_only_in_the_database(): void
    {
        $w = $this->world();
        $this->map($w);
        $student = $this->student($w);
        $assignment = $this->assign($w, $student);
        $link = $this->links($w, $assignment)->sole();
        $row = fn (array $over) => array_merge([
            'id' => (string) Str::uuid7(), 'school_id' => $w['school']->id, 'transport_student_assignment_id' => $assignment->id,
            'academic_year_id' => $w['year']->id, 'fee_head_id' => $w['transportHead']->id, 'fee_optional_selection_id' => $link->fee_optional_selection_id,
            'link_reason' => 'assignment', 'selection_outcome' => 'reused', 'created_at' => now(),
        ], $over);
        $insert = fn (array $over) => $this->inSchool($w['school'], fn () => DB::transaction(fn () => DB::table('transport_fee_selections')->insert($row($over))));

        $this->assertThrows(fn () => $insert([]), QueryException::class, 'transport_fee_selections_one_per_year');

        // Another Student's selection can never be recorded as this assignment's provenance.
        $otherStudent = $this->student($w);
        $line = $this->inSchool($w['school'], fn () => DB::table('fee_structure_lines')->where('fee_structure_id', $w['structure']->id)->where('fee_head_id', $w['transportHead']->id)->value('id'));
        $foreignSelection = app(FeeOptionalSelectionService::class)->select($w['school'], $otherStudent->id, $line, $w['finance']);
        $next = $this->createAcademicYear($w['school'], ['code' => 'AY27', 'status' => 'draft', 'starts_on' => '2027-06-01', 'ends_on' => '2028-05-31']);
        $this->assertThrows(fn () => $insert(['academic_year_id' => $next->id, 'fee_optional_selection_id' => $foreignSelection->id]), QueryException::class, "not the assignment Student's selection");

        // Insert-only: the runtime role can neither rewrite nor delete it.
        $this->assertThrows(fn () => $this->inSchool($w['school'], fn () => DB::transaction(fn () => DB::table('transport_fee_selections')->where('id', $link->id)->update(['link_reason' => 'carry_forward']))), QueryException::class, 'permission denied');
        $this->assertThrows(fn () => $this->inSchool($w['school'], fn () => DB::transaction(fn () => DB::table('transport_fee_selections')->where('id', $link->id)->delete())), QueryException::class, 'permission denied');
    }

    #[Test]
    public function transport_authority_records_intent_but_never_assesses_and_finance_authority_is_unchanged(): void
    {
        $w = $this->world();
        $base = "/api/v1/schools/{$w['school']->id}";
        $as = fn (User $u) => $this->actingAs($u)->withHeader('X-School-Id', $w['school']->id);

        $as($w['transport'])->putJson("{$base}/transport-routes/{$w['route']->id}/fee-head", ['fee_head_id' => $w['transportHead']->id])
            ->assertOk()->assertJsonPath('data.feeHeadId', $w['transportHead']->id);
        $as($w['transport'])->getJson("{$base}/transport-routes/{$w['route']->id}/fee-head")->assertOk()->assertJsonPath('data.code', 'TRANSPORT');

        // Finance staff without Transport capabilities cannot configure Transport.
        $as($w['finance'])->putJson("{$base}/transport-routes/{$w['route']->id}/fee-head", ['fee_head_id' => null])->assertForbidden();
        $as($w['finance'])->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("{$base}/transport-fee-selections/carry-forward", ['academic_year_id' => $w['year']->id])->assertForbidden();

        // Transport staff can carry intent forward, but cannot run (or create) a Finance assessment.
        $as($w['transport'])->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("{$base}/transport-fee-selections/carry-forward", ['academic_year_id' => $w['year']->id])->assertOk()->assertJsonPath('data.linked', 0);
        $this->assertThrows(fn () => $this->runs()->create($w['school'], $w['structure']->id, 'T1', $w['transport']), AuthorizationException::class);
        $as($w['transport'])->postJson("{$base}/fee-assessment-runs", ['fee_structure_id' => $w['structure']->id, 'billing_period_key' => 'T1'])->assertForbidden();

        // A School's assignment fee intent is not readable from another School.
        $student = $this->student($w);
        $assignment = $this->assign($w, $student);
        $as($w['transport'])->getJson("{$base}/transport-student-assignments/{$assignment->id}/fee-selections")->assertOk()->assertJsonCount(1, 'data');
        [$otherAdmin, $other] = $this->createSchoolAdmin();
        $this->actingAs($otherAdmin)->withHeader('X-School-Id', $other->id)
            ->getJson("/api/v1/schools/{$other->id}/transport-student-assignments/{$assignment->id}/fee-selections")->assertNotFound();
    }
}
