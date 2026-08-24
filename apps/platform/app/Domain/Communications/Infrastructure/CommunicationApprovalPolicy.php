<?php

namespace App\Domain\Communications\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\CommunicationApprovalPolicyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 5A.12 §7/§8 -- a School's explicit approval-REQUIREMENT policy.
 * No row (or every flag false) means "approval not required for
 * anything," identical in shape to
 * App\Domain\Communications\Infrastructure\CommunicationChannelPolicy's
 * own "no row means the safe default" docblock.
 *
 * @use HasFactory<CommunicationApprovalPolicyFactory>
 *
 * @property string $id
 * @property string $school_id
 * @property bool $require_school_wide_approval
 * @property bool $require_required_communication_approval
 * @property bool $require_non_privileged_sender_approval
 */
class CommunicationApprovalPolicy extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $fillable = [
        'school_id', 'require_school_wide_approval',
        'require_required_communication_approval', 'require_non_privileged_sender_approval',
    ];

    protected function casts(): array
    {
        return [
            'require_school_wide_approval' => 'boolean',
            'require_required_communication_approval' => 'boolean',
            'require_non_privileged_sender_approval' => 'boolean',
        ];
    }

    protected static function newFactory(): CommunicationApprovalPolicyFactory
    {
        return CommunicationApprovalPolicyFactory::new();
    }
}
