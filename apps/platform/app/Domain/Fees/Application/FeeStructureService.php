<?php

namespace App\Domain\Fees\Application;

use App\Domain\AcademicStructure\Infrastructure\AcademicTerm;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\Fees\Application\Exceptions\DuplicateFeeStructureCodeException;
use App\Domain\Fees\Application\Exceptions\DuplicateFeeStructureLineException;
use App\Domain\Fees\Application\Exceptions\FeeStructureActivationConflictException;
use App\Domain\Fees\Application\Exceptions\FeeStructureIllegalTransitionException;
use App\Domain\Fees\Application\Exceptions\FeeStructureIncompleteException;
use App\Domain\Fees\Application\Exceptions\FeeStructureLineHasSelectionsException;
use App\Domain\Fees\Application\Exceptions\FeeStructureLineNotFoundException;
use App\Domain\Fees\Application\Exceptions\FeeStructureNotDraftException;
use App\Domain\Fees\Application\Exceptions\FeeStructureNotFoundException;
use App\Domain\Fees\Application\Exceptions\InvalidFeeStructureException;
use App\Domain\Fees\Domain\FeeAmount;
use App\Domain\Fees\Domain\FeeCode;
use App\Domain\Fees\Events\FeeStructureActivated;
use App\Domain\Fees\Infrastructure\FeeHead;
use App\Domain\Fees\Infrastructure\FeeOptionalSelection;
use App\Domain\Fees\Infrastructure\FeeStructure;
use App\Domain\Fees\Infrastructure\FeeStructureInstallment;
use App\Domain\Fees\Infrastructure\FeeStructureLine;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * FEE.1 (ADR 0062 §7): the only write path for fee structures, their lines
 * and their instalment schedule.
 *
 * - A structure is created as a draft for one AcademicYear x GradeLevel,
 *   optionally one Campus (decision B; no Section path exists).
 * - Lines and instalments are edited only while the structure is a draft.
 *   The service locks the structure row first; the database refuses any
 *   child write to a non-draft structure regardless
 *   (`fees_reject_non_draft_structure_child_write`).
 * - The stored instalment rows are authoritative (decision C). The
 *   `one_time`/`term`/`monthly` generators only write a proposed schedule
 *   into the draft, which stays editable.
 * - Activation requires every line's instalments to sum exactly to the line
 *   amount (checked here for a clear error, and by
 *   `fees_validate_fee_structure_lifecycle`). One active structure per scope
 *   is a partial unique index; losing that race is a typed
 *   `FeeStructureActivationConflictException`, never a second active row.
 * - Amendment is a successor: an active structure is copied into a draft
 *   that `supersedes` it; activating the successor retires the predecessor
 *   in the same transaction.
 *
 * Fees reads AcademicYear/GradeLevel/AcademicTerm/Campus rows only to
 * validate references and generate schedules (the Charge UI's own
 * precedent); the composite foreign keys remain the structural guarantee.
 *
 * Authorization boundary: `finance.fee_structures.manage` on every call.
 */
class FeeStructureService
{
    use AuthorizesCapability;

    private const OPEN_ACADEMIC_YEAR_STATUSES = ['draft', 'active'];

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    // --- structures ---------------------------------------------------------

    /**
     * @param  array{academic_year_id: string, grade_level_id: string, campus_id?: string|null, code: string, name: string}  $data
     */
    public function createDraft(School $school, array $data, User $actor): FeeStructure
    {
        $this->authorize($school, $actor);
        $this->assertValidCodeAndName($data['code'], $data['name']);

        return $this->context->withSchool($school, function () use ($school, $data, $actor) {
            return DB::transaction(function () use ($school, $data, $actor) {
                $this->assertScope($school, $data['academic_year_id'], $data['grade_level_id'], $data['campus_id'] ?? null);

                $structure = new FeeStructure;
                $structure->forceFill([
                    'school_id' => $school->id,
                    'academic_year_id' => $data['academic_year_id'],
                    'grade_level_id' => $data['grade_level_id'],
                    'campus_id' => $data['campus_id'] ?? null,
                    'code' => $data['code'],
                    'name' => trim($data['name']),
                    'status' => FeeStructure::STATUS_DRAFT,
                    'created_by_user_id' => $actor->id,
                ]);
                $this->saveStructure($structure);

                $this->auditCreated($school, $structure, $actor);

                return $structure->refresh();
            });
        });
    }

