<?php

namespace App\Domain\Schools\Application;

use App\Domain\Schools\Events\SchoolProfileUpdated;
use App\Models\School;
use App\Models\User;
use App\Support\Approval\ApprovalDecisionAuthority;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\Mfa\FreshMfaProof;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ORG.12C (ADR 0080 §8): the ONE writer of a School's institutional identity
 * -- `legal_name`, `registration_reference`, `affiliation_reference`. A
 * direct, assured action (ApprovalWorkflowRegistry::DIRECT_ASSURED), never a
 * workflow:
 *
 * - a FreshMfaProof minted by FreshMfaRequirement after the route's
 *   capability check and outside this transaction;
 * - a closed SchoolIdentityChangeReason;
 * - under the School row lock, current whole-School `school.profile.manage`
 *   re-read fresh (`authority->requireCurrent`, which also requires an
 *   operational School);
 * - the values the form was opened with must still be the stored values,
 *   so a stale form never overwrites a newer identity;
 * - one `school.institutional_identity_changed` audit event: the changed
 *   field names, the reason and the MFA time -- never the values.
 *
 * These are institutional references, never tax or statutory identifiers
 * (OD-12.2): no GST, PAN, VAT or EIN semantics.
 */
class SchoolIdentityService
{
    public const array FIELDS = ['legal_name', 'registration_reference', 'affiliation_reference'];

    public function __construct(
        private readonly ApprovalDecisionAuthority $authority,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  array<string, string|null>  $values  the new identity (all three fields)
     * @param  array<string, string|null>  $expected  the stored values the form was opened with
     * @return list<string> the changed fields ([] when nothing changed)
     */
    public function change(School $school, array $values, array $expected, SchoolIdentityChangeReason $reason, User $actor, FreshMfaProof $mfa): array
    {
        if (! $mfa->isFor($actor->id)) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        return DB::transaction(function () use ($school, $values, $expected, $reason, $actor, $mfa): array {
            $locked = School::query()->whereKey($school->id)->lockForUpdate()->firstOrFail();
            $this->authority->requireCurrent($actor, 'school.profile.manage', $locked);

            $stored = fn (string $field): ?string => ($value = trim((string) $locked->{$field})) === '' ? null : $value;
            foreach (self::FIELDS as $field) {
                if (($expected[$field] ?? null) !== $stored($field)) {
                    throw ValidationException::withMessages(['identity' => 'The institutional identity changed since you opened this form. Reload the page and try again.']);
                }
            }

            $changed = array_values(array_filter(self::FIELDS, fn (string $field): bool => ($values[$field] ?? null) !== $stored($field)));
            if ($changed === []) {
                return [];
            }

            $locked->forceFill(array_intersect_key($values, array_flip($changed)))->save();
            $this->audit->school($locked, 'school.institutional_identity_changed', actor: $actor, subject: $locked, metadata: [
                'changed' => $changed,
                'reason_code' => $reason->value,
                'mfa_verified_at' => $mfa->verifiedAt->toIso8601String(),
            ]);
            event(new SchoolProfileUpdated($locked->id, $changed));
            $school->setRawAttributes($locked->getAttributes(), true);

            return $changed;
        });
    }
}
