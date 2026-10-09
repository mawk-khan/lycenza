<?php

namespace App\Http\Controllers\App\Staff;

use App\Domain\Identity\Application\Staff\StaffAccessService;
use App\Domain\Identity\Application\Staff\StaffAccountDirectory;
use App\Domain\Identity\Application\Staff\StaffAccountException;
use App\Domain\Identity\Application\Staff\StaffInvitationService;
use App\Domain\Identity\Application\Staff\StaffRoleCatalog;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\User;
use App\Support\Auth\Mfa\FreshMfaRequirement;
use App\Support\Auth\RendersCredentialJson;
use App\Support\Email\Providers\EmailProviderResolver;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Phase 0O.12B (ADR 0059 section 23, owner amendment): Settings -> Staff
 * accounts, the selected School's own staff access lifecycle. The School is
 * always the trusted, selected School (`school-context`), never a
 * client-supplied id.
 *
 * - view the staff list: `school.members.view`;
 * - view the role catalogue and role assignments (who holds which role, the
 *   roles an invitation carries): `school.roles.view` (SR.2, ADR 0071 §10.4)
 *   -- a viewing capability only: it never satisfies any mutation;
 * - invite: `school.members.manage` + `school.roles.manage`;
 * - resend / revoke an invitation: `school.members.manage`;
 * - off-board (suspend) / reactivate: `school.members.manage` +
 *   `school.roles.manage`;
 * - grant / revoke one role: `school.roles.manage`;
 * every mutation ALSO needs a fresh MFA code, checked after the capability
 * so a refused person never spends a code. The services re-check
 * everything (fresh capabilities, the School operational, the last
 * qualifying administrator). There is no platform or Group bypass:
 * elevation is refused on School routes and Group authority grants no
 * School capability.
 */
class StaffAccountController extends Controller
{
    use RendersCredentialJson;

    public function __construct(
        private readonly StaffAccountDirectory $directory,
        private readonly StaffRoleCatalog $roles,
        private readonly StaffInvitationService $invitations,
        private readonly StaffAccessService $access,
        private readonly FreshMfaRequirement $mfa,
        private readonly EmailProviderResolver $emailProviders,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $school = $this->context->requireSchool();

        if (! $this->roles->canView($user, $school)) {
            throw new AccessDeniedHttpException('You cannot view this School\'s staff accounts.');
        }

        $canViewRoles = $this->roles->canViewRoles($user, $school);

        return Inertia::render('App/StaffAccounts/Index', [
            ...$this->directory->for($school, withRoles: $canViewRoles),
            'roleCatalog' => $canViewRoles ? $this->roles->catalogFor($user, $school) : [],
            'canViewRoles' => $canViewRoles,
            'canInvite' => $this->roles->canAdminister($user, $school),
            'canManageMembers' => $this->roles->canManageMembers($user, $school),
            'canManageRoles' => $this->roles->canManageRoles($user, $school),
            'hasMfaFactor' => $this->mfa->hasActiveFactor($user),
            'emailAvailable' => $this->emailProviders->criticalEmailAvailable(),
            'currentUserId' => $user->id,
        ])->toResponse($request);
    }

    public function invite(Request $request): JsonResponse
    {
        return $this->staffJson($request, [StaffRoleCatalog::MEMBERS, StaffRoleCatalog::ROLES], function (School $school, User $user) use ($request): array {
            $validated = $request->validate([
                'email' => ['required', 'string', 'max:254'],
                'roles' => ['required', 'array', 'min:1', 'max:20'],
                'roles.*' => ['string', 'max:100'],
            ]);

            return ['id' => $this->invitations->issue($school, $user, $validated['email'], array_values($validated['roles']))->id];
        }, 201);
    }

    public function resendInvitation(Request $request, string $invitation): JsonResponse
    {
        return $this->staffJson($request, [StaffRoleCatalog::MEMBERS], fn (School $school, User $user): array => [
            'id' => $this->invitations->resend($school, $user, $invitation)->id,
        ]);
    }

    public function revokeInvitation(Request $request, string $invitation): JsonResponse
    {
        return $this->staffJson($request, [StaffRoleCatalog::MEMBERS], function (School $school, User $user) use ($invitation): array {
            $this->invitations->revoke($school, $user, $invitation);

            return ['revoked' => true];
        });
    }

    public function suspend(Request $request, string $membership): JsonResponse
    {
        return $this->staffJson($request, [StaffRoleCatalog::MEMBERS, StaffRoleCatalog::ROLES], function (School $school, User $user) use ($membership): array {
            $this->access->suspend($school, $user, $membership);

            return ['suspended' => true];
        });
    }

    public function reactivate(Request $request, string $membership): JsonResponse
    {
        return $this->staffJson($request, [StaffRoleCatalog::MEMBERS, StaffRoleCatalog::ROLES], function (School $school, User $user) use ($request, $membership): array {
            $validated = $request->validate([
                'roles' => ['required', 'array', 'min:1', 'max:20'],
                'roles.*' => ['string', 'max:100'],
            ]);
            $this->access->reactivate($school, $user, $membership, array_values($validated['roles']));

            return ['reactivated' => true];
        });
    }

    public function grantRole(Request $request, string $membership): JsonResponse
    {
        return $this->staffJson($request, [StaffRoleCatalog::ROLES], function (School $school, User $user) use ($request, $membership): array {
            $validated = $request->validate(['role' => ['required', 'string', 'max:100']]);
            $this->access->grantRole($school, $user, $membership, $validated['role']);

            return ['granted' => true];
        });
    }

    public function revokeRole(Request $request, string $membership, string $role): JsonResponse
    {
        return $this->staffJson($request, [StaffRoleCatalog::ROLES], function (School $school, User $user) use ($membership, $role): array {
            $this->access->revokeRole($school, $user, $membership, $role);

            return ['revoked' => true];
        });
    }

    /**
     * Capability first (a refused person never spends an MFA code), then the
     * fresh MFA code, then the service -- whose refusals are rendered as the
     * bounded, non-disclosing message of their outcome.
     *
     * @param  list<string>  $capabilities
     * @param  Closure(School, User): array<string, mixed>  $action
     */
    private function staffJson(Request $request, array $capabilities, Closure $action, int $status = 200): JsonResponse
    {
        return $this->credentialJson(function () use ($request, $capabilities, $action): array {
            $user = $request->user();
            $school = $this->context->requireSchool();

            $allowed = match ($capabilities) {
                [StaffRoleCatalog::MEMBERS] => $this->roles->canManageMembers($user, $school),
                [StaffRoleCatalog::ROLES] => $this->roles->canManageRoles($user, $school),
                default => $this->roles->canAdminister($user, $school),
            };

            if (! $allowed) {
                throw new AccessDeniedHttpException(StaffAccountException::MESSAGES['not_authorized']);
            }

            $this->mfa->require($request, $user, $request->input('mfa_code'));

            try {
                return $action($school, $user);
            } catch (StaffAccountException $e) {
                if ($e->outcome === 'not_authorized') {
                    throw new AccessDeniedHttpException($e->getMessage());
                }

                throw ValidationException::withMessages(['staff' => $e->getMessage()]);
            }
        }, $status);
    }
}