    /**
     * @param  array{code?: string, name?: string}  $data
     */
    public function updateDraft(School $school, string $feeStructureId, array $data, User $actor): FeeStructure
    {
        $this->authorize($school, $actor);

        return $this->mutateDraft($school, $feeStructureId, $actor, function (FeeStructure $structure) use ($data) {
            $changed = [];

            if (array_key_exists('code', $data) && FeeCode::normalize($data['code']) !== $structure->code) {
                $this->assertValidCodeAndName($data['code'], $data['name'] ?? $structure->name);
                $structure->code = $data['code'];
                $changed[] = 'code';
            }

            if (array_key_exists('name', $data) && trim($data['name']) !== $structure->name) {
                $this->assertValidCodeAndName($structure->code, $data['name']);
                $structure->name = trim($data['name']);
                $changed[] = 'name';
            }

            if ($changed !== []) {
                $this->saveStructure($structure);
            }

            return [$structure, $changed];
        });
    }

    public function activate(School $school, string $feeStructureId, User $actor): FeeStructure
    {
        $this->authorize($school, $actor);

        return $this->context->withSchool($school, function () use ($school, $feeStructureId, $actor) {
            return DB::transaction(function () use ($school, $feeStructureId, $actor) {
                $structure = $this->lockStructure($school, $feeStructureId);

                if (! $structure->isDraft()) {
                    throw new FeeStructureIllegalTransitionException($structure->status, FeeStructure::STATUS_ACTIVE);
                }

                $this->assertComplete($structure);

                $predecessor = null;
                if ($structure->supersedes_fee_structure_id !== null) {
                    $predecessor = $this->lockStructure($school, $structure->supersedes_fee_structure_id);
                }

                $now = now();
                $superseded = null;

                try {
                    if ($predecessor !== null && $predecessor->isActive()) {
                        $superseded = $predecessor;
                        $predecessor->forceFill([
                            'status' => FeeStructure::STATUS_RETIRED,
                            'retired_at' => $now,
                            'retired_by_user_id' => $actor->id,
                        ])->save();
                    }

                    $structure->forceFill([
                        'status' => FeeStructure::STATUS_ACTIVE,
                        'activated_at' => $now,
                        'activated_by_user_id' => $actor->id,
                    ])->save();
                } catch (UniqueConstraintViolationException $e) {
                    if (str_contains($e->getMessage(), 'fee_structures_one_active_per_scope')
                        || str_contains($e->getMessage(), 'fee_structures_one_live_successor')) {
                        throw new FeeStructureActivationConflictException($structure->id);
                    }

                    throw $e;
                } catch (QueryException $e) {
                    if (str_contains($e->getMessage(), 'do not sum to the line amount')
                        || str_contains($e->getMessage(), 'has no lines')) {
                        throw new FeeStructureIncompleteException('Every line needs instalments that sum exactly to its amount.');
                    }

                    throw $e;
                }

                if ($superseded !== null) {
                    $this->audit->school($school, 'fee_structure.superseded', actor: $actor, subject: $superseded, metadata: [
                        'feeStructureId' => $superseded->id,
                        'successorFeeStructureId' => $structure->id,
                    ]);
                }

                $this->audit->school($school, 'fee_structure.activated', actor: $actor, subject: $structure, metadata: [
                    'feeStructureId' => $structure->id,
                    'supersedesFeeStructureId' => $structure->supersedes_fee_structure_id,
                ]);

                event(new FeeStructureActivated(
                    $school->id,
                    $structure->id,
                    $structure->academic_year_id,
                    $structure->grade_level_id,
                    $structure->campus_id,
                    $structure->supersedes_fee_structure_id,
                ));

                return $structure->refresh();
            });
        });
    }

    public function retire(School $school, string $feeStructureId, User $actor): FeeStructure
    {
        $this->authorize($school, $actor);

        return $this->context->withSchool($school, function () use ($school, $feeStructureId, $actor) {
            return DB::transaction(function () use ($school, $feeStructureId, $actor) {
                $structure = $this->lockStructure($school, $feeStructureId);

                if (! $structure->isActive()) {
                    throw new FeeStructureIllegalTransitionException($structure->status, FeeStructure::STATUS_RETIRED);
                }

                $structure->forceFill([
                    'status' => FeeStructure::STATUS_RETIRED,
                    'retired_at' => now(),
                    'retired_by_user_id' => $actor->id,
                ])->save();

                $this->audit->school($school, 'fee_structure.retired', actor: $actor, subject: $structure, metadata: [
                    'feeStructureId' => $structure->id,
                ]);

                return $structure->refresh();
            });
        });
    }

