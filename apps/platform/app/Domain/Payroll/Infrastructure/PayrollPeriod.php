<?php

namespace App\Domain\Payroll\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\PayrollPeriodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Phase 9.1 (ADR 0034 "Monthly period model") -- `period_month` is the
 * true identity (normalized to the first of the month);
 * `starts_on`/`ends_on` are derived and CHECK-validated against it.
 * Monthly only -- no configurable-frequency calendar engine.
 *
 * @property string $id
 * @property string $school_id
 * @property Carbon $period_month
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property Carbon|null $payment_date
 * @property string $status draft|open|closed
 */
class PayrollPeriod extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $fillable = [
        'school_id',
        'period_month',
        'starts_on',
        'ends_on',
        'payment_date',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'period_month' => 'date',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'payment_date' => 'date',
        ];
    }

    protected static function newFactory(): PayrollPeriodFactory
    {
        return PayrollPeriodFactory::new();
    }

    /** @return HasMany<PayrollRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(PayrollRun::class);
    }
}
