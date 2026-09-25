<?php

namespace App\Support\Ai;

use RuntimeException;

/**
 * Phase 0O.1: the AI context signing key is missing or blank. Nothing is
 * minted or verified with empty key material -- ever, in any environment
 * (ADR 0023, ADR 0016). The message names the setting, never a value.
 */
class AiContextSigningKeyNotConfiguredException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The AI context signing key (AI_GATEWAY_CONTEXT_SIGNING_KEY) is not configured.');
    }
}
