<?php

namespace App\Domain\Platform\Application\Schools;

use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\Mfa\MfaReverificationService;
use App\Support\Authorization\CapabilityResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Phase 0N.9 (ADR 0047 sections 4, 5, 7, 13): the checks every School
 * lifecycle and bootstrap operation shares, in the order they run --
 * account not disabled -> `platform.schools.manage` (root-reserved; a
 * capability, never a role name) -> the operation's own state checks ->
 * explicit confirmation -> an enrolled factor -> a fresh in-session MFA
 * re-verification. Security, authorization and state refusals are
 * audited once as `platform.school.lifecycle_denied` (operation +
 * outcome code only); ordinary input validation (a malformed slug, an
 * unknown or disabled account, a missing confirmation) is a plain 422 and
 * is not.
 */
class SchoolLifecycleAuthority
{
    public const CAPABILITY = 'platform.schools.manage';

    /** The capabilities that let a School administer itself after activation. */
    public const ADMINISTRATOR_CAPABILITIES = ['school.members.manage', 'school.roles.manage'];

    public function __construct(
        private readonly CapabilityResolver $capabilities,
        private readonly MfaReverificationService $mfa,
        private readonly AuditRecorder $audit,
    ) {}

    public function authorize(Request $request, User $actor, SchoolLifecycleOperation $operation, ?School $school): void
    {
        // Account state first: a disabled account resolves no capability
        // anyway, so checking it first records the refusal as what it is.
        if ($actor->isDisabled()) {
            $this->deny($request, $actor, $operation, $school, 'actor_disabled', 403, 'school', 'This account cannot manage Schools.');
        }

        if (! $this->capabilities->canPlatform($actor, self::CAPABILITY)) {
            $this->deny($request, $actor, $operation, $school, 'capability_missing', 403, 'school', 'This account cannot manage Schools.');
        }
    }

    public function canManage(User $actor): bool
    {
        return ! $actor->isDisabled() && $this->capabilities->canPlatform($actor, self::CAPABILITY);
    }

    public function hasActiveFactor(User $actor): bool
    {
        return $this->mfa->hasActiveFactor($actor);
    }

    /**
     * Explicit confirmation, then a fresh in-session MFA re-verification
     * (ADR 0047 section 7): stale sign-in assurance is never enough.
     */
    public function confirmAndReverify(Request $request, User $actor, SchoolLifecycleOperation $operation, ?School $school, mixed $confirmed, mixed $code): void
    {
        if (! in_array($confirmed, [true, 1, '1', 'true', 'on', 'yes'], true)) {
            throw ValidationException::withMessages(['confirmed' => 'Confirm this action to continue.']);
        }

        if (! $this->mfa->hasActiveFactor($actor)) {
            $this->deny($request, $actor, $operation, $school, 'mfa_not_enrolled', 403, 'mfa_code', 'This action requires multi-factor authentication. Enroll a factor under Account security first.');
        }

        if (! is_string($code) || trim($code) === '') {
            throw ValidationException::withMessages(['mfa_code' => 'Enter a current authentication code.']);
        }

        if (! $this->mfa->reverify($request, $actor, trim($code))) {
            $this->deny($request, $actor, $operation, $school, 'mfa_verification_failed', 422, 'mfa_code', 'That code is not valid.');
        }
    }

    /**
     * The exact, existing, enabled account a bootstrap administrator is
     * given to -- an exact email (case-insensitive) or account id, the
     * resolution platform-role governance uses. Never the acting operator.
     */
    public function bootstrapTarget(Request $request, User $actor, SchoolLifecycleOperation $operation, ?School $school, mixed $identifier): User
    {
        $identifier = is_string($identifier) ? trim($identifier) : '';

        if ($identifier === '' || strlen($identifier) > 255) {
            throw ValidationException::withMessages(['admin' => 'Enter the exact email or account id of an existing account.']);
        }

        $user = Str::isUuid($identifier)
            ? User::query()->find(strtolower($identifier))
            : User::query()->where('email', strtolower($identifier))->first();

        if ($user === null) {
            throw ValidationException::withMessages(['admin' => 'No account has exactly that email or identifier.']);
        }

        if ($user->isDisabled()) {
            throw ValidationException::withMessages(['admin' => 'That account is disabled.']);
        }

        if ($user->id === $actor->id) {
            $this->deny($request, $actor, $operation, $school, 'self_nomination', 422, 'admin', 'You cannot make yourself a School administrator.');
        }

        return $user;
    }

    /**
     * Non-disabled users with an ACTIVE membership in $school whose roles
     * grant every ADMINISTRATOR_CAPABILITIES key -- a capability test, not
     * a role name (CLAUDE.md rule 24). Read fresh (cache forgotten).
     *
     * @return Collection<int, User>
     */
    public function qualifyingAdministrators(School $school): Collection
    {
        return SchoolMembership::query()->active()
            ->where('school_id', $school->id)
            ->with('user')
            ->get()
            ->map(fn (SchoolMembership $m) => $m->user)
            ->filter(function (?User $user) use ($school): bool {
                if ($user === null || $user->isDisabled()) {
                    return false;
                }

                $this->capabilities->forgetCache($user, $school);
                $held = $this->capabilities->schoolCapabilities($user, $school);

                return array_diff(self::ADMINISTRATOR_CAPABILITIES, $held) === [];
            })
            ->values();
    }

    public function forgetCapabilities(User $user, School $school): void
    {
        $this->capabilities->forgetCache($user, $school);
    }

    public function deny(Request $request, User $actor, SchoolLifecycleOperation $operation, ?School $school, string $outcome, int $status, string $field, string $message): never
    {
        $this->audit->platform(SchoolLifecycleAudit::DENIED, actor: $actor, subject: $school, metadata: [
            'operation' => $operation->value,
            'outcome_code' => $outcome,
        ], ipAddress: $request->ip(), userAgent: $request->userAgent());

        throw new SchoolLifecycleDeniedException($outcome, $status, $field, $message);
    }
}
