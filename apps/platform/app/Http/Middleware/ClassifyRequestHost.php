<?php

namespace App\Http\Middleware;

use App\Support\Domains\DomainTelemetry;
use App\Support\Domains\HostClassification;
use App\Support\Domains\HostClassifier;
use App\Support\Domains\Probe\ProbeProof;
use App\Support\Domains\SchoolHostSurface;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 0O.8A (ADR 0054 section 8): the Host boundary. Global middleware,
 * after the unchanged trusted-proxy handling and before every web/api group
 * middleware -- so an unexpected Host is refused before any session, cookie,
 * CSRF, School resolution or absolute-URL generation happens.
 *
 * - unknown (including every non-active domain): 421 with one fixed body,
 *   identical for every reason -- nothing about registration, lifecycle or
 *   School is disclosed;
 * - not_served (a health path on a non-platform name): fixed 404;
 * - school_alias: 308 to the same path on the stored ACTIVE primary -- the
 *   destination host comes from the database, never from the request;
 * - probe: the probe path only;
 * - internal: `api/internal/ai/*` and health only (404 otherwise);
 * - school: the closed SchoolHostSurface, plus `invitations/{school}/...`
 *   only for that host's own School (fixed 404 otherwise);
 * - platform: everything the platform serves, except the internal AI
 *   routes, which answer 404 unless the host is internal or a development
 *   host (ADR 0054 section 8.3).
 *
 * In production the absolute-URL root is forced to the stored canonical
 * origin (`https://<primary>` on a School host, APP_URL elsewhere), so no
 * in-request URL is ever built from the Host header itself.
 */
class ClassifyRequestHost
{
    public const MISDIRECTED_BODY = "Misdirected Request\n";

    public const NOT_FOUND_BODY = "Not Found\n";

    private DomainTelemetry $telemetry;

    /**
     * Dependencies are resolved here, never in a constructor: when a boot
     * fails (ProductionConfigurationGuard), the kernel's terminate phase
     * still instantiates every global middleware, and nothing it needs
     * (cache, database) may be required just to construct this one.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->telemetry = app(DomainTelemetry::class);
        $classification = app(HostClassifier::class)->classify($request);
        $request->attributes->set(HostClassification::ATTRIBUTE, $classification);
        $path = trim($request->path(), '/');
        $internalPath = $path === 'api/internal/ai' || str_starts_with($path, 'api/internal/ai/');

        switch ($classification->class) {
            case HostClassification::UNKNOWN:
                $this->telemetry->hostResponse('misdirected');

                return self::misdirected();

            case HostClassification::NOT_SERVED:
                $this->telemetry->hostResponse('not_served');

                return self::notFound();

            case HostClassification::SCHOOL_ALIAS:
                $this->telemetry->hostResponse('alias_redirect');

                return response('', 308, [
                    'Location' => 'https://'.$classification->primaryHostname.$request->getRequestUri(),
                    'Cache-Control' => 'no-store',
                ]);

            case HostClassification::PROBE:
                return $path === ProbeProof::PATH ? $next($request) : self::misdirected();

            case HostClassification::HEALTH:
                return $next($request);

            case HostClassification::INTERNAL:
                return $internalPath || in_array($path, HostClassifier::HEALTH_PATHS, true) ? $next($request) : self::notFound();

            case HostClassification::SCHOOL:
                if (! SchoolHostSurface::admits($path) || ! $this->invitationForThisSchool($path, (string) $classification->schoolId)) {
                    $this->telemetry->hostResponse('surface_not_found');

                    return self::notFound();
                }

                if (app()->isProduction()) {
                    URL::forceRootUrl('https://'.$classification->hostname);
                    URL::forceScheme('https');
                }

                return $next($request);

            default:
                if ($internalPath && ! $classification->development) {
                    return self::notFound();
                }

                if (app()->isProduction()) {
                    URL::forceRootUrl(rtrim((string) config('app.url'), '/'));
                }

                return $next($request);
        }
    }

    public static function misdirected(): Response
    {
        return response(self::MISDIRECTED_BODY, 421, self::fixedHeaders());
    }

    public static function notFound(): Response
    {
        return response(self::NOT_FOUND_BODY, 404, self::fixedHeaders());
    }

    /** `invitations/{school}/...` on a School host is served only for that host's School. */
    private function invitationForThisSchool(string $path, string $schoolId): bool
    {
        if (! str_starts_with($path, 'invitations/')) {
            return true;
        }

        return (explode('/', $path)[1] ?? '') === $schoolId;
    }

    /** @return array<string, string> */
    private static function fixedHeaders(): array
    {
        // This runs before ApplySecurityHeaders would see the response.
        return [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
        ];
    }
}
