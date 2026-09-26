<?php

namespace App\Support\Observability\Metrics;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 0O.5A (ADR 0051 §9): the ONLY way metrics leave the application.
 * Reached exclusively through `metrics/index.php` (outside `public/`),
 * which the production image's nginx maps ONLY on its private internal
 * listener (port 9102) -- no Laravel route exists for it, so the public
 * listener can never serve it.
 *
 * - The private listener marks every request with the FastCGI parameter
 *   LYCENZA_METRICS_LISTENER=1 (set by nginx, not a client header);
 *   anything else is refused.
 * - A bearer scrape token (`METRICS_SCRAPE_TOKEN`, an O4 production secret)
 *   is required, compared in constant time over fixed-length digests. A
 *   missing, wrong or unconfigured token all get the SAME empty 401 --
 *   nothing reveals whether a token was close, and none is ever logged.
 *   It is not a service identity (O5 stays open).
 * - The body is operational metrics only; no HTML, no cache.
 */
final class MetricsEndpoint
{
    public function __construct(private readonly MetricsExporter $exporter) {}

    public function respond(Request $request): Response
    {
        if ($request->server('LYCENZA_METRICS_LISTENER') !== '1' || ! in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return $this->plain(404, '');
        }

        if (! self::authorized($request->bearerToken())) {
            return $this->plain(401, '', ['WWW-Authenticate' => 'Bearer']);
        }

        return $this->plain(200, $this->exporter->render(), ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8']);
    }

    public static function authorized(?string $presented): bool
    {
        $expected = (string) config('observability.metrics.scrape_token');

        return $expected !== '' && hash_equals(hash('sha256', $expected), hash('sha256', (string) $presented));
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function plain(int $status, string $body, array $headers = []): Response
    {
        return new Response($body, $status, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
            ...$headers,
        ]);
    }
}
