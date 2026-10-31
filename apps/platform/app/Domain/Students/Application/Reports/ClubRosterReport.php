<?php

namespace App\Domain\Students\Application\Reports;

use App\Domain\Students\Application\Groups\CommunityAdministrationService;
use App\Domain\Students\Application\Groups\StudentGroupType;
use App\Support\StudentReports\StudentReportColumn as C;
use App\Support\StudentReports\StudentReportDefinition;

/**
 * SIS 11.8 (OD-SIS-5A): the Club roster -- CURRENT Club members on the
 * shared StudentGroupRosterReport query, under `students.communities.view`
 * (Campus-aware: a Campus reader lists only in-scope Students, and no count
 * reveals the School-wide Club). A Student in several Clubs appears once per
 * Club. Browser-only.
 */
final class ClubRosterReport extends StudentGroupRosterReport
{
    public const array SORTS = [
        'club' => 'g.display_order asc, g.name asc, s.last_name asc nulls last, s.first_name asc, m.id asc',
        'student' => 's.last_name asc nulls last, s.first_name asc, m.id asc',
        'since' => 'm.starts_on desc, m.id asc',
    ];

    protected function type(): StudentGroupType
    {
        return StudentGroupType::CLUB;
    }

    protected function authority(): string
    {
        return CommunityAdministrationService::VIEW;
    }

    public function definition(): StudentReportDefinition
    {
        return new StudentReportDefinition(
            key: 'students.clubs',
            title: 'Club roster',
            description: 'Students in each Club now, with their class and Club responsibility.',
            category: 'students',
            owner: 'Students (Communities)',
            authority: [CommunityAdministrationService::VIEW],
            scope: StudentReportDefinition::CAMPUS_AWARE,
            route: StudentReportDefinition::RENDERED,
            filters: ['club_id', 'q', 'responsibility', 'campus_id', 'grade_level_id', 'section_id'],
            columns: [
                new C('student', 'Student', 'student'),
                new C('club', 'Club'),
                new C('placement', 'Class', 'placement', C::RESTRICTED, ['enrollments.view']),
                new C('responsibility', 'Responsibility'),
                new C('since', 'Member since', 'date'),
            ],
            sorts: ['club' => 'Club, then Student', 'student' => 'Student name', 'since' => 'Newest members first'],
            perPage: 25,
            reference: ['SR-17'],
        );
    }
}
