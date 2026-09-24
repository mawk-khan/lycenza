<?php

namespace App\Domain\Platform\Application\Elevation;

use App\Models\GroupRoleAssignment;
use App\Models\School;
use App\Models\SchoolElevation;
use App\Models\SchoolGroup;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\Mfa\MfaReverificationService;
use App\Support\Authorization\CapabilityResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 0N.3 -- the platform elevation substrate (ADR 0044). The only
 * code that creates or finishes a `school_elevations` row.
 *
 * Starting (sections 6, 9, 15), in this order: account not disabled ->
 * platform actor holds `platform.schools.elevate` -> exact target
 * (ElevationTargetResolver) -> target active -> actor is NOT a member ->
 * no active elevation -> approved reason code -> explicit confirmation ->
 * fresh in-session MFA re-verification -> one transaction inserting the
 * record and `platform.school_elevation.started`. Security refusals are
 * audited as `platform.school_elevation.denied`; plain input validation
 * (reason code, confirmation, empty code) is a 422 and is not. Nothing
 * here sets TenantContext.
 *
 * Elevation establishes (on an opted-in route only) one School's context
 * and grants NO School capability: nothing here touches memberships,
 * roles or CapabilityResolver's School side.
 */
class SchoolElevationService
{
    public const CAPABILITY = 'platform.schools.elevate';

    /** Phase 0N.5 (ADR 0045 section 9): the Group-scope authority to start. */
    public const GROUP_CAPABILITY = 'group.schools.elevate';

    /** Owner-approved fixed maximum lifetime; also a CHECK constraint. */
    public const MAX_MINUTES = 30;

