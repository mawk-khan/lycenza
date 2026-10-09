<?php

namespace App\Domain\Identity\Application\Staff;

use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * SR.2 (ADR 0071 §13): `school.membership.role_grant_refused` -- one event per
 * refused role-grant authority DECISION (grant, invitation issue/resend,
 * invitation acceptance, reactivation, single-role revoke).
 *
 * Emitted only by the service that took the decision, never for validation
 * (unknown role, missing field, malformed payload, already granted, not
 * found) and never by the HTTP capability gate. A refusal inside a mutation
 * transaction is recorded in its OWN transaction AFTER that transaction
 * rolled back (a refused mutation leaves nothing else behind); an acceptance
 * refusal is recorded in the acceptance transaction, which commits its
 * `invalid` outcome. Metadata: stage, target membership / invitation, role
 * key, refusal code and -- for `not_covered` -- the uncovered capability
 * CLASSES. Never a capability list, never personal or HR/payroll data.
 */
final class RoleGrantRefusalAudit
{
    public const REFUSED = 'school.membership.role_grant_refused';

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /** After the refused mutation rolled back: its own transaction. */
    public function recordAfterRollback(School $school, ?User $actor, StaffAccountException $refused): void
    {
        if ($refused->refusal === []) {
            return;
        }

        DB::transaction(fn () => $this->record($school, $actor, $refused->refusal));
    }

    /** @param  array<string, mixed>  $metadata */
    public function record(School $school, ?User $actor, array $metadata): void
    {
        $this->context->withSchool($school, function () use ($school, $actor, $metadata): void {
            $subject = isset($metadata['schoolMembershipId'])
                ? SchoolMembership::query()->where('school_id', $school->id)->find($metadata['schoolMembershipId'])
                : null;

            $this->audit->school($school, self::REFUSED, actor: $actor, subject: $subject, metadata: $metadata);
        });
    }
}
