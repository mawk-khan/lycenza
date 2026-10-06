<?php

namespace App\Domain\Admissions\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * OPF.3 (ADR 0067 §16, D7): which FEE fee head is the School's Admission
 * fee. One per School. Configuration only -- it never holds an amount; FEE's
 * structure instalments own every Admission fee amount (and already vary by
 * academic year, grade and campus). Written only by
 * AdmissionFeeSelectionService.
 *
 * @property string $id
 * @property string $school_id
 * @property string $fee_head_id
 */
class AdmissionFeeHead extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $table = 'admission_fee_heads';

    protected $fillable = ['school_id', 'fee_head_id'];
}
