<?php

namespace App\Domain\Communications\Infrastructure;

use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Students\Infrastructure\Student;
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
 * Phase 5B.1 widened this for Guardian; Phase 5B.2 widens it again for
 * Student (an unlinked/inactively-linked Student's IN_APP
 * `recipient_ineligible` suppression needs a party to record itself
 * against) -- exactly one of `recipient_user_id`/`recipient_guardian_id`/
 * `recipient_student_id` is set per row (database-enforced).
 *
 * @use HasFactory<CommunicationDeliveryPolicyDecisionFactory>
 *
 * @property string $id
 * @property string $school_id
 * @property string $message_id
 * @property string|null $recipient_user_id
 * @property string|null $recipient_guardian_id
 * @property string|null $recipient_student_id
 * @property string $channel
 * @property string $reason
 */
class CommunicationDeliveryPolicyDecision extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $fillable = ['school_id', 'message_id', 'recipient_user_id', 'recipient_guardian_id', 'recipient_student_id', 'channel', 'reason'];

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

    /** @return BelongsTo<Guardian, $this> */
    public function recipientGuardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class, 'recipient_guardian_id');
    }

    /** @return BelongsTo<Student, $this> */
    public function recipientStudent(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'recipient_student_id');
    }
}
