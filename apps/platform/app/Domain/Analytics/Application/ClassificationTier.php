<?php

namespace App\Domain\Analytics\Application;

/**
 * The docs/security/DATA-CLASSIFICATION.md tiers an Analytics read
 * model's OUTPUT can carry (ADR 0040 §6: an aggregate inherits the
 * strongest tier among its sources). Public/Internal are deliberately
 * absent -- every Analytics surface requires `analytics.view`, so none
 * is ever below Confidential.
 */
enum ClassificationTier: string
{
    case Confidential = 'confidential';
    case Sensitive = 'sensitive';
    case HighlySensitive = 'highly_sensitive';

    /**
     * DATA-CLASSIFICATION.md's handling baseline: access to Sensitive
     * and Highly Sensitive data is audited (ADR 0017); Confidential is
     * capability-gated but not access-audited.
     */
    public function requiresReadAudit(): bool
    {
        return $this !== self::Confidential;
    }
}
