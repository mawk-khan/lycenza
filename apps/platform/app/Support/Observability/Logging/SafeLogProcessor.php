<?php

namespace App\Support\Observability\Logging;

use App\Support\Observability\LogSanitizer;
use App\Support\Observability\SafeException;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Throwable;

/**
 * Phase 0O.5A (ADR 0051 §6.1): the central sanitizing boundary, installed
 * on every application channel by StructuredLogTap. Runs for every record
 * whatever its caller:
 *
 * - a Throwable anywhere in the context (the framework reporter passes
 *   it as `exception`) becomes SafeException::describe() -- class,
 *   SQLSTATE, error code, argument-free frames, and a message only for
 *   safe types; when the record's own message IS that exception's
 *   message (the framework reporter does exactly this) and it is not
 *   safe, the message becomes the `application.exception` event code;
 * - context and `extra` (where Laravel `Context` lands) go through
 *   LogSanitizer; the message through its value scrubber;
 * - route, command and job are added where known.
 */
final class SafeLogProcessor implements ProcessorInterface
{
    public const EXCEPTION_EVENT = 'application.exception';

    public function __construct(private readonly LogSanitizer $sanitizer) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        $message = $record->message;
        $context = $record->context;

        foreach ($context as $key => $value) {
            if (! $value instanceof Throwable) {
                continue;
            }

            if ($message === $value->getMessage()) {
                $message = SafeException::messageIsSafe($value) ? $message : self::EXCEPTION_EVENT;
            }

            unset($context[$key]);
            $context = [...SafeException::fields($value), ...$context, 'exception' => SafeException::describe($value)];
        }

        $extra = [...$record->extra, ...$this->runtime()];

        return $record->with(
            message: $this->sanitizer->sanitizeString($message),
            context: $this->sanitizer->sanitize($context),
            extra: $this->sanitizer->sanitize($extra),
        );
    }

    /**
     * @return array<string, string>
     */
    private function runtime(): array
    {
        $runtime = array_filter([
            'command' => LogRuntime::command(),
            'job' => LogRuntime::job(),
        ]);

        try {
            $route = app()->bound('request') && ! app()->runningInConsole() ? app('request')->route()?->getName() : null;
            if (is_string($route) && preg_match('/^[A-Za-z0-9._-]{1,120}$/', $route) === 1) {
                $runtime['route'] = $route;
            }
        } catch (Throwable) {
            // no route information: nothing to add
        }

        return $runtime;
    }
}
