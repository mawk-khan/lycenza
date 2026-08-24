<?php

namespace App\Domain\Communications\Infrastructure;

use App\Domain\Communications\Domain\CommunicationPriority;
use App\Models\School;
use App\Models\User;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\CommunicationTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 5A.4 §4 -- reusable, School-owned source content. See the
 * creating migration's docblock for why no version table exists.
 *
 * @use HasFactory<CommunicationTemplateFactory>
 *
 * @property string $id
 * @property string $school_id
 * @property string $created_by_user_id
 * @property string $name
 * @property string|null $description
 * @property string $template_type
 * @property string|null $subject
 * @property string $body
 * @property string|null $priority
 * @property string $status
 */
class CommunicationTemplate extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $fillable = [
        'school_id', 'created_by_user_id', 'name', 'description',
        'template_type', 'subject', 'body', 'priority', 'status',
    ];

    protected static function newFactory(): CommunicationTemplateFactory
    {
        return CommunicationTemplateFactory::new();
    }

    /** @return BelongsTo<School, $this> */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function priorityEnum(): ?CommunicationPriority
    {
        return $this->priority === null ? null : CommunicationPriority::from($this->priority);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
