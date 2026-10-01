<?php

namespace App\Models;

use App\Support\Identifiers\GeneratesUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * E21.2F (E21-D10): one reviewed data-subject erasure case, a platform
 * compliance record (migration 2026_11_08_090100). It holds codes, dates,
 * a subject reference and a per-category outcome summary, never the
 * subject's data. Written only by ErasureCaseService.
 *
 * @property string $id
 * @property string $scope school|platform
 * @property string|null $school_id
 * @property string $subject_type student|guardian|employee|user
 * @property string $subject_id
 * @property string $request_channel
 * @property string $status requested|approved|partially_approved|denied|executing|completed
 * @property Carbon $requested_at
 * @property Carbon|null $decided_at
 * @property string|null $decision_reason
 * @property Carbon|null $target_on
 * @property Carbon|null $execution_started_at
 * @property Carbon|null $completed_at
 * @property array<int, array<string, mixed>>|null $outcome
 */
class ErasureCase extends Model
{
    use GeneratesUuidV7;

    protected $table = 'erasure_cases';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
            'target_on' => 'date',
            'execution_started_at' => 'datetime',
            'completed_at' => 'datetime',
            'outcome' => 'array',
        ];
    }
}
