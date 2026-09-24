<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A page rendered for a signed-in user -- an HTML document (Inertia's
 * first load, a Blade error page such as the 403 with its account
 * identity) or an Inertia JSON page -- is sent `Cache-Control: no-store,
 * private`, so the browser neither keeps it in its HTTP cache nor
 * restores it from the back/forward cache after logout: Back re-requests
 * it and the server answers a guest with a redirect to /login.
 *
 * Deliberately narrow (web group only, see bootstrap/app.php): guest
 * responses, redirects, and non-page responses (file downloads, plain
 * JSON) keep the framework default. Inertia's own history state is
 * handled separately (HandleInertiaRequests encrypts it for signed-in
 * users; LoginController::destroy() clears it). See
 * docs/security/AUTHORIZATION.md ("After logout").
 */
class PreventAuthenticatedPageCaching
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if ($request->user() !== null && ! $response->isRedirection() && $this->isPage($response)) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }

    private function isPage(Response $response): bool
    {
        if ($response instanceof BinaryFileResponse || $response instanceof StreamedResponse) {
            return false;
        }

        if ($response->headers->get('X-Inertia') === 'true') {
            return true;
        }

        // An unprepared response without a Content-Type is sent as
        // text/html by Symfony's prepare().
        $contentType = $response->headers->get('Content-Type');

        return $contentType === null || str_starts_with($contentType, 'text/html');
    }
}
