<?php

namespace App\Domain\Students\Infrastructure;

use App\Domain\Students\Application\Documents\StudentDocumentCategory;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * SIS 11.5: one School's rule that a closed document category is Required or
 * Optional for Students (or Applicants) of one academic year -- optionally
 * one Campus and/or Grade -- from `effective_from`. Terms are immutable
 * (`student_document_requirements_guard`); a change is retire + a new rule.
 *
 * @property string $id
 * @property string $subject
 * @property StudentDocumentCategory $category
 * @property bool $required
 * @property string $academic_year_id
 * @property ?string $campus_id
 * @property ?string $grade_level_id
 * @property Carbon $effective_from
 * @property ?Carbon $retired_at
 */
class StudentDocumentRequirement extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const string STUDENT = 'student';

    public const string APPLICANT = 'applicant';

    protected $table = 'student_document_requirements';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'category' => StudentDocumentCategory::class,
            'required' => 'boolean',
            'effective_from' => 'date',
            'retired_at' => 'datetime',
        ];
    }
}
