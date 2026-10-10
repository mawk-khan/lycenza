<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\WebhookEndpoint;
use App\Models\WebhookSubscription;
use App\Support\Auth\Mfa\FreshMfaRequirement;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Webhooks\WebhookSubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class WebhookSubscriptionController extends Controller
{
    use AuthorizesCapability;

    /** SR.4 (ADR 0071 §26.7): subscribing sends a new event stream out -- a fresh `mfa_code`. */
    public function store(Request $request, School $school, string $webhookEndpoint, WebhookSubscriptionService $service, FreshMfaRequirement $mfa): JsonResponse
    {
        $this->authorizeCapability('integrations.webhooks.manage', $school);

        $endpoint = WebhookEndpoint::query()->findOrFail($webhookEndpoint);

        $validated = $request->validate([
            'event_type' => ['required', 'string', 'max:255'],
        ]);
        $mfa->requireForAction($request, $request->user());

        try {
            $subscription = $service->subscribe($endpoint, $validated['event_type'], $request->user());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['event_type' => [$e->getMessage()]]);
        }

        return response()->json(['data' => $this->present($subscription)], 201);
    }

    public function destroy(Request $request, School $school, string $webhookEndpoint, string $webhookSubscription, WebhookSubscriptionService $service): JsonResponse
    {
        $this->authorizeCapability('integrations.webhooks.manage', $school);

        // Endpoint resolved (and scoped) even though unused beyond
        // proving the subscription genuinely belongs to THIS endpoint,
        // not just this School -- a subscription id alone is not
        // sufficient to authorize deleting it via the wrong endpoint's
        // URL.
        WebhookEndpoint::query()->findOrFail($webhookEndpoint);

        $subscription = WebhookSubscription::query()
            ->where('webhook_endpoint_id', $webhookEndpoint)
            ->findOrFail($webhookSubscription);

        $service->unsubscribe($subscription, $request->user());

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(WebhookSubscription $subscription): array
    {
        return [
            'id' => $subscription->id,
            'webhookEndpointId' => $subscription->webhook_endpoint_id,
            'eventType' => $subscription->event_type,
            'enabled' => $subscription->enabled,
        ];
    }
}
