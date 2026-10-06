<?php

namespace App\Domain\Examinations\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * RES.3 (ADR 0068 §7.1, §21): one paper's marks state, `open` -> `locked`,
 * one-way (no unlock; database-enforced). Separate from the paper's own
 * `active` / `inactive` status. Written only by StudentMarkLockService.
 *
 * @property string $id
 * @property string $examination_paper_id
 * @property string $state
 * @property string|null $locked_by_user_id
 */
class ExaminationPaperMarkState extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const STATE_OPEN = 'open';

    public const STATE_LOCKED = 'locked';

    protected $table = 'examination_paper_mark_states';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['locked_at' => 'immutable_datetime'];
    }
}
