<?php

namespace App\Support\Routing;

/**
 * LC-1 (Local Product Completion, M1): the route parameters of the signed-in
 * web surface that name a record by its UUID primary key.
 *
 * A malformed id (`/app/attendance/not-a-uuid`) used to reach a UUID column
 * and fail as a database error (SQLSTATE 22P02, a 500). A mistyped path
 * that fell through to a `/{id}` route did the same
 * (`/app/communications/settings` → `/{thread}`). Constrained here, such a
 * path matches no route: the normal 404, before any controller or query
 * runs, the same for every School. Applied as a `where` on the two
 * signed-in groups of routes/web.php. Route-level constraints (`->where()`
 * / `->whereUuid()`) still apply.
 *
 * Every parameter of those groups is either listed here or in NOT_UUID;
 * Tests\Feature\Routing\WebRouteParametersGuardTest fails on a new one
 * that is neither, so a new page cannot silently skip the constraint.
 */
final class WebRouteParameters
{
    public const UUID_PATTERN = '[\da-fA-F]{8}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{12}';

    /** Parameters that are not UUIDs, each with its own route-level rule or controller check. */
    public const NOT_UUID = [
        'action',          // platform School lifecycle / module action (whereIn)
        'check',           // Data Health check code (controller 404; must not 404 before school-context)
        'identifierType',  // statutory identifier type
        'module',          // ORG.5 module key (where [a-z_]{1,64}; the service refuses unknown keys)
        'role',            // role key (where)
        'ruleType',        // Automation rule type key
        'selector',        // account-recovery / activation selector (where)
        'token',           // API token id / invitation token
    ];

    public const UUID = [
        'academicDepartment', 'academicTerm', 'academicYear', 'address', 'adjustment', 'admissionApplication',
        'announcement', 'applicant', 'approvalRequest', 'assessment', 'assignment', 'attachment',
        'attendanceRecord', 'attendanceSession', 'authorization', 'campus', 'canteenItem', 'canteenOrder',
        'canteenOutlet', 'category', 'certification', 'charge', 'client', 'compensationAssignment',
        'concession', 'contact', 'correctionRequest', 'curriculumDelivery', 'department', 'document', 'domain', 'electiveGroup',
        'electiveTeachingAssignment', 'employee', 'event', 'employment', 'employmentRecord', 'enrollment', 'examination',
        'examinationPaper', 'experience', 'feeHead', 'feeStructure', 'gradeBand', 'gradeLevel', 'gradeScale',
        'grant', 'guardian', 'hostel', 'hostelBed', 'hostelResidencyAssignment', 'hostelRoom', 'inventoryItem',
        'inventoryLocation', 'invitation', 'item', 'journalEntry', 'learningContent', 'leavePolicy',
        'leavePolicyAssignment', 'leaveRequest', 'leaveType', 'ledgerAccount', 'libraryCopy', 'libraryFine', 'libraryLoan',
        'libraryTitle', 'line', 'mapping', 'membership', 'note', 'payment', 'payrollPeriod', 'payrollRun',
        'period', 'position', 'qualification', 'region', 'relationship', 'requirement', 'rollover', 'room', 'rule', 'run',
        'salaryComponent', 'salaryStructure', 'school', 'schoolGroup', 'section', 'selection',
        'staffAttendanceRecord', 'staffHoliday', 'student', 'studentMark', 'studentMarkCorrection', 'subject',
        'subjectOffering', 'syllabusUnit', 'targetUser', 'teachingAssignment', 'template', 'thread',
        'timetableEntry', 'timetablePeriod', 'transportRoute', 'transportRouteAssignment', 'transportStop',
        'transportStudentAssignment', 'transportVehicle', 'visitor', 'visitorVisit', 'zone',
    ];

    /**
     * @return array<string, string>
     */
    public static function uuidPatterns(): array
    {
        return array_fill_keys(self::UUID, self::UUID_PATTERN);
    }
}
