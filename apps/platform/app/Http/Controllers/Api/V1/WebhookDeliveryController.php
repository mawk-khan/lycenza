<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\WebhookDelivery;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use App\Support\Webhooks\WebhookDeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Read-only delivery history plus manual redelivery (section 47/48).
 * Never exposes a signing secret, a full response body, or an internal
 * stack trace (section 47) -- only the operational facts an
 * integrator/administrator legitimately needs.
 */
class WebhookDeliveryController extends Controller
{
    use AuthorizesCapability;

    public function index(Request $request, School $school): JsonResponse
    {
        $this->authorizeCapability('integrations.webhooks.view', $school);

        $deliveries = WebhookDelivery::query()
            ->when($request->string('status')->toString(), fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return response()->json(['data' => $deliveries->map(fn (WebhookDelivery $d) => $this->present($d))->all()]);
    }

    public function show(School $school, string $webhookDelivery): JsonResponse
    {
        $this->authorizeCapability('integrations.webhooks.view', $school);

        $delivery = WebhookDelivery::query()->with('deliveryAttempts')->findOrFail($webhookDelivery);

        return response()->json(['data' => $this->present($delivery, withAttempts: true)]);
    }

    public function redeliver(Request $request, School $school, string $webhookDelivery, WebhookDeliveryService $service, TenantContext $context): JsonResponse
    {
        // Authorized by the `capability:` ROUTE middleware (before
        // `idempotent`), not this trait -- see routes/api.php.
        $delivery = WebhookDelivery::query()->findOrFail($webhookDelivery);

        try {
            $service->redeliver($delivery, $request->user());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['delivery' => [$e->getMessage()]]);
        }

        // WebhookDeliveryService::redeliver() dispatches
        // DeliverWebhookJob, whose own finally-block clears
        // TenantContext when it happens to run SYNCHRONOUSLY within
        // THIS request (only possible under the sync queue connection
        // used in local/testing, ADR 0024 -- a real async worker runs
        // in a separate process with its own context and would never
        // touch this request's context at all). Re-establish it so the
        // `idempotent` middleware's own post-controller processing
        // (which still needs an active School context to read this
        // School's RLS-protected api_idempotency_keys row) isn't left
        // looking at a cleared one.
        $context->set($school);

        return response()->json(['data' => $this->present($delivery->refresh())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(WebhookDelivery $delivery, bool $withAttempts = false): array
    {
        $latestAttempt = $delivery->relationLoaded('deliveryAttempts')
            ? $delivery->deliveryAttempts->sortByDesc('attempt_number')->first()
            : null;

        $data = [
            'id' => $delivery->id,
            'webhookEndpointId' => $delivery->webhook_endpoint_id,
            'eventId' => $delivery->event_id,
            'eventType' => $delivery->event_type,
            'status' => $delivery->status,
            'attempts' => $delivery->attempts,
            'nextAttemptAt' => $delivery->next_attempt_at?->toIso8601String(),
            'deliveredAt' => $delivery->delivered_at?->toIso8601String(),
            'createdAt' => $delivery->created_at->toIso8601String(),
            'lastAttempt' => $latestAttempt === null ? null : [
                'attemptNumber' => $latestAttempt->attempt_number,
                'outcome' => $latestAttempt->outcome,
                'responseStatus' => $latestAttempt->response_status,
                'completedAt' => $latestAttempt->completed_at?->toIso8601String(),
            ],
        ];

        if ($withAttempts) {
            $data['attemptHistory'] = $delivery->deliveryAttempts->sortBy('attempt_number')->values()->map(fn ($a) => [
                'attemptNumber' => $a->attempt_number,
                'outcome' => $a->outcome,
                'responseStatus' => $a->response_status,
                'durationMs' => $a->duration_ms,
                'errorClass' => $a->error_class,
                'startedAt' => $a->started_at->toIso8601String(),
                'completedAt' => $a->completed_at?->toIso8601String(),
            ])->all();
        }

        return $data;
    }
}