    /**
     * Copies an ACTIVE structure (lines and instalments) into a new draft of
     * the same scope that supersedes it. The copy can then be edited and
     * activated; activation retires the predecessor.
     *
     * @param  array{code: string, name?: string}  $data
     */
    public function createSuccessor(School $school, string $feeStructureId, array $data, User $actor): FeeStructure
    {
        $this->authorize($school, $actor);

        return $this->context->withSchool($school, function () use ($school, $feeStructureId, $data, $actor) {
            return DB::transaction(function () use ($school, $feeStructureId, $data, $actor) {
                $source = $this->lockStructure($school, $feeStructureId);

                if (! $source->isActive()) {
                    throw new InvalidFeeStructureException('fee_structure_id', 'Only an active fee structure can be amended by a successor.');
                }

                $name = $data['name'] ?? $source->name;
                $this->assertValidCodeAndName($data['code'], $name);

                $successor = new FeeStructure;
                $successor->forceFill([
                    'school_id' => $school->id,
                    'academic_year_id' => $source->academic_year_id,
                    'grade_level_id' => $source->grade_level_id,
                    'campus_id' => $source->campus_id,
                    'code' => $data['code'],
                    'name' => trim($name),
                    'status' => FeeStructure::STATUS_DRAFT,
                    'supersedes_fee_structure_id' => $source->id,
                    'created_by_user_id' => $actor->id,
                ]);
                $this->saveStructure($successor);

                foreach ($source->lines()->with('installments')->orderBy('id')->get() as $line) {
                    $copy = new FeeStructureLine;
                    $copy->forceFill([
                        'school_id' => $school->id,
                        'fee_structure_id' => $successor->id,
                        'fee_head_id' => $line->fee_head_id,
                        'is_optional' => $line->is_optional,
                        'frequency' => $line->frequency,
                        'amount' => $line->amount,
                        'currency' => $line->currency,
                    ])->save();

                    foreach ($line->installments as $installment) {
                        (new FeeStructureInstallment)->forceFill([
                            'school_id' => $school->id,
                            'fee_structure_line_id' => $copy->id,
                            'sequence' => $installment->sequence,
                            'label' => $installment->label,
                            'billing_period_key' => $installment->billing_period_key,
                            'period_starts_on' => $installment->period_starts_on->toDateString(),
                            'period_ends_on' => $installment->period_ends_on->toDateString(),
                            'due_date' => $installment->due_date->toDateString(),
                            'academic_term_id' => $installment->academic_term_id,
                            'amount' => $installment->amount,
                            'currency' => $installment->currency,
                        ])->save();
                    }
                }

                $this->auditCreated($school, $successor, $actor);

                return $successor->refresh();
            });
        });
    }

    // --- lines ----------------------------------------------------------------

    /**
     * @param  array{fee_head_id: string, amount: string, is_optional?: bool, frequency?: string}  $data
     */
    public function addLine(School $school, string $feeStructureId, array $data, User $actor): FeeStructureLine
    {
        $this->authorize($school, $actor);
        $amount = $this->validAmount($data['amount'], 'amount');
        $frequency = $data['frequency'] ?? FeeStructureLine::FREQUENCY_CUSTOM;
        $this->assertFrequency($frequency, FeeStructureLine::FREQUENCIES);

        return $this->mutateDraft($school, $feeStructureId, $actor, function (FeeStructure $structure) use ($school, $data, $amount, $frequency) {
            $head = FeeHead::query()->where('school_id', $school->id)->find($data['fee_head_id']);

            if ($head === null || ! $head->isActive()) {
                throw new InvalidFeeStructureException('fee_head_id', 'The fee head must be an active fee head of this School.');
            }

            $line = new FeeStructureLine;
            $line->forceFill([
                'school_id' => $school->id,
                'fee_structure_id' => $structure->id,
                'fee_head_id' => $head->id,
                'is_optional' => (bool) ($data['is_optional'] ?? false),
                'frequency' => $frequency,
                'amount' => $amount,
                'currency' => 'INR',
            ]);

            try {
                $line->save();
            } catch (UniqueConstraintViolationException $e) {
                if (! str_contains($e->getMessage(), 'fee_structure_lines_fee_structure_id_fee_head_id_unique')) {
                    throw $e;
                }

                throw new DuplicateFeeStructureLineException($head->id);
            }

            return [$line, ['lines']];
        });
    }

