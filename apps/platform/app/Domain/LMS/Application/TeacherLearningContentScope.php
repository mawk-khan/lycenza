<?php

namespace App\Domain\LMS\Application;

/**
 * TCH.5C (ADR 0063 sections 34.7, 36) -- TeacherLmsScope for Learning
 * Content: what one teacher may see today.
 */
final readonly class TeacherLearningContentScope extends TeacherLmsScope
{
    protected function table(): string
    {
        return 'learning_content';
    }

    protected function bridge(): string
    {
        return 'learning_content_section_audiences';
    }

    protected function parentColumn(): string
    {
        return 'learning_content_id';
    }
}
