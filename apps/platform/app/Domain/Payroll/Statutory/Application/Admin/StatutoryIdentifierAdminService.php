<?php

namespace App\Domain\Payroll\Statutory\Application\Admin;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Statutory\Application\Exceptions\StatutoryEmploymentRecordNotFoundException;
use App\Domain\Payroll\Statutory\Application\Exceptions\StatutoryIdentifierInvalidException;
use App\Domain\Payroll\Statutory\Infrastructure\EmployeeStatutoryIdentifier;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Privacy\PartialValueMasker;
use App\Support\Privacy\StatutoryIdentifierLookupHasher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Checkpoint 9.6I (Section 1 "Statutory identifiers") -- the
 * administrative read/write surface for PAN/UAN/PF Member ID/ESIC IP
 * Number. `list()` always masks unless the caller holds
 * `payroll.statutory.identifiers.view`; the raw value is decrypted
 * and returned ONLY from `reveal()`, a SEPARATE method a controller
 * must call EXPLICITLY (never bundled into a general "employee
 * details" payload) -- this is what makes "the raw value must never
 * be serialized to an unauthorized caller" true no matter how a
 * future controller composes its response, not merely a UI-layer
 * masking convention. `reveal()` is audited on every successful call
 * (a raw-identifier read is Highly Sensitive); `list()`'s masked form
 * is not (mirrors `DocumentReadService`'s "only the Highly Sensitive
 * path is audited" established precedent).
 */
class StatutoryIdentifierAdminService
{
    use AuthorizesCapability;

    private const VALID_TYPES = ['pan', 'uan', 'pf_member_id', 'esic_ip_number'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
        private readonly StatutoryIdentifierLookupHasher $hasher,
    ) {}

    /**
     * @return Collection<int, array{identifierType: string, masked: string, updatedAt: string}>
     */
    public function list(School $school, string $employmentRecordId, User $actor): Collection
    {
        return $this->context->withSchool($school, function () use ($school, $employmentRecordId, $actor) {
            $this->authorizeCapabilityFor($actor, 'payroll.statutory.view', $school);
            $this->assertEmploymentRecordExists($school, $employmentRecordId);

            return EmployeeStatutoryIdentifier::query()
                ->where('school_id', $school->id)
                ->where('employment_record_id', $employmentRecordId)
                ->get()
                ->map(fn (EmployeeStatutoryIdentifier $identifier) => [
                    'identifierType' => $identifier->identifier_type,
                    'masked' => PartialValueMasker::mask($identifier->encrypted_value),
                    'updatedAt' => $identifier->updated_at->toIso8601String(),
                ]);
        });
    }

    public function reveal(School $school, string $employmentRecordId, string $identifierType, User $actor): ?string
    {
        return $this->context->withSchool($school, function () use ($school, $employmentRecordId, $identifierType, $actor) {
            $this->authorizeCapabilityFor($actor, 'payroll.statutory.identifiers.view', $school);
            $this->assertEmploymentRecordExists($school, $employmentRecordId);

            $identifier = EmployeeStatutoryIdentifier::query()
                ->where('school_id', $school->id)
                ->where('employment_record_id', $employmentRecordId)
                ->where('identifier_type', $identifierType)
                ->first();

            $this->audit->school($school, 'payroll.statutory.identifier.revealed', actor: $actor, subject: $identifier, metadata: [
                'employmentRecordId' => $employmentRecordId,
                'identifierType' => $identifierType,
            ]);

            return $identifier?->encrypted_value;
        });
    }

    public function set(School $school, string $employmentRecordId, string $identifierType, string $value, User $actor): EmployeeStatutoryIdentifier
    {
        return $this->context->withSchool($school, function () use ($school, $employmentRecordId, $identifierType, $value, $actor) {
            $this->authorizeCapabilityFor($actor, 'payroll.statutory.identifiers.manage', $school);
            $this->assertEmploymentRecordExists($school, $employmentRecordId);

            if (! in_array($identifierType, self::VALID_TYPES, true)) {
                throw new StatutoryIdentifierInvalidException('identifierType must be one of: '.implode(', ', self::VALID_TYPES));
            }

            $normalized = mb_strtoupper(trim($value));
            if ($normalized === '') {
                throw new StatutoryIdentifierInvalidException('identifier value must not be blank.');
            }

            return DB::transaction(function () use ($school, $employmentRecordId, $identifierType, $normalized, $actor) {
                $identifier = EmployeeStatutoryIdentifier::query()->updateOrCreate(
                    ['school_id' => $school->id, 'employment_record_id' => $employmentRecordId, 'identifier_type' => $identifierType],
                    [
                        'encrypted_value' => $normalized,
                        'lookup_hash' => $this->hasher->hash($school->id, $identifierType, $normalized),
                        'lookup_key_version' => $this->hasher->keyVersion(),
                    ],
                );

                // Audited by reference only -- never the value itself
                // (rule 63/ADR 0035's own privacy requirement).
                $this->audit->school($school, 'payroll.statutory.identifier.set', actor: $actor, subject: $identifier, metadata: [
                    'employmentRecordId' => $employmentRecordId,
                    'identifierType' => $identifierType,
                ]);

                return $identifier;
            });
        });
    }

    private function assertEmploymentRecordExists(School $school, string $employmentRecordId): void
    {
        $exists = EmploymentRecord::query()->where('school_id', $school->id)->where('id', $employmentRecordId)->exists();

        if (! $exists) {
            throw new StatutoryEmploymentRecordNotFoundException($employmentRecordId);
        }
    }
}
