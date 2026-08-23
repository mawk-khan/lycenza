<?php

namespace App\Http\Controllers\Api\V1\Internal;

use App\Domain\Platform\Events\PlatformWebhookTestEmitted;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 0C.3 section 78/79: emits the synthetic, infrastructure-only
 * `platform.webhook_test.v1` event so the webhook subsystem's live
 * proof can exercise the full chain without a real ERP business event.
 *
 * Registered ONLY in local/testing (routes/api.php) -- there is no
 * production route that lets a customer arbitrarily emit a platform
 * event -- and STILL capability-gated even there (never a bare
 * unauthenticated trigger), reusing `integrations.webhooks.manage`
 * rather than inventing a throwaway capability namespace for a
 * demonstration action, exactly like
 * App\Http\Controllers\Api\V1\Internal\IdempotencyDemoController does
 * for `school.settings.manage`.
 */
class WebhookTestEventController extends Controller
{
    use AuthorizesCapability;

    public function emit(Request $request, School $school): JsonResponse
    {
        $this->authorizeCapability('integrations.webhooks.manage', $school);

        $note = $request->string('note')->toString() ?: 'test';

        event(new PlatformWebhookTestEmitted($school->id, $note));

        return response()->json(['data' => ['emitted' => true]]);
    }
}
