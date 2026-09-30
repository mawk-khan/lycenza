<?php

namespace App\Domain\Fees\Application;

use App\Domain\Fees\Application\Exceptions\AcademicYearNotFoundException;
use App\Domain\Fees\Application\Exceptions\ChargeAlreadyCancelledException;
use App\Domain\Fees\Application\Exceptions\ChargeNotFoundException;
use App\Domain\Fees\Application\Exceptions\FeeConcessionIdempotencyConflictException;
use App\Domain\Fees\Application\Exceptions\FeeConcessionIllegalTransitionException;
use App\Domain\Fees\Application\Exceptions\FeeConcessionNotFoundException;
use App\Domain\Fees\Application\Exceptions\FeeConcessionNotRequesterException;
use App\Domain\Fees\Application\Exceptions\FeeHeadNotFoundException;
use App\Domain\Fees\Application\Exceptions\InvalidFeeConcessionException;
use App\Domain\Fees\Application\Exceptions\SelfApprovalNotAllowedException;
use App\Domain\Fees\Application\Exceptions\StudentNotFoundException;
use App\Domain\Fees\Events\FeeConcessionApproved;
use App\Domain\Fees\Events\FeeConcessionRejected;
use App\Domain\Fees\Infrastructure\Charge;
use App\Domain\Fees\Infrastructure\FeeAdjustment;
use App\Domain\Fees\Infrastructure\FeeConcession;
use App\Domain\Fees\Infrastructure\FeeHead;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Money\Money;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FEE.3 (ADR 0062 §14; owner decisions F, G1, M): the administrative facade
 * for concessions -- every human concession action checks its capability
 * here (the `ChargeAdministrationService` pattern), and the financial
 * effect goes through the trusted `FeeAdjustmentService`.
 *
 * - `request` / `withdraw`: `finance.fee_concessions.request`; only the
 *   requester withdraws, only while pending. Requests carry a
 *   server-issued idempotency key: the same requester re-sending identical
 *   content replays; anything else fails closed.
 * - `approve` / `reject` / `revoke` / `cancelAdjustment`:
 *   `finance.fee_concessions.approve`. The decider is never the requester
 *   (`SelfApprovalNotAllowedException`, backed by
 *   `fee_concessions_sod_check`). Approving a targeted concession posts its
 *   adjustment in the same transaction; a refusal (G1, F2) rolls the
 *   approval back and the request stays pending.
 * - Revoking a standing concession stops future application only; posted
 *   adjustments stay until each is cancelled explicitly.
 *
 * Decisions lock the concession row (FOR UPDATE) and re-check its status;
 * the database transition trigger is the backstop. There is no free-text
 * note (M): audit metadata is ids and closed codes only.
 */
class FeeConcessionService
{
    use AuthorizesCapability;

    public const REQUEST = 'finance.fee_concessions.request';

    public const APPROVE = 'finance.fee_concessions.approve';

    private const AMOUNT_PATTERN = '/^(0|[1-9]\d{0,11})(\.\d{1,2})?$/';

    public function __construct(
        private readonly FeeAdjustmentService $adjustments,
        private readonly SchoolOperationalGuard $operational,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /** @return array{concession: FeeConcession, replayed: bool} */
    public function request(School $school, RequestFeeConcessionData $data, User $actor): array
    {
        $this->authorizeCapabilityFor($actor, self::REQUEST, $school);
        $this->assertValidShape($data);

        return $this->context->withSchool($school, function () use ($school, $data, $actor) {
            try {
                return DB::transaction(function () use ($school, $data, $actor) {
                    $this->operational->requireOperational($school->id);
                    DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ["fees.concession_request:{$school->id}:{$data->idempotencyKey}"]);

                    $existing = $this->findByKey($school, $data->idempotencyKey);
                    if ($existing !== null) {
                        return ['concession' => $this->replay($existing, $data, $actor), 'replayed' => true];
                    }

                    return ['concession' => $this->create($school, $data, $actor), 'replayed' => false];
                });
            } catch (UniqueConstraintViolationException $e) {
                if (! str_contains($e->getMessage(), 'fee_concessions_idempotency_unique')) {
                    throw $e;
                }

                // Unreachable while the advisory lock serializes same-key
                // requests; the unique index is the authoritative claim (rule 30).
                $existing = $this->findByKey($school, $data->idempotencyKey) ?? throw new FeeConcessionIdempotencyConflictException;

                return ['concession' => $this->replay($existing, $data, $actor), 'replayed' => true];
            }
        });
    }

