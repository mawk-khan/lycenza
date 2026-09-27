<?php

namespace App\Domain\Platform\Application\Domains;

use RuntimeException;

/**
 * A refused domain-management action, with a closed code and a School-safe
 * message. "Not available" never says which School holds a hostname.
 */
final class SchoolDomainException extends RuntimeException
{
    public const MESSAGES = [
        'disabled' => 'Custom domains are not enabled for this deployment.',
        'invalid_hostname' => 'Enter a domain name you own, such as erp.yourschool.org.',
        'unavailable' => 'That domain is not available.',
        'limit' => 'This School already has the maximum number of domains (3).',
        'not_found' => 'That domain was not found.',
        'invalid_state' => 'That action is not possible for this domain now.',
        'replacement_required' => 'Choose another active domain to become primary before removing this one.',
        'rate_limited' => 'Too many checks. Wait a minute and try again.',
        'school_not_active' => 'This School is not active.',
    ];

    public function __construct(public readonly string $refusal, public readonly ?string $reason = null)
    {
        parent::__construct(self::MESSAGES[$refusal] ?? 'That action is not possible.');
    }
}
