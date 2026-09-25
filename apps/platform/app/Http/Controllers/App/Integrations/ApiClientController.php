<?php

namespace App\Http\Controllers\App\Integrations;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Api\PartnerScopeRegistry;
use App\Support\ApiClients\ApiClientService;
use App\Support\Auth\Mfa\FreshMfaRequirement;
use App\Support\Auth\Mfa\MfaChallengeService;
use App\Support\Auth\RendersCredentialJson;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Phase 0O.3 (ADR 0049 section 3): the School Integrations page for
 * partner API clients. The School is always the trusted, selected School
 * (`school-context`, TenantContext) -- never a client-supplied id; there
 * is no platform-wide partner manager and no secret is ever re-displayed.
 *
 * - view: `integrations.api_clients.view` and current MFA assurance;
 * - issue, rotate, revoke: `integrations.api_clients.manage` and a FRESH
 *   MFA code (checked after the capability, so a refused person never
 *   spends a code); new values are returned once, as JSON.
 * With no approved production partner scope, nothing can be issued in
 * production (the page says so).
 */
class ApiClientController extends Controller
{
    use RendersCredentialJson;

    public function __construct(
        private readonly ApiClientService $clients,
        private readonly FreshMfaRequirement $mfa,
        private readonly CapabilityResolver $capabilities,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request, MfaChallengeService $challenge): Response
    {
        $user = $request->user();
        $school = $this->context->requireSchool();

        if (! $this->capabilities->canInSchool($user, ApiClientService::CAPABILITY_VIEW, $school)) {
            throw new AccessDeniedHttpException('You cannot view API clients in this School.');
        }

        if (! $challenge->userHasActiveFactor($user) || ! $challenge->hasValidAssurance($request)) {
            return Inertia::render('App/Platform/MfaRequired', [
                'code' => $challenge->userHasActiveFactor($user) ? 'mfa_step_up_required' : 'mfa_required_not_enrolled',
            ])->toResponse($request)->setStatusCode($challenge->userHasActiveFactor($user) ? 401 : 403);
        }

        return Inertia::render('App/Integrations/ApiClients', [
            'clients' => $this->clients->listFor($school, $user),
            'scopes' => PartnerScopeRegistry::available(),
            'canManage' => $this->capabilities->canInSchool($user, ApiClientService::CAPABILITY_MANAGE, $school),
            'defaultDays' => ApiClientService::DEFAULT_LIFETIME_DAYS,
            'maxDays' => ApiClientService::MAX_LIFETIME_DAYS,
            'overlapHours' => ApiClientService::ROTATION_OVERLAP_HOURS,
        ])->toResponse($request);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->credentialJson(function () use ($request): array {
            $validated = $request->validate([
                'name' => ['required', 'string', 'max:100'],
                'scopes' => ['required', 'array', 'min:1'],
                'scopes.*' => ['string'],
                'lifetime_days' => ['nullable', 'integer'],
            ]);

            $school = $this->requireManage($request);
            $this->mfa->require($request, $request->user(), $request->input('mfa_code'));

            [$client, $credential] = $this->clients->issue(
                $school,
                $request->user(),
                $validated['name'],
                array_values($validated['scopes']),
                isset($validated['lifetime_days']) ? (int) $validated['lifetime_days'] : null,
            );

            return ['clientId' => $client->id, 'credential' => $credential];
        }, 201);
    }

    public function rotate(Request $request, string $client): JsonResponse
    {
        return $this->credentialJson(function () use ($request, $client): array {
            $validated = $request->validate(['lifetime_days' => ['nullable', 'integer']]);
            $school = $this->requireManage($request);
            $this->mfa->require($request, $request->user(), $request->input('mfa_code'));

            return ['credential' => $this->clients->rotate(
                $school,
                $request->user(),
                $client,
                isset($validated['lifetime_days']) ? (int) $validated['lifetime_days'] : null,
            )];
        });
    }

    public function revoke(Request $request, string $client): JsonResponse
    {
        return $this->credentialJson(function () use ($request, $client): array {
            $school = $this->requireManage($request);
            $this->mfa->require($request, $request->user(), $request->input('mfa_code'));
            $this->clients->revoke($school, $request->user(), $client);

            return ['revoked' => true];
        });
    }

    private function requireManage(Request $request): School
    {
        $school = $this->context->requireSchool();

        if (! $this->capabilities->canInSchool($request->user(), ApiClientService::CAPABILITY_MANAGE, $school)) {
            throw new AccessDeniedHttpException('You cannot manage API clients in this School.');
        }

        return $school;
    }
}