    public function withdraw(School $school, string $concessionId, User $actor): FeeConcession
    {
        $this->authorizeCapabilityFor($actor, self::REQUEST, $school);

        return $this->decide($school, $concessionId, $actor, 'withdrawn', function (FeeConcession $concession) use ($actor) {
            if ($concession->requested_by_user_id !== $actor->id) {
                throw new FeeConcessionNotRequesterException($concession->id);
            }

            $concession->forceFill(['status' => FeeConcession::STATUS_WITHDRAWN, 'withdrawn_at' => now()])->save();
        });
    }

    public function approve(School $school, string $concessionId, User $actor): FeeConcession
    {
        $this->authorizeCapabilityFor($actor, self::APPROVE, $school);

        return $this->decide($school, $concessionId, $actor, 'approved', function (FeeConcession $concession) use ($school, $actor) {
            $this->assertNotRequester($concession, $actor);
            $concession->forceFill([
                'status' => FeeConcession::STATUS_APPROVED,
                'decided_by_user_id' => $actor->id,
                'decided_at' => now(),
            ])->save();

            if ($concession->isTargeted()) {
                $this->adjustments->post(
                    $school,
                    $concession,
                    (string) $concession->charge_id,
                    Money::of((string) $concession->fixed_amount, $concession->currency),
                    null,
                    $actor,
                );
            }

            event(new FeeConcessionApproved($school->id, $concession->id, $concession->student_id, $concession->scope, $concession->category));
        });
    }

    public function reject(School $school, string $concessionId, User $actor): FeeConcession
    {
        $this->authorizeCapabilityFor($actor, self::APPROVE, $school);

        return $this->decide($school, $concessionId, $actor, 'rejected', function (FeeConcession $concession) use ($school, $actor) {
            $this->assertNotRequester($concession, $actor);
            $concession->forceFill([
                'status' => FeeConcession::STATUS_REJECTED,
                'decided_by_user_id' => $actor->id,
                'decided_at' => now(),
            ])->save();

            event(new FeeConcessionRejected($school->id, $concession->id, $concession->student_id, $concession->scope, $concession->category));
        });
    }

    public function revoke(School $school, string $concessionId, User $actor): FeeConcession
    {
        $this->authorizeCapabilityFor($actor, self::APPROVE, $school);

        return $this->decide($school, $concessionId, $actor, 'revoked', function (FeeConcession $concession) use ($actor) {
            $concession->forceFill([
                'status' => FeeConcession::STATUS_REVOKED,
                'revoked_by_user_id' => $actor->id,
                'revoked_at' => now(),
            ])->save();
        });
    }