    /**
     * @param  array{amount?: string, is_optional?: bool}  $data
     */
    public function updateLine(School $school, string $feeStructureId, string $lineId, array $data, User $actor): FeeStructureLine
    {
        $this->authorize($school, $actor);
        $amount = array_key_exists('amount', $data) ? $this->validAmount($data['amount'], 'amount') : null;

        return $this->mutateDraft($school, $feeStructureId, $actor, function (FeeStructure $structure) use ($lineId, $data, $amount) {
            $line = $this->findLine($structure, $lineId);

            if ($amount !== null) {
                $line->amount = $amount;
            }

            if (array_key_exists('is_optional', $data)) {
                $line->is_optional = (bool) $data['is_optional'];
            }

            $line->save();

            return [$line->refresh(), ['lines']];
        });
    }

    public function removeLine(School $school, string $feeStructureId, string $lineId, User $actor): void
    {
        $this->authorize($school, $actor);

        $this->mutateDraft($school, $feeStructureId, $actor, function (FeeStructure $structure) use ($lineId) {
            // FOR UPDATE on the line: a concurrent selection takes FOR SHARE
            // on the same row (FeeOptionalSelectionService::select), so the
            // check below cannot be raced; the NO ACTION foreign key
            // `fee_optional_selections_line_fk` remains the backstop.
            $line = $this->findLine($structure, $lineId, lock: true);

            if (FeeOptionalSelection::query()->where('fee_structure_line_id', $line->id)->exists()) {
                throw new FeeStructureLineHasSelectionsException($line->id);
            }

            $line->delete();

            return [$structure, ['lines']];
        });
    }

    // --- instalments ------------------------------------------------------------

    /**
     * Replaces a draft line's whole schedule with the given rows, in order
     * (sequence 1..n). The sum is not required to match yet -- only
     * activation requires that.
     *
     * @param  list<array{label: string, billing_period_key: string, period_starts_on: string, period_ends_on: string, due_date: string, academic_term_id?: string|null, amount: string}>  $rows
     * @return list<FeeStructureInstallment>
     */
    public function replaceInstallments(School $school, string $feeStructureId, string $lineId, array $rows, User $actor): array
    {
        $this->authorize($school, $actor);

        return $this->mutateDraft($school, $feeStructureId, $actor, function (FeeStructure $structure) use ($school, $lineId, $rows) {
            $line = $this->findLine($structure, $lineId);

            return [$this->writeSchedule($school, $structure, $line, $rows, FeeStructureLine::FREQUENCY_CUSTOM), ['installments']];
        });
    }

    /**
     * Writes a generated schedule into a draft line (decision C: the rows are
     * a proposal the draft can still edit). `one_time` = one row for the
     * whole year; `term` = one row per AcademicTerm of the year; `monthly` =
     * one row per calendar month the year spans. The line amount is split
     * exactly in paise (FeeAmount::split).
     *
     * @return list<FeeStructureInstallment>
     */
    public function generateInstallments(School $school, string $feeStructureId, string $lineId, string $frequency, User $actor): array
    {
        $this->authorize($school, $actor);
        $this->assertFrequency($frequency, FeeStructureLine::GENERATED_FREQUENCIES);

        return $this->mutateDraft($school, $feeStructureId, $actor, function (FeeStructure $structure) use ($school, $lineId, $frequency) {
            $line = $this->findLine($structure, $lineId);
            $year = AcademicYear::query()->where('school_id', $school->id)->findOrFail($structure->academic_year_id);

            $periods = match ($frequency) {
                FeeStructureLine::FREQUENCY_ONE_TIME => $this->oneTimePeriods($year),
                FeeStructureLine::FREQUENCY_TERM => $this->termPeriods($school, $year),
                FeeStructureLine::FREQUENCY_MONTHLY => $this->monthlyPeriods($year),
                default => throw new InvalidFeeStructureException('frequency', 'No generator exists for this frequency.'),
            };

            $shares = FeeAmount::split($line->amount, count($periods));
            $rows = [];
            foreach ($periods as $i => $period) {
                $rows[] = [...$period, 'amount' => $shares[$i]];
            }

            return [$this->writeSchedule($school, $structure, $line, $rows, $frequency), ['installments']];
        });
    }

