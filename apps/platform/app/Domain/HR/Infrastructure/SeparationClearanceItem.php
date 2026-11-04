<?php

namespace App\Domain\HR\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * HR.12: a case's snapshot of one applicable clearance requirement, taken at
 * approval. pending -> cleared once, never by the subject, never waived
 * (trg_separation_clearance_items_guard).
 *
 * @property string $id
 * @property string $case_id
 * @property string $requirement_id
 * @property string $code
 * @property string $label
 * @property string $responsibility
 * @property bool $is_mandatory
 * @property int $sequence
 * @property string $status
 * @property string|null $cleared_by_user_id
 * @property Carbon|null $cleared_at
 */
class SeparationClearanceItem extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $table = 'separation_clearance_items';

    protected $fillable = ['case_id', 'requirement_id', 'code', 'label', 'responsibility', 'is_mandatory', 'sequence'];

    protected function casts(): array
    {
        return [
            'is_mandatory' => 'boolean',
            'sequence' => 'integer',
            'cleared_at' => 'datetime',
        ];
    }
}
