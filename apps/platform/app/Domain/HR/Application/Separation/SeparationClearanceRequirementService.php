<?php

namespace App\Domain\HR\Application\Separation;

use App\Domain\HR\Application\HrEmployeeAccess;
use App\Domain\HR\Application\Workforce\StaffType;
use App\Domain\HR\Infrastructure\SeparationClearanceRequirement;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\CodeNormalizer;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * HR.12: the School's administrative clearance requirements -- created and
 * retired, never edited or deleted (the database refuses both). A case takes
 * its own snapshot at approval, so retiring or adding a requirement never
 * changes a case already approved. Applicability is one optional closed
 * dimension (staff type, HR.1A); responsibility is a closed list mapped to a
 * capability in code (ClearanceResponsibility) -- never an authorization
 * expression a School writes.
 */
final class SeparationClearanceRequirementService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
        private readonly HrEmployeeAccess $access,
        private readonly SchoolOperationalGuard $operational,
    ) {}

    public function create(School $school, string $code, string $label, ClearanceResponsibility $responsibility, bool $mandatory, ?StaffType $staffType, User $actor): SeparationClearanceRequirement
    {
        $this->access->requireWholeSchool($actor, SeparationCapabilities::CONFIGURE, $school);
        $label = trim($label);
        if ($label === '' || mb_strlen($label) > 120) {
            throw SeparationException::invalid('SEPARATION_REQUIREMENT_LABEL', 'Enter a label of at most 120 characters.');
        }

        return $this->context->withSchool($school, function () use ($school, $code, $label, $responsibility, $mandatory, $staffType, $actor): SeparationClearanceRequirement {
            return DB::transaction(function () use ($school, $code, $label, $responsibility, $mandatory, $staffType, $actor): SeparationClearanceRequirement {
                $this->operational->requireOperational($school->id);
                // Display order only (ties fall back to the code); the code is the identity.
                $sequence = (int) SeparationClearanceRequirement::query()->where('school_id', $school->id)->max('sequence') + 1;

                try {
                    $requirement = DB::transaction(fn () => SeparationClearanceRequirement::query()->forceCreate([
                        'code' => CodeNormalizer::normalize($code),
                        'label' => $label,
                        'responsibility' => $responsibility->value,
                        'is_mandatory' => $mandatory,
                        'staff_type' => $staffType?->value,
                        'sequence' => $sequence,
                        'created_by_user_id' => $actor->id,
                    ]));
                } catch (UniqueConstraintViolationException) {
                    throw SeparationException::conflict('SEPARATION_REQUIREMENT_CODE_TAKEN', 'A clearance requirement with this code already exists (codes are never reused).');
                }

                $this->audit->school($school, 'hr.separation_requirement.created', actor: $actor, subject: $requirement, metadata: [
                    'code' => $requirement->code,
                    'responsibility' => $requirement->responsibility,
                    'mandatory' => $requirement->is_mandatory,
                    'staffType' => $requirement->staff_type,
                ]);

                return $requirement;
            });
        });
    }

    public function retire(School $school, string $requirementId, User $actor): SeparationClearanceRequirement
    {
        $this->access->requireWholeSchool($actor, SeparationCapabilities::CONFIGURE, $school);

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $requirementId, $actor): SeparationClearanceRequirement {
            $this->operational->requireOperational($school->id);
            $requirement = SeparationClearanceRequirement::query()->where('school_id', $school->id)->whereKey($requirementId)->lockForUpdate()->first()
                ?? throw SeparationException::notFound();
            if ($requirement->status === 'retired') {
                throw SeparationException::conflict('SEPARATION_REQUIREMENT_RETIRED', 'This clearance requirement is already retired.');
            }

            $requirement->forceFill(['status' => 'retired', 'retired_by_user_id' => $actor->id, 'retired_at' => now()])->save();
            $this->audit->school($school, 'hr.separation_requirement.retired', actor: $actor, subject: $requirement, metadata: ['code' => $requirement->code]);

            return $requirement;
        }));
    }
}
