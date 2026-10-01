<?php

namespace App\Support\Retention\Erasure\Subjects;

use App\Models\School;
use App\Support\Retention\Erasure\ErasureCategory;
use App\Support\Retention\Erasure\ErasureSubjectAdapter;
use App\Support\Retention\RetentionHolds;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * E21.2F (E21-D10): a reviewed erasure case for one Guardian. NOTHING is
 * executable yet. E21.2G adopted the period for Guardian personal data (the
 * Guardian, contacts and Documents): 1 year after the Guardian has had no
 * relationship and no retained dependent. Its mechanism, a durable
 * no-relationship marker, ships in E21.3C, so the data is retained until
 * then (`retained_until`, `mechanism_pending`). A Guardian's
 * relationships are governed by each Student's D7 retention, not by the
 * Guardian's case.
 */
final class GuardianErasureAdapter implements ErasureSubjectAdapter
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly RetentionHolds $holds,
    ) {}

    public function subjectType(): string
    {
        return 'guardian';
    }

    public function exists(?School $school, string $subjectId): bool
    {
        return $school !== null && $this->context->withSchool($school, fn (): bool => DB::table('guardians')->where('id', $subjectId)->exists());
    }

    public function plan(?School $school, string $subjectId): array
    {
        if ($school === null || ! $this->exists($school, $subjectId)) {
            return [new ErasureCategory('guardian_record', ErasureCategory::COMPLETED, 'subject_absent')];
        }

        if ($this->holds->isHeld($school->id)) {
            return array_map(fn (string $c) => new ErasureCategory($c, ErasureCategory::LEGAL_HOLD, 'school_hold'), ['guardian_personal_data', 'guardian_relationships']);
        }

        return [
            new ErasureCategory('guardian_personal_data', ErasureCategory::RETAINED_UNTIL, 'mechanism_pending'),
            new ErasureCategory('guardian_relationships', ErasureCategory::OUTSIDE_SCOPE, 'governed_by_student_retention'),
        ];
    }

    public function execute(?School $school, string $subjectId): array
    {
        return $this->plan($school, $subjectId);
    }
}
