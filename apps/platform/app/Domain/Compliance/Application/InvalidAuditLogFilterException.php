<?php

namespace App\Domain\Compliance\Application;

use InvalidArgumentException;

/**
 * ORG.9B (ADR 0076 §68): an audit-log filter named an event type or subject
 * label the School's own log does not contain. Refused rather than guessed,
 * so a filter can never be used to probe for other values.
 */
final class InvalidAuditLogFilterException extends InvalidArgumentException
{
    public function __construct(public readonly string $field)
    {
        parent::__construct("Unknown audit-log {$field} filter.");
    }
}
