<?php

namespace App\Domain\Communications\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\CommunicationConversationPolicyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 5D.1 §16 -- an EXPLICIT School override of the system default
 * private-conversation safeguarding policy. See the creating
 * migration's docblock for why no row means "use the system default,"
 * not "policy undefined" -- same shape as CommunicationChannelPolicy.
 *
 * @use HasFactory<CommunicationConversationPolicyFactory>
 *
 * @property string $id
 * @property string $school_id
 * @property bool $allow_guardian_conversations
 * @property bool $allow_student_conversations
 */
class CommunicationConversationPolicy extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $fillable = ['school_id', 'allow_guardian_conversations', 'allow_student_conversations'];

    protected function casts(): array
    {
        return [
            'allow_guardian_conversations' => 'boolean',
            'allow_student_conversations' => 'boolean',
        ];
    }

    protected static function newFactory(): CommunicationConversationPolicyFactory
    {
        return CommunicationConversationPolicyFactory::new();
    }
}
