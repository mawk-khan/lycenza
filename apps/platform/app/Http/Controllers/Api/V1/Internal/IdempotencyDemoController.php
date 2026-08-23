<?php

namespace App\Http\Controllers\Api\V1\Internal;

use App\Http\Controllers\Controller;
use App\Models\PlatformIdempotencyDemoCounter;
use App\Support\Audit\AuditRecorder;
use App\Support\Idempotency\IdempotencyGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Phase 0C.2's tiny infrastructure-only demonstration action (section
 * 18) -- NOT a real ERP business module. Its only purpose is to give
 * the `idempotent` middleware a genuine state-changing side effect
 * (a durable per-School counter) to prove against:
 *
 *   one request           -> counter += 1, once
 *   duplicate retry        -> still += 1 total (replayed, not re-run)
 *   concurrent duplicates  -> still += 1 total (database uniqueness)
 *
 * Also demonstrates the STRONGER, opt-in crash-window guarantee from
 * IdempotencyGuard's docblock: the counter increment, the audit event,
 * and the idempotency record's completion all happen inside ONE
 * database transaction via completeWithin(), so a crash between
 * "counter committed" and "idempotency record marked completed" cannot
 * happen for this endpoint -- unlike the middleware's own default
 * post-$next() completion path, which is two separate statements.
 *
 * Route is only registered in local/testing (routes/api.php) and
 * requires the `school.settings.manage` capability -- reusing an
 * existing capability rather than inventing a demo-only capability
 * namespace for a throwaway proof endpoint.
 */
class IdempotencyDemoController extends Controller
{
    public function increment(Request $request, TenantContext $context, AuditRecorder $audit, IdempotencyGuard $guard): JsonResponse
    {
        $school = $context->requireSchool();
        $actor = $context->actor();
        $record = $request->attributes->get('idempotency_record');

        $body = DB::transaction(function () use ($school, $actor, $audit, $guard, $record, $request) {
            $counter = PlatformIdempotencyDemoCounter::query()
                ->lockForUpdate()
                ->firstOrCreate(['school_id' => $school->id], ['value' => 0]);

            $counter->value += 1;
            $counter->save();

            // Section 28: this business audit event must fire exactly
            // once per LOGICAL request, never once per HTTP attempt --
            // a replayed request never reaches this closure at all
            // (EnsureIdempotent short-circuits before $next()), so a
            // retry never produces a second audit row.
            $audit->school($school, 'platform.idempotency_demo.counter_incremented', actor: $actor, metadata: [
                'value' => $counter->value,
            ]);

            $body = [
                'data' => ['value' => $counter->value],
                'meta' => [
                    'apiVersion' => 'v1',
                    'requestId' => $request->attributes->get('request_id'),
                ],
            ];

            if ($record !== null) {
                $guard->completeWithin($record, 200, $body, ['Content-Type' => 'application/json']);
            }

            return $body;
        });

        return response()->json($body);
    }
}
