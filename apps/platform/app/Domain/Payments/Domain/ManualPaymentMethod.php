<?php

namespace App\Domain\Payments\Domain;

/**
 * Phase 0O.11A (ADR 0031 implementation amendment section 4): the CLOSED
 * v1 catalog of offline payment methods a School user may record, mirrored
 * by `payments_method_check`. Lycenza records that the money was received
 * this way; it never moves money. No card, wallet, UPI-provider, gateway
 * or processor method exists, and no free-form method is accepted --
 * adding a member needs an ADR 0031 amendment and a migration.
 */
enum ManualPaymentMethod: string
{
    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case Cheque = 'cheque';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::BankTransfer => 'Bank transfer',
            self::Cheque => 'Cheque',
        };
    }
}
