<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Support\Observability\OperationalStatus;
use App\Support\Observability\OperationalStatusService;
use Illuminate\Http\JsonResponse;

/**
 * Phase 0C.4 sections 5-9: liveness and readiness are DELIBERATELY
 * separate endpoints, never collapsed into one. Both are
 * unauthenticated (infrastructure -- load balancers, orchestrators --
 * must be able to poll them without a credential) and both return the
 * absolute minimum information (section 9/60): no hostnames, database
 * names, Redis addresses, stack traces, or exception messages, ever.
 * Detailed diagnostics live behind
 * App\Http\Controllers\Api\Internal\OperationsController instead,
 * which IS authenticated.
 */
class HealthController extends Controller
{
    /**
     * "Is this application process alive enough that restarting it is
     * not immediately warranted?" Never fails on Redis/PostgreSQL/
     * FastAPI/a customer webhook endpoint/an external provider being
     * unavailable -- those are readiness or internal-diagnostics
     * concerns, never liveness ones.
     */
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    /**
     * "Can this instance safely receive normal application traffic?"
     * Checks only PostgreSQL + Redis (section 7) -- customer webhook
     * endpoints, the AI Gateway, and object storage are deliberately
     * excluded (sections 7/12/50/59); an optional-subsystem outage
     * must never make the whole ERP "unready."
     */
    public function ready(): JsonResponse
    {
        $status = app(OperationalStatusService::class)->readiness();

        return response()->json(
            ['status' => $status === OperationalStatus::Healthy ? 'ok' : 'degraded'],
            $status === OperationalStatus::Healthy ? 200 : 503,
        );
    }
}
