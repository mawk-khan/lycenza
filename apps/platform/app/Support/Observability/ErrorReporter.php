<?php

namespace App\Support\Observability;

use Throwable;

/**
 * Phase 0C.4 section 38: a provider-independent application error
 * reporter -- business/infrastructure code depends on THIS interface,
 * never directly on Sentry/Bugsnag/Datadog/New Relic. A future adapter
 * binds to a real vendor without any call site changing.
 *
 * `$metadata` must already be safe/sanitized by the CALLER (section
 * 39) -- this is deliberate: a reporter that tries to "figure out"
 * what's safe from an arbitrary array is exactly how a Request/User/
 * Student model ends up serialized into an error report by accident.
 * Callers pass explicit, minimal, already-safe key/value pairs; if
 * they need to log something they're not sure is safe, they don't pass
 * it.
 */
interface ErrorReporter
{
    /**
     * @param  array<string, mixed>  $metadata  Already-sanitized, explicit, minimal context -- never a Request/User/model instance.
     */
    public function report(
        Throwable $exception,
        string $component,
        string $operation,
        ?string $schoolId = null,
        ?string $requestId = null,
        ?string $correlationId = null,
        array $metadata = [],
    ): void;
}
