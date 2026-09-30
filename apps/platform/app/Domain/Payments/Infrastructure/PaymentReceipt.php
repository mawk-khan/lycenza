<?php

namespace App\Domain\Payments\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * FEE.4 (ADR 0062 §17; owner decisions I, I2): evidence that one Payment
 * settled -- never a second ledger. It stores no amount and no Student
 * snapshot (they are read from the Payment and its allocations) and is
 * immutable forever (runtime UPDATE/DELETE revoked; a trigger refuses any
 * change). `App\Domain\Payments\Application\ReceiptIssuer` is the only
 * writer. There is no void, status or tax column (J: DEVELOPMENT
 * AUTHORISED — PROD LEGAL SIGN-OFF REQUIRED).
 *
 * @property string $id
 * @property string $school_id
 * @property string $payment_id
 * @property string $receipt_number
 * @property string $series_key
 * @property int $sequence_value
 * @property Carbon $issued_at
 * @property string|null $issued_by_user_id
 */
class PaymentReceipt extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public $timestamps = false;

    protected $table = 'payment_receipts';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'sequence_value' => 'integer',
        ];
    }
}
