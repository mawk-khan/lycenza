<?php

namespace App\Domain\LMS\Application;

/**
 * TCH.5D (ADR 0063 sections 34.7, 37) -- TeacherLmsScope for Assignments:
 * what one teacher may see today. `published` is the only shared status;
 * `draft` and `closed` rows are their owner's alone.
 */
final readonly class TeacherAssignmentScope extends TeacherLmsScope
{
    protected function table(): string
    {
        return 'assignments';
    }

    protected function bridge(): string
    {
        return 'assignment_section_audiences';
    }

    protected function parentColumn(): string
    {
        return 'assignment_id';
    }
}
