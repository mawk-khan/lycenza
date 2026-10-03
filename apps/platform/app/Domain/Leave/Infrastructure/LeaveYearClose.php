<?php

namespace App\Domain\Leave\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * HRX.2 (ADR 0065 §23.8): the one close of a School's leave year (header,
 * append-only). Its per-employment results are LeaveYearCloseItem rows.
 *
 * @property string $id
 * @property string $school_id
 */
class LeaveYearClose extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public $timestamps = false;

    protected $table = 'leave_year_closes';

    protected $fillable = ['school_id', 'leave_year_id', 'next_leave_year_id', 'item_count', 'executed_by_user_id', 'executed_at'];

    protected function casts(): array
    {
        return ['item_count' => 'integer', 'executed_at' => 'datetime'];
    }
}
