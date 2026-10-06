<?php

namespace App\Domain\Admissions\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * OPF.3 (ADR 0067 §16): insert-only provenance of the FEE optional selection
 * a converted application recorded Admission-fee intent for -- one per
 * application, naming the converted Student (never the applicant). Database-
 * checked against the application's conversion and the selection's
 * Student, year and fee head; never updated or deleted by the runtime role.
 * Written only by AdmissionFeeSelectionService.
 *
 * @property string $id
 * @property string $school_id
 * @property string $admission_application_id
 * @property string $student_id
 * @property string $academic_year_id
 * @property string $fee_head_id
 * @property string $fee_optional_selection_id
 * @property string $selection_outcome
 * @property Carbon|null $created_at
 */
class AdmissionFeeSelection extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const UPDATED_AT = null;

    protected $table = 'admission_fee_selections';

    protected $fillable = [
        'school_id', 'admission_application_id', 'student_id', 'academic_year_id', 'fee_head_id',
        'fee_optional_selection_id', 'selection_outcome',
    ];

    /** @return BelongsTo<AdmissionApplication, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(AdmissionApplication::class, 'admission_application_id');
    }
}
