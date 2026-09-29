<?php

namespace App\Domain\Fees\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * FEE.1 (ADR 0062 §8, decision E): a Student's explicit choice of an
 * optional fee for one AcademicYear, keyed by fee head (the line is
 * provenance). `active -> withdrawn` only; never deleted.
 *
 * @property string $id
 * @property string $school_id
 * @property string $student_id
 * @property string $academic_year_id
 * @property string $fee_head_id
 * @property string $fee_structure_line_id
 * @property string $status
 * @property string|null $selected_by_user_id
 * @property Carbon|null $withdrawn_at
 * @property string|null $withdrawn_by_user_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class FeeOptionalSelection extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_WITHDRAWN = 'withdrawn';

    protected $table = 'fee_optional_selections';

    protected $fillable = [
        'school_id', 'student_id', 'academic_year_id', 'fee_head_id', 'fee_structure_line_id',
        'status', 'selected_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'withdrawn_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
