<?php

namespace App\Domain\Communications\Infrastructure;

use App\Models\User;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\CommunicationThreadParticipantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @use HasFactory<CommunicationThreadParticipantFactory>
 *
 * @property string $id
 * @property string $school_id
 * @property string $thread_id
 * @property string $user_id
 * @property Carbon $joined_at
 * @property Carbon|null $left_at
 * @property Carbon|null $last_read_at
 * @property bool $muted
 * @property bool $archived
 */
class CommunicationThreadParticipant extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $fillable = [
        'school_id', 'thread_id', 'user_id', 'joined_at', 'left_at',
        'last_read_at', 'muted', 'archived',
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
            'last_read_at' => 'datetime',
            'muted' => 'boolean',
            'archived' => 'boolean',
        ];
    }

    protected static function newFactory(): CommunicationThreadParticipantFactory
    {
        return CommunicationThreadParticipantFactory::new();
    }

    /** @return BelongsTo<CommunicationThread, $this> */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(CommunicationThread::class, 'thread_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->left_at === null;
    }
}
