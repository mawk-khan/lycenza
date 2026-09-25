<?php

namespace App\Support\Configuration;

use RuntimeException;

/**
 * Phase 0O.1: production refused to boot. The message lists violation
 * codes only -- never a configuration value, key or secret.
 */
class ProductionConfigurationException extends RuntimeException
{
    /**
     * @param  list<string>  $violations
     */
    public function __construct(public readonly array $violations)
    {
        parent::__construct('Refusing to start: unsafe production configuration ('.implode(', ', $violations).'). '
            .'See docs/architecture/PRODUCTION-RELEASE.md.');
    }
}