    // --- internals ----------------------------------------------------------

    private function authorize(School $school, User $actor): void
    {
        $this->authorizeCapabilityFor($actor, 'finance.fee_structures.manage', $school);
    }

    /**
     * Runs a draft-only mutation under the structure's row lock and writes
     * one `fee_structure.updated` audit event when something changed.
     *
     * @template T
     *
     * @param  callable(FeeStructure): array{0: T, 1: list<string>}  $mutation
     * @return T
     */
    private function mutateDraft(School $school, string $feeStructureId, User $actor, callable $mutation): mixed
    {
        return $this->context->withSchool($school, function () use ($school, $feeStructureId, $actor, $mutation) {
            return DB::transaction(function () use ($school, $feeStructureId, $actor, $mutation) {
                $structure = $this->lockStructure($school, $feeStructureId);

                if (! $structure->isDraft()) {
                    throw new FeeStructureNotDraftException($structure->id, $structure->status);
                }

                [$result, $changed] = $mutation($structure);

                if ($changed !== []) {
                    $this->audit->school($school, 'fee_structure.updated', actor: $actor, subject: $structure, metadata: [
                        'feeStructureId' => $structure->id,
                        'changedFields' => $changed,
                    ]);
                }

                return $result;
            });
        });
    }

    private function lockStructure(School $school, string $feeStructureId): FeeStructure
    {
        $structure = FeeStructure::query()->where('school_id', $school->id)->lockForUpdate()->find($feeStructureId);

        if ($structure === null) {
            throw new FeeStructureNotFoundException($feeStructureId);
        }

        return $structure;
    }

    private function findLine(FeeStructure $structure, string $lineId, bool $lock = false): FeeStructureLine
    {
        $line = FeeStructureLine::query()->where('fee_structure_id', $structure->id)
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->find($lineId);

        if ($line === null) {
            throw new FeeStructureLineNotFoundException($lineId);
        }

        return $line;
    }

    private function saveStructure(FeeStructure $structure): void
    {
        try {
            $structure->save();
        } catch (UniqueConstraintViolationException $e) {
            if (! str_contains($e->getMessage(), 'fee_structures_year_code_ci_unique')) {
                throw $e;
            }

            throw new DuplicateFeeStructureCodeException($structure->code);
        }
    }

    private function assertScope(School $school, string $academicYearId, string $gradeLevelId, ?string $campusId): void
    {
        $year = AcademicYear::query()->where('school_id', $school->id)->find($academicYearId);
        if ($year === null || ! in_array($year->status, self::OPEN_ACADEMIC_YEAR_STATUSES, true)) {
            throw new InvalidFeeStructureException('academic_year_id', 'The academic year must be a draft or active academic year of this School.');
        }

        $grade = GradeLevel::query()->where('school_id', $school->id)->find($gradeLevelId);
        if ($grade === null || $grade->status !== 'active') {
            throw new InvalidFeeStructureException('grade_level_id', 'The grade level must be an active grade level of this School.');
        }

        if ($campusId !== null) {
            $campus = Campus::query()->where('school_id', $school->id)->find($campusId);
            if ($campus === null || $campus->status !== 'active') {
                throw new InvalidFeeStructureException('campus_id', 'The campus must be an active campus of this School.');
            }
        }
    }

    private function assertValidCodeAndName(string $code, string $name): void
    {
        if (! FeeCode::isValid($code)) {
            throw new InvalidFeeStructureException('code', FeeCode::RULE_MESSAGE);
        }

        $trimmed = trim($name);
        if ($trimmed === '' || mb_strlen($trimmed) > 120) {
            throw new InvalidFeeStructureException('name', 'A fee structure name is required (at most 120 characters).');
        }
    }

    /** @param list<string> $allowed */
    private function assertFrequency(string $frequency, array $allowed): void
    {
        if (! in_array($frequency, $allowed, true)) {
            throw new InvalidFeeStructureException('frequency', 'The frequency must be one of: '.implode(', ', $allowed).'.');
        }
    }

