<?php

namespace App\Domain\Communications\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\CommunicationChannelPolicyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 5A.5 §15 -- an EXPLICIT School override of the system default
 * channel policy. See the creating migration's docblock for why no
 * row means "use the system default," not "policy undefined."
 *
 * @use HasFactory<CommunicationChannelPolicyFactory>
 *
 * @property string $id
 * @property string $school_id
 * @property string $channel
 * @property bool $optional_allowed
 * @property bool $required_allowed
 * @property bool $recipient_can_opt_out
 */
class CommunicationChannelPolicy extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $fillable = ['school_id', 'channel', 'optional_allowed', 'required_allowed', 'recipient_can_opt_out'];

    protected function casts(): array
    {
        return [
            'optional_allowed' => 'boolean',
            'required_allowed' => 'boolean',
            'recipient_can_opt_out' => 'boolean',
        ];
    }

    protected static function newFactory(): CommunicationChannelPolicyFactory
    {
        return CommunicationChannelPolicyFactory::new();
    }
}
