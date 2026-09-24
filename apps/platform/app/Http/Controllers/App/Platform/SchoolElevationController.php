<?php

namespace App\Http\Controllers\App\Platform;

use App\Domain\Platform\Application\Elevation\ElevationDeniedException;
use App\Domain\Platform\Application\Elevation\ElevationEndReason;
use App\Domain\Platform\Application\Elevation\ElevationReason;
use App\Domain\Platform\Application\Elevation\ElevationTargetResolver;
use App\Domain\Platform\Application\Elevation\SchoolElevationService;
use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireSchoolContext;
use App\Http\Middleware\ResolvePlatformElevation;
use App\Models\School;
use App\Models\SchoolGroup;
use App\Support\Auth\Mfa\MfaReverificationService;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\ElevationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Phase 0N.3 (ADR 0044): the platform "Enter School" flow and Exit.
 * Context-neutral routes (no `school-context`); every decision is made by
 * SchoolElevationService, which also audits every refusal.
 *
 * exact identifier (+ reason) -> exact match -> confirmation page naming
 * the School -> explicit confirmation + fresh MFA code -> elevation. No
 * School directory, search or list. Each step renders its page directly
 * (errors included) rather than redirecting back, so no pending target is
 * kept in the session: the session only ever holds the elevation id.
 */
class SchoolElevationController extends Controller
{
    public function create(Request $request, ElevationContext $elevated, SchoolElevationService $elevations, MfaReverificationService $mfa): Response|RedirectResponse
    {
        if ($elevated->isElevated()) {
            return redirect()->route('app.dashboard');
        }

        return $this->startPage($request, $elevations, $mfa, null);
    }

    public function confirm(Request $request, SchoolElevationService $elevations, MfaReverificationService $mfa): Response|SymfonyResponse
    {
        return $this->doConfirm($request, $elevations, $mfa, null);
    }

    public function store(Request $request, SchoolElevationService $elevations, MfaReverificationService $mfa): Response|SymfonyResponse
    {
        return $this->doStore($request, $elevations, $mfa, null);
    }

    /**
     * Phase 0N.5 (ADR 0045 section 9): the same flow under the authority of
     * ONE School Group, chosen explicitly by the route. The start page is
     * opened from the Group view for one of its member Schools; a Group the
     * actor holds no entry authority in, or a School outside it, is a 404.
     */
    public function createForGroup(Request $request, SchoolGroup $schoolGroup, ElevationContext $elevated, SchoolElevationService $elevations, MfaReverificationService $mfa, CapabilityResolver $capabilities): Response|RedirectResponse
    {
        if ($elevated->isElevated()) {
            return redirect()->route('app.dashboard');
        }

        abort_unless($capabilities->canInGroup($request->user(), SchoolElevationService::GROUP_CAPABILITY, $schoolGroup), 404);

        $schoolId = (string) $request->query('school', '');
        abort_unless(Str::isUuid($schoolId) && $schoolGroup->schools()->whereKey($schoolId)->exists(), 404);

        return $this->startPage($request, $elevations, $mfa, $schoolGroup, $schoolId);
    }

    public function confirmForGroup(Request $request, SchoolGroup $schoolGroup, SchoolElevationService $elevations, MfaReverificationService $mfa): Response|SymfonyResponse
    {
        return $this->doConfirm($request, $elevations, $mfa, $schoolGroup);
    }

    public function storeForGroup(Request $request, SchoolGroup $schoolGroup, SchoolElevationService $elevations, MfaReverificationService $mfa): Response|SymfonyResponse
    {
        return $this->doStore($request, $elevations, $mfa, $schoolGroup);
    }

    private function doConfirm(Request $request, SchoolElevationService $elevations, MfaReverificationService $mfa, ?SchoolGroup $group): Response|SymfonyResponse
    {
        $target = trim((string) $request->input('target'));
        $reasonInput = $request->input('reason_code');
        $reason = is_string($reasonInput) ? ElevationReason::tryFrom($reasonInput) : null;

        if ($target === '' || strlen($target) > 255) {
            return $this->invalid($request, fn () => $this->startPage($request, $elevations, $mfa, $group, null, ['target' => 'Enter the School\'s exact domain or identifier.']));
        }

        try {
            $school = $elevations->prepare($request, $request->user(), $target, $reason?->value, $group);
        } catch (ElevationDeniedException $e) {
            return $this->denied($request, $e, fn () => $this->startPage($request, $elevations, $mfa, $group, null, [$e->field => $e->getMessage()]));
        }

        if ($reason === null) {
            return $this->invalid($request, fn () => $this->startPage($request, $elevations, $mfa, $group, null, ['reason_code' => 'Choose one of the listed reasons.']));
        }

        return $this->confirmPage($target, $reason, $school, $mfa->hasActiveFactor($request->user()), $group);
    }

