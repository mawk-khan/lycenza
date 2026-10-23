<?php

namespace App\Domain\Inventory\Application;

/**
 * ORG.9C-2 (ADR 0076 §70): the closed, code-owned reasons a stock write-off
 * may record -- physical loss of usable stock only. Mirrored by the
 * database CHECK `stock_movements_write_off_reason_check`.
 *
 * Deliberately absent:
 * - a count or data correction: Lycenza has no stocktake model, and a
 *   counting or entry mistake is not damage (it is a separate gap);
 * - "other" with free text: movements are tenant-lifetime data without
 *   personal data (E21.2G O5), so no remark is stored;
 * - any tenant-configurable reason.
 */
final class WriteOffReason
{
    public const CODES = ['damaged', 'expired', 'lost'];

    public const LABELS = [
        'damaged' => 'Damaged beyond use',
        'expired' => 'Expired or spoiled',
        'lost' => 'Lost or missing',
    ];
}
