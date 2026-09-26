<?php

namespace App\Support\Observability;

use Throwable;

/**
 * Phase 0C.4 section 38, redefined in Phase 0O.5A (ADR 0051 §6): the
 * application's ONE call for reporting a HANDLED exception (one the code
 * catches and survives -- a scheduled command's failure, a consumer
 * failure). Unhandled exceptions go through the framework reporter.
 * Both end in the same central log pipeline (StructuredLogTap), which
 * owns sanitization and the safe exception form; this interface only
 * fixes the event shape and the stable fingerprint. No vendor is bound.
 *
 * `$context` must be explicit, minimal, already-safe values -- never a
 * Request/User/model instance. Request, correlation, School and actor
 * ids come from the request/job context automatically.
 */
interface ErrorReporter
{
    /**
     * @param  string  $eventCode  stable dotted code, e.g. `platform.outbox_dispatch.failed`
     * @param  array<string, scalar|null>  $context
     */
    public function report(Throwable $exception, string $eventCode, string $component, string $operation, array $context = []): void;
}
