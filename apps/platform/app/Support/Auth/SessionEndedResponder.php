<?php

namespace App\Support\Auth;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Session\TokenMismatchException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * The response for a browser page whose session has ended without an
 * explicit logout (it expired in the session store, or was signed out
 * elsewhere) -- discovered on the next request that page makes to a
 * route that requires sign-in.
 *
 * Without this, an Inertia request just followed the framework's 302 to
 * /login and swapped the login page into the SAME document: the signed-in
 * page JSON stayed in `<script data-page>` and in JavaScript memory, and
 * the history key stayed in sessionStorage, so Back decrypted and redrew
 * the signed-in page. Now it gets the same treatment as an explicit
 * logout (LoginController::destroy()): Inertia's clear-history flag in
 * the (new) session and a hard navigation to /login (Inertia::location()
 * -- 409 + X-Inertia-Location for an Inertia request, the usual 302
 * otherwise), so the browser loads a fresh guest document and drops the
 * key that could decrypt the signed-in history entries.
 *
 * Two cases, nothing else:
 * - AuthenticationException on a web (non-JSON) request -- what the
 *   `auth` middleware throws for a request without a signed-in user.
 *   Status and target are unchanged for a plain browser request (302 to
 *   the same /login, intended URL remembered).
 * - A CSRF token mismatch (419) on a route that requires sign-in, for a
 *   request that has no signed-in user: the session holding the token is
 *   gone, and `auth` would reject the request anyway once CSRF passed. A
 *   token mismatch for a signed-in user, or on a guest route (/login,
 *   /login/mfa, invitation acceptance), keeps its 419.
 *
 * JSON/API requests keep their 401/419 semantics, and authorization
 * (403), validation (422), rate limiting (429) and server errors are
 * untouched. See docs/security/AUTHORIZATION.md ("When a session ends
 * without logout").
 */
final class SessionEndedResponder
{
    /** One-request flash read by the login page (LoginController::create()). */
    public const FLASH_KEY = 'auth.session_ended';

    public function render(Throwable $e, Request $request): ?Response
    {
        if ($request->is('api/*') || $request->expectsJson()) {
            return null;
        }

        if ($e instanceof AuthenticationException) {
            $to = $e->redirectTo($request);

            return is_string($to) ? $this->toLogin($request, $to, notice: $this->isInertia($request)) : null;
        }

        if ($this->isTokenMismatchWithoutSignedInUserOnProtectedRoute($e, $request)) {
            return $this->toLogin($request, route('login'), notice: true);
        }

        return null;
    }

    /**
     * @param  bool  $notice  whether the request came from an open signed-in
     *                        page (an Inertia request or a form on one) --
     *                        a plain GET may just be a bookmark, so it gets
     *                        no "session ended" message.
     */
    private function toLogin(Request $request, string $to, bool $notice): Response
    {
        if ($request->hasSession()) {
            Inertia::clearHistory();

            if ($notice) {
                $request->session()->flash(self::FLASH_KEY, true);
            }
        }

        return Inertia::location(redirect()->guest($to));
    }

    private function isInertia(Request $request): bool
    {
        return $request->header('X-Inertia') === 'true';
    }

    private function isTokenMismatchWithoutSignedInUserOnProtectedRoute(Throwable $e, Request $request): bool
    {
        if (! $e instanceof HttpExceptionInterface
            || $e->getStatusCode() !== 419
            || ! $e->getPrevious() instanceof TokenMismatchException
            || $request->user() !== null) {
            return false;
        }

        $route = $request->route();

        if (! $route instanceof Route) {
            return false;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && (
                $middleware === 'auth'
                || str_starts_with($middleware, 'auth:')
                || $middleware === Authenticate::class
                || str_starts_with($middleware, Authenticate::class.':')
            )) {
                return true;
            }
        }

        return false;
    }
}
