<?php

namespace App\Support\Observability;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Default ErrorReporter implementation (section 38): one structured
 * log line, sanitized metadata, and a stable grouping key (section 40)
 * -- exception class + component + operation, never a hash of the full
 * stack trace (two occurrences of the same logical failure at
 * different call depths should still group together).
 */
class LogErrorReporter implements ErrorReporter
{
    public function __construct(private readonly LogSanitizer $sanitizer) {}

    public function report(
        Throwable $exception,
        string $component,
        string $operation,
        ?string $schoolId = null,
        ?string $requestId = null,
        ?string $correlationId = null,
        array $metadata = [],
    ): void {
        $exceptionClass = $exception::class;

        Log::error('application_error', [
            'exception_class' => $exceptionClass,
            'message' => $exception->getMessage(),
            'component' => $component,
            'operation' => $operation,
            'school_id' => $schoolId,
            'request_id' => $requestId,
            'correlation_id' => $correlationId,
            'fingerprint' => $this->fingerprint($exceptionClass, $component, $operation),
            'metadata' => $this->sanitizer->sanitize($metadata),
        ]);
    }

    /**
     * Stable grouping key (section 40) -- deliberately NOT a hash of
     * the stack trace, which would scatter the same logical failure
     * across many distinct "groups" depending on call depth/line
     * number.
     */
    private function fingerprint(string $exceptionClass, string $component, string $operation): string
    {
        return "{$exceptionClass}:{$component}:{$operation}";
    }
}
