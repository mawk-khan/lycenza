<?php

namespace App\Domain\Students\Application\Movements;

/**
 * SIS.EXP-2: the closed vocabulary of the Student movement register -- only
 * movements the canonical history can actually distinguish. Each value names
 * its ONE canonical source (StudentMovementRegister):
 *
 * | Type                | Category | Canonical source                                                                 | Effective date            |
 * |---------------------|----------|----------------------------------------------------------------------------------|---------------------------|
 * | INITIAL_ENROLLMENT  | entry    | a placement that is the Student's first and no transfer or rollover created      | placement `starts_on`     |
 * | RE_ENROLLMENT       | entry    | a LATER placement that no transfer or rollover created (e.g. a return)          | placement `starts_on`     |
 * | REACTIVATION        | entry    | `student_lifecycle_events` action `student_reactivated`                         | event date (School-local) |
 * | SECTION_CHANGE      | movement | a `transferred` placement and the placement that starts the next day, same year and Campus | the new `starts_on` |
 * | CAMPUS_TRANSFER     | movement | the same pair, with a different Campus                                          | the new `starts_on`       |
 * | PROMOTION           | movement | an executed rollover item, decision `promote` (source → target placement)       | target `starts_on`        |
 * | RETENTION           | movement | an executed rollover item, decision `repeat`                                    | target `starts_on`        |
 * | WITHDRAWAL          | exit     | a `withdrawn` placement (+ its `enrollment_withdrawn` reason)                    | placement `ends_on`       |
 * | PLACEMENT_COMPLETED | exit     | a `completed` placement that no executed rollover continued                     | placement `ends_on`       |
 * | DEACTIVATION        | exit     | `student_lifecycle_events` action `student_deactivated`                         | event date (School-local) |
 *
 * Rejected, because the history cannot tell them apart honestly:
 * - GRADE_CHANGE: a mid-year Grade change is refused (IntraYearGradeChangeException); Grades change only by rollover.
 * - GRADUATION: there is no graduation state; leaving with `completed_studies` is a DEACTIVATION with that reason.
 * - Cancelled placements (`did_not_join`, `duplicate_placement`, `entered_in_error`) are corrections, not movements: never listed.
 */
enum StudentMovementType: string
{
    case INITIAL_ENROLLMENT = 'initial_enrollment';
    case RE_ENROLLMENT = 're_enrollment';
    case REACTIVATION = 'student_reactivated';
    case SECTION_CHANGE = 'section_change';
    case CAMPUS_TRANSFER = 'campus_transfer';
    case PROMOTION = 'promotion';
    case RETENTION = 'retention';
    case WITHDRAWAL = 'withdrawal';
    case PLACEMENT_COMPLETED = 'placement_completed';
    case DEACTIVATION = 'student_deactivated';

    public const string ENTRY = 'entry';

    public const string MOVEMENT = 'movement';

    public const string EXIT = 'exit';

    public const array CATEGORIES = [self::ENTRY, self::MOVEMENT, self::EXIT];

    public function category(): string
    {
        return match ($this) {
            self::INITIAL_ENROLLMENT, self::RE_ENROLLMENT, self::REACTIVATION => self::ENTRY,
            self::SECTION_CHANGE, self::CAMPUS_TRANSFER, self::PROMOTION, self::RETENTION => self::MOVEMENT,
            self::WITHDRAWAL, self::PLACEMENT_COMPLETED, self::DEACTIVATION => self::EXIT,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::INITIAL_ENROLLMENT => 'First enrollment',
            self::RE_ENROLLMENT => 'Re-enrollment',
            self::REACTIVATION => 'Marked active again',
            self::SECTION_CHANGE => 'Section change',
            self::CAMPUS_TRANSFER => 'Campus transfer',
            self::PROMOTION => 'Promotion',
            self::RETENTION => 'Repeated the Grade',
            self::WITHDRAWAL => 'Withdrawn',
            self::PLACEMENT_COMPLETED => 'Placement completed',
            self::DEACTIVATION => 'Marked inactive',
        };
    }

    /** Student lifecycle rows: shown only to a whole-School `students.view` holder. */
    public function isStudentLifecycle(): bool
    {
        return $this === self::REACTIVATION || $this === self::DEACTIVATION;
    }

    /** @return list<string> */
    public static function valuesIn(string $category): array
    {
        return array_values(array_map(fn (self $t) => $t->value, array_filter(self::cases(), fn (self $t) => $t->category() === $category)));
    }
}
