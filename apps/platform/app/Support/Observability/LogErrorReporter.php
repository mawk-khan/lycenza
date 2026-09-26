<?php

namespace App\Support\Observability;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Default ErrorReporter (ADR 0051 §6): one `error` record under the given
 * event code, carrying the exception itself so the central SafeLogProcessor
 * reduces it to class / SQLSTATE / error code / argument-free frames
 * (never an unsafe message), plus a stable grouping fingerprint --
 * `{exception_class}:{component}:{operation}`, never a stack-trace hash.
 */
class LogErrorReporter implements ErrorReporter
{
    public function report(Throwable $exception, string $eventCode, string $component, string $operation, array $context = []): void
    {
        Log::error($eventCode, [
            ...$context,
            'component' => $component,
            'operation' => $operation,
            'fingerprint' => $exception::class.":{$component}:{$operation}",
            'exception' => $exception,
        ]);
    }
}
