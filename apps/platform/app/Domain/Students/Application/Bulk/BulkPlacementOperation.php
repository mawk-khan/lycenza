<?php

namespace App\Domain\Students\Application\Bulk;

/**
 * SIS 11.13: the two bulk placement moves -- and nothing else. Each is the
 * existing single-Student move, repeated for a reviewed selection:
 *
 * - SectionChange: another Section of the SAME year, Campus and Grade
 *   (StudentEnrollmentService::transferPlacement(); whole-School only, as a
 *   same-Campus Section change has always been, ADR 0076 §47).
 * - CampusTransfer: a Section of the same year and Grade on ANOTHER Campus
 *   (the whole-School transferPlacement() with its blockers, or the
 *   Campus-scoped CampusStudentTransferService).
 *
 * Another year is Rollover; another Grade is promotion. Never a generic
 * "run an action on selected Students" catalogue.
 */
enum BulkPlacementOperation: string
{
    case SectionChange = 'section_change';
    case CampusTransfer = 'campus_transfer';

    public function label(): string
    {
        return match ($this) {
            self::SectionChange => 'Change Section',
            self::CampusTransfer => 'Transfer Campus',
        };
    }
}
