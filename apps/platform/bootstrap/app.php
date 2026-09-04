<?php

use App\Http\Middleware\Api\EnsurePrivateNoStoreResponse;
use App\Http\Middleware\Api\EnsureSchoolMembershipContext;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\AssignTraceContext;
use App\Http\Middleware\DevOnlySchoolHeaderResolver;
use App\Http\Middleware\EnsureCapability;
use App\Http\Middleware\EnsureIdempotent;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireMfa;
use App\Http\Middleware\ResolveSchoolContext;
use App\Http\Middleware\VerifyAiGatewayServiceToken;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
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
        $middleware->append(AssignRequestId::class);
        $middleware->append(AssignTraceContext::class);

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
        $middleware->web(
            append: [ResolveSchoolContext::class, DevOnlySchoolHeaderResolver::class, HandleInertiaRequests::class],
        );

        $middleware->api(
            append: [ResolveSchoolContext::class, DevOnlySchoolHeaderResolver::class],
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
    })->create();
