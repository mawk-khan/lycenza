<?php

namespace App\Domain\Communications\Infrastructure;

use App\Domain\Guardians\Infrastructure\Guardian;
use App\Models\User;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\CommunicationRecipientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @use HasFactory<CommunicationRecipientFactory>
 *
 * @property string $id
 * @property string $school_id
 * @property string $message_id
 * @property string|null $recipient_user_id
 * @property string|null $recipient_guardian_id Phase 5B.1 -- exactly one of recipient_user_id/recipient_guardian_id is set (database-enforced).
 */
class CommunicationRecipient extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $fillable = ['school_id', 'message_id', 'recipient_user_id', 'recipient_guardian_id'];

    protected static function newFactory(): CommunicationRecipientFactory
    {
        return CommunicationRecipientFactory::new();
    }

    /** @return BelongsTo<CommunicationMessage, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(CommunicationMessage::class, 'message_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recipientUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    /** @return BelongsTo<Guardian, $this> */
    public function recipientGuardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class, 'recipient_guardian_id');
    }

    /** @return HasMany<CommunicationDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(CommunicationDelivery::class, 'recipient_id');
    }
}
