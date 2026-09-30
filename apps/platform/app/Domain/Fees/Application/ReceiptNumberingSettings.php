<?php

namespace App\Domain\Fees\Application;

/**
 * FEE.4 (ADR 0062 §17.2, owner decision I): a School's effective receipt
 * numbering settings from the `fee_settings` singleton -- the prefix
 * (default RCPT) and the financial-year start month (default 4 = April).
 * The sequence padding is fixed (six digits) and is not a setting.
 */
final class ReceiptNumberingSettings
{
    public const DEFAULT_PREFIX = 'RCPT';

    public const DEFAULT_START_MONTH = 4;

    /** Mirrors fee_settings_receipt_prefix_format_check; '/' is the number separator. */
    public const PREFIX_PATTERN = '/^[A-Z0-9][A-Z0-9-]{0,15}$/';

    public function __construct(
        public readonly string $prefix,
        public readonly int $financialYearStartMonth,
    ) {}
}