    private function doStore(Request $request, SchoolElevationService $elevations, MfaReverificationService $mfa, ?SchoolGroup $group): SymfonyResponse
    {
        $target = trim((string) $request->input('target'));
        $reason = is_string($request->input('reason_code')) ? ElevationReason::tryFrom($request->input('reason_code')) : null;

        if ($target === '' || strlen($target) > 255) {
            return $this->invalid($request, fn () => $this->startPage($request, $elevations, $mfa, $group, null, ['target' => 'Enter the School\'s exact domain or identifier.']));
        }

        try {
            $elevation = $elevations->start($request, $request->user(), $target, $request->input('reason_code'), $request->input('confirmed'), $request->input('code'), $group);
        } catch (ElevationDeniedException $e) {
            return $this->denied($request, $e, fn () => $this->retryPage($request, $elevations, $mfa, $target, $reason, $group, [$e->field => $e->getMessage()]));
        } catch (ValidationException $e) {
            return $this->invalid($request, fn () => $this->retryPage($request, $elevations, $mfa, $target, $reason, $group, array_map(fn (array $m) => $m[0], $e->errors())));
        }

        // Elevation replaces any ordinary selection (the confirmation page
        // says so); a fresh session id and history key mark the boundary.
        $request->session()->forget(RequireSchoolContext::SESSION_KEY);
        $request->session()->regenerate();
        $request->session()->put(ResolvePlatformElevation::SESSION_KEY, $elevation->id);
        $request->session()->flash(ResolvePlatformElevation::FLASH_KEY, 'started');
        Inertia::clearHistory();

        return redirect()->route('app.dashboard');
    }

    /**
     * Explicit Exit: ends the actor's active elevation -- whether this
     * session or another one holds it -- then clears every trace of it
     * from this session. Needs no capability: an actor must always be able
     * to leave.
     */
    public function exit(Request $request, SchoolElevationService $elevations): RedirectResponse
    {
        $elevations->finishActiveFor($request->user(), ElevationEndReason::Exited);

        $request->session()->forget([ResolvePlatformElevation::SESSION_KEY, RequireSchoolContext::SESSION_KEY]);
        $request->session()->regenerate();
        $request->session()->flash(ResolvePlatformElevation::FLASH_KEY, 'exited');
        Inertia::clearHistory();

        return redirect()->route('app.dashboard');
    }

    /**
     * @param  array<string, string>  $errors
     */
    private function startPage(Request $request, SchoolElevationService $elevations, MfaReverificationService $mfa, ?SchoolGroup $group, ?string $fixedTarget = null, array $errors = []): Response
    {
        $user = $request->user();

        return Inertia::render('App/Platform/EnterSchool', [
            'group' => $this->groupProp($group),
            'reasons' => array_map(fn (ElevationReason $r) => ['value' => $r->value, 'label' => $r->label()], ElevationReason::cases()),
            'mfaEnrolled' => $mfa->hasActiveFactor($user),
            'hasActiveElevation' => $elevations->activeFor($user) !== null,
            'maxMinutes' => SchoolElevationService::MAX_MINUTES,
            'old' => [
                'target' => $fixedTarget ?? (string) $request->input('target', ''),
                'reason_code' => (string) $request->input('reason_code', ''),
            ],
            'errors' => (object) $errors,
        ]);
    }

    /**
     * @param  array<string, string>  $errors
     */
    private function retryPage(Request $request, SchoolElevationService $elevations, MfaReverificationService $mfa, string $target, ?ElevationReason $reason, ?SchoolGroup $group, array $errors): Response
    {
        // Only reached after start() already passed every target check in
        // this same request (a refusal on `target` goes to the first step),
        // so the confirmation page may be shown again for a new code.
        if ($reason !== null && ! array_key_exists('target', $errors)) {
            [$school] = app(ElevationTargetResolver::class)->resolve($target);

            if ($school instanceof School) {
                return $this->confirmPage($target, $reason, $school, $mfa->hasActiveFactor($request->user()), $group, $errors);
            }
        }

        return $this->startPage($request, $elevations, $mfa, $group, null, $errors);
    }

    /**
     * @param  array<string, string>  $errors
     */
    private function confirmPage(string $target, ElevationReason $reason, School $school, bool $mfaEnrolled, ?SchoolGroup $group, array $errors = []): Response
    {
        return Inertia::render('App/Platform/ConfirmEnterSchool', [
            'group' => $this->groupProp($group),
            'target' => $target,
            'schoolName' => $school->name,
            'reason' => ['value' => $reason->value, 'label' => $reason->label()],
            'maxMinutes' => SchoolElevationService::MAX_MINUTES,
            'mfaEnrolled' => $mfaEnrolled,
            'errors' => (object) $errors,
        ]);
    }

    /**
     * @return array{id: string, name: string}|null
     */
    private function groupProp(?SchoolGroup $group): ?array
    {
        return $group !== null ? ['id' => $group->id, 'name' => $group->name] : null;
    }

    /**
     * @param  callable(): Response  $page
     */
    private function denied(Request $request, ElevationDeniedException $e, callable $page): SymfonyResponse
    {
        if ($this->wantsJson($request)) {
            return $this->json($request, $e->getStatusCode(), $e->errorCode(), $e->getMessage());
        }

        if ($e->getStatusCode() === 403) {
            abort(403, $e->getMessage());
        }

        return $page()->toResponse($request);
    }

    /**
     * @param  callable(): Response  $page
     */
    private function invalid(Request $request, callable $page): SymfonyResponse
    {
        if ($this->wantsJson($request)) {
            return $this->json($request, 422, 'validation_failed', 'The given data was invalid.');
        }

        return $page()->toResponse($request);
    }

    private function wantsJson(Request $request): bool
    {
        return $request->expectsJson() && $request->header('X-Inertia') !== 'true';
    }

    private function json(Request $request, int $status, string $code, string $message): JsonResponse
    {
        return response()->json([
            'error' => [
                'message' => $message,
                'status' => $status,
                'code' => $code,
                'requestId' => $request->attributes->get('request_id'),
                'errors' => null,
            ],
        ], $status);
    }
}
