<?php

namespace App\Models;

use App\Support\Identifiers\GeneratesUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Phase 0O.9A (ADR 0055 section 12.3): one suppression of one keyed
 * address fingerprint -- platform data, never readable by a School, never
 * an address. History is kept: a release sets `released_at` (the only
 * permitted update) and the runtime role cannot DELETE.
 *
 * @property string $id
 * @property string $key_id
 * @property string $address_fingerprint
 * @property string $scope
 * @property string $reason
 * @property string|null $source_event_id
 * @property string|null $source_email_message_id
 * @property Carbon $created_at
 * @property Carbon|null $released_at
 * @property string|null $released_by_user_id
 * @property string|null $release_reason
 */
class EmailSuppression extends Model
{
    use GeneratesUuidV7;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('released_at');
    }
}
