<?php

namespace App\Domain\WorkforceTasks\Application;

/**
 * HR.13 (owner decision 2026-11-04, ADR 0071 §32): the staff-task keys. All
 * whole-School, never Campus-adopted. `team` is ownership-scoped on top: it
 * acts only for the holder's CURRENT direct reports (HR.3A ReportingLine).
 */
final class TaskCapabilities
{
    /** Read the Staff Task Monitor and any task's detail. */
    public const string VIEW = 'hr.tasks.view';

    /** Categories, templates and versions, and running generation. */
    public const string CONFIGURE = 'hr.tasks.configure';

    /** Assign to any current employment, review, cancel and reassign any task. */
    public const string MANAGE = 'hr.tasks.manage';

    /** Assign to, review, cancel and reassign tasks of one's CURRENT direct reports only. */
    public const string TEAM = 'hr.tasks.team';

    /** See and submit one's own tasks (the acting employee's current employment). */
    public const string SELF = 'hr.tasks.self';
}
