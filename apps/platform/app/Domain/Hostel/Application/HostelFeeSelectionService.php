<?php

namespace App\Domain\Hostel\Application;

use App\Domain\AcademicStructure\Application\CurrentAcademicYearResolver;
use App\Domain\Fees\Application\Sources\FeeSelectionSource;
use App\Domain\Fees\Application\Sources\FeeSourceSelectionService;
use App\Domain\Hostel\Application\Exceptions\HostelCarryForwardYearInvalidException;
use App\Domain\Hostel\Application\Exceptions\HostelFeeHeadNotSelectableException;
use App\Domain\Hostel\Infrastructure\Hostel;
use App\Domain\Hostel\Infrastructure\HostelBed;
use App\Domain\Hostel\Infrastructure\HostelFeeHead;
use App\Domain\Hostel\Infrastructure\HostelFeeSelection;
use App\Domain\Hostel\Infrastructure\HostelResidencyAssignment;
use App\Domain\Hostel\Infrastructure\HostelRoom;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * OPF.2 (ADR 0067 §15): Hostel's side of the Operational Fee Integration --
 * the ONLY writer of `hostel_fee_heads` and `hostel_fee_selections`. The
 * recurring accommodation fee only: deposits stay deferred (D2).
 *
 * - **Intent, never money.** Starting a residency records Hostel fee
 *   selection intent for the year; ending it withdraws every selection it
 *   recorded. Both go through FEE's trusted source seam
 *   (FeeSourceSelectionService) -- the same seam Transport uses, never a
 *   Hostel-specific FEE API. Nothing here assesses, cancels or alters a
 *   charge; only FEE assessment runs (`finance.fee_assessments.run`) make
 *   money, a full period at a time (no proration, D5). A move is end +
 *   assign: the old residency's intent is withdrawn, the new one's recorded.
 * - **Amounts stay in FEE (D7).** The tier is the bed's room override, else
 *   its Hostel's default; each maps to a fee head and FEE's instalments own
 *   the amount. An unmapped tier records nothing and never fails the
 *   residency.
 * - **Academic year.** A new residency records intent for the School's
 *   active year (CurrentAcademicYearResolver). Later years only through the
 *   explicit, audited carry-forward (D8); nothing carries itself.
 * - **Exactly once.** One provenance row per residency x year
 *   (`hostel_fee_selections_one_per_year`) plus FEE's one-active-selection
 *   key. The residency row is locked first, so a concurrent end() or
 *   carry-forward of the same residency serializes behind it.
 * - **Authorization** is the caller's Hostel capability
 *   (`hostel.residency.manage` / `hostel.directory.manage`, checked by the
 *   controllers); this grants no Finance capability (D9).
 */
