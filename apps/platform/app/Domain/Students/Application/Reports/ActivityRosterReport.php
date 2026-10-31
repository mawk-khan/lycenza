<?php

namespace App\Domain\Students\Application\Reports;

use App\Domain\Students\Application\Groups\CommunityAdministrationService;
use App\Domain\Students\Application\Groups\StudentGroupType;
use App\Support\StudentReports\StudentReportColumn as C;
use App\Support\StudentReports\StudentReportDefinition;

/**
 * SIS 11.8 (OD-SIS-5B): the Activity participant roster -- CURRENT
 * participants on the shared StudentGroupRosterReport query, under
 * `students.communities.view` (Campus-aware; no School-wide count leaks).
 * The category is the Activity's, never anything about the Student. No
 * level, schedule, score or result. Browser-only.
 */
final class ActivityRosterReport extends StudentGroupRosterReport
{
    public const array SORTS = [
        'activity' => 'g.display_order asc, g.name asc, s.last_name asc nulls last, s.first_name asc, m.id asc',
        'student' => 's.last_name asc nulls last, s.first_name asc, m.id asc',
        'since' => 'm.starts_on desc, m.id asc',
    ];

    protected function type(): StudentGroupType
    {
        return StudentGroupType::ACTIVITY;
    }

    protected function authority(): string
    {
        return CommunityAdministrationService::VIEW;
    }

    public function definition(): StudentReportDefinition
    {
        return new StudentReportDefinition(
            key: 'students.activities',
            title: 'Activity participants',
            description: 'Students taking part in each Activity now, with their class.',
            category: 'students',
            owner: 'Students (Communities)',
            authority: [CommunityAdministrationService::VIEW],
            scope: StudentReportDefinition::CAMPUS_AWARE,
            route: StudentReportDefinition::RENDERED,
            filters: ['activity_id', 'activity_category', 'q', 'campus_id', 'grade_level_id', 'section_id'],
            columns: [
                new C('student', 'Student', 'student'),
                new C('activity', 'Activity'),
                new C('category', 'Category'),
                new C('placement', 'Class', 'placement', C::RESTRICTED, ['enrollments.view']),
                new C('since', 'Taking part since', 'date'),
            ],
            sorts: ['activity' => 'Activity, then Student', 'student' => 'Student name', 'since' => 'Newest participants first'],
            perPage: 25,
            reference: ['SR-17'],
        );
    }
}
