<?php

use App\Http\Middleware\Api\EnforceApiCredentialScope;
use App\Http\Middleware\Api\EnsurePrivateNoStoreResponse;
use App\Http\Middleware\Api\EnsureSchoolMembershipContext;
use App\Http\Middleware\Api\EstablishPartnerContext;
use App\Http\Middleware\Api\RequirePartnerScope;
use App\Http\Middleware\Api\ThrottleFailedApiAuthentication;
use App\Http\Middleware\ApplySecurityHeaders;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\AssignTraceContext;
use App\Http\Middleware\DevOnlySchoolHeaderResolver;
use App\Http\Middleware\EnsureCapability;
use App\Http\Middleware\EnsureIdempotent;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PreventAuthenticatedPageCaching;
use App\Http\Middleware\RequireMfa;
use App\Http\Middleware\RequireSchoolContext;
use App\Http\Middleware\ResolvePlatformElevation;
use App\Http\Middleware\ResolveSchoolContext;
use App\Http\Middleware\TrustConfiguredProxies;
use App\Http\Middleware\VerifyAiGatewayServiceToken;
use App\Support\Api\ApiAuthFailureLimiter;
use App\Support\Auth\SessionEndedResponder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Phase 0O.4A (ADR 0050 section 2): only the explicitly configured
        // proxies (TRUSTED_PROXIES) are believed -- never `*`.
        $middleware->replace(TrustProxies::class, TrustConfiguredProxies::class);
        // Phase 0O.4A (ADR 0050 section 13, CLAUDE.md rule 55): liveness
        // stays 200 during a maintenance window -- the process is alive and
        // must not be restarted. Readiness answers 503 itself
        // (HealthController::ready(), which also fails closed when the
        // PostgreSQL-held flag is unreadable); everything else is 503 here.
        $middleware->preventRequestsDuringMaintenance(except: ['api/health/live', 'api/health/ready']);

        $middleware->append(AssignRequestId::class);
        $middleware->append(AssignTraceContext::class);
        // Phase 0O.3 (ADR 0049 section 11): the browser security header
        // baseline on every response, alongside the existing no-store,
        // history-privacy and logout headers.
        $middleware->append(ApplySecurityHeaders::class);

        $middleware->alias([
            'capability' => EnsureCapability::class,
            'ai-service' => VerifyAiGatewayServiceToken::class,
            'school-membership' => EnsureSchoolMembershipContext::class,
            'idempotent' => EnsureIdempotent::class,
            'private-no-store' => EnsurePrivateNoStoreResponse::class,
            // Phase 0H.4D-P1: opt-in per-route MFA assurance gate,
            // never global -- see App\Http\Middleware\RequireMfa's
            // docblock.
            'mfa' => RequireMfa::class,
            // Phase 0N.1: the School-context prerequisite for every
            // School-scoped web route (routes/web.php's School group) --
            // see App\Http\Middleware\RequireSchoolContext.
            'school-context' => RequireSchoolContext::class,
            'partner-context' => EstablishPartnerContext::class,
            'partner-scope' => RequirePartnerScope::class,
        ]);

        // Tenant resolution needs the session already started (so
        // $request->user()/session() work for the session-based active
        // -school path) but MUST run before SubstituteBindings, so
        // tenant-scoped route model binding is safe (section 37). Array
        // position in web()/api() below doesn't express that ordering
        // reliably (StartSession and SubstituteBindings both live
        // inside the framework's own default group middleware), so we
        // pin the relative order explicitly via the priority list
        // instead of prepend/append position. See
        // docs/architecture/TENANCY.md and ADR 0022.
        // PreventAuthenticatedPageCaching: signed-in HTML/Inertia pages
        // are `no-store, private` so Back after logout cannot restore
        // them from the HTTP or back/forward cache (see its docblock).
        $middleware->web(
            append: [ResolveSchoolContext::class, DevOnlySchoolHeaderResolver::class, ResolvePlatformElevation::class, HandleInertiaRequests::class, PreventAuthenticatedPageCaching::class],
        );

        $middleware->api(
            append: [ResolveSchoolContext::class, DevOnlySchoolHeaderResolver::class, ThrottleFailedApiAuthentication::class, EnforceApiCredentialScope::class],
        );

        // Phase 0O.3 (ADR 0049 sections 7-8), without reordering Phase
        // 0C.2: a (client, credential) pair that keeps failing
        // authentication is refused BEFORE authentication runs, and a human
        // token's scope is checked AFTER authentication and throttling --
        // the same slot as a capability check. Authentication itself
        // (including `auth:partner`) stays framework-prioritized ahead of
        // ThrottleRequests, so limiters can key by principal.
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: ThrottleFailedApiAuthentication::class,
        );

        $middleware->appendToPriorityList(
            after: ThrottleRequests::class,
            append: EnforceApiCredentialScope::class,
        );

        $middleware->appendToPriorityList(
            after: StartSession::class,
            append: ResolveSchoolContext::class,
        );

        $middleware->appendToPriorityList(
            after: ResolveSchoolContext::class,
            append: DevOnlySchoolHeaderResolver::class,
        );

        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: DevOnlySchoolHeaderResolver::class,
        );

        // Phase 0N.3 (ADR 0044): the session elevation pointer is resolved
        // after every ordinary School resolver (so it can discard and
        // detect a School they set) and before `school-context`. Web group
        // only -- the `api` group has no session and never resolves it.
        // A single `after:` entry on purpose: DevOnlySchoolHeaderResolver
        // already sits after ResolveSchoolContext, and the framework's
        // "after the last of several" insertion is not reliable when the
        // listed entries are adjacent (asserted by
        // Tests\Feature\Platform\Elevation\ElevationArchitectureGuardTest).
        $middleware->appendToPriorityList(
            after: DevOnlySchoolHeaderResolver::class,
            append: ResolvePlatformElevation::class,
        );

        // `school-context` is route middleware, so without a priority
        // entry it would run AFTER SubstituteBindings: a School-scoped
        // route model would be bound (and 404) with no School context
        // before the prerequisite ever ran. Pinned after the user and
        // every School resolver; that lands it before ThrottleRequests
        // and SubstituteBindings, and so before every `capability:`/
        // `mfa` route middleware and the controller (asserted by
        // Tests\Feature\Tenancy\SchoolContextRouteGuardTest).
        $middleware->appendToPriorityList(
            after: [AuthenticatesRequests::class, ResolveSchoolContext::class, DevOnlySchoolHeaderResolver::class, ResolvePlatformElevation::class],
            append: RequireSchoolContext::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Consistent JSON error envelope for the whole /api surface.
        // See docs/architecture/API.md ("Error format").
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = match (true) {
                $e instanceof ValidationException => $e->status,
                $e instanceof AuthenticationException => 401,
                $e instanceof AuthorizationException => 403,
                method_exists($e, 'getStatusCode') => $e->getStatusCode(),
                default => 500,
            };
            $errors = $e instanceof ValidationException ? $e->errors() : null;

            // Phase 0O.3 (ADR 0049 section 7): every failed API
            // authentication counts toward `api-auth-failure`.
            if ($e instanceof AuthenticationException && $request->is('api/v1/*')) {
                app(ApiAuthFailureLimiter::class)->hit($request);
            }

            // Section 40 (Phase 0C.2): extends the existing envelope
            // with an optional stable machine `code` -- present only
            // for exceptions that define one (e.g. every
            // App\Support\Idempotency\Exceptions\IdempotencyException),
            // `null` otherwise. Not a new/parallel error JSON shape.
            $code = method_exists($e, 'errorCode') ? $e->errorCode() : null;

            // Phase 8A.15: this render callback builds a brand-new
            // response, so it does NOT automatically inherit headers
            // Laravel's own default exception rendering would have
            // attached -- most importantly `Retry-After` on a thrown
            // `Illuminate\Http\Exceptions\ThrottleRequestsException`
            // (every `throttle:*` limiter across the whole API, not
            // HR-specific, was silently losing this header before this
            // fix, confirmed empirically). `getHeaders()` is present on
            // any HttpExceptionInterface that carries response headers
            // (Symfony's HttpException family) -- merged in, never
            // overriding this envelope's own Content-Type.
            $headers = method_exists($e, 'getHeaders') ? $e->getHeaders() : [];

            return response()->json([
                'error' => [
                    'message' => $status === 500 && ! config('app.debug')
                        ? 'Internal Server Error'
                        : $e->getMessage(),
                    'status' => $status,
                    'code' => $code,
                    'requestId' => $request->attributes->get('request_id'),
                    'errors' => $errors,
                ],
            ], $status, $headers);
        });

        // A signed-in browser page whose session ended without logout
        // (expired, or signed out elsewhere): hard navigation to a fresh
        // /login document with Inertia history cleared, like an explicit
        // logout. Web (non-JSON) requests only; see its docblock.
        $exceptions->render(fn (Throwable $e, Request $request) => app(SessionEndedResponder::class)->render($e, $request));
    })->create();
