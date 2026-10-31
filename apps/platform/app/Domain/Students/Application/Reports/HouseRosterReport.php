<?php

namespace App\Domain\Students\Application\Reports;

use App\Domain\Students\Application\Groups\HouseAdministrationService;
use App\Domain\Students\Application\Groups\StudentGroupType;
use App\Support\StudentReports\StudentReportColumn as C;
use App\Support\StudentReports\StudentReportDefinition;

/**
 * SIS 11.7: the House roster -- CURRENT House members, as the viewer may see
 * them (`students.houses.view`, Campus-aware through StudentAccess: a
 * Campus reader lists only Students whose current Campus is in scope, never
 * the School-wide House). Class columns need `enrollments.view`.
 *
 * Operational listing only: no count by gender or any attribute, no
 * percentage, chart or demographic column. Browser-only for now (no export
 * or print: House membership is relationship information that needs its own
 * export review). Bounded: one page query, one placement read, one
 * responsibility read.
 */
final class HouseRosterReport extends StudentGroupRosterReport
{
    public const array SORTS = [
        'house' => 'g.display_order asc, g.name asc, s.last_name asc nulls last, s.first_name asc, m.id asc',
        'student' => 's.last_name asc nulls last, s.first_name asc, m.id asc',
        'since' => 'm.starts_on desc, m.id asc',
    ];

    protected function type(): StudentGroupType
    {
        return StudentGroupType::HOUSE;
    }

    protected function authority(): string
    {
        return HouseAdministrationService::VIEW;
    }

    public function definition(): StudentReportDefinition
    {
        return new StudentReportDefinition(
            key: 'students.houses',
            title: 'House roster',
            description: 'Students in each House now, with their class and House responsibility.',
            category: 'students',
            owner: 'Students (Houses)',
            authority: [HouseAdministrationService::VIEW],
            scope: StudentReportDefinition::CAMPUS_AWARE,
            route: StudentReportDefinition::RENDERED,
            filters: ['house_id', 'q', 'responsibility', 'campus_id', 'grade_level_id', 'section_id'],
            columns: [
                new C('student', 'Student', 'student'),
                new C('house', 'House'),
                new C('placement', 'Class', 'placement', C::RESTRICTED, ['enrollments.view']),
                new C('responsibility', 'Responsibility'),
                new C('since', 'Member since', 'date'),
            ],
            sorts: ['house' => 'House, then Student', 'student' => 'Student name', 'since' => 'Newest members first'],
            perPage: 25,
            reference: ['SR-17'],
        );
    }
}