    /** Cancels one posted adjustment through a Finance reversal (ADR 0062 §14.1). */
    public function cancelAdjustment(School $school, string $adjustmentId, User $actor, ?string $reason = null): FeeAdjustment
    {
        $this->authorizeCapabilityFor($actor, self::APPROVE, $school);

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $adjustmentId, $actor, $reason) {
            $this->operational->requireOperational($school->id);

            return $this->adjustments->cancel($school, $adjustmentId, $actor, $reason);
        }));
    }

    /**
     * @param  'withdrawn'|'approved'|'rejected'|'revoked'  $to
     * @param  callable(FeeConcession): void  $transition
     */
    private function decide(School $school, string $concessionId, User $actor, string $to, callable $transition): FeeConcession
    {
        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $concessionId, $actor, $to, $transition) {
            $this->operational->requireOperational($school->id);

            $concession = Str::isUuid($concessionId)
                ? FeeConcession::query()->where('school_id', $school->id)->lockForUpdate()->find($concessionId)
                : null;
            if ($concession === null) {
                throw new FeeConcessionNotFoundException($concessionId);
            }

            $allowed = $to === 'revoked'
                ? $concession->status === FeeConcession::STATUS_APPROVED && ! $concession->isTargeted()
                : $concession->status === FeeConcession::STATUS_PENDING;
            if (! $allowed) {
                throw new FeeConcessionIllegalTransitionException($concession->id, $concession->status, $to);
            }

            $transition($concession);

            $this->audit->school($school, "fee_concession.{$to}", actor: $actor, subject: $concession, metadata: [
                'feeConcessionId' => $concession->id,
                'scope' => $concession->scope,
                'category' => $concession->category,
            ]);

            return $concession->refresh();
        }));
    }

    private function assertNotRequester(FeeConcession $concession, User $actor): void
    {
        if ($concession->requested_by_user_id === $actor->id) {
            throw new SelfApprovalNotAllowedException($concession->id);
        }
    }

    private function create(School $school, RequestFeeConcessionData $data, User $actor): FeeConcession
    {
        $columns = [
            'school_id' => $school->id,
            'category' => $data->category,
            'scope' => $data->scope,
            'kind' => $data->kind,
            'fixed_amount' => $data->kind === FeeConcession::KIND_FIXED ? $data->fixedAmount : null,
            'percentage' => $data->kind === FeeConcession::KIND_PERCENTAGE ? $data->percentage : null,
            'currency' => 'INR',
            'status' => FeeConcession::STATUS_PENDING,
            'idempotency_key' => $data->idempotencyKey,
            'requested_by_user_id' => $actor->id,
        ];

        if ($data->scope === FeeConcession::SCOPE_TARGETED) {
            $charge = Charge::query()->where('school_id', $school->id)->find($data->chargeId)
                ?? throw new ChargeNotFoundException((string) $data->chargeId);
            if ($charge->isCancelled()) {
                throw new ChargeAlreadyCancelledException($charge->id);
            }
            if (bccomp((string) $data->fixedAmount, $charge->amount, 2) > 0) {
                throw new InvalidFeeConcessionException('fixed_amount', 'A concession cannot exceed the charge amount.');
            }
            $columns += ['charge_id' => $charge->id, 'student_id' => $charge->student_id, 'academic_year_id' => $charge->academic_year_id];
        } else {
            if ($data->feeHeadId !== null && ! FeeHead::query()->where('school_id', $school->id)->whereKey($data->feeHeadId)->exists()) {
                throw new FeeHeadNotFoundException($data->feeHeadId);
            }
            $columns += [
                'student_id' => $data->studentId,
                'academic_year_id' => $data->academicYearId,
                'fee_head_id' => $data->feeHeadId,
                'valid_from' => $data->validFrom,
                'valid_to' => $data->validTo,
            ];
        }

        try {
            $concession = new FeeConcession;
            $concession->forceFill($columns)->save();
        } catch (UniqueConstraintViolationException $e) {
            throw $e;
        } catch (QueryException $e) {
            $message = $e->getMessage();
            throw match (true) {
                str_contains($message, 'fee_concessions_student_fk') => new StudentNotFoundException((string) $data->studentId),
                str_contains($message, 'fee_concessions_academic_year_fk') => new AcademicYearNotFoundException((string) $data->academicYearId),
                str_contains($message, 'must lie inside its academic year') => new InvalidFeeConcessionException('valid_to', 'The validity window must lie inside the academic year.'),
                str_contains($message, 'needs an uncancelled charge') => new ChargeAlreadyCancelledException((string) $data->chargeId),
                default => $e,
            };
        }

        $this->audit->school($school, 'fee_concession.requested', actor: $actor, subject: $concession, metadata: [
            'feeConcessionId' => $concession->id,
            'scope' => $concession->scope,
            'category' => $concession->category,
            'kind' => $concession->kind,
        ]);

        return $concession->refresh();
    }

    private function findByKey(School $school, string $idempotencyKey): ?FeeConcession
    {
        return FeeConcession::query()->where('school_id', $school->id)->where('idempotency_key', $idempotencyKey)->first();
    }

    /** Replays only the same requester's identical request; anything else fails closed. */
    private function replay(FeeConcession $existing, RequestFeeConcessionData $data, User $actor): FeeConcession
    {
        $same = fn (?string $a, ?string $b) => ($a === null && $b === null) || ($a !== null && $b !== null && bccomp($a, $b, 2) === 0);
        $date = fn (?Carbon $a, ?string $b) => $a?->toDateString() === $b;

        $matches = $existing->requested_by_user_id === $actor->id
            && $existing->scope === $data->scope
            && $existing->category === $data->category
            && $existing->kind === $data->kind
            && $same($existing->fixed_amount, $data->kind === FeeConcession::KIND_FIXED ? $data->fixedAmount : null)
            && $same($existing->percentage, $data->kind === FeeConcession::KIND_PERCENTAGE ? $data->percentage : null)
            && ($data->scope === FeeConcession::SCOPE_TARGETED
                ? $existing->charge_id === $data->chargeId
                : $existing->student_id === $data->studentId
                    && $existing->academic_year_id === $data->academicYearId
                    && $existing->fee_head_id === $data->feeHeadId
                    && $date($existing->valid_from, $data->validFrom)
                    && $date($existing->valid_to, $data->validTo));

        if (! $matches) {
            throw new FeeConcessionIdempotencyConflictException;
        }

        return $existing;
    }

    private function assertValidShape(RequestFeeConcessionData $data): void
    {
        if (! Str::isUuid($data->idempotencyKey)) {
            throw new InvalidFeeConcessionException('idempotency_key', 'The concession form is missing its request key. Reload the form and try again.');
        }
        if (! in_array($data->category, FeeConcession::CATEGORIES, true)) {
            throw new InvalidFeeConcessionException('category', 'Choose concession, scholarship or waiver.');
        }
        if (! in_array($data->scope, [FeeConcession::SCOPE_TARGETED, FeeConcession::SCOPE_STANDING], true)) {
            throw new InvalidFeeConcessionException('scope', 'Choose a charge or a standing concession.');
        }
        if (! in_array($data->kind, [FeeConcession::KIND_FIXED, FeeConcession::KIND_PERCENTAGE], true)) {
            throw new InvalidFeeConcessionException('kind', 'Choose a fixed amount or a percentage.');
        }

        if ($data->kind === FeeConcession::KIND_FIXED) {
            if ($data->fixedAmount === null || ! preg_match(self::AMOUNT_PATTERN, $data->fixedAmount) || bccomp($data->fixedAmount, '0', 2) <= 0) {
                throw new InvalidFeeConcessionException('fixed_amount', 'Enter an amount greater than zero with at most two decimal places.');
            }
        } elseif ($data->percentage === null || ! preg_match('/^(0|[1-9]\d{0,2})(\.\d{1,2})?$/', $data->percentage)
            || bccomp($data->percentage, '0', 2) <= 0 || bccomp($data->percentage, '100', 2) > 0) {
            throw new InvalidFeeConcessionException('percentage', 'Enter a percentage above 0 and at most 100, with at most two decimal places.');
        }

        if ($data->scope === FeeConcession::SCOPE_TARGETED) {
            if ($data->chargeId === null || ! Str::isUuid($data->chargeId)) {
                throw new InvalidFeeConcessionException('charge_id', 'Choose the charge this concession applies to.');
            }
            if ($data->kind !== FeeConcession::KIND_FIXED) {
                throw new InvalidFeeConcessionException('kind', 'A concession on one charge is a fixed amount.');
            }

            return;
        }

        foreach (['studentId' => 'student_id', 'academicYearId' => 'academic_year_id'] as $property => $field) {
            if ($data->{$property} === null || ! Str::isUuid($data->{$property})) {
                throw new InvalidFeeConcessionException($field, 'This field is required.');
            }
        }
        if ($data->feeHeadId !== null && ! Str::isUuid($data->feeHeadId)) {
            throw new InvalidFeeConcessionException('fee_head_id', 'Choose a fee head, or leave it empty for every head.');
        }
        foreach (['validFrom' => 'valid_from', 'validTo' => 'valid_to'] as $property => $field) {
            $value = $data->{$property};
            if ($value === null || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
                throw new InvalidFeeConcessionException($field, 'Enter a valid date.');
            }
        }
        if ($data->validFrom > $data->validTo) {
            throw new InvalidFeeConcessionException('valid_to', 'The window must end on or after it starts.');
        }
    }
}
