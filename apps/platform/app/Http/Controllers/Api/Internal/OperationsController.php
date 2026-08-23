<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Observability\ComponentStatus;
use App\Support\Observability\OperationalStatus;
use App\Support\Observability\OperationalStatusService;
use Illuminate\Http\JsonResponse;

/**
 * Phase 0C.4 section 10/52: authenticated internal diagnostics --
 * every component `App\Support\Observability\OperationalStatusService`
 * knows how to check, for a PLATFORM operator specifically (not a
 * School role -- this is cross-tenant operational data, e.g. queue
 * depth across every School's webhook deliveries, that no School-scoped
 * capability could legitimately grant access to).
 *
 * Human platform-capability auth (`platform.operations.view`), not
 * internal-service auth (`ai-service:`-style) -- the intended caller
 * is a platform operator/dashboard, not another service acting on its
 * own behalf; there is no service identity that has a legitimate
 * reason to read this today. If a future service genuinely needs
 * programmatic access, that is an explicit, separate, reviewed
 * decision, not an assumption made here.
 */
class OperationsController extends Controller
{
    use AuthorizesCapability;

    public function status(OperationalStatusService $service): JsonResponse
    {
        $this->authorizeCapability('platform.operations.view', platform: true);

        $components = $service->full();
        $overall = OperationalStatus::worstOf(array_map(fn (ComponentStatus $c) => $c->status, $components));

        return response()->json([
            'data' => [
                'status' => $overall->value,
                'components' => array_map(fn (ComponentStatus $c) => $c->toArray(), $components),
            ],
        ]);
    }
}
