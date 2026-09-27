<?php

namespace App\Http\Controllers\Api\Integrations;

use App\Http\Controllers\Controller;
use App\Support\Email\EmailTelemetry;
use App\Support\Email\Events\EmailEventAdapterResolver;
use App\Support\Email\Events\EmailEventIngestion;
use App\Support\Email\Events\InvalidEmailEventPayload;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Phase 0O.9A (ADR 0055 section 11.2): `POST /api/integrations/email-provider/events`.
 *
 * A system boundary, not a user one: no session, no cookie, no School --
 * the Host boundary serves it on the platform host only (a School or
 * internal host answers 404), the route opts out of School resolution, and
 * no School is ever read from the payload. Order: bounds -> provider
 * authentication (the configured adapter's own scheme; never IP alone) ->
 * depth-bounded JSON -> normalization -> dedupe and queue. Responses carry
 * no detail: 404 when no event adapter is configured, one empty 401 for
 * every authentication failure, 413/400 for bounds and shape. The raw body
 * is never logged or stored.
 */
class EmailProviderEventController extends Controller
{
    public function __invoke(Request $request, EmailEventAdapterResolver $adapters, EmailEventIngestion $ingestion, EmailTelemetry $telemetry): Response
    {
        $adapter = $adapters->active();

        if ($adapter === null) {
            $telemetry->webhook('disabled');

            return response('', 404);
        }

        $max = (int) config('email.events.max_body_bytes');
        $declared = $request->header('Content-Length');

        if ((is_numeric($declared) && (int) $declared > $max) || strlen($request->getContent()) > $max) {
            $telemetry->webhook('too_large');

            return response('', 413);
        }

        if (! $adapter->authenticate($request)) {
            $telemetry->webhook('unauthenticated');

            return response('', 401);
        }

        if (! str_contains(strtolower((string) $request->header('Content-Type')), 'application/json')) {
            $telemetry->webhook('malformed');

            return response('', 415);
        }

        $decoded = json_decode($request->getContent(), true, (int) config('email.events.max_json_depth'));

        if (! is_array($decoded)) {
            $telemetry->webhook('malformed');

            return response('', 400);
        }

        try {
            $events = $adapter->normalize($decoded);
        } catch (InvalidEmailEventPayload) {
            $telemetry->webhook('malformed');

            return response('', 400);
        }

        if (count($events) > (int) config('email.events.max_events')) {
            $telemetry->webhook('too_large');

            return response('', 413);
        }

        $result = $ingestion->ingest($adapter->provider(), $events);
        $telemetry->webhook($result['new'] === 0 && $result['duplicate'] > 0 ? 'duplicate' : 'accepted');

        return response('', 202);
    }
}
