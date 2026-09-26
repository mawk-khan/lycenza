<?php

namespace App\Http\Middleware;

use App\Support\Observability\Metrics\MetricCatalog;
use App\Support\Observability\MetricsRecorder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Phase 0O.5A (ADR 0051 §11, "API and security"): request counts,
 * rejection counts (401/403/404/419/429) and duration per bounded request
 * SURFACE -- never a route URI, user, client key, School or IP. Recorded in
 * terminate(), after the response has been sent; best effort (the
 * recorder never throws in production).
 */
class RecordHttpMetrics
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('metrics_started_at', microtime(true));

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        // Resolved lazily and fully guarded: telemetry must never break a
        // response -- not even when the application refused to boot (the
        // production guard throws before any binding is registered).
        try {
            $this->record($request, $response, app(MetricsRecorder::class));
        } catch (Throwable) {
            // best effort
        }
    }

    private function record(Request $request, Response $response, MetricsRecorder $metrics): void
    {
        $surface = self::surface($request->path());
        $status = $response->getStatusCode();

        $metrics->counter('lycenza_http_requests_total', 1, ['request_surface' => $surface, 'status_class' => intdiv($status, 100).'xx']);

        if (in_array((string) $status, MetricCatalog::REJECTION_CODES, true)) {
            $metrics->counter('lycenza_http_rejections_total', 1, ['request_surface' => $surface, 'code' => (string) $status]);
        }

        $started = $request->attributes->get('metrics_started_at');
        if (is_float($started)) {
            $metrics->observe('lycenza_http_request_duration_seconds', microtime(true) - $started, ['request_surface' => $surface]);
        }
    }

    public static function surface(string $path): string
    {
        $path = ltrim($path, '/');

        return match (true) {
            str_starts_with($path, 'api/health') => 'health',
            str_starts_with($path, 'api/v1/partner') => 'api_partner',
            str_starts_with($path, 'api/internal') => 'api_internal',
            str_starts_with($path, 'api/') => 'api_v1',
            default => 'web',
        };
    }
}