    private function validAmount(mixed $amount, string $field): string
    {
        if (! FeeAmount::isValidPositive($amount)) {
            throw new InvalidFeeStructureException($field, 'An amount is a positive decimal string with at most 12 digits and 2 decimal places (e.g. "1200.00").');
        }

        return FeeAmount::normalize($amount);
    }

    private function assertComplete(FeeStructure $structure): void
    {
        $lines = $structure->lines()->with('installments', 'feeHead')->get();

        if ($lines->isEmpty()) {
            throw new FeeStructureIncompleteException('A fee structure needs at least one line before it can be activated.');
        }

        foreach ($lines as $line) {
            $amounts = $line->installments->map(fn (FeeStructureInstallment $i) => (string) $i->amount)->all();

            if ($amounts === [] || ! FeeAmount::equals(FeeAmount::sum($amounts), (string) $line->amount)) {
                throw new FeeStructureIncompleteException(
                    "The instalments of fee head '{$line->feeHead->code}' must sum exactly to its amount ({$line->amount})."
                );
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<FeeStructureInstallment>
     */
    private function writeSchedule(School $school, FeeStructure $structure, FeeStructureLine $line, array $rows, string $frequency): array
    {
        if ($rows === []) {
            throw new InvalidFeeStructureException('installments', 'A schedule needs at least one instalment.');
        }

        $year = AcademicYear::query()->where('school_id', $school->id)->findOrFail($structure->academic_year_id);
        $normalized = [];
        $keys = [];

        foreach ($rows as $i => $row) {
            $field = "installments.{$i}";
            $key = FeeCode::normalize((string) ($row['billing_period_key'] ?? ''));
            if (! FeeCode::isValid($key)) {
                throw new InvalidFeeStructureException("{$field}.billing_period_key", 'A billing period key: '.FeeCode::RULE_MESSAGE);
            }
            if (in_array($key, $keys, true)) {
                throw new InvalidFeeStructureException("{$field}.billing_period_key", "Billing period key '{$key}' is used twice in this schedule.");
            }
            $keys[] = $key;

            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '' || mb_strlen($label) > 64) {
                throw new InvalidFeeStructureException("{$field}.label", 'An instalment label is required (at most 64 characters).');
            }

            $starts = $this->date($row['period_starts_on'] ?? null, "{$field}.period_starts_on");
            $ends = $this->date($row['period_ends_on'] ?? null, "{$field}.period_ends_on");
            $due = $this->date($row['due_date'] ?? null, "{$field}.due_date");

            if ($starts->gt($ends)) {
                throw new InvalidFeeStructureException("{$field}.period_ends_on", 'An instalment period cannot end before it starts.');
            }
            if ($starts->lt($year->starts_on) || $ends->gt($year->ends_on)) {
                throw new InvalidFeeStructureException("{$field}.period_starts_on", 'An instalment period must lie inside the academic year.');
            }
            if ($due->lt($starts)) {
                throw new InvalidFeeStructureException("{$field}.due_date", 'A due date cannot be before its period starts.');
            }

            $termId = $row['academic_term_id'] ?? null;
            if ($termId !== null && ! AcademicTerm::query()->where('school_id', $school->id)
                ->where('academic_year_id', $year->id)->whereKey($termId)->exists()) {
                throw new InvalidFeeStructureException("{$field}.academic_term_id", 'The term must belong to the structure\'s academic year.');
            }

            $normalized[] = [
                'label' => $label,
                'billing_period_key' => $key,
                'period_starts_on' => $starts->toDateString(),
                'period_ends_on' => $ends->toDateString(),
                'due_date' => $due->toDateString(),
                'academic_term_id' => $termId,
                'amount' => $this->validAmount($row['amount'] ?? null, "{$field}.amount"),
            ];
        }

        FeeStructureInstallment::query()->where('fee_structure_line_id', $line->id)->delete();

        $written = [];
        foreach ($normalized as $i => $row) {
            $installment = new FeeStructureInstallment;
            $installment->forceFill([
                ...$row,
                'school_id' => $school->id,
                'fee_structure_line_id' => $line->id,
                'sequence' => $i + 1,
                'currency' => 'INR',
            ])->save();
            $written[] = $installment;
        }

        if ($line->frequency !== $frequency) {
            $line->forceFill(['frequency' => $frequency])->save();
        }

        return $written;
    }

    private function date(mixed $value, string $field): Carbon
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new InvalidFeeStructureException($field, 'A date is required in YYYY-MM-DD form.');
        }

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            $date = false;
        }

