<?php

namespace App\Support\Observability\Logging;

use App\Support\Observability\LogSanitizer;
use Monolog\Formatter\NormalizerFormatter;
use Monolog\LogRecord;

/**
 * Phase 0O.5A (ADR 0051 §5): one JSON object per line with a FIXED,
 * bounded top-level schema. Known identifiers and operational fields are
 * promoted from the record's context/extra; everything else goes under
 * `ctx`. Empty fields are omitted. The sanitizer runs again here -- the
 * formatter is the last step before bytes leave the process, so safety
 * never depends on processor ordering.
 */
final class StructuredJsonFormatter extends NormalizerFormatter
{
    /** The complete top-level field catalog (guard-tested). */
    public const FIELDS = [
        'time', 'level', 'service', 'process_role', 'environment', 'event_code', 'message',
        'request_id', 'correlation_id', 'trace_id', 'school_id', 'actor_user_id', 'elevation_id', 'api_client_id',
        'route', 'command', 'job', 'queue', 'outcome', 'error_code', 'exception_class', 'sqlstate', 'ctx',
    ];

    /** context/extra key => top-level field */
    private const PROMOTED = [
        'event_code' => 'event_code',
        'request_id' => 'request_id',
        'correlation_id' => 'correlation_id',
        'trace_id' => 'trace_id',
        'school_id' => 'school_id',
        'actor_id' => 'actor_user_id',
        'actor_user_id' => 'actor_user_id',
        'elevation_id' => 'elevation_id',
        'api_client_id' => 'api_client_id',
        'route' => 'route',
        'command' => 'command',
        'job' => 'job',
        'queue' => 'queue',
        'outcome' => 'outcome',
        'error_code' => 'error_code',
        'exception_class' => 'exception_class',
        'sqlstate' => 'sqlstate',
    ];

    /** Context kept out of logs entirely (diagnostic noise, not identity). */
    private const DROPPED = ['span_id'];

    public function __construct(private readonly LogSanitizer $sanitizer)
    {
        parent::__construct('Y-m-d\TH:i:s.vP');
    }

    public function format(LogRecord $record): string
    {
        $context = $this->sanitizer->sanitize($record->context);
        $extra = $this->sanitizer->sanitize($record->extra);
        $message = $this->sanitizer->sanitizeString($record->message);

        $line = [
            'time' => $record->datetime->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'),
            'level' => strtolower($record->level->getName()),
            'service' => (string) config('observability.service', 'platform'),
            'process_role' => LogRuntime::processRole(),
            'environment' => (string) config('app.env'),
        ];

        $ctx = [];
        foreach ([...$extra, ...$context] as $key => $value) {
            if (in_array($key, self::DROPPED, true)) {
                continue;
            }
            if (isset(self::PROMOTED[$key]) && (is_scalar($value) || $value === null)) {
                if ($value !== null && $value !== '') {
                    $line[self::PROMOTED[$key]] = is_string($value) ? mb_substr($value, 0, 200) : $value;
                }

                continue;
            }
            $ctx[$key] = $value;
        }

        // A dotted lower-case message IS the event code (the codebase's
        // convention: Log::info('platform.outbox_dispatch.completed')).
        if (! isset($line['event_code']) && preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/', $message) === 1) {
            $line['event_code'] = $message;
        } elseif ($message !== '' && $message !== ($line['event_code'] ?? null)) {
            $line['message'] = mb_substr($message, 0, 1000);
        }

        if ($ctx !== []) {
            $line['ctx'] = $this->normalize($ctx);
        }

        $ordered = [];
        foreach (self::FIELDS as $field) {
            if (array_key_exists($field, $line)) {
                $ordered[$field] = $line[$field];
            }
        }

        return $this->toJson($ordered, true)."\n";
    }
}
