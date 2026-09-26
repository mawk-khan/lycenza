<?php

namespace App\Support\Observability\Logging;

/**
 * Phase 0O.5A (ADR 0051 §5): process-level facts the log pipeline adds to
 * every record -- the process role and, inside a queue worker, the class
 * of the job being processed. A plain holder, not Laravel `Context`
 * (Context is dehydrated into jobs dispatched from inside a job, which
 * would mislabel their records). Cleared when each job ends.
 */
final class LogRuntime
{
    private static ?string $job = null;

    public static function startJob(string $class): void
    {
        self::$job = class_basename($class);
    }

    public static function endJob(): void
    {
        self::$job = null;
    }

    public static function job(): ?string
    {
        return self::$job;
    }

    /** web | worker | scheduler | console (set by the image entrypoint; inferred otherwise). */
    public static function processRole(): string
    {
        $configured = (string) (getenv('PROCESS_ROLE') ?: '');

        if (in_array($configured, ['web', 'worker', 'scheduler', 'console'], true)) {
            return $configured;
        }

        return PHP_SAPI === 'cli' ? 'console' : 'web';
    }

    /** The artisan command name for console processes (bounded: `[a-z0-9:_-]`). */
    public static function command(): ?string
    {
        if (PHP_SAPI !== 'cli') {
            return null;
        }

        $name = $_SERVER['argv'][1] ?? null;

        return is_string($name) && preg_match('/^[a-z][a-z0-9:_-]{0,63}$/', $name) === 1 ? $name : null;
    }
}
