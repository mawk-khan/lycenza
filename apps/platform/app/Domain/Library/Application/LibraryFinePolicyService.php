<?php

namespace App\Domain\Library\Application;

use App\Domain\Fees\Application\Sources\FeeSourceChargeService;
use App\Domain\Library\Application\Exceptions\InvalidLibraryFinePolicyException;
use App\Domain\Library\Infrastructure\LibraryFinePolicy;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * OPF.4 (ADR 0067 D3): the only writer of `library_fine_policies`. A School's
 * policy is a sequence of immutable versions; publishing creates the next
 * version (under the School's key, the unique version number the backstop),
 * so no assessed fine's rule can ever change. The highest version governs
 * fines assessed from then on. Authorization is `library.fines.manage`
 * (checked by the controller); it grants no Finance capability.
 */
class LibraryFinePolicyService
{
    private const MONEY = '/^(0|[1-9]\d{0,9})(\.\d{1,2})?$/';

    public function __construct(
        private readonly FeeSourceChargeService $fees,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  array{status: string, fee_head_id?: ?string, daily_rate?: ?string, grace_days?: ?int, max_amount?: ?string}  $data
     */
    public function publish(School $school, array $data, ?User $actor): LibraryFinePolicy
    {
        $columns = $this->validated($school, $data);

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $columns, $actor) {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['library.fine_policy:'.$school->id]);
            $previous = $this->current($school);

            $policy = LibraryFinePolicy::query()->create([
                ...$columns,
                'school_id' => $school->id,
                'version' => ($previous->version ?? 0) + 1,
                'currency' => 'INR',
                'created_by_user_id' => $actor?->id,
            ]);

            $this->audit->school($school, 'library.fine_policy.published', actor: $actor, subject: $policy, metadata: [
                'libraryFinePolicyId' => $policy->id,
                'version' => $policy->version,
                'status' => $policy->status,
                'feeHeadId' => $policy->fee_head_id,
                'dailyRate' => $policy->daily_rate,
                'graceDays' => $policy->grace_days,
                'maxAmount' => $policy->max_amount,
                'supersedesVersion' => $previous?->version,
            ]);

            return $policy->refresh();
        }));
    }

    /** The governing (highest) version, or null when the School never published one. */
    public function current(School $school): ?LibraryFinePolicy
    {
        return $this->context->withSchool($school, fn () => LibraryFinePolicy::query()->where('school_id', $school->id)->orderByDesc('version')->first());
    }

    /** @return Collection<int, LibraryFinePolicy> every version, newest first */
    public function versions(School $school): Collection
    {
        return $this->context->withSchool($school, fn () => LibraryFinePolicy::query()->where('school_id', $school->id)->orderByDesc('version')->get());
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validated(School $school, array $data): array
    {
        $status = $data['status'] ?? null;
        if ($status === LibraryFinePolicy::STATUS_DISABLED) {
            return ['status' => $status, 'fee_head_id' => null, 'daily_rate' => null, 'grace_days' => null, 'max_amount' => null];
        }
        if ($status !== LibraryFinePolicy::STATUS_ACTIVE) {
            throw new InvalidLibraryFinePolicyException('The status must be active or disabled.');
        }

        $feeHeadId = $data['fee_head_id'] ?? null;
        if (! is_string($feeHeadId) || $this->fees->chargeableFeeHead($school, $feeHeadId) === null) {
            throw new InvalidLibraryFinePolicyException('The fee head must be an active fee head of this School.');
        }
        $rate = $data['daily_rate'] ?? null;
        if (! is_string($rate) || ! preg_match(self::MONEY, $rate) || bccomp($rate, '0', 2) <= 0) {
            throw new InvalidLibraryFinePolicyException('The daily rate must be greater than zero, with at most two decimal places.');
        }
        $grace = $data['grace_days'] ?? null;
        if (! is_int($grace) || $grace < 0 || $grace > 365) {
            throw new InvalidLibraryFinePolicyException('The grace days must be a whole number from 0 to 365.');
        }
        $cap = $data['max_amount'] ?? null;
        if ($cap !== null && (! is_string($cap) || ! preg_match(self::MONEY, $cap) || bccomp($cap, '0', 2) <= 0)) {
            throw new InvalidLibraryFinePolicyException('The cap must be greater than zero, with at most two decimal places, or empty for no cap.');
        }

        return ['status' => $status, 'fee_head_id' => $feeHeadId, 'daily_rate' => $rate, 'grace_days' => $grace, 'max_amount' => $cap];
    }
}
