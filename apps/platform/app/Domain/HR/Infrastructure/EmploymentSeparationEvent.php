<?php

namespace App\Domain\HR\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * HR.12: one append-only entry of a separation case's timeline (the runtime
 * role holds no UPDATE or DELETE). A planned-day change keeps its before and
 * after here; the audit ledger names fields only.
 *
 * @property string $id
 * @property string $case_id
 * @property string $kind
 * @property Carbon|null $planned_last_day_before
 * @property Carbon|null $planned_last_day_after
 * @property string|null $clearance_item_id
 * @property string $actor_user_id
 * @property Carbon $occurred_at
 */
class EmploymentSeparationEvent extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public $timestamps = false;

    protected $table = 'employment_separation_events';

    protected $fillable = ['case_id', 'kind', 'planned_last_day_before', 'planned_last_day_after', 'clearance_item_id', 'actor_user_id', 'occurred_at'];

    protected function casts(): array
    {
        return [
            'planned_last_day_before' => 'date',
            'planned_last_day_after' => 'date',
            'occurred_at' => 'datetime',
        ];
    }
}
