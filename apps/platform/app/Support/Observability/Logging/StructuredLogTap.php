<?php

namespace App\Support\Observability\Logging;

use Illuminate\Log\Logger;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Logger as Monolog;

/**
 * Phase 0O.5A (ADR 0051 §5-§6): the logging `tap` on every application
 * channel (config/logging.php). Installs the central SafeLogProcessor and,
 * when `observability.logging.format` is `json` (production default),
 * the StructuredJsonFormatter on every handler. Call sites never format
 * or sanitize for themselves.
 */
final class StructuredLogTap
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if (! $monolog instanceof Monolog) {
            return;
        }

        $monolog->pushProcessor(app(SafeLogProcessor::class));

        if (config('observability.logging.format') !== 'json') {
            return;
        }

        foreach ($monolog->getHandlers() as $handler) {
            if ($handler instanceof FormattableHandlerInterface) {
                $handler->setFormatter(app(StructuredJsonFormatter::class));
            }
        }
    }
}
