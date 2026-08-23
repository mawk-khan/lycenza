<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\WebhookEndpoint;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Webhooks\SsrfRejectedException;
use App\Support\Webhooks\WebhookEndpointService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Minimal, coherent School webhook-endpoint management API (Phase
 * 0C.3 section 51). `{webhookEndpoint}` is resolved explicitly inside
 * each action (never via implicit route-model binding) so resolution
 * happens strictly AFTER `school-membership` middleware has set
 * TenantContext -- WebhookEndpoint's SchoolScope then makes a School B
 * endpoint id 404 for a School A caller "for free," but only once
 * context is guaranteed to already be set (section 53).
 */
class WebhookEndpointController extends Controller
{
    use AuthorizesCapability;

    public function index(School $school): JsonResponse
    {
        $this->authorizeCapability('integrations.webhooks.view', $school);

        $endpoints = WebhookEndpoint::query()->orderBy('created_at')->get();

        return response()->json([
            'data' => $endpoints->map(fn (WebhookEndpoint $e) => $this->present($e))->all(),
        ]);
    }

    public function store(Request $request, School $school, WebhookEndpointService $service): JsonResponse
    {
        // Authorized by the `capability:` ROUTE middleware (before
        // `idempotent`, routes/api.php) -- not this trait -- so a
        // replay re-checks authorization first. See that route's
        // comment.
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'string', 'max:2048'],
        ]);

        try {
            [$endpoint, $secret] = $service->create($school, $validated['name'], $validated['url'], $request->user());
        } catch (SsrfRejectedException $e) {
            throw ValidationException::withMessages(['url' => [$e->getMessage()]]);
        }

        return response()->json([
            'data' => $this->present($endpoint) + [
                // Section 7: the ONLY response that ever contains the
                // plaintext secret -- never returned again afterward.
                'secret' => $secret,
            ],
        ], 201);
    }

    public function show(School $school, string $webhookEndpoint): JsonResponse
    {
        $this->authorizeCapability('integrations.webhooks.view', $school);

        $endpoint = WebhookEndpoint::query()->findOrFail($webhookEndpoint);

        return response()->json(['data' => $this->present($endpoint)]);
    }

    public function rotateSecret(Request $request, School $school, string $webhookEndpoint, WebhookEndpointService $service): JsonResponse
    {
        // Authorized by the `capability:` ROUTE middleware -- see store().
        $endpoint = WebhookEndpoint::query()->findOrFail($webhookEndpoint);
        $secret = $service->rotateSecret($endpoint, $request->user());

        return response()->json(['data' => $this->present($endpoint->refresh()) + ['secret' => $secret]]);
    }

    public function disable(Request $request, School $school, string $webhookEndpoint, WebhookEndpointService $service): JsonResponse
    {
        $this->authorizeCapability('integrations.webhooks.manage', $school);

        $endpoint = WebhookEndpoint::query()->findOrFail($webhookEndpoint);
        $service->disable($endpoint, $request->user());

        return response()->json(['data' => $this->present($endpoint->refresh())]);
    }

    public function enable(Request $request, School $school, string $webhookEndpoint, WebhookEndpointService $service): JsonResponse
    {
        $this->authorizeCapability('integrations.webhooks.manage', $school);

        $endpoint = WebhookEndpoint::query()->findOrFail($webhookEndpoint);
        $service->enable($endpoint, $request->user());

        return response()->json(['data' => $this->present($endpoint->refresh())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(WebhookEndpoint $endpoint): array
    {
        return [
            'id' => $endpoint->id,
            'name' => $endpoint->name,
            'url' => $endpoint->url,
            'status' => $endpoint->status,
            'createdAt' => $endpoint->created_at->toIso8601String(),
            'disabledAt' => $endpoint->disabled_at?->toIso8601String(),
        ];
    }
}
