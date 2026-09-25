<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 0O.3 (ADR 0049 section 11): the application-owned browser security
 * header baseline, on every response (global middleware), ADDED alongside
 * -- never replacing -- the existing Cache-Control/no-store, Inertia
 * history and logout-privacy headers. A header a response already carries
 * is left as it is.
 *
 * - Every response: nosniff, strict-origin-when-cross-origin referrer,
 *   frame denial, and a Permissions-Policy denying powerful features the
 *   ERP does not use.
 * - HTML pages: an ENFORCED Content-Security-Policy, self-only, no
 *   `unsafe-eval`, no `unsafe-inline` (Inertia's injected progress-bar CSS
 *   is disabled and shipped in the built stylesheet instead). Only in the
 *   `local` environment, while the Vite dev server runs (`public/hot`),
 *   is that dev server's origin added for scripts, styles and HMR.
 * - Downloads (`Content-Disposition: attachment`, whatever their type) and
 *   every non-HTML response (API JSON, Inertia JSON): a sandboxed
 *   `default-src 'none'` policy.
 * - HSTS: production only, only for a request known to be HTTPS, no
 *   includeSubDomains, no preload (O9 open). Behind a TLS-terminating
 *   proxy that depends on trusted-proxy configuration, which is decision
 *   O3 -- nothing here trusts every proxy.
 *
 * With APP_DEBUG on (never in production -- ProductionConfigurationGuard),
 * a 5xx debug error page keeps its own inline assets, so no CSP is added
 * to it.
 */
class ApplySecurityHeaders
{
    public const APP_CSP = "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'";

    public const RESTRICTIVE_CSP = "default-src 'none'; frame-ancestors 'none'; sandbox";

    public const PERMISSIONS_POLICY = 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), serial=(), bluetooth=(), hid=(), midi=(), display-capture=()';

    /** Owner value V5 (ADR 0049 implementation amendment). */
    public const HSTS = 'max-age=31536000';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $defaults = [
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-Frame-Options' => 'DENY',
            'Permissions-Policy' => self::PERMISSIONS_POLICY,
        ];

        foreach ($defaults as $name => $value) {
            if (! $headers->has($name)) {
                $headers->set($name, $value);
            }
        }

        if (! $headers->has('Content-Security-Policy')) {
            $policy = $this->policyFor($response);

            if ($policy !== null) {
                $headers->set('Content-Security-Policy', $policy);
            }
        }

        if (app()->isProduction() && $request->isSecure() && ! $headers->has('Strict-Transport-Security')) {
            $headers->set('Strict-Transport-Security', self::HSTS);
        }

        return $response;
    }

    private function policyFor(Response $response): ?string
    {
        $disposition = strtolower((string) $response->headers->get('Content-Disposition', ''));
        $type = strtolower((string) $response->headers->get('Content-Type', ''));

        if (str_starts_with($disposition, 'attachment')) {
            return self::RESTRICTIVE_CSP;
        }

        if (! str_contains($type, 'text/html')) {
            return self::RESTRICTIVE_CSP;
        }

        if (config('app.debug') && $response->getStatusCode() >= 500) {
            return null;
        }

        return $this->withLocalViteOrigin(self::APP_CSP);
    }

    private function withLocalViteOrigin(string $policy): string
    {
        if (! app()->environment('local') || ! Vite::isRunningHot()) {
            return $policy;
        }

        $url = trim((string) @file_get_contents(Vite::hotFile()));
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return $policy;
        }

        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $socket = ($parts['scheme'] === 'https' ? 'wss' : 'ws').'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        return str_replace(
            ["script-src 'self'", "style-src 'self'", "connect-src 'self'"],
            ["script-src 'self' {$origin}", "style-src 'self' {$origin}", "connect-src 'self' {$origin} {$socket}"],
            $policy,
        );
    }
}
