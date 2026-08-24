<?php

namespace App\Domain\Communications\Infrastructure;

use App\Models\User;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\CommunicationDeliveryPolicyDecisionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 5A.5 §20/§21 -- the append-only record of a SUPPRESSED
 * requested channel. See the creating migration's docblock for why
 * only suppressions are stored here (an ALLOW already has its own
 * record: the resulting CommunicationDelivery row).
 *
 * @use HasFactory<CommunicationDeliveryPolicyDecisionFactory>
 *
 * @property string $id
 * @property string $school_id
 * @property string $message_id
 * @property string $recipient_user_id
 * @property string $channel
 * @property string $reason
 */
class CommunicationDeliveryPolicyDecision extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $fillable = ['school_id', 'message_id', 'recipient_user_id', 'channel', 'reason'];

    protected static function newFactory(): CommunicationDeliveryPolicyDecisionFactory
    {
        return CommunicationDeliveryPolicyDecisionFactory::new();
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
}
