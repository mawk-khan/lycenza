<?php

namespace App\Domain\Automation\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Tier 0 output: an informational, append-only pointer to a source record
 * (type + id only) that a rule found worth reviewing.
 *
 * @property string $id
 * @property string $school_id
 * @property string $rule_instance_id
 * @property string $execution_id
 * @property string $item_type
 * @property string $subject_type
 * @property string $subject_id
 * @property Carbon $created_at
 */
class AutomationReviewItem extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const UPDATED_AT = null;

    protected $fillable = ['school_id', 'rule_instance_id', 'execution_id', 'item_type', 'subject_type', 'subject_id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
