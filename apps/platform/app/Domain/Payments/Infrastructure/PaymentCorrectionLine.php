<?php

namespace App\Domain\Payments\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * UAT-C3: one movement of a corrected Payment's money -- `release` takes it
 * off a charge, `apply` puts it on one. A charge's effective allocation is
 * its allocations plus applied minus released. Append-only.
 *
 * @property string $id
 * @property string $school_id
 * @property string $payment_correction_id
 * @property string $payment_id
 * @property string $charge_id
 * @property string $direction
 * @property string $amount
 * @property string $currency
 */
class PaymentCorrectionLine extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const string RELEASE = 'release';

    public const string APPLY = 'apply';

    protected $table = 'payment_correction_lines';

    protected $fillable = ['school_id', 'payment_correction_id', 'payment_id', 'charge_id', 'direction', 'amount', 'currency'];

    public function correction(): BelongsTo
    {
        return $this->belongsTo(PaymentCorrection::class, 'payment_correction_id');
    }
}
