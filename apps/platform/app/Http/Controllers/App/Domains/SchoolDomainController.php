<?php

namespace App\Http\Controllers\App\Domains;

use App\Domain\Platform\Application\Domains\DomainCheckLimiter;
use App\Domain\Platform\Application\Domains\SchoolDomainException;
use App\Domain\Platform\Application\Domains\SchoolDomainService;
use App\Http\Controllers\Controller;
use App\Jobs\CheckSchoolDomainJob;
use App\Models\School;
use App\Models\SchoolDomain;
use App\Support\Auth\Mfa\FreshMfaRequirement;
use App\Support\Auth\RendersCredentialJson;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Phase 0O.8A (ADR 0054 section 10): the School's custom-domain page. The
 * School is always the trusted, selected School (`school-context`) -- never
 * a client-supplied id -- and a School only ever sees its own rows.
 *
 * - view: `school.domains.view` (the DNS record values: `.manage` only);
 * - add, regenerate the challenge, choose the primary, remove:
 *   `school.domains.manage` AND a fresh MFA code, checked after the
 *   capability so a refused person never spends a code;
 * - check now: `school.domains.manage`, limited per School and per domain,
 *   queued (CheckSchoolDomainJob). Activation is never a School action --
 *   it follows the evidence.
 * There is no platform or Group bypass: platform and Group authority grant
 * no School capability.
 */
class SchoolDomainController extends Controller
{
    use RendersCredentialJson;

    public function __construct(
        private readonly SchoolDomainService $domains,
        private readonly FreshMfaRequirement $mfa,
        private readonly CapabilityResolver $capabilities,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $school = $this->context->requireSchool();

        if (! $this->capabilities->canInSchool($user, SchoolDomainService::CAPABILITY_VIEW, $school)) {
            throw new AccessDeniedHttpException('You cannot view this School\'s domains.');
        }

        return Inertia::render('App/Domains/Index', [
            'enabled' => $this->domains->enabled(),
            'domains' => $this->domains->listFor($school, $user),
            'routing' => $this->domains->routingTarget(),
            'canManage' => $this->capabilities->canInSchool($user, SchoolDomainService::CAPABILITY_MANAGE, $school),
            'hasMfaFactor' => $this->mfa->hasActiveFactor($user),
            'maxDomains' => SchoolDomainService::MAX_PER_SCHOOL,
        ])->toResponse($request);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->domainJson(function () use ($request): array {
            $validated = $request->validate(['hostname' => ['required', 'string', 'max:260']]);
            $school = $this->requireManage($request);
            $this->mfa->require($request, $request->user(), $request->input('mfa_code'));

            return ['id' => $this->domains->claim($school, $request->user(), $validated['hostname'])->id];
        }, 201);
    }

    public function regenerate(Request $request, string $domain): JsonResponse
    {
        return $this->domainJson(function () use ($request, $domain): array {
            $school = $this->requireManage($request);
            $this->mfa->require($request, $request->user(), $request->input('mfa_code'));
            $this->domains->regenerateChallenge($school, $request->user(), $domain);

            return ['regenerated' => true];
        });
    }

    public function primary(Request $request, string $domain): JsonResponse
    {
        return $this->domainJson(function () use ($request, $domain): array {
            $school = $this->requireManage($request);
            $this->mfa->require($request, $request->user(), $request->input('mfa_code'));
            $this->domains->setPrimary($school, $request->user(), $domain);

            return ['primary' => true];
        });
    }

    public function revoke(Request $request, string $domain): JsonResponse
    {
        return $this->domainJson(function () use ($request, $domain): array {
            $validated = $request->validate(['replacement_id' => ['nullable', 'uuid']]);
            $school = $this->requireManage($request);
            $this->mfa->require($request, $request->user(), $request->input('mfa_code'));
            $this->domains->revoke($school, $request->user(), $domain, $validated['replacement_id'] ?? null);

            return ['revoked' => true];
        });
    }

    public function check(Request $request, string $domain, DomainCheckLimiter $limiter): JsonResponse
    {
        return $this->domainJson(function () use ($request, $domain, $limiter): array {
            $school = $this->requireManage($request);
            $row = SchoolDomain::query()->where('school_id', $school->id)->claiming()->find($domain);

            if ($row === null) {
                throw new SchoolDomainException('not_found');
            }

            $limiter->hit($school->id, $row->id);
            CheckSchoolDomainJob::dispatch($row->id);

            return ['queued' => true];
        }, 202);
    }

    /** @param \Closure(): array<string, mixed> $action */
    private function domainJson(\Closure $action, int $status = 200): JsonResponse
    {
        return $this->credentialJson(function () use ($action): array {
            try {
                return $action();
            } catch (SchoolDomainException $e) {
                throw ValidationException::withMessages(['hostname' => $e->getMessage()]);
            }
        }, $status);
    }

    private function requireManage(Request $request): School
    {
        $school = $this->context->requireSchool();

        if (! $this->capabilities->canInSchool($request->user(), SchoolDomainService::CAPABILITY_MANAGE, $school)) {
            throw new AccessDeniedHttpException('You cannot manage this School\'s domains.');
        }

        return $school;
    }
}