    public function __construct(
        private readonly CapabilityResolver $capabilities,
        private readonly ElevationTargetResolver $targets,
        private readonly MfaReverificationService $mfa,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * Steps 1-8: everything that decides whether this actor may enter this
     * exact School -- under platform authority, or (Phase 0N.5, ADR 0045)
     * under the authority of exactly ONE named School Group. Used by the
     * confirmation step and repeated by start().
     */
    public function prepare(Request $request, User $actor, string $identifier, ?string $reasonCode = null, ?SchoolGroup $group = null): School
    {
        return $this->authorize($request, $actor, $identifier, $reasonCode, $group)[0];
    }

    /**
     * @return array{0: School, 1: GroupRoleAssignment|null} the target and,
     *                                                       for Group authority, the one grant it rests on
     */
    private function authorize(Request $request, User $actor, string $identifier, ?string $reasonCode, ?SchoolGroup $group): array
    {
        $authority = $this->authorityMetadata($group);

        // Account state before the capability: CapabilityResolver already
        // yields no capability for a disabled account, so checking it first
        // is what lets the refusal be audited as what it is.
        if ($actor->isDisabled()) {
            $this->deny($request, $actor, null, 'actor_disabled', $reasonCode, 403, 'forbidden', 'target', 'This account cannot enter a School.', $authority);
        }

        $grant = null;

        if ($group === null) {
            if (! $this->capabilities->canPlatform($actor, self::CAPABILITY)) {
                $this->deny($request, $actor, null, 'capability_missing', $reasonCode, 403, 'forbidden', 'target', 'This account cannot enter a School.', $authority);
            }
        } else {
            $grant = $this->groupGrant($request, $actor, $group, $reasonCode, $authority);
        }

        [$school, $failure] = $this->targets->resolve($identifier);

        if ($school === null) {
            $this->deny($request, $actor, null, $failure ?? ElevationTargetResolver::OUTCOME_NOT_FOUND, $reasonCode, 422, 'target_unavailable', 'target', self::UNAVAILABLE, $authority);
        }

        if (! $school->isActive()) {
            $this->deny($request, $actor, $school, 'target_inactive', $reasonCode, 422, 'target_unavailable', 'target', self::UNAVAILABLE, $authority);
        }

        if ($group !== null && ! $this->isGroupMember($group, $school)) {
            $this->deny($request, $actor, $school, 'school_not_in_group', $reasonCode, 422, 'target_unavailable', 'target', self::UNAVAILABLE, $authority);
        }

        if ($this->isMember($actor, $school)) {
            $this->deny($request, $actor, $school, 'actor_is_member', $reasonCode, 422, 'actor_is_member', 'target', 'You are a member of that School: select it from your School list instead.', $authority);
        }

        if ($this->activeFor($actor) !== null) {
            $this->deny($request, $actor, $school, 'already_elevated', $reasonCode, 409, 'already_elevated', 'target', 'You already have elevated access active. Exit it first.', $authority);
        }

        return [$school, $grant];
    }

    /**
     * The one unrevoked grant in an active $group that carries
     * `group.schools.elevate` for this actor -- read fresh, never cached.
     *
     * @param  array<string, string>  $authority
     */
    private function groupGrant(Request $request, User $actor, SchoolGroup $group, ?string $reasonCode, array $authority): GroupRoleAssignment
    {
        $grants = GroupRoleAssignment::query()->active()
            ->where('user_id', $actor->id)
            ->where('school_group_id', $group->id)
            ->orderBy('granted_at')->orderBy('id')
            ->get();

        if ($grants->isEmpty()) {
            $this->deny($request, $actor, null, 'group_grant_missing', $reasonCode, 403, 'forbidden', 'target', 'You hold no authority in that School Group.', $authority);
        }

        if (SchoolGroup::query()->whereKey($group->id)->value('status') !== SchoolGroup::STATUS_ACTIVE) {
            $this->deny($request, $actor, null, 'group_inactive', $reasonCode, 403, 'forbidden', 'target', 'That School Group is archived.', $authority);
        }

        $grant = $grants->first(fn (GroupRoleAssignment $g) => $this->roleHolds($g->role_id, self::GROUP_CAPABILITY));

        if ($grant === null) {
            $this->deny($request, $actor, null, 'group_capability_missing', $reasonCode, 403, 'forbidden', 'target', 'Your Group role cannot enter Schools.', $authority);
        }

        return $grant;
    }

    public function start(Request $request, User $actor, string $identifier, mixed $reasonCode, mixed $confirmed, mixed $mfaCode, ?SchoolGroup $group = null): SchoolElevation
    {
        $validReason = is_string($reasonCode) ? ElevationReason::tryFrom($reasonCode) : null;
        $authority = $this->authorityMetadata($group);
        [$school, $grant] = $this->authorize($request, $actor, $identifier, $validReason?->value, $group);

        if ($validReason === null) {
            throw ValidationException::withMessages(['reason_code' => 'Choose one of the listed reasons.']);
        }

        if (! in_array($confirmed, [true, 1, '1', 'true', 'on', 'yes'], true)) {
            throw ValidationException::withMessages(['confirmed' => 'Confirm that you are entering this School.']);
        }

        if (! $this->mfa->hasActiveFactor($actor)) {
            $this->deny($request, $actor, $school, 'mfa_not_enrolled', $validReason->value, 403, 'mfa_required_not_enrolled', 'code', 'Entering a School requires multi-factor authentication. Enroll a factor under Account security first.', $authority);
        }

        if (! is_string($mfaCode) || trim($mfaCode) === '') {
            throw ValidationException::withMessages(['code' => 'Enter a current authentication code.']);
        }

        if (! $this->mfa->reverify($request, $actor, trim($mfaCode))) {
            $this->deny($request, $actor, $school, 'mfa_verification_failed', $validReason->value, 422, 'mfa_verification_failed', 'code', 'That code is not valid.', $authority);
        }

        $now = now()->startOfSecond();
        $expiresAt = $this->expiryFor($request, $now);

        try {
            return DB::transaction(function () use ($request, $actor, $school, $grant, $validReason, $now, $expiresAt): SchoolElevation {
                // For Group authority the database re-verifies the grant, the
                // Group and the membership at INSERT, holding them FOR SHARE,
                // so a concurrent removal/revocation/archive waits for this
                // row and then terminates it (fail closed).
                $elevation = SchoolElevation::create([
                    'actor_user_id' => $actor->id,
                    'school_id' => $school->id,
                    'authority_type' => $grant !== null ? SchoolElevation::AUTHORITY_GROUP : SchoolElevation::AUTHORITY_PLATFORM,
                    'school_group_id' => $grant?->school_group_id,
                    'group_role_assignment_id' => $grant?->id,
                    'reason_code' => $validReason->value,
                    'status' => SchoolElevation::STATUS_ACTIVE,
                    'started_at' => $now,
                    'expires_at' => $expiresAt,
                    'start_request_id' => $request->attributes->get('request_id'),
                ]);

                $this->audit->platform(ElevationAudit::STARTED, actor: $actor, subject: $school, metadata: [
                    'elevation_id' => $elevation->id,
                    'reason_code' => $validReason->value,
                    'expires_at' => $expiresAt->toIso8601String(),
                ] + $this->provenance($elevation), ipAddress: $request->ip(), userAgent: $request->userAgent());

                return $elevation;
            });
        } catch (UniqueConstraintViolationException) {
            // The partial unique index is the authoritative answer when two
            // starts race past the pre-check above (CLAUDE.md rule 30).
            $this->deny($request, $actor, $school, 'already_elevated', $validReason->value, 409, 'already_elevated', 'target', 'You already have elevated access active. Exit it first.', $authority);
        } catch (QueryException $e) {
            // The Group-authority INSERT trigger: the Group, grant or
            // membership changed after the checks above.
            $outcome = match (true) {
                str_contains($e->getMessage(), 'School Group is not active') => 'group_inactive',
                str_contains($e->getMessage(), 'Group grant is not an active grant') => 'group_grant_missing',
                str_contains($e->getMessage(), 'not a member of the authorizing School Group') => 'school_not_in_group',
                default => throw $e,
            };

            $this->deny($request, $actor, $school, $outcome, $validReason->value, 409, 'group_authority_changed', 'target', 'Your Group authority for that School changed. Start again.', $authority);
        }
    }

    /**
     * Why an active elevation may no longer be honoured for this actor, or
     * null when it is still valid (ADR 0044 section 4). Checked on every
     * elevated request by ResolvePlatformElevation.
     */
    public function invalidityReason(SchoolElevation $elevation, User $actor): ?ElevationEndReason
    {
        return match (true) {
            $elevation->hasExpired() => ElevationEndReason::Expired,
            $actor->isDisabled() => ElevationEndReason::ActorDisabled,
            // Only the authority the elevation was STARTED under counts --
            // never a fallback from one source to the other (ADR 0045 §9).
            $elevation->isGroupDerived() => $this->groupAuthorityInvalidity($elevation) ?? $this->commonInvalidity($elevation, $actor),
            ! $this->capabilities->canPlatform($actor, self::CAPABILITY) => ElevationEndReason::CapabilityRevoked,
            default => $this->commonInvalidity($elevation, $actor),
        };
    }

    private function commonInvalidity(SchoolElevation $elevation, User $actor): ?ElevationEndReason
    {
        return match (true) {
            $elevation->school === null || ! $elevation->school->isActive() => ElevationEndReason::SchoolIneligible,
            $this->isMember($actor, $elevation->school) => ElevationEndReason::MembershipConflict,
            ! $this->mfa->hasActiveFactor($actor) => ElevationEndReason::MfaFactorRevoked,
            default => null,
        };
    }

    /**
     * Group authority, read fresh on every request (ADR 0045 section 10):
     * the Group is active, the exact grant is unrevoked and its role still
     * carries `group.schools.elevate`, and the School is still a member.
     */
    private function groupAuthorityInvalidity(SchoolElevation $elevation): ?ElevationEndReason
    {
        $grant = GroupRoleAssignment::query()->find($elevation->group_role_assignment_id);

        return match (true) {
            SchoolGroup::query()->whereKey($elevation->school_group_id)->value('status') !== SchoolGroup::STATUS_ACTIVE => ElevationEndReason::GroupInactive,
            $grant === null || ! $grant->isActive() || ! $this->roleHolds($grant->role_id, self::GROUP_CAPABILITY) => ElevationEndReason::GroupAuthorityRevoked,
            ! $this->isGroupMemberId($elevation->school_group_id, $elevation->school_id) => ElevationEndReason::SchoolLeftGroup,
            default => null,
        };
    }

    /**
     * Terminates every ACTIVE elevation matching $scope with $reason (each
     * one finished and audited exactly once). Used by the Group governance
     * operations inside their own transaction.
     *
     * @param  callable(Builder<SchoolElevation>): mixed  $scope
     */
    public function terminateWhere(callable $scope, ElevationEndReason $reason): int
    {
        $query = SchoolElevation::query()->where('status', SchoolElevation::STATUS_ACTIVE);
        $scope($query);

        $terminated = 0;

        foreach ($query->get() as $elevation) {
            $terminated += $this->finish($elevation, $reason) ? 1 : 0;
        }

        return $terminated;
    }

    public function activeFor(User $actor): ?SchoolElevation
    {
        return SchoolElevation::query()
            ->where('actor_user_id', $actor->id)
            ->where('status', SchoolElevation::STATUS_ACTIVE)
            ->first();
    }

    /**
     * Finishes $elevation exactly once: a conditional UPDATE on a still-
     * active row plus its lifecycle audit event, in one transaction. False
     * when another request, the sweep or a hook already finished it -- the
     * database trigger makes a finished row immutable either way.
     */
    public function finish(SchoolElevation $elevation, ElevationEndReason $reason): bool
    {
        return DB::transaction(function () use ($elevation, $reason): bool {
            $finished = SchoolElevation::query()
                ->whereKey($elevation->id)
                ->where('status', SchoolElevation::STATUS_ACTIVE)
                ->update([
                    'status' => $reason->status(),
                    'ended_at' => now(),
                    'end_reason' => $reason->value,
                    'updated_at' => now(),
                ]);

            if ($finished !== 1) {
                return false;
            }

            $metadata = ['elevation_id' => $elevation->id];
            $metadata += $reason === ElevationEndReason::Expired
                ? ['expires_at' => $elevation->expires_at->toIso8601String()]
                : ['end_reason' => $reason->value];
            $metadata += $this->provenance($elevation);

            $this->audit->platform($reason->auditEvent(), actor: $elevation->actor, subject: $elevation->school, metadata: $metadata);

            return true;
        });
    }

    /** Ends the actor's active elevation, if any (explicit exit, MFA hooks). */
    public function finishActiveFor(User $actor, ElevationEndReason $reason): bool
    {
        $elevation = $this->activeFor($actor);

        return $elevation !== null && $this->finish($elevation, $reason);
    }

    /**
     * The sweep (ADR 0044 section 10): records `expired` for every overdue
     * active elevation -- including ones whose session vanished without a
     * request. Idempotent: finish() only ever acts on a still-active row.
     * Platform-owned rows only; no School context is entered.
     */
    public function expireOverdue(): int
    {
        $expired = 0;

        SchoolElevation::query()
            ->where('status', SchoolElevation::STATUS_ACTIVE)
            ->where('expires_at', '<=', now())
            // Keyset pagination: finishing a row removes it from this
            // filter, which an offset-based chunk would skip past.
            ->lazyById(100)
            ->each(function (SchoolElevation $elevation) use (&$expired): void {
                $expired += $this->finish($elevation, ElevationEndReason::Expired) ? 1 : 0;
            });

        return $expired;
    }

    private const UNAVAILABLE = 'That School cannot be entered.';

    /**
     * Authority provenance on every elevation audit row (ADR 0045 section 11).
     *
     * @return array<string, string>
     */
    private function provenance(SchoolElevation $elevation): array
    {
        return $elevation->isGroupDerived()
            ? ['authority_type' => SchoolElevation::AUTHORITY_GROUP, 'school_group_id' => (string) $elevation->school_group_id, 'group_role_assignment_id' => (string) $elevation->group_role_assignment_id]
            : ['authority_type' => SchoolElevation::AUTHORITY_PLATFORM];
    }

    /**
     * @return array<string, string>
     */
    private function authorityMetadata(?SchoolGroup $group): array
    {
        return $group !== null
            ? ['authority_type' => SchoolElevation::AUTHORITY_GROUP, 'school_group_id' => $group->id]
            : ['authority_type' => SchoolElevation::AUTHORITY_PLATFORM];
    }

    private function roleHolds(string $roleId, string $capability): bool
    {
        return DB::table('role_capabilities')->where('role_id', $roleId)->where('capability_key', $capability)->exists();
    }

    private function isGroupMember(SchoolGroup $group, School $school): bool
    {
        return $this->isGroupMemberId($group->id, $school->id);
    }

    private function isGroupMemberId(?string $groupId, string $schoolId): bool
    {
        return $groupId !== null && DB::table('school_group_members')
            ->where('school_group_id', $groupId)
            ->where('school_id', $schoolId)
            ->exists();
    }

    private function isMember(User $actor, School $school): bool
    {
        return SchoolMembership::query()
            ->active()
            ->where('user_id', $actor->id)
            ->where('school_id', $school->id)
            ->exists();
    }

    /**
     * Fixed 30 minutes from start, and never beyond the MFA assurance the
     * start just re-established (ADR 0044 section 5).
     */
    private function expiryFor(Request $request, Carbon $now): Carbon
    {
        $expiresAt = $now->copy()->addMinutes(self::MAX_MINUTES);
        $verifiedAt = $request->hasSession() ? $request->session()->get('mfa_verified_at') : null;

        if (is_string($verifiedAt)) {
            $assuranceEnds = Carbon::parse($verifiedAt)->addMinutes((int) config('mfa.assurance_window_minutes'))->startOfSecond();

            if ($assuranceEnds->lessThan($expiresAt)) {
                $expiresAt = $assuranceEnds;
            }
        }

        return $expiresAt;
    }

    /**
     * @param  array<string, string>  $authority
     */
    private function deny(Request $request, User $actor, ?School $school, string $outcome, ?string $reasonCode, int $status, string $code, string $field, string $message, array $authority = []): never
    {
        $metadata = ['outcome_code' => $outcome];

        if ($reasonCode !== null && ElevationReason::tryFrom($reasonCode) !== null) {
            $metadata['reason_code'] = $reasonCode;
        }

        $metadata += $authority;

        $this->audit->platform(ElevationAudit::DENIED, actor: $actor, subject: $school, metadata: $metadata, ipAddress: $request->ip(), userAgent: $request->userAgent());

        throw new ElevationDeniedException($outcome, $status, $code, $field, $message);
    }
}
