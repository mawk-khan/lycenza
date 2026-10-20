<?php

namespace App\Domain\Students\Application;

use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\Campus\CampusScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * ORG.7F-2 (ADR 0076 §46, owner decisions F2-D2, F1-D4): the ONE Student
 * name edit -- first, middle and last name only, never the Student number,
 * date of birth, status or anything else (an explicit whitelist: extra input
 * is ignored, never mass-assigned).
 *
 * The target is StudentAccess::lockedWriteTarget() inside this transaction:
 * for a Campus-scoped editor the Student's CURRENT operational Campus must
 * be in scope and stays locked until the change commits, so an edit can
 * never commit after a transfer, inactivation, year switch or Campus
 * deactivation that committed first. Authorization-neutral beyond the scope
 * it is given (the caller resolves it through CampusScopeResolver).
 */
final class StudentNameEditService
{
    public const array FIELDS = ['first_name', 'middle_name', 'last_name'];

    public function __construct(
        private readonly StudentAccess $access,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  array<string, mixed>  $input  only the three name fields are read
     */
    public function edit(School $school, CampusScope $scope, string $studentId, array $input, ?User $actor = null): Student
    {
        $names = [
            'first_name' => (string) $input['first_name'],
            'middle_name' => isset($input['middle_name']) && $input['middle_name'] !== '' ? (string) $input['middle_name'] : null,
            'last_name' => isset($input['last_name']) && $input['last_name'] !== '' ? (string) $input['last_name'] : null,
        ];

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $scope, $studentId, $names, $actor): Student {
            $student = $this->access->lockedWriteTarget($school, $scope, $studentId);
            $changed = array_keys(array_filter($names, fn ($value, string $field) => $student->{$field} !== $value, ARRAY_FILTER_USE_BOTH));
            if ($changed === []) {
                return $student;
            }

            $student->forceFill($names)->save();
            $this->audit->school($school, 'student.updated', actor: $actor, subject: $student, metadata: [
                'changedFields' => $changed,
                'scope' => $scope->isWholeSchool() ? 'school' : 'campus',
            ]);

            return $student;
        }));
    }
}
