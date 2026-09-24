<?php

namespace App\Domain\Platform\Application\Elevation;

use App\Models\School;
use App\Models\SchoolElevation;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\Mfa\MfaReverificationService;
use App\Support\Authorization\CapabilityResolver;
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
     * exact School. Used by the confirmation step and repeated by start().
     */
    public function prepare(Request $request, User $actor, string $identifier, ?string $reasonCode = null): School
    {
        // Account state before the capability: CapabilityResolver already
        // yields no capability for a disabled account, so checking it first
        // is what lets the refusal be audited as what it is.
        if ($actor->isDisabled()) {
            $this->deny($request, $actor, null, 'actor_disabled', $reasonCode, 403, 'forbidden', 'target', 'This account cannot enter a School.');
        }

        if (! $this->capabilities->canPlatform($actor, self::CAPABILITY)) {
            $this->deny($request, $actor, null, 'capability_missing', $reasonCode, 403, 'forbidden', 'target', 'This account cannot enter a School.');
        }

        [$school, $failure] = $this->targets->resolve($identifier);

        if ($school === null) {
            $this->deny($request, $actor, null, $failure ?? ElevationTargetResolver::OUTCOME_NOT_FOUND, $reasonCode, 422, 'target_unavailable', 'target', self::UNAVAILABLE);
        }

        if (! $school->isActive()) {
            $this->deny($request, $actor, $school, 'target_inactive', $reasonCode, 422, 'target_unavailable', 'target', self::UNAVAILABLE);
        }

        if ($this->isMember($actor, $school)) {
            $this->deny($request, $actor, $school, 'actor_is_member', $reasonCode, 422, 'actor_is_member', 'target', 'You are a member of that School: select it from your School list instead.');
        }

        if ($this->activeFor($actor) !== null) {
            $this->deny($request, $actor, $school, 'already_elevated', $reasonCode, 409, 'already_elevated', 'target', 'You already have elevated access active. Exit it first.');
        }

        return $school;
    }

    public function start(Request $request, User $actor, string $identifier, mixed $reasonCode, mixed $confirmed, mixed $mfaCode): SchoolElevation
    {
        $validReason = is_string($reasonCode) ? ElevationReason::tryFrom($reasonCode) : null;
        $school = $this->prepare($request, $actor, $identifier, $validReason?->value);

        if ($validReason === null) {
            throw ValidationException::withMessages(['reason_code' => 'Choose one of the listed reasons.']);
        }

        if (! in_array($confirmed, [true, 1, '1', 'true', 'on', 'yes'], true)) {
            throw ValidationException::withMessages(['confirmed' => 'Confirm that you are entering this School.']);
        }

        if (! $this->mfa->hasActiveFactor($actor)) {
            $this->deny($request, $actor, $school, 'mfa_not_enrolled', $validReason->value, 403, 'mfa_required_not_enrolled', 'code', 'Entering a School requires multi-factor authentication. Enroll a factor under Account security first.');
        }

        if (! is_string($mfaCode) || trim($mfaCode) === '') {
            throw ValidationException::withMessages(['code' => 'Enter a current authentication code.']);
        }

        if (! $this->mfa->reverify($request, $actor, trim($mfaCode))) {
            $this->deny($request, $actor, $school, 'mfa_verification_failed', $validReason->value, 422, 'mfa_verification_failed', 'code', 'That code is not valid.');
        }

        $now = now()->startOfSecond();
        $expiresAt = $this->expiryFor($request, $now);

        try {
            return DB::transaction(function () use ($request, $actor, $school, $validReason, $now, $expiresAt): SchoolElevation {
                $elevation = SchoolElevation::create([
                    'actor_user_id' => $actor->id,
                    'school_id' => $school->id,
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
                ], ipAddress: $request->ip(), userAgent: $request->userAgent());

                return $elevation;
            });
        } catch (UniqueConstraintViolationException) {
            // The partial unique index is the authoritative answer when two
            // starts race past the pre-check above (CLAUDE.md rule 30).
            $this->deny($request, $actor, $school, 'already_elevated', $validReason->value, 409, 'already_elevated', 'target', 'You already have elevated access active. Exit it first.');
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
            ! $this->capabilities->canPlatform($actor, self::CAPABILITY) => ElevationEndReason::CapabilityRevoked,
            $elevation->school === null || ! $elevation->school->isActive() => ElevationEndReason::SchoolIneligible,
            $this->isMember($actor, $elevation->school) => ElevationEndReason::MembershipConflict,
            ! $this->mfa->hasActiveFactor($actor) => ElevationEndReason::MfaFactorRevoked,
            default => null,
        };
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

    private function deny(Request $request, User $actor, ?School $school, string $outcome, ?string $reasonCode, int $status, string $code, string $field, string $message): never
    {
        $metadata = ['outcome_code' => $outcome];

        if ($reasonCode !== null && ElevationReason::tryFrom($reasonCode) !== null) {
            $metadata['reason_code'] = $reasonCode;
        }

        $this->audit->platform(ElevationAudit::DENIED, actor: $actor, subject: $school, metadata: $metadata, ipAddress: $request->ip(), userAgent: $request->userAgent());

        throw new ElevationDeniedException($outcome, $status, $code, $field, $message);
    }
}
