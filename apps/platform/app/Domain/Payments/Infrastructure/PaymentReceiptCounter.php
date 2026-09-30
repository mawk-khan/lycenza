<?php

namespace App\Domain\Payments\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * FEE.4 (ADR 0062 §17.2): the NEXT sequence value of one School x
 * financial-year receipt series, with the prefix snapshotted when the
 * series started. Advanced only by `ReceiptIssuer`, by exactly one, in the
 * transaction that inserts the receipt (database-enforced).
 *
 * @property string $id
 * @property string $school_id
 * @property string $series_key
 * @property string $prefix
 * @property int $next_value
 */
class PaymentReceiptCounter extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $table = 'payment_receipt_counters';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'next_value' => 'integer',
        ];
    }
}
