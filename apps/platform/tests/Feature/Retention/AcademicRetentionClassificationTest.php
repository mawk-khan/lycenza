<?php

namespace Tests\Feature\Retention;

use App\Support\Retention\ReferencingRows;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * E21.3D (E21.2G A1): every table that references a year-bound academic
 * row is classified here, read against the live FK catalog. The expiry
 * already fails closed on an unclassified table (any referencing row keeps
 * the row, RetentionBatch / LmsResourceRetention); this makes the decision
 * explicit and forces one when a new table appears.
 */
class AcademicRetentionClassificationTest extends TestCase
{
    /** parent => referencing table => how A1 treats it */
    public const CLASSIFICATION = [
        'curriculum_deliveries' => [],
        'attendance_sessions' => [
            'attendance_records' => 'D7 operational (E21.2D): each Student record keeps its header until it expires; the header goes only once empty',
        ],
        'timetable_entries' => [
            'attendance_sessions' => 'A1: a register header keeps its entry until the header itself expires',
        ],
        'learning_content' => [
            'documents' => 'D5 inherits the resource: purged with it (DocumentParentRetention)',
            'learning_content_section_audiences' => 'D6 embedded audience: removed only with the resource, by its floored function',
        ],
        'assignments' => [
            'documents' => 'D5 inherits the resource: purged with it (DocumentParentRetention)',
            'assignment_section_audiences' => 'D6 embedded audience: removed only with the resource, by its floored function',
        ],
    ];

    #[Test]
    public function every_reference_to_a_year_bound_academic_row_is_classified(): void
    {
        $references = app(ReferencingRows::class);

        foreach (array_keys(self::CLASSIFICATION) as $parent) {
            $live = array_values(array_unique(array_column($references->to($parent), 'table')));
            sort($live);
            $classified = array_keys(self::CLASSIFICATION[$parent]);
            sort($classified);

            $this->assertSame($classified, $live, "references to {$parent} changed: classify them for E21.2G A1");
        }
    }
}
