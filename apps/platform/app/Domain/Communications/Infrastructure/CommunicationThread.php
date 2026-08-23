<?php

namespace App\Domain\Communications\Infrastructure;

use App\Models\Campus;
use App\Models\User;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\CommunicationThreadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @use HasFactory<CommunicationThreadFactory>
 *
 * @property string $id
 * @property string $school_id
 * @property string|null $campus_id
 * @property string $thread_type
 * @property string|null $subject
 * @property string $status
 * @property string $created_by_user_id
 * @property Carbon|null $last_activity_at
 */
class CommunicationThread extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    /**
     * Phase 5A.8 §46: microsecond precision (paired with this
     * checkpoint's migration widening `last_activity_at`/`created_at`/
     * `updated_at` to `timestamp(6)`) -- see
     * CommunicationInboxReadModel's docblock and
     * CommunicationMessage::$dateFormat (Phase 5A.7) for why.
     */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'school_id', 'campus_id', 'thread_type', 'subject', 'status',
        'created_by_user_id', 'last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'last_activity_at' => 'datetime',
        ];
    }

    protected static function newFactory(): CommunicationThreadFactory
    {
        return CommunicationThreadFactory::new();
    }

    /** @return BelongsTo<Campus, $this> */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return HasMany<CommunicationThreadParticipant, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(CommunicationThreadParticipant::class, 'thread_id');
    }

    /** @return HasMany<CommunicationMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(CommunicationMessage::class, 'thread_id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
