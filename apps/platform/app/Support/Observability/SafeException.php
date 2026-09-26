<?php

namespace App\Support\Observability;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use PDOException;
use Throwable;

/**
 * Phase 0O.5A (ADR 0051 §6.3): what an exception may contribute to a log
 * record or a stored diagnostic. Default deny:
 *
 * - always: the class, a stable error code where the code defines one,
 *   and the SQLSTATE for database exceptions (never their message: it
 *   carries SQL with bound values, and PostgreSQL DETAIL text repeats row
 *   values);
 * - a message only for exception types whose text the application itself
 *   writes for users (its own `App\` exceptions, authentication,
 *   authorization and validation) -- still passed through the value
 *   scrubber. Everything else (framework/runtime exceptions, HTTP-client,
 *   storage-SDK and provider errors) contributes no message.
 *
 * Stack frames are file:line plus function -- never arguments (see also
 * `zend.exception_ignore_args` in the production image).
 */
final class SafeException
{
    private const MAX_FRAMES = 20;

    /**
     * @return array{exception_class: class-string, sqlstate?: string, error_code?: string}
     */
    public static function fields(Throwable $e): array
    {
        $fields = ['exception_class' => $e::class];

        $sqlstate = self::sqlstate($e);
        if ($sqlstate !== null) {
            $fields['sqlstate'] = $sqlstate;
        }

        if (method_exists($e, 'errorCode')) {
            $code = $e->errorCode();
            if (is_string($code) && preg_match('/^[a-z0-9_.]{1,64}$/', $code) === 1) {
                $fields['error_code'] = $code;
            }
        }

        return $fields;
    }

    public static function messageIsSafe(Throwable $e): bool
    {
        if ($e instanceof QueryException || $e instanceof PDOException) {
            return false;
        }

        return str_starts_with($e::class, 'App\\')
            || $e instanceof AuthenticationException
            || $e instanceof AuthorizationException
            || $e instanceof ValidationException;
    }

    /** The message when it is safe to record, scrubbed; otherwise null. */
    public static function message(Throwable $e): ?string
    {
        return self::messageIsSafe($e) ? app(LogSanitizer::class)->sanitizeString($e->getMessage()) : null;
    }

    /**
     * A short, safe diagnostic string for stored fields such as
     * `scheduler_heartbeats.last_error`: the error code or SQLSTATE, else
     * the class basename. Never message text.
     */
    public static function code(Throwable $e): string
    {
        $fields = self::fields($e);

        return $fields['error_code'] ?? (isset($fields['sqlstate']) ? 'sqlstate_'.$fields['sqlstate'] : class_basename($e));
    }

    public static function sqlstate(Throwable $e): ?string
    {
        $info = $e instanceof QueryException || $e instanceof PDOException ? ($e->errorInfo ?? null) : null;
        $state = is_array($info) && isset($info[0]) ? (string) $info[0] : ($e instanceof QueryException || $e instanceof PDOException ? (string) $e->getCode() : '');

        return preg_match('/^[0-9A-Z]{5}$/', $state) === 1 ? $state : null;
    }

    /**
     * The structured, argument-free form of an exception for a log record.
     *
     * @return array<string, mixed>
     */
    public static function describe(Throwable $e): array
    {
        $described = self::fields($e);

        if (($message = self::message($e)) !== null) {
            $described['message'] = $message;
        }

        $described['file'] = self::relative($e->getFile()).':'.$e->getLine();
        $described['trace'] = array_slice(array_map(
            fn (array $frame) => trim(($frame['class'] ?? '').($frame['type'] ?? '').$frame['function'].' '.(isset($frame['file']) ? self::relative($frame['file']).':'.($frame['line'] ?? 0) : '')),
            $e->getTrace(),
        ), 0, self::MAX_FRAMES);

        $previous = [];
        for ($p = $e->getPrevious(); $p !== null && count($previous) < 3; $p = $p->getPrevious()) {
            $previous[] = self::fields($p);
        }
        if ($previous !== []) {
            $described['previous'] = $previous;
        }

        return $described;
    }

    private static function relative(string $path): string
    {
        $base = function_exists('base_path') ? base_path().'/' : '';

        return $base !== '' && str_starts_with($path, $base) ? substr($path, strlen($base)) : basename($path);
    }
}
