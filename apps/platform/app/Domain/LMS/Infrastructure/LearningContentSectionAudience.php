<?php

namespace App\Domain\LMS\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TCH.5B (ADR 0063 section 35) -- one Section in the audience of a
 * teacher-owned Learning Content row. Written only in the transaction that
 * creates the owned parent (database-enforced), never updated, never
 * deleted by the runtime role. The Offering context columns exist so the
 * composite FKs can pin the Section to the parent's Offering context.
 * Persistence only: it authorizes nothing.
 */
class LearningContentSectionAudience extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const UPDATED_AT = null;

    protected $table = 'learning_content_section_audiences';

    protected $fillable = [];

    public function learningContent(): BelongsTo
    {
        return $this->belongsTo(LearningContent::class, 'learning_content_id');
    }
}
