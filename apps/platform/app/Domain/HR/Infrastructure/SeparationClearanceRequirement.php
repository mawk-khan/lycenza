<?php

namespace App\Domain\HR\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\NormalizesCode;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * HR.12: a School-configured administrative clearance requirement. Retired,
 * never edited or deleted (trg_separation_clearance_requirements_guard); a
 * case snapshots it at approval, so a later change never rewrites a case.
 *
 * @property string $id
 * @property string $code
 * @property string $label
 * @property string $responsibility
 * @property bool $is_mandatory
 * @property string|null $staff_type
 * @property int $sequence
 * @property string $status
 * @property string $created_by_user_id
 * @property string|null $retired_by_user_id
 * @property Carbon|null $retired_at
 */
class SeparationClearanceRequirement extends Model
{
    use BelongsToSchool, GeneratesUuidV7, NormalizesCode;

    protected $table = 'separation_clearance_requirements';

    protected $fillable = ['code', 'label', 'responsibility', 'is_mandatory', 'staff_type', 'sequence', 'created_by_user_id'];

    protected function casts(): array
    {
        return [
            'is_mandatory' => 'boolean',
            'sequence' => 'integer',
            'retired_at' => 'datetime',
        ];
    }
}