class HostelFeeSelectionService
{
    public function __construct(
        private readonly FeeSourceSelectionService $fees,
        private readonly CurrentAcademicYearResolver $years,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * Called by HostelResidencyService::assign() inside its transaction,
     * right after the residency row is written.
     */
    public function recordForNewResidency(HostelResidencyAssignment $residency, ?User $actor): void
    {
        $school = $residency->school;
        $tier = $this->tierFor($residency);
        if ($tier === null) {
            return; // an unmapped tier bills nothing
        }

        $year = $this->years->tryResolve($school);
        if ($year === null) {
            $this->notApplicable($school, $residency, null, $tier['mapping']->fee_head_id, 'no_active_academic_year', HostelFeeSelection::REASON_RESIDENCY, $actor);

            return;
        }

        $this->link($residency, $year->id, HostelFeeSelection::REASON_RESIDENCY, $actor);
    }

    /**
     * Called by HostelResidencyService::end() inside its transaction:
     * withdraws every still-active selection this residency recorded (future
     * assessment only; no charge is touched).
     */
    public function withdrawForEndedResidency(HostelResidencyAssignment $residency, ?User $actor): void
    {
        $school = $residency->school;
        $links = HostelFeeSelection::query()->where('hostel_residency_assignment_id', $residency->id)->orderBy('academic_year_id')->get();

        foreach ($links as $link) {
            if ($this->fees->withdrawForSource($school, $link->fee_optional_selection_id, FeeSelectionSource::hostel($residency->id), $actor)) {
                $this->audit->school($school, 'hostel.fee_selection.withdrawn', actor: $actor, subject: $residency, metadata: [
                    'hostelResidencyAssignmentId' => $residency->id,
                    'studentId' => $residency->student_id,
                    'academicYearId' => $link->academic_year_id,
                    'feeHeadId' => $link->fee_head_id,
                    'feeOptionalSelectionId' => $link->fee_optional_selection_id,
                ]);
            }
        }
    }

    /**
     * D8: records next year's Hostel fee intent for every residency that is
     * still active. Idempotent (a linked residency-year is skipped) and
     * race-safe (each residency is locked and re-checked in its own
     * transaction). Audited once as a summary, and per newly linked
     * residency.
     *
     * @return array{linked: int, already_linked: int, unmapped: int, ended: int, not_applicable: int}
     */
    public function carryForward(School $school, string $academicYearId, ?User $actor): array
    {
        if ($this->years->openYear($school, $academicYearId) === null) {
            throw new HostelCarryForwardYearInvalidException;
        }

        $totals = ['linked' => 0, 'already_linked' => 0, 'unmapped' => 0, 'ended' => 0, 'not_applicable' => 0];

        $this->context->withSchool($school, function () use ($school, $academicYearId, $actor, &$totals): void {
            HostelResidencyAssignment::query()->where('school_id', $school->id)->where('status', 'active')->orderBy('id')
                ->select('id')->chunkById(200, function ($residencies) use ($academicYearId, $actor, &$totals): void {
                    foreach ($residencies as $row) {
                        $outcome = DB::transaction(function () use ($row, $academicYearId, $actor): string {
                            $residency = HostelResidencyAssignment::query()->lockForUpdate()->find($row->id);
                            if ($residency === null || ! $residency->isActive()) {
                                return 'ended';
                            }

                            return $this->link($residency, $academicYearId, HostelFeeSelection::REASON_CARRY_FORWARD, $actor);
                        });
                        $totals[$outcome]++;
                    }
                });

            $this->audit->school($school, 'hostel.fee_selection.carried_forward', actor: $actor, metadata: [
                'academicYearId' => $academicYearId,
                ...$totals,
            ]);
        });

        return $totals;
    }

    /**
     * D7: maps a Hostel's default accommodation tier to an active fee head of
     * its School, or clears it (null). Affects only residencies started
     * afterwards and later carry-forwards; recorded intent is never rewritten.
     */
    public function setHostelFeeHead(Hostel $hostel, ?string $feeHeadId, ?User $actor): ?HostelFeeHead
    {
        $school = $hostel->school;
        $this->assertSelectable($school, $feeHeadId);

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $hostel, $feeHeadId, $actor) {
            Hostel::query()->whereKey($hostel->id)->lockForUpdate()->firstOrFail();

            return $this->writeMapping($school, $hostel->id, null, $feeHeadId, 'hostel.hostel_fee_head', $hostel, $actor);
        }));
    }

    /**
     * D7: overrides the tier for one room of a Hostel (or clears the
     * override, falling back to the Hostel default). Same effect rules as
     * setHostelFeeHead().
     */
    public function setRoomFeeHead(HostelRoom $room, ?string $feeHeadId, ?User $actor): ?HostelFeeHead
    {
        $school = $room->school;
        $this->assertSelectable($school, $feeHeadId);

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $room, $feeHeadId, $actor) {
            $locked = HostelRoom::query()->whereKey($room->id)->lockForUpdate()->firstOrFail();

            return $this->writeMapping($school, $locked->hostel_id, $locked->id, $feeHeadId, 'hostel.room_fee_head', $locked, $actor);
        }));
    }

    /** @return array{feeHeadId: string, code: string, name: string}|null the Hostel's default fee head */
    public function hostelFeeHead(Hostel $hostel): ?array
    {
        $mapping = $this->context->withSchool($hostel->school, fn () => HostelFeeHead::query()
            ->where('hostel_id', $hostel->id)->whereNull('hostel_room_id')->first());

        return $this->present($hostel->school, $mapping);
    }

    /** @return array{feeHeadId: string, code: string, name: string}|null the room's override fee head (not the Hostel default) */
    public function roomFeeHead(HostelRoom $room): ?array
    {
        $mapping = $this->context->withSchool($room->school, fn () => HostelFeeHead::query()
            ->where('hostel_room_id', $room->id)->first());

        return $this->present($room->school, $mapping);
    }

    private function assertSelectable(School $school, ?string $feeHeadId): void
    {
        if ($feeHeadId !== null && $this->fees->selectableFeeHead($school, $feeHeadId) === null) {
            throw new HostelFeeHeadNotSelectableException;
        }
    }

    private function writeMapping(School $school, string $hostelId, ?string $roomId, ?string $feeHeadId, string $event, Hostel|HostelRoom $subject, ?User $actor): ?HostelFeeHead
    {
        $current = HostelFeeHead::query()->where('hostel_id', $hostelId)
            ->when($roomId === null, fn ($q) => $q->whereNull('hostel_room_id'), fn ($q) => $q->where('hostel_room_id', $roomId))
            ->first();
        $previous = $current?->fee_head_id;

        if ($feeHeadId === null) {
            $current?->delete();
        } elseif ($current === null) {
            $current = HostelFeeHead::query()->create(['school_id' => $school->id, 'hostel_id' => $hostelId, 'hostel_room_id' => $roomId, 'fee_head_id' => $feeHeadId]);
        } else {
            $current->update(['fee_head_id' => $feeHeadId]);
        }

        if ($previous !== $feeHeadId) {
            $this->audit->school($school, $feeHeadId === null ? "{$event}.cleared" : "{$event}.set", actor: $actor, subject: $subject, metadata: [
                'hostelId' => $hostelId,
                'hostelRoomId' => $roomId,
                'feeHeadId' => $feeHeadId,
                'previousFeeHeadId' => $previous,
            ]);
        }

        return $feeHeadId === null ? null : $current;
    }

    /** @return array{feeHeadId: string, code: string, name: string}|null */
    private function present(School $school, ?HostelFeeHead $mapping): ?array
    {
        if ($mapping === null) {
            return null;
        }
        $head = $this->fees->selectableFeeHead($school, $mapping->fee_head_id);

        return [
            'feeHeadId' => $mapping->fee_head_id,
            'code' => $head['code'] ?? '',
            'name' => $head['name'] ?? '',
        ];
    }

    /**
     * The residency's accommodation tier: its bed's room override, else its
     * Hostel's default. Beds and rooms never move, so this is stable for the
     * residency's lifetime (mappings themselves may change for later years).
     *
     * @return array{mapping: HostelFeeHead, scope: string}|null
     */
    private function tierFor(HostelResidencyAssignment $residency): ?array
    {
        $bed = HostelBed::query()->select(['id', 'hostel_room_id'])->find($residency->hostel_bed_id);
        $room = $bed === null ? null : HostelRoom::query()->select(['id', 'hostel_id'])->find($bed->hostel_room_id);
        if ($room === null) {
            return null;
        }

        $override = HostelFeeHead::query()->where('hostel_room_id', $room->id)->first();
        if ($override !== null) {
            return ['mapping' => $override, 'scope' => HostelFeeSelection::SCOPE_ROOM];
        }

        $default = HostelFeeHead::query()->where('hostel_id', $room->hostel_id)->whereNull('hostel_room_id')->first();

        return $default === null ? null : ['mapping' => $default, 'scope' => HostelFeeSelection::SCOPE_HOSTEL];
    }

    /**
     * Records intent for one locked, active residency and one year.
     *
     * @return 'linked'|'already_linked'|'unmapped'|'not_applicable'
     */
    private function link(HostelResidencyAssignment $residency, string $academicYearId, string $reason, ?User $actor): string
    {
        $school = $residency->school;
        if (HostelFeeSelection::query()->where('hostel_residency_assignment_id', $residency->id)->where('academic_year_id', $academicYearId)->exists()) {
            return 'already_linked';
        }

        $tier = $this->tierFor($residency);
        if ($tier === null) {
            return 'unmapped';
        }
        $feeHeadId = $tier['mapping']->fee_head_id;

        $result = $this->fees->selectForSource($school, $residency->student_id, $academicYearId, $feeHeadId, FeeSelectionSource::hostel($residency->id), $actor);
        if (! $result->hasSelection()) {
            $this->notApplicable($school, $residency, $academicYearId, $feeHeadId, (string) $result->reason, $reason, $actor);

            return 'not_applicable';
        }

        try {
            // A savepoint: a concurrent identical link must not abort the caller's transaction.
            $link = DB::transaction(fn () => HostelFeeSelection::query()->create([
                'school_id' => $school->id,
                'hostel_residency_assignment_id' => $residency->id,
                'academic_year_id' => $academicYearId,
                'fee_head_id' => $feeHeadId,
                'fee_optional_selection_id' => $result->selectionId,
                'mapping_scope' => $tier['scope'],
                'link_reason' => $reason,
                'selection_outcome' => $result->outcome,
            ]));
        } catch (UniqueConstraintViolationException $e) {
            if (! str_contains($e->getMessage(), 'hostel_fee_selections_one_per_year')) {
                throw $e;
            }

            return 'already_linked';
        }

        $this->audit->school($school, 'hostel.fee_selection.linked', actor: $actor, subject: $residency, metadata: [
            'hostelResidencyAssignmentId' => $residency->id,
            'studentId' => $residency->student_id,
            'academicYearId' => $academicYearId,
            'feeHeadId' => $feeHeadId,
            'mappingScope' => $tier['scope'],
            'feeOptionalSelectionId' => $link->fee_optional_selection_id,
            'selectionOutcome' => $result->outcome,
            'linkReason' => $reason,
        ]);

        return 'linked';
    }

    /** A mapped tier whose fee intent could not be recorded: actionable, so audited (never fails the residency). */
    private function notApplicable(School $school, HostelResidencyAssignment $residency, ?string $academicYearId, string $feeHeadId, string $why, string $reason, ?User $actor): void
    {
        $this->audit->school($school, 'hostel.fee_selection.not_applicable', actor: $actor, subject: $residency, metadata: [
            'hostelResidencyAssignmentId' => $residency->id,
            'studentId' => $residency->student_id,
            'academicYearId' => $academicYearId,
            'feeHeadId' => $feeHeadId,
            'reason' => $why,
            'linkReason' => $reason,
        ]);
    }
}