        if ($date === false || $date->toDateString() !== $value) {
            throw new InvalidFeeStructureException($field, 'A date is required in YYYY-MM-DD form.');
        }

        return $date;
    }

    /** @return list<array{label: string, billing_period_key: string, period_starts_on: string, period_ends_on: string, due_date: string, academic_term_id: null}> */
    private function oneTimePeriods(AcademicYear $year): array
    {
        return [[
            'label' => 'Annual',
            'billing_period_key' => 'ANNUAL',
            'period_starts_on' => $year->starts_on->toDateString(),
            'period_ends_on' => $year->ends_on->toDateString(),
            'due_date' => $year->starts_on->toDateString(),
            'academic_term_id' => null,
        ]];
    }

    /** @return list<array{label: string, billing_period_key: string, period_starts_on: string, period_ends_on: string, due_date: string, academic_term_id: string}> */
    private function termPeriods(School $school, AcademicYear $year): array
    {
        $terms = AcademicTerm::query()
            ->where('school_id', $school->id)
            ->where('academic_year_id', $year->id)
            ->orderBy('sequence')
            ->orderBy('starts_on')
            ->get();

        if ($terms->isEmpty()) {
            throw new InvalidFeeStructureException('frequency', 'The academic year has no terms to generate a term schedule from.');
        }

        $periods = [];
        $keys = [];
        foreach ($terms as $term) {
            $key = $this->periodKeyFrom((string) $term->code, 'T'.$term->sequence, $keys);
            $keys[] = $key;
            $periods[] = [
                'label' => mb_substr((string) $term->name, 0, 64),
                'billing_period_key' => $key,
                'period_starts_on' => Carbon::parse($term->starts_on)->toDateString(),
                'period_ends_on' => Carbon::parse($term->ends_on)->toDateString(),
                'due_date' => Carbon::parse($term->starts_on)->toDateString(),
                'academic_term_id' => $term->id,
            ];
        }

        return $periods;
    }

    /** @return list<array{label: string, billing_period_key: string, period_starts_on: string, period_ends_on: string, due_date: string, academic_term_id: null}> */
    private function monthlyPeriods(AcademicYear $year): array
    {
        $periods = [];
        $cursor = $year->starts_on->copy()->startOfMonth();

        while ($cursor->lte($year->ends_on)) {
            $starts = $cursor->lt($year->starts_on) ? $year->starts_on->copy() : $cursor->copy();
            $monthEnd = $cursor->copy()->endOfMonth()->startOfDay();
            $ends = $monthEnd->gt($year->ends_on) ? $year->ends_on->copy() : $monthEnd;

            $periods[] = [
                'label' => $cursor->format('F Y'),
                'billing_period_key' => $cursor->format('Y-m'),
                'period_starts_on' => $starts->toDateString(),
                'period_ends_on' => $ends->toDateString(),
                'due_date' => $starts->toDateString(),
                'academic_term_id' => null,
            ];

            $cursor->addMonthNoOverflow()->startOfMonth();
        }

        return $periods;
    }

    /** @param list<string> $taken */
    private function periodKeyFrom(string $code, string $fallback, array $taken): string
    {
        $candidate = substr(ltrim((string) preg_replace('/[^A-Z0-9_-]+/', '-', strtoupper(trim($code))), '-_'), 0, 32);
        if ($candidate === '' || ! FeeCode::isValid($candidate) || in_array($candidate, $taken, true)) {
            $candidate = $fallback;
        }

        return $candidate;
    }

    private function auditCreated(School $school, FeeStructure $structure, User $actor): void
    {
        $this->audit->school($school, 'fee_structure.created', actor: $actor, subject: $structure, metadata: [
            'feeStructureId' => $structure->id,
            'code' => $structure->code,
            'academicYearId' => $structure->academic_year_id,
            'gradeLevelId' => $structure->grade_level_id,
            'campusId' => $structure->campus_id,
            'supersedesFeeStructureId' => $structure->supersedes_fee_structure_id,
        ]);
    }
}
