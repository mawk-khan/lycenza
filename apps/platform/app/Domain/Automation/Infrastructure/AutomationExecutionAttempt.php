<?php

namespace App\Domain\Automation\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Append-only record of one attempt (UPDATE/DELETE revoked from the
 * runtime role).
 *
 * @property string $id
 * @property string $school_id
 * @property string $execution_id
 * @property int $attempt_number
 * @property string $outcome
 * @property string|null $outcome_code
 * @property Carbon|null $started_at
 * @property Carbon $finished_at
 */
class AutomationExecutionAttempt extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const UPDATED_AT = null;

    protected $fillable = ['school_id', 'execution_id', 'attempt_number', 'outcome', 'outcome_code', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
