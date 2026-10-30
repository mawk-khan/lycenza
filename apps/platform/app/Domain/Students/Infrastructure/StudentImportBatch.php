<?php

namespace App\Domain\Students\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * SIS.EXP-5: one committed bulk Student & Guardian import -- counts, the
 * content digest, the actor and the time; never a row or any personal data.
 * Append-only for the runtime role; nothing is mass assignable.
 *
 * @property string $id
 * @property string $school_id
 * @property string $format_version
 * @property string $content_digest
 * @property int $row_count
 * @property int $students_created
 * @property int $guardians_created
 * @property int $relationships_created
 * @property int $placements_created
 * @property int $campus_count
 * @property string $committed_by_user_id
 * @property Carbon $committed_at
 */
class StudentImportBatch extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public $timestamps = false;

    protected $table = 'student_import_batches';

    protected $fillable = [];

    protected function casts(): array
    {
        return ['committed_at' => 'datetime'];
    }
}
