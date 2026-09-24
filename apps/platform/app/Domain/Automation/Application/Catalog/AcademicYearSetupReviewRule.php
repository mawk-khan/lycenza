<?php

namespace App\Domain\Automation\Application\Catalog;

use App\Models\DomainEventOutbox;
use Illuminate\Support\Str;

/**
 * Phase 0L.6 -- the first and only v1 rule type (owner decision,
 * 2026-09-24): when an Academic Year becomes active, create an
 * informational set-up review item pointing at that year. Tier 0: it
 * changes nothing in Academic Structure, Timetable, Attendance,
 * Curriculum or anywhere else, and sends nothing.
 *
 * Trigger: the existing `academic_year.activated.v1` event
 * (App\Domain\AcademicStructure\Events\AcademicYearActivated), whose
 * payload already carries the one identifier needed (`academicYearId`).
 */
final class AcademicYearSetupReviewRule implements AutomationRuleType
{
    public const KEY = 'academic_year.setup_review';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Academic year set-up review';
    }

    public function description(): string
    {
        return 'When an academic year becomes active, add an item to this page reminding staff to review that year\'s set-up. Nothing is changed or sent.';
    }

    public function triggerEventType(): string
    {
        return 'academic_year.activated.v1';
    }

    public function tier(): int
    {
        return 0;
    }

    public function requiredCapabilities(): array
    {
        return ['automation.manage', 'academics.years.view'];
    }

    public function maxExecutionsPerDay(): int
    {
        return 20;
    }

    public function reviewItemType(): string
    {
        return 'academic_year_setup_review';
    }

    public function subjectFor(DomainEventOutbox $event): ?array
    {
        $id = $event->payload['academicYearId'] ?? null;

        return is_string($id) && Str::isUuid($id) ? ['type' => 'academic_year', 'id' => $id] : null;
    }
}
