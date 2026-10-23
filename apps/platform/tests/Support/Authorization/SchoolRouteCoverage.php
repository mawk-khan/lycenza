<?php

namespace Tests\Support\Authorization;

use App\Domain\Admissions\Application\AdmissionApplicationAccess;
use App\Domain\Analytics\Application\AnalyticsReadGate;
use App\Domain\Attendance\Application\AttendanceAdministrationAccess;
use App\Domain\Attendance\Application\AttendanceExceptionReadService;
use App\Domain\Attendance\Application\AttendanceOperationsReadService;
use App\Domain\Attendance\Application\TeacherAttendanceAccess;
use App\Domain\Automation\Application\AutomationReadService;
use App\Domain\Automation\Application\AutomationRuleService;
use App\Domain\Canteen\Application\CanteenOrderAccess;
use App\Domain\Communications\Http\Controllers\CommunicationAttachmentController;
use App\Domain\Communications\Http\Controllers\CommunicationPreferenceController;
use App\Domain\Compliance\Application\AuditLogReviewService;
use App\Domain\CurriculumDelivery\Application\CurriculumDeliveryAccess;
use App\Domain\CurriculumDelivery\Application\TeacherDeliveryAccess;
use App\Domain\Documents\Application\DocumentListingService;
use App\Domain\Documents\Application\DocumentReadService;
use App\Domain\Documents\Application\DocumentService;
use App\Domain\Examinations\Application\ExaminationPaperAccess;
use App\Domain\Fees\Application\ChargeReadService;
use App\Domain\Hostel\Application\HostelResidencyAccess;
use App\Domain\HR\Application\EmployeeDirectoryService;
use App\Domain\HR\Application\EmployeeOnboardingService;
use App\Domain\HR\Application\EmployeeOperationalOverviewService;
use App\Domain\HR\Application\EmployeeProfileWorkspaceService;
use App\Domain\HR\Application\HrEmployeeAccess;
use App\Domain\HR\Http\Support\HighlySensitiveDocumentStepUp;
use App\Domain\Identity\Application\Portal\ActingGuardianResolver;
use App\Domain\Identity\Application\Staff\StaffAccessService;
use App\Domain\Identity\Application\Staff\StaffInvitationService;
use App\Domain\Identity\Application\Staff\StaffRoleCatalog;
use App\Domain\Inventory\Application\InventoryStockAccess;
use App\Domain\Inventory\Application\InventoryStockHistory;
use App\Domain\Leave\Application\LeaveLedgerService;
use App\Domain\Leave\Application\LeavePolicyAssignmentService;
use App\Domain\Leave\Application\LeavePolicyService;
use App\Domain\Leave\Application\LeaveRequestReadService;
use App\Domain\Leave\Application\LeaveRequestService;
use App\Domain\Leave\Application\LeaveTypeService;
use App\Domain\Leave\Application\LeaveYearCloseService;
use App\Domain\Leave\Application\LeaveYearService;
use App\Domain\Leave\Application\StaffCalendarService;
use App\Domain\Library\Application\LibraryCirculationAccess;
use App\Domain\LMS\Application\LmsAdministrationAccess;
use App\Domain\LMS\Application\TeacherAssignmentAccess;
use App\Domain\LMS\Application\TeacherLearningContentAccess;
use App\Domain\Payments\Application\Access\FinanceCampusAccess;
use App\Domain\Payments\Application\ManualPaymentRecordingService;
use App\Domain\Payroll\Application\PayrollAccountingAdministrationService;
use App\Domain\Payroll\Application\PayrollCompensationReadService;
use App\Domain\Payroll\Application\PayrollHrxInputReadService;
use App\Domain\Payroll\Application\PayrollPeriodAdministrationService;
use App\Domain\Payroll\Application\PayrollRunAdministrationService;
use App\Domain\Payroll\Application\PayrollStructureAdministrationService;
use App\Domain\Payroll\Application\PayrollStructureReadService;
use App\Domain\Payroll\Application\PayslipReadService;
use App\Domain\Payroll\Statutory\Application\Admin\EmployeeEsiCoverageAdminService;
use App\Domain\Payroll\Statutory\Application\Admin\EmployeePfStatusAdminService;
use App\Domain\Payroll\Statutory\Application\Admin\EmployeeTaxProfileAdminService;
use App\Domain\Payroll\Statutory\Application\Admin\StatutoryAccountingAdministrationService;
use App\Domain\Payroll\Statutory\Application\Admin\StatutoryIdentifierAdminService;
use App\Domain\Payroll\Statutory\Application\Export\StatutoryEcrExportService;
use App\Domain\Payroll\Statutory\Application\Export\StatutoryEsiContributionWorksheetExportService;
use App\Domain\Payroll\Statutory\Application\Export\StatutoryTdsDraftStatementExportService;
use App\Domain\StaffAttendance\Application\StaffAttendanceReadService;
use App\Domain\StaffAttendance\Application\StaffAttendanceService;
use App\Domain\Students\Application\CampusStudentOnboardingService;
use App\Domain\Students\Application\CampusStudentTransferService;
use App\Domain\Students\Application\StudentAccess;
use App\Domain\Students\Application\StudentNameEditService;
use App\Domain\Timetable\Application\TimetableScheduleAccess;
use App\Domain\Transport\Application\TransportRiderAccess;
use App\Domain\Transport\Application\TransportRiderImport;
use App\Domain\Visitor\Application\VisitorVisitAccess;
use Tests\Feature\Api\V1\SchoolContextTest;
use Tests\Feature\App\StatutoryPayrollUiTest;
use Tests\Feature\Identity\InvitationAcceptanceHubTest;
use Tests\Feature\Identity\Staff\StaffInvitationAcceptanceTest;
use Tests\Feature\Tenancy\SchoolContextRequiredTest;

/**
 * ORG.2 (ADR 0076 §28): the authorization contract of every School-facing
 * route that does not carry its own `capability:` middleware (C1), plus the
 * scoped authority of owned surfaces (teacher "my", Guardian portal, staff
 * self-service) whether or not they also carry C1.
 *
 * Adding a School route means adding its contract here -- or giving the
 * route its own `capability:` middleware. AuthorizationCoverageGuardTest
 * fails CI for a School route with no contract, an entry naming a missing
 * route, a duplicate entry, or an entry whose evidence the code no longer
 * shows. School context or membership is never a contract on its own.
 *
 * Keys are the exact capability keys the code gates on (resolved from the
 * code by CoverageEvidence, so a rename cannot drift silently). Never add a
 * capability to make this pass -- an unexpressible route is an architecture
 * gap to report (ADR 0076 §28).
 */
final class SchoolRouteCoverage
{
    /** @return list<Coverage> */
    public static function entries(): array
    {
        return [
            ...array_values(self::special()),
            // Http\Controllers\Api\V1\Internal\WebhookTestEventController
            Coverage::gate('api.v1.schools.webhook-test-events.emit', 'integrations.webhooks.manage'),

            // Http\Controllers\Api\V1\WebhookEndpointController
            Coverage::gate('api.v1.schools.webhook-endpoints.index', 'integrations.webhooks.view'),
            Coverage::gate('api.v1.schools.webhook-endpoints.show', 'integrations.webhooks.view'),
            Coverage::gate('api.v1.schools.webhook-endpoints.disable', 'integrations.webhooks.manage'),
            Coverage::gate('api.v1.schools.webhook-endpoints.enable', 'integrations.webhooks.manage'),

            // Http\Controllers\Api\V1\WebhookSubscriptionController
            Coverage::gate('api.v1.schools.webhook-endpoints.subscriptions.store', 'integrations.webhooks.manage'),
            Coverage::gate('api.v1.schools.webhook-endpoints.subscriptions.destroy', 'integrations.webhooks.manage'),

            // Http\Controllers\Api\V1\WebhookDeliveryController
            Coverage::gate('api.v1.schools.webhook-deliveries.index', 'integrations.webhooks.view'),
            Coverage::gate('api.v1.schools.webhook-deliveries.show', 'integrations.webhooks.view'),

            // Http\Controllers\Api\V1\SchoolProfileController
            Coverage::gate('api.v1.schools.profile.show', 'school.profile.view'),

            // Http\Controllers\Api\V1\CampusController
            Coverage::gate('api.v1.schools.campuses.index', 'school.campuses.view'),
            Coverage::gate('api.v1.schools.campuses.show', 'school.campuses.view'),

            // Domain\AcademicStructure\Http\Controllers\RoomController
            Coverage::gate('api.v1.schools.campuses.rooms.index', 'academics.structure.view'),
            Coverage::gate('api.v1.schools.rooms.show', 'academics.structure.view'),

            // Domain\AcademicStructure\Http\Controllers\AcademicYearController
            Coverage::gate('api.v1.schools.academic-years.index', 'academics.years.view'),
            Coverage::gate('api.v1.schools.academic-years.show', 'academics.years.view'),

            // Domain\AcademicStructure\Http\Controllers\AcademicTermController
            Coverage::gate('api.v1.schools.academic-years.terms.index', 'academics.years.view'),
            Coverage::gate('api.v1.schools.academic-terms.show', 'academics.years.view'),

            // Domain\AcademicStructure\Http\Controllers\GradeLevelController
            Coverage::gate('api.v1.schools.grade-levels.index', 'academics.structure.view'),
            Coverage::gate('api.v1.schools.grade-levels.show', 'academics.structure.view'),

            // Domain\AcademicStructure\Http\Controllers\SectionController
            Coverage::gate('api.v1.schools.academic-years.sections.index', 'academics.structure.view'),
            Coverage::gate('api.v1.schools.sections.show', 'academics.structure.view'),

            // Domain\AcademicStructure\Http\Controllers\SubjectController
            Coverage::gate('api.v1.schools.subjects.index', 'academics.subjects.view'),
            Coverage::gate('api.v1.schools.subjects.show', 'academics.subjects.view'),

            // Domain\AcademicStructure\Http\Controllers\AcademicDepartmentController
            Coverage::gate('api.v1.schools.academic-departments.index', 'academics.structure.view'),
            Coverage::gate('api.v1.schools.academic-departments.show', 'academics.structure.view'),

            // Domain\AcademicStructure\Http\Controllers\SubjectOfferingController
            Coverage::gate('api.v1.schools.academic-years.subject-offerings.index', 'academics.subjects.view'),
            Coverage::gate('api.v1.schools.subject-offerings.show', 'academics.subjects.view'),

            // Domain\Students\Http\Controllers\StudentSubjectEnrollmentController
            Coverage::gate('api.v1.schools.subject-offerings.roster', 'academics.subjects.view'),

            // Domain\Students\Http\Controllers\StudentController
            Coverage::campus('api.v1.schools.students.index', 'students.view', StudentAccess::class, 'students'),

            // Domain\Guardians\Http\Controllers\StudentGuardianRelationshipController

            // Domain\Guardians\Http\Controllers\GuardianController
            Coverage::gate('api.v1.schools.guardians.index', 'guardians.view'),
            Coverage::gate('api.v1.schools.guardian-candidates', 'guardians.view'),
            Coverage::gate('api.v1.schools.guardians.show', 'guardians.view'),

            // Domain\Students\Http\Controllers\StudentEnrollmentController
            Coverage::campus('api.v1.schools.enrollments.index', 'enrollments.view', StudentAccess::class, 'enrollmentDirectory'),
            Coverage::campus('api.v1.schools.enrollments.show', 'enrollments.view', StudentAccess::class, 'enrollment'),
            Coverage::campus('api.v1.schools.enrollments.transfer', 'enrollments.manage', StudentAccess::class, 'enrollment'),
            // ORG.7G-1 (ADR 0076 §50): Campus-aware Finance API reads.
            Coverage::campus('api.v1.schools.charges.index', 'finance.charges.view', ChargeReadService::class, 'listCharges'),
            Coverage::campus('api.v1.schools.charges.show', 'finance.charges.view', ChargeReadService::class, 'getChargeDetail'),
            Coverage::campus('api.v1.schools.payments.index', 'finance.payments.view', FinanceCampusAccess::class, 'payments'),
            Coverage::campus('api.v1.schools.payments.show', 'finance.payments.view', FinanceCampusAccess::class, 'payment'),
            Coverage::campus('api.v1.schools.payments.receipt', 'finance.payments.view', FinanceCampusAccess::class, 'receipt'),
            Coverage::campus('api.v1.schools.students.enrollments.current', 'enrollments.view', StudentAccess::class, 'student'),

            // Domain\HR\Http\Controllers\EmployeeDocumentController
            Coverage::service('api.v1.schools.employees.hr-document-records.store', HighlySensitiveDocumentStepUp::class, 'requireFor'),
            Coverage::service('api.v1.schools.employees.hr-document-records.update', HighlySensitiveDocumentStepUp::class, 'requireFor'),
            Coverage::service('api.v1.schools.employees.hr-document-records.archive', HighlySensitiveDocumentStepUp::class, 'requireFor'),

            // Domain\HR\Http\Controllers\DepartmentController
            Coverage::gate('api.v1.schools.hr-departments.index', 'hr.departments.view'),

            // ORG.7E-1 (ADR 0076 §43): Campus-adopted HR API routes (`,campus` floors).
            Coverage::campus('api.v1.schools.employees.index', 'hr.employees.view', EmployeeDirectoryService::class, 'search'),
            Coverage::campus('api.v1.schools.employees.update', 'hr.employees.manage', HrEmployeeAccess::class, 'employee'),
            Coverage::campus('api.v1.schools.employees.assignments.store', 'hr.employees.assignments.manage', HrEmployeeAccess::class, 'employee'),
            Coverage::campus('api.v1.schools.employees.assignments.end', 'hr.employees.assignments.manage', HrEmployeeAccess::class, 'assignment'),
            Coverage::campus('api.v1.schools.employees.assignments.set-primary', 'hr.employees.assignments.manage', HrEmployeeAccess::class, 'assignment'),
            Coverage::campus('api.v1.schools.employees.assignments.set-manager', 'hr.employees.assignments.manage', HrEmployeeAccess::class, 'assignment'),

            // Domain\HR\Http\Controllers\PositionController
            Coverage::gate('api.v1.schools.positions.index', 'hr.positions.view'),

            // Domain\HR\Http\Controllers\EmployeeCategoryController
            Coverage::gate('api.v1.schools.employee-categories.index', 'hr.categories.view'),

            // Domain\Students\Http\Controllers\EnrollmentRolloverController

            // Domain\Students\Http\Controllers\EnrollmentRolloverItemController

            // Domain\Documents\Http\Controllers\DocumentController
            Coverage::service('api.v1.schools.employees.documents.store', DocumentService::class, 'create')->validatesFirst(),
            Coverage::service('api.v1.schools.employees.documents.index', DocumentListingService::class, 'list'),
            Coverage::service('api.v1.schools.employees.documents.sensitive', DocumentListingService::class, 'listSensitive'),
            Coverage::service('api.v1.schools.documents.show', DocumentReadService::class, 'metadata'),
            Coverage::service('api.v1.schools.documents.content', DocumentReadService::class, 'content'),
            Coverage::service('api.v1.schools.documents.archive', DocumentService::class, 'archive'),

            // Domain\Admissions\Http\Controllers\ApplicantController

            // Domain\Admissions\Http\Controllers\AdmissionApplicationController
            Coverage::campus('api.v1.schools.admission-applications.index', 'admissions.view', AdmissionApplicationAccess::class, 'directory'),
            Coverage::campus('api.v1.schools.admission-applications.show', 'admissions.view', AdmissionApplicationAccess::class, 'detail'),

            // Domain\Admissions\Http\Controllers\AdmissionFeeController

            // Domain\Library\Http\Controllers\LibraryTitleController
            Coverage::gate('api.v1.schools.library-titles.index', 'library.catalogue.view'),
            Coverage::gate('api.v1.schools.library-titles.show', 'library.catalogue.view'),

            // Domain\Library\Http\Controllers\LibraryCopyController
            Coverage::gate('api.v1.schools.library-titles.copies.index', 'library.catalogue.view'),

            // Domain\Library\Http\Controllers\LibraryLoanController
            // ORG.7D-5 (ADR 0076 §41): Domain\Library\Http\Controllers\LibraryLoanController
            Coverage::campus('api.v1.schools.library-loans.index', 'library.circulation.view', LibraryCirculationAccess::class, 'loans'),
            Coverage::campus('api.v1.schools.library-loans.show', 'library.circulation.view', LibraryCirculationAccess::class, 'loan'),
            Coverage::campus('api.v1.schools.library-loans.store', 'library.circulation.manage', LibraryCirculationAccess::class, 'writeGuard'),
            Coverage::campus('api.v1.schools.library-loans.check-in', 'library.circulation.manage', LibraryCirculationAccess::class, 'writeGuard'),

            // Domain\Library\Http\Controllers\LibraryFineController
            Coverage::gate('api.v1.schools.library-fine-policy.show', 'library.fines.view'),
            Coverage::gate('api.v1.schools.library-fine-policy.versions.index', 'library.fines.view'),
            Coverage::gate('api.v1.schools.library-loans.fine.show', 'library.fines.view'),

            // Domain\Transport\Http\Controllers\TransportRouteController
            Coverage::campus('api.v1.schools.transport-routes.index', 'transport.routes.view', TransportRiderAccess::class, 'routes'),
            Coverage::campus('api.v1.schools.transport-routes.show', 'transport.routes.view', TransportRiderAccess::class, 'scopedRoute'),

            // Domain\Transport\Http\Controllers\TransportStopController
            Coverage::campus('api.v1.schools.transport-routes.stops.index', 'transport.routes.view', TransportRiderAccess::class, 'scopedRoute'),

            // Domain\Transport\Http\Controllers\TransportVehicleController
            Coverage::gate('api.v1.schools.transport-vehicles.index', 'transport.vehicles.view'),
            Coverage::gate('api.v1.schools.transport-vehicles.show', 'transport.vehicles.view'),

            // Domain\Transport\Http\Controllers\TransportRouteAssignmentController
            Coverage::gate('api.v1.schools.transport-routes.assignments.index', 'transport.vehicles.view'),

            // Domain\Transport\Http\Controllers\TransportStudentAssignmentController
            Coverage::campus('api.v1.schools.transport-student-assignments.index', 'transport.assignments.view', TransportRiderAccess::class, 'assignments'),
            Coverage::campus('api.v1.schools.transport-student-assignments.store', 'transport.assignments.manage', TransportRiderAccess::class, 'writeGuard'),
            Coverage::campus('api.v1.schools.transport-student-assignments.end', 'transport.assignments.manage', TransportRiderAccess::class, 'writeGuard'),
            Coverage::campus('api.v1.schools.transport-student-assignments.show', 'transport.assignments.view', TransportRiderAccess::class, 'assignment'),

            // Domain\Transport\Http\Controllers\TransportFeeController

            // Domain\Visitor\Http\Controllers\VisitorController
            Coverage::gate('api.v1.schools.visitors.index', 'visitor.directory.view'),
            Coverage::gate('api.v1.schools.visitors.show', 'visitor.directory.view'),

            // Domain\Visitor\Http\Controllers\VisitorVisitController
            Coverage::campus('api.v1.schools.visitor-visits.index', 'visitor.visits.view', VisitorVisitAccess::class, 'visits'),
            Coverage::campus('api.v1.schools.visitor-visits.show', 'visitor.visits.view', VisitorVisitAccess::class, 'visit'),
            Coverage::campus('api.v1.schools.visitor-visits.store', 'visitor.visits.manage', VisitorVisitAccess::class, 'campusForNewVisit'),
            Coverage::campus('api.v1.schools.visitor-visits.end', 'visitor.visits.manage', VisitorVisitAccess::class, 'visit'),

            // Domain\Hostel\Http\Controllers\HostelController
            Coverage::gate('api.v1.schools.hostels.index', 'hostel.directory.view'),
            Coverage::gate('api.v1.schools.hostels.show', 'hostel.directory.view'),

            // Domain\Hostel\Http\Controllers\HostelRoomController
            Coverage::gate('api.v1.schools.hostels.hostel-rooms.index', 'hostel.directory.view'),

            // Domain\Hostel\Http\Controllers\HostelBedController
            Coverage::gate('api.v1.schools.hostel-rooms.hostel-beds.index', 'hostel.directory.view'),

            // Domain\Hostel\Http\Controllers\HostelResidencyAssignmentController
            // ORG.7D-3 (ADR 0076 §39): Hostel residency is Campus-adopted.
            Coverage::campus('api.v1.schools.hostel-residency-assignments.index', 'hostel.residency.view', HostelResidencyAccess::class, 'residencies'),
            Coverage::campus('api.v1.schools.hostel-residency-assignments.store', 'hostel.residency.manage', HostelResidencyAccess::class, 'writeGuard'),
            Coverage::campus('api.v1.schools.hostel-residency-assignments.end', 'hostel.residency.manage', HostelResidencyAccess::class, 'residency'),
            Coverage::campus('api.v1.schools.hostel-residency-assignments.show', 'hostel.residency.view', HostelResidencyAccess::class, 'residency'),

            // Domain\Hostel\Http\Controllers\HostelFeeController
            Coverage::gate('api.v1.schools.hostels.fee-head.show', 'hostel.directory.view'),
            Coverage::gate('api.v1.schools.hostel-rooms.fee-head.show', 'hostel.directory.view'),

            // Domain\Inventory\Http\Controllers\InventoryItemController
            Coverage::gate('api.v1.schools.inventory-items.index', 'inventory.directory.view'),
            Coverage::gate('api.v1.schools.inventory-items.show', 'inventory.directory.view'),

            // Domain\Inventory\Http\Controllers\InventoryLocationController
            Coverage::gate('api.v1.schools.inventory-locations.index', 'inventory.directory.view'),
            Coverage::gate('api.v1.schools.inventory-locations.show', 'inventory.directory.view'),

            // Domain\Inventory\Http\Controllers\InventoryStockController
            Coverage::campus('api.v1.schools.inventory-stock.index', 'inventory.stock.view', InventoryStockAccess::class, 'balances'),
            Coverage::campus('api.v1.schools.inventory-stock.movements', 'inventory.stock.view', InventoryStockAccess::class, 'movements'),
            Coverage::campus('api.v1.schools.inventory-stock.receive', 'inventory.stock.manage', InventoryStockAccess::class, 'location'),
            Coverage::campus('api.v1.schools.inventory-stock.issue', 'inventory.stock.manage', InventoryStockAccess::class, 'location'),
            Coverage::campus('api.v1.schools.inventory-stock.transfer', 'inventory.stock.manage', InventoryStockAccess::class, 'location'),
            Coverage::campus('api.v1.schools.inventory-stock.write-off', 'inventory.stock.manage', InventoryStockAccess::class, 'location'),

            // Domain\Canteen\Http\Controllers\CanteenOutletController
            Coverage::gate('api.v1.schools.canteen-outlets.index', 'canteen.directory.view'),
            Coverage::gate('api.v1.schools.canteen-outlets.show', 'canteen.directory.view'),

            // Domain\Canteen\Http\Controllers\CanteenItemController
            Coverage::gate('api.v1.schools.canteen-items.index', 'canteen.directory.view'),
            Coverage::gate('api.v1.schools.canteen-items.show', 'canteen.directory.view'),

            // Domain\Canteen\Http\Controllers\CanteenRecipeController
            Coverage::gate('api.v1.schools.canteen-items.recipe.index', 'canteen.directory.view'),

            // Domain\Attendance\Http\Controllers\TeacherAttendanceController
            Coverage::owned('api.v1.schools.my.attendance-sessions.index', TeacherAttendanceAccess::class, 'scope', 'teaching ownership: the acting teacher\'s own sections'),
            Coverage::owned('api.v1.schools.my.attendance-sessions.store', TeacherAttendanceAccess::class, 'guard', 'teaching ownership: the acting teacher\'s own sections'),
            Coverage::owned('api.v1.schools.my.attendance-sessions.scheduled-classes', TeacherAttendanceAccess::class, 'scope', 'teaching ownership: the acting teacher\'s own sections'),
            Coverage::owned('api.v1.schools.my.attendance-sessions.roster-preview', TeacherAttendanceAccess::class, 'scope', 'teaching ownership: the acting teacher\'s own sections'),
            Coverage::owned('api.v1.schools.my.attendance-sessions.show', TeacherAttendanceAccess::class, 'scope', 'teaching ownership: the acting teacher\'s own sections'),
            Coverage::owned('api.v1.schools.my.attendance-records.correct', TeacherAttendanceAccess::class, 'guard', 'teaching ownership: the acting teacher\'s own sections'),

            // Domain\Documents\Http\Controllers\DocumentController
            Coverage::service('api.v1.schools.learning-content.documents.store', DocumentService::class, 'create')->validatesFirst(),
            Coverage::service('api.v1.schools.learning-content.documents.index', DocumentListingService::class, 'list'),

            // Domain\LMS\Http\Controllers\TeacherLearningContentController
            Coverage::owned('api.v1.schools.my.learning-content-contexts.index', TeacherLearningContentAccess::class, 'scope', 'teaching ownership: the acting teacher\'s own offerings'),
            Coverage::owned('api.v1.schools.my.learning-content.index', TeacherLearningContentAccess::class, 'scope', 'teaching ownership: the acting teacher\'s own offerings'),
            Coverage::owned('api.v1.schools.my.learning-content.store', TeacherLearningContentAccess::class, 'guard', 'teaching ownership: the acting teacher\'s own offerings'),
            Coverage::owned('api.v1.schools.my.learning-content.show', TeacherLearningContentAccess::class, 'scope', 'teaching ownership: the acting teacher\'s own offerings'),
            Coverage::owned('api.v1.schools.my.learning-content.update', TeacherLearningContentAccess::class, 'guard', 'teaching ownership: the acting teacher\'s own offerings'),
            Coverage::owned('api.v1.schools.my.learning-content.publish', TeacherLearningContentAccess::class, 'guard', 'teaching ownership: the acting teacher\'s own offerings'),
            Coverage::owned('api.v1.schools.my.learning-content.archive', TeacherLearningContentAccess::class, 'guard', 'teaching ownership: the acting teacher\'s own offerings'),

            // Domain\Documents\Http\Controllers\DocumentController
            Coverage::service('api.v1.schools.assignments.documents.store', DocumentService::class, 'create')->validatesFirst(),
            Coverage::service('api.v1.schools.assignments.documents.index', DocumentListingService::class, 'list'),

            // Domain\LMS\Http\Controllers\TeacherAssignmentController
            Coverage::owned('api.v1.schools.my.assignment-contexts.index', TeacherAssignmentAccess::class, 'scope', 'teaching ownership: the acting teacher\'s own offerings'),
            Coverage::owned('api.v1.schools.my.assignments.index', TeacherAssignmentAccess::class, 'scope', 'teaching ownership: the acting teacher\'s own offerings'),
            Coverage::owned('api.v1.schools.my.assignments.store', TeacherAssignmentAccess::class, 'guard', 'teaching ownership: the acting teacher\'s own offerings'),
            Coverage::owned('api.v1.schools.my.assignments.show', TeacherAssignmentAccess::class, 'scope', 'teaching ownership: the acting teacher\'s own offerings'),
            Coverage::owned('api.v1.schools.my.assignments.update', TeacherAssignmentAccess::class, 'guard', 'teaching ownership: the acting teacher\'s own offerings'),
            Coverage::owned('api.v1.schools.my.assignments.publish', TeacherAssignmentAccess::class, 'guard', 'teaching ownership: the acting teacher\'s own offerings'),
            Coverage::owned('api.v1.schools.my.assignments.close', TeacherAssignmentAccess::class, 'guard', 'teaching ownership: the acting teacher\'s own offerings'),

            // Domain\CurriculumDelivery\Http\Controllers\TeacherCurriculumDeliveryController
            Coverage::owned('api.v1.schools.my.curriculum-delivery-contexts.index', TeacherDeliveryAccess::class, 'scope', 'teaching ownership: the acting teacher\'s own contexts'),
            Coverage::owned('api.v1.schools.my.curriculum-deliveries.index', TeacherDeliveryAccess::class, 'scope', 'teaching ownership: the acting teacher\'s own contexts'),
            Coverage::owned('api.v1.schools.my.curriculum-deliveries.store', TeacherDeliveryAccess::class, 'scope', 'teaching ownership: the acting teacher\'s own contexts'),
            Coverage::owned('api.v1.schools.my.curriculum-deliveries.show', TeacherDeliveryAccess::class, 'scope', 'teaching ownership: the acting teacher\'s own contexts'),
            Coverage::owned('api.v1.schools.my.curriculum-deliveries.update', TeacherDeliveryAccess::class, 'guard', 'teaching ownership: the acting teacher\'s own contexts'),
            Coverage::owned('api.v1.schools.my.curriculum-deliveries.transition', TeacherDeliveryAccess::class, 'guard', 'teaching ownership: the acting teacher\'s own contexts'),

            // Domain\Leave\Http\Controllers\MyLeaveController
            Coverage::owned('api.v1.schools.my.leave.overview', LeaveRequestReadService::class, 'ownOverview', 'acting employee: the actor\'s own leave'),
            Coverage::owned('api.v1.schools.my.leave.requests.index', LeaveRequestReadService::class, 'own', 'acting employee: the actor\'s own leave'),
            Coverage::owned('api.v1.schools.my.leave.requests.store', LeaveRequestService::class, 'submitOwn', 'acting employee: the actor\'s own leave'),
            Coverage::owned('api.v1.schools.my.leave.requests.show', LeaveRequestReadService::class, 'ownShow', 'acting employee: the actor\'s own leave'),
            Coverage::owned('api.v1.schools.my.leave.requests.withdraw', LeaveRequestService::class, 'withdrawOwn', 'acting employee: the actor\'s own leave'),
            Coverage::owned('api.v1.schools.my.leave.requests.cancel', LeaveRequestService::class, 'cancelOwn', 'acting employee: the actor\'s own leave'),

            // Domain\StaffAttendance\Http\Controllers\MyStaffAttendanceController
            Coverage::owned('api.v1.schools.my.staff-attendance.index', StaffAttendanceReadService::class, 'own', 'acting employee: the actor\'s own staff attendance'),

            // Domain\Payroll\Http\Controllers\MyPayslipController
            Coverage::owned('api.v1.schools.my.payslips.index', PayslipReadService::class, 'ownPayslips', 'acting employee: the actor\'s own payslips'),
            Coverage::owned('api.v1.schools.my.payslips.show', PayslipReadService::class, 'renderOwn', 'acting employee: the actor\'s own payslips'),

            // Http\Controllers\App\Staff\StaffAccountController
            Coverage::service('app.settings.staff.index', StaffRoleCatalog::class, 'canView'),
            Coverage::service('app.settings.staff.invitations.store', StaffInvitationService::class, 'issue'),
            Coverage::service('app.settings.staff.invitations.resend', StaffInvitationService::class, 'resend'),
            Coverage::service('app.settings.staff.invitations.revoke', StaffInvitationService::class, 'revoke'),
            Coverage::service('app.settings.staff.members.suspend', StaffAccessService::class, 'suspend'),
            Coverage::service('app.settings.staff.members.reactivate', StaffAccessService::class, 'reactivate'),
            Coverage::service('app.settings.staff.members.roles.store', StaffAccessService::class, 'grantRole'),
            Coverage::service('app.settings.staff.members.roles.revoke', StaffAccessService::class, 'revokeRole'),

            // Http\Controllers\App\Domains\SchoolDomainController
            Coverage::gate('app.settings.domains.index', 'school.domains.view'),
            Coverage::gate('app.settings.domains.store', 'school.domains.manage')->validatesFirst(),
            Coverage::gate('app.settings.domains.challenge', 'school.domains.manage'),
            Coverage::gate('app.settings.domains.primary', 'school.domains.manage'),
            Coverage::gate('app.settings.domains.revoke', 'school.domains.manage'),
            Coverage::gate('app.settings.domains.check', 'school.domains.manage'),

            // Http\Controllers\App\Integrations\ApiClientController
            Coverage::gate('app.integrations.api-clients.index', 'integrations.api_clients.view'),
            Coverage::gate('app.integrations.api-clients.store', 'integrations.api_clients.manage')->validatesFirst(),
            Coverage::gate('app.integrations.api-clients.rotate', 'integrations.api_clients.manage'),
            Coverage::gate('app.integrations.api-clients.revoke', 'integrations.api_clients.manage'),

            // Http\Controllers\App\SchoolSettingsController
            Coverage::gate('app.settings.update', 'school.settings.manage'),

            // Http\Controllers\App\SchoolSetupController
            Coverage::gate('app.school-setup.index', 'school.profile.view'),
            Coverage::gate('app.school-setup.profile', 'school.profile.view'),
            Coverage::gate('app.school-setup.profile.update', 'school.profile.manage'),
            Coverage::gate('app.school-setup.campuses', 'school.campuses.view'),
            Coverage::gate('app.school-setup.campuses.store', 'school.campuses.manage'),
            Coverage::gate('app.school-setup.campuses.update', 'school.campuses.manage'),
            Coverage::gate('app.school-setup.academic-years', 'academics.years.view'),
            Coverage::gate('app.school-setup.academic-years.store', 'academics.years.manage'),
            Coverage::gate('app.school-setup.academic-years.update', 'academics.years.manage'),
            Coverage::gate('app.school-setup.academic-years.activate', 'academics.years.manage'),
            Coverage::gate('app.school-setup.academic-years.close', 'academics.years.manage'),
            Coverage::gate('app.school-setup.grade-levels', 'academics.structure.view'),
            Coverage::gate('app.school-setup.grade-levels.store', 'academics.structure.manage'),
            Coverage::gate('app.school-setup.grade-levels.update', 'academics.structure.manage'),
            Coverage::gate('app.school-setup.subjects', 'academics.subjects.view'),
            Coverage::gate('app.school-setup.subjects.store', 'academics.subjects.manage'),
            Coverage::gate('app.school-setup.subjects.update', 'academics.subjects.manage'),

            // Http\Controllers\App\SchoolSetupStructureController
            Coverage::gate('app.school-setup.terms', 'academics.years.view'),
            Coverage::gate('app.school-setup.terms.store', 'academics.years.manage'),
            Coverage::gate('app.school-setup.terms.update', 'academics.years.manage'),
            Coverage::gate('app.school-setup.sections', 'academics.structure.view'),
            Coverage::gate('app.school-setup.sections.store', 'academics.structure.manage'),
            Coverage::gate('app.school-setup.sections.update', 'academics.structure.manage'),
            Coverage::gate('app.school-setup.departments', 'academics.structure.view'),
            Coverage::gate('app.school-setup.departments.store', 'academics.structure.manage'),
            Coverage::gate('app.school-setup.departments.update', 'academics.structure.manage'),
            Coverage::gate('app.school-setup.rooms', 'academics.structure.view'),
            Coverage::gate('app.school-setup.rooms.store', 'academics.structure.manage'),
            Coverage::gate('app.school-setup.rooms.update', 'academics.structure.manage'),

            // Http\Controllers\App\ElectiveGroupController
            Coverage::gate('app.elective-groups.index', 'academics.subjects.view'),
            Coverage::gate('app.elective-groups.store', 'academics.subjects.manage'),
            Coverage::gate('app.elective-groups.show', 'academics.subjects.view'),
            Coverage::gate('app.elective-groups.offerings.store', 'academics.subjects.manage'),
            Coverage::gate('app.elective-groups.offerings.destroy', 'academics.subjects.manage'),

            // Http\Controllers\App\StudentController
            Coverage::campus('app.students.index', 'students.view', StudentAccess::class, 'students'),
            Coverage::campus('app.students.overview', 'students.view', StudentAccess::class, 'student'),
            // ORG.7F-2 (ADR 0076 §46): the narrow Campus writes.
            Coverage::campus('app.students.names.edit', 'students.manage', StudentAccess::class, 'student'),
            Coverage::campus('app.students.names.update', 'students.manage', StudentNameEditService::class, 'edit'),
            Coverage::campus('app.students.onboard', 'students.manage', CampusStudentOnboardingService::class, 'destinations'),
            Coverage::campus('app.students.onboard.store', 'students.manage', CampusStudentOnboardingService::class, 'onboard'),
            // ORG.7F-3 (ADR 0076 §47): the dual-scope Campus transfer.
            Coverage::campus('app.students.transfer.create', 'enrollments.manage', StudentAccess::class, 'transferDestinations'),
            Coverage::campus('app.students.transfer.store', 'enrollments.manage', CampusStudentTransferService::class, 'transfer'),

            // Http\Controllers\App\StudentGuardianRelationshipController

            // Http\Controllers\App\StudentAccountLinkController

            // Http\Controllers\App\StudentEnrollmentController

            // Http\Controllers\App\StudentGuardianRelationshipController

            // Http\Controllers\App\GuardianController
            Coverage::gate('app.guardians.index', 'guardians.view'),
            Coverage::gate('app.guardians.create', 'guardians.manage'),
            Coverage::gate('app.guardians.store', 'guardians.manage'),
            Coverage::gate('app.guardians.show', 'guardians.view'),
            Coverage::gate('app.guardians.edit', 'guardians.manage'),
            Coverage::gate('app.guardians.update', 'guardians.manage'),
            Coverage::gate('app.guardians.status', 'guardians.manage'),
            Coverage::gate('app.guardians.contacts.store', 'guardians.manage'),

            // Http\Controllers\App\GuardianAccountLinkController
            Coverage::gate('app.guardians.account-link.search', 'guardians.manage'),
            Coverage::gate('app.guardians.account-link.store', 'guardians.manage'),
            Coverage::gate('app.guardians.account-link.destroy', 'guardians.manage'),

            // Http\Controllers\App\GuardianPortalOffboardingController
            Coverage::gate('app.guardians.portal-offboard', ['guardians.manage', 'school.members.manage']),

            // Http\Controllers\App\GuardianAccountInvitationController
            Coverage::gate('app.guardians.account-invitation.store', ['guardians.manage', 'school.members.manage']),
            Coverage::gate('app.guardians.account-invitation.resend', ['guardians.manage', 'school.members.manage']),
            Coverage::gate('app.guardians.account-invitation.destroy', ['guardians.manage', 'school.members.manage']),

            // Http\Controllers\App\GuardianCommunicationPreferenceController
            Coverage::gate('app.guardians.communication-preference.update', ['communications.manage', 'guardians.manage']),
            Coverage::gate('app.guardians.communication-consent.record', ['communications.manage', 'guardians.manage']),

            // Http\Controllers\App\GuardianController
            Coverage::gate('app.contacts.primary', 'guardians.manage'),
            Coverage::gate('app.contacts.deactivate', 'guardians.manage'),

            // Http\Controllers\App\Portal\GuardianCommunicationController
            Coverage::owned('app.portal.communications.index', ActingGuardianResolver::class, 'require', 'Guardian scope: the acting Guardian\'s linked Students'),
            Coverage::owned('app.portal.communications.show', ActingGuardianResolver::class, 'require', 'Guardian scope: the acting Guardian\'s linked Students'),
            Coverage::owned('app.portal.communications.attachments.download', ActingGuardianResolver::class, 'require', 'Guardian scope: the acting Guardian\'s linked Students'),

            // Http\Controllers\App\Portal\GuardianAttendanceController
            Coverage::owned('app.portal.attendance.index', ActingGuardianResolver::class, 'require', 'Guardian scope: the acting Guardian\'s linked Students'),
            Coverage::owned('app.portal.attendance.show', ActingGuardianResolver::class, 'require', 'Guardian scope: the acting Guardian\'s linked Students'),

            // Http\Controllers\App\Portal\GuardianFeeController
            Coverage::owned('app.portal.fees.index', ActingGuardianResolver::class, 'require', 'Guardian scope: the acting Guardian\'s linked Students'),
            Coverage::owned('app.portal.fees.show', ActingGuardianResolver::class, 'require', 'Guardian scope: the acting Guardian\'s linked Students'),
            Coverage::owned('app.portal.fees.payment', ActingGuardianResolver::class, 'require', 'Guardian scope: the acting Guardian\'s linked Students'),

            // Http\Controllers\App\Portal\GuardianConversationController
            Coverage::owned('app.portal.conversations.index', ActingGuardianResolver::class, 'require', 'Guardian scope: the acting Guardian\'s linked Students'),
            Coverage::owned('app.portal.conversations.show', ActingGuardianResolver::class, 'require', 'Guardian scope: the acting Guardian\'s linked Students'),
            Coverage::owned('app.portal.conversations.reply', ActingGuardianResolver::class, 'require', 'Guardian scope: the acting Guardian\'s linked Students'),
            Coverage::owned('app.portal.conversations.attachments.download', ActingGuardianResolver::class, 'require', 'Guardian scope: the acting Guardian\'s linked Students'),

            // Domain\Communications\Http\Controllers\CommunicationInboxController
            Coverage::gate('app.communications.index', 'communications.view'),
            Coverage::gate('app.communications.unread', 'communications.view'),
            Coverage::gate('app.communications.sent', 'communications.view'),
            Coverage::gate('app.communications.failed', 'communications.manage'),
            Coverage::gate('app.communications.search', 'communications.view'),

            // Domain\Communications\Http\Controllers\CommunicationHubController
            Coverage::gate('app.communications.conversations', 'communications.view'),
            Coverage::gate('app.communications.store', 'communications.send'),

            // Domain\Communications\Http\Controllers\AnnouncementController
            Coverage::gate('app.communications.announcements.index', 'communications.view'),
            Coverage::gate('app.communications.announcements.create', 'communications.announce'),
            Coverage::gate('app.communications.announcements.store', 'communications.announce'),
            Coverage::gate('app.communications.announcements.show', 'communications.view'),
            Coverage::gate('app.communications.announcements.update', 'communications.announce'),
            Coverage::gate('app.communications.announcements.publish', 'communications.announce'),
            Coverage::gate('app.communications.announcements.cancel', 'communications.announce'),
            Coverage::gate('app.communications.announcements.schedule', 'communications.announce'),

            // Domain\Communications\Http\Controllers\CommunicationAttachmentController
            Coverage::owned('app.communications.announcements.attachments.store', CommunicationAttachmentController::class, 'authorizeEditor', 'announcement creator, or communications.manage', 'communications.announce'),
            Coverage::owned('app.communications.announcements.attachments.destroy', CommunicationAttachmentController::class, 'authorizeEditor', 'announcement creator, or communications.manage', 'communications.announce'),

            // Domain\Communications\Http\Controllers\CommunicationAuditController
            Coverage::gate('app.communications.announcements.audit', 'communications.audit.view'),

            // Domain\Communications\Http\Controllers\CommunicationAnalyticsController
            Coverage::gate('app.communications.announcements.analytics', 'communications.manage'),

            // Domain\Communications\Http\Controllers\AnnouncementController
            Coverage::gate('app.communications.announcements.submit-approval', 'communications.announce'),
            Coverage::gate('app.communications.announcements.withdraw-approval', 'communications.announce'),

            // Domain\Communications\Http\Controllers\CommunicationApprovalController
            Coverage::gate('app.communications.approvals.index', 'communications.approve'),
            Coverage::gate('app.communications.approvals.show', 'communications.approve'),
            Coverage::gate('app.communications.approvals.approve', 'communications.approve'),
            Coverage::gate('app.communications.approvals.reject', 'communications.approve'),

            // Domain\Communications\Http\Controllers\CommunicationAttachmentController
            Coverage::gate('app.communications.attachments.download', 'communications.view'),

            // Domain\Communications\Http\Controllers\CommunicationTemplateController
            Coverage::gate('app.communications.templates.index', 'communications.templates.manage'),
            Coverage::gate('app.communications.templates.create', 'communications.templates.manage'),
            Coverage::gate('app.communications.templates.store', 'communications.templates.manage'),
            Coverage::gate('app.communications.templates.edit', 'communications.templates.manage'),
            Coverage::gate('app.communications.templates.update', 'communications.templates.manage'),
            Coverage::gate('app.communications.templates.activate', 'communications.templates.manage'),
            Coverage::gate('app.communications.templates.deactivate', 'communications.templates.manage'),

            // Domain\Communications\Http\Controllers\CommunicationPreferenceController
            Coverage::owned('app.communications.preferences', CommunicationPreferenceController::class, 'currentMembership', 'own membership: the actor\'s own email preference')->forEveryMember(),
            Coverage::owned('app.communications.preferences.update', CommunicationPreferenceController::class, 'currentMembership', 'own membership: the actor\'s own email preference')->forEveryMember(),

            // Domain\Communications\Http\Controllers\CommunicationChannelPolicyController
            Coverage::gate('app.communications.settings.channels', 'communications.manage'),
            Coverage::gate('app.communications.settings.channels.update', 'communications.manage'),

            // Domain\Communications\Http\Controllers\CommunicationDeliveryTimingPolicyController
            Coverage::gate('app.communications.settings.timing.update', 'communications.manage'),

            // Domain\Communications\Http\Controllers\CommunicationApprovalPolicyController
            Coverage::gate('app.communications.settings.approvals.update', 'communications.manage'),

            // Domain\Communications\Http\Controllers\CommunicationConversationPolicyController
            Coverage::gate('app.communications.settings.conversations.update', 'communications.manage'),

            // Domain\Communications\Http\Controllers\CommunicationHubController
            Coverage::gate('app.communications.participants.search', 'communications.send'),
            Coverage::gate('app.communications.participants.search-guardians', 'communications.conversations.guardians'),
            Coverage::gate('app.communications.participants.search-students', 'communications.conversations.students'),

            // Domain\Communications\Http\Controllers\CommunicationAudienceSearchController
            Coverage::gate('app.communications.audience.students.search', 'communications.announce'),
            Coverage::gate('app.communications.audience.guardians.search', 'communications.announce'),
            Coverage::gate('app.communications.audience.grade-levels.search', 'communications.announce'),
            Coverage::gate('app.communications.audience.sections.search', 'communications.announce'),
            Coverage::gate('app.communications.audience.subject-offerings.search', 'communications.announce'),

            // Domain\Communications\Http\Controllers\CommunicationAnalyticsController
            Coverage::gate('app.communications.analytics', 'communications.manage'),

            // Domain\Communications\Http\Controllers\CommunicationHubController
            Coverage::gate('app.communications.show', 'communications.view'),
            Coverage::gate('app.communications.messages.store', 'communications.reply'),
            Coverage::gate('app.communications.archive', 'communications.view'),
            Coverage::gate('app.communications.unarchive', 'communications.view'),

            // Domain\Communications\Http\Controllers\CommunicationAttachmentController
            Coverage::gate('app.communications.threads.attachments.store', 'communications.reply'),
            Coverage::gate('app.communications.threads.attachments.destroy', 'communications.reply'),

            // Http\Controllers\App\StudentEnrollmentController
            Coverage::campus('app.enrollments.index', 'enrollments.view', StudentAccess::class, 'enrollmentDirectory'),

            // Http\Controllers\App\ClosedYearPlacementController

            // Http\Controllers\App\StudentEnrollmentController

            // Http\Controllers\App\EnrollmentRolloverController

            // Http\Controllers\App\EnrollmentRolloverMappingController

            // Http\Controllers\App\EnrollmentRolloverSubjectMappingController

            // Http\Controllers\App\EnrollmentRolloverItemController

            // Http\Controllers\App\EnrollmentRolloverBulkDecisionController

            // Http\Controllers\App\SubjectOfferingController
            Coverage::gate('app.subject-offerings.index', 'academics.subjects.view'),

            // Http\Controllers\App\SubjectOfferingAdminController
            Coverage::gate('app.subject-offerings.store', 'academics.subjects.manage'),

            // Http\Controllers\App\SubjectOfferingController
            Coverage::gate('app.subject-offerings.show', 'academics.subjects.view'),

            // Http\Controllers\App\SubjectOfferingAdminController
            Coverage::gate('app.subject-offerings.update', 'academics.subjects.manage'),

            // Http\Controllers\App\StudentSubjectEnrollmentController
            Coverage::gate('app.subject-offerings.eligible-students', 'academics.subjects.manage'),
            Coverage::gate('app.subject-offerings.enrollments.store', 'academics.subjects.manage'),
            Coverage::gate('app.subject-enrollments.withdraw', 'academics.subjects.manage'),
            Coverage::gate('app.subject-enrollments.cancel', 'academics.subjects.manage'),
            Coverage::gate('app.subject-enrollments.transfer', 'academics.subjects.manage'),
            Coverage::gate('app.subject-enrollments.transfer-targets', 'academics.subjects.manage'),

            // Http\Controllers\App\AdmissionApplicationController
            // ORG.7D-3 (ADR 0076 §39): pre-conversion Admissions is Campus-adopted.
            Coverage::campus('app.admissions.index', 'admissions.view', AdmissionApplicationAccess::class, 'directory'),
            Coverage::campus('api.v1.schools.admission-applications.submit', 'admissions.manage', AdmissionApplicationAccess::class, 'application'),
            Coverage::campus('api.v1.schools.admission-applications.accept', 'admissions.manage', AdmissionApplicationAccess::class, 'application'),
            Coverage::campus('api.v1.schools.admission-applications.reject', 'admissions.manage', AdmissionApplicationAccess::class, 'application'),
            Coverage::campus('api.v1.schools.admission-applications.withdraw', 'admissions.manage', AdmissionApplicationAccess::class, 'application'),

            // Http\Controllers\App\Fees\OperationalFeeController

            // Http\Controllers\App\ApplicantController

            // Http\Controllers\App\AdmissionApplicationController
            Coverage::campus('app.admissions.show', 'admissions.view', AdmissionApplicationAccess::class, 'detail'),
            Coverage::campus('app.admissions.submit', 'admissions.manage', AdmissionApplicationAccess::class, 'application'),
            Coverage::campus('app.admissions.accept', 'admissions.manage', AdmissionApplicationAccess::class, 'application'),
            Coverage::campus('app.admissions.reject', 'admissions.manage', AdmissionApplicationAccess::class, 'application'),
            Coverage::campus('app.admissions.withdraw', 'admissions.manage', AdmissionApplicationAccess::class, 'application'),

            // Http\Controllers\App\LibraryCatalogueController
            Coverage::gate('app.library.titles.index', 'library.catalogue.view'),
            Coverage::gate('app.library.titles.create', 'library.catalogue.manage'),
            Coverage::gate('app.library.titles.store', 'library.catalogue.manage'),
            Coverage::gate('app.library.titles.show', 'library.catalogue.view'),
            Coverage::gate('app.library.titles.copies.store', 'library.catalogue.manage'),
            Coverage::gate('app.library.copies.update', 'library.catalogue.manage'),

            // Http\Controllers\App\LibraryCirculationController
            // ORG.7D-5 (ADR 0076 §41): Http\Controllers\App\LibraryCirculationController
            Coverage::campus('app.library.circulation.index', 'library.circulation.view', LibraryCirculationAccess::class, 'loans'),
            Coverage::campusLookup('app.library.circulation.create', 'library.circulation.manage', 'Data-free form page; Copies and borrowers come from the scoped searches.'),
            Coverage::campus('app.library.circulation.search-copies', 'library.circulation.manage', LibraryCirculationAccess::class, 'copies'),
            Coverage::campus('app.library.circulation.search-students', 'library.circulation.manage', LibraryCirculationAccess::class, 'borrowerCandidates'),
            Coverage::campus('app.library.circulation.store', 'library.circulation.manage', LibraryCirculationAccess::class, 'writeGuard'),
            Coverage::campus('app.library.circulation.check-in', 'library.circulation.manage', LibraryCirculationAccess::class, 'writeGuard'),

            // Http\Controllers\App\Fees\LibraryFineWebController
            Coverage::campus('app.library.circulation.show', 'library.circulation.view', LibraryCirculationAccess::class, 'loan'),
            Coverage::gate('app.library.fine-policy', 'library.fines.view'),
            Coverage::gate('app.library.fine-policy.publish', 'library.fines.manage'),
            Coverage::gate('app.library.fines.void', 'library.fines.void'),

            // Http\Controllers\App\TransportRouteController
            Coverage::campus('app.transport.routes.index', 'transport.routes.view', TransportRiderAccess::class, 'routes'),
            Coverage::gate('app.transport.routes.create', 'transport.routes.manage'),
            Coverage::gate('app.transport.routes.store', 'transport.routes.manage'),
            Coverage::campus('app.transport.routes.show', 'transport.routes.view', TransportRiderAccess::class, 'scopedRoute'),
            Coverage::gate('app.transport.routes.update', 'transport.routes.manage'),
            Coverage::gate('app.transport.routes.stops.store', 'transport.routes.manage'),

            // Http\Controllers\App\Fees\OperationalFeeController
            Coverage::gate('app.transport.routes.fee-head.update', 'transport.routes.manage'),

            // Http\Controllers\App\TransportRouteController
            Coverage::gate('app.transport.stops.update', 'transport.routes.manage'),

            // Http\Controllers\App\TransportVehicleController
            Coverage::gate('app.transport.vehicles.index', 'transport.vehicles.view'),
            Coverage::gate('app.transport.vehicles.create', 'transport.vehicles.manage'),
            Coverage::gate('app.transport.vehicles.store', 'transport.vehicles.manage'),
            Coverage::gate('app.transport.vehicles.update', 'transport.vehicles.manage'),

            // Http\Controllers\App\TransportOperationsController
            Coverage::gate('app.transport.operations.index', 'transport.vehicles.view'),
            Coverage::gate('app.transport.operations.search-vehicles', 'transport.vehicles.manage'),
            Coverage::gate('app.transport.operations.search-drivers', 'transport.vehicles.manage'),
            Coverage::gate('app.transport.operations.show', 'transport.vehicles.view'),
            Coverage::gate('app.transport.operations.store', 'transport.vehicles.manage'),
            Coverage::gate('app.transport.route-assignments.end', 'transport.vehicles.manage'),

            // Http\Controllers\App\TransportStudentAssignmentController
            Coverage::campus('app.transport.assignments.index', 'transport.assignments.view', TransportRiderAccess::class, 'assignments'),
            Coverage::campusLookup('app.transport.assignments.create', 'transport.assignments.manage', 'Data-free form page; Students, routes and stops come from the scoped searches.'),
            Coverage::campus('app.transport.assignments.search-students', 'transport.assignments.manage', TransportRiderAccess::class, 'riderCandidates'),
            Coverage::campus('app.transport.assignments.search-routes', 'transport.assignments.manage', TransportRiderAccess::class, 'routes'),
            Coverage::campusLookup('app.transport.assignments.import.create', 'transport.assignments.manage', 'Data-free upload page (limits and column names only).'),
            Coverage::campusLookup('app.transport.assignments.import.template', 'transport.assignments.manage', 'A blank template: the header and one placeholder line, no School data.'),
            Coverage::campus('app.transport.assignments.import.preview', 'transport.assignments.manage', TransportRiderImport::class, 'preview'), // scoped via TransportRiderAccess::routes()/assignments()
            Coverage::campus('app.transport.assignments.import.commit', 'transport.assignments.manage', TransportRiderImport::class, 'commit'), // + TransportRiderAccess::writeGuard() and fresh authority
            Coverage::campus('app.transport.assignments.route-stops', 'transport.assignments.manage', TransportRiderAccess::class, 'scopedRoute'),
            Coverage::campus('app.transport.assignments.store', 'transport.assignments.manage', TransportRiderAccess::class, 'writeGuard'),
            Coverage::campus('app.transport.assignments.end', 'transport.assignments.manage', TransportRiderAccess::class, 'writeGuard'),

            // Http\Controllers\App\VisitorController
            Coverage::gate('app.visitor.directory.index', 'visitor.directory.view'),
            Coverage::gate('app.visitor.directory.create', 'visitor.directory.manage'),
            Coverage::gate('app.visitor.directory.store', 'visitor.directory.manage'),
            Coverage::gate('app.visitor.directory.update', 'visitor.directory.manage'),

            // Http\Controllers\App\VisitorVisitController
            Coverage::campus('app.visitor.visits.index', 'visitor.visits.view', VisitorVisitAccess::class, 'visits'),
            Coverage::campus('app.visitor.visits.create', 'visitor.visits.manage', VisitorVisitAccess::class, 'campusesForNewVisit'),
            Coverage::campusLookup('app.visitor.visits.search-visitors', 'visitor.visits.manage', 'Check-in lookup of the School-wide Visitor directory (CampusScopeCatalogue::DOMAINS visitor sharedLookups).'),
            Coverage::campusLookup('app.visitor.visits.search-hosts', 'visitor.visits.manage', 'Check-in lookup of active host Employees, names only (CampusScopeCatalogue::DOMAINS visitor sharedLookups).'),
            Coverage::campus('app.visitor.visits.store', 'visitor.visits.manage', VisitorVisitAccess::class, 'campusForNewVisit'),
            Coverage::campus('app.visitor.visits.end', 'visitor.visits.manage', VisitorVisitAccess::class, 'visit'),

            // Http\Controllers\App\Finance\FinanceController
            Coverage::gate('app.finance.index', ['finance.ledger.view', 'finance.charges.view', 'finance.payments.view', 'finance.payments.record', 'finance.fee_structures.view', 'finance.fee_concessions.view', 'finance.payment_corrections.view']),

            // Http\Controllers\App\Finance\LedgerAccountController
            Coverage::gate('app.finance.ledger-accounts.index', 'finance.ledger.view'),
            Coverage::gate('app.finance.ledger-accounts.ledger', 'finance.ledger.view'),
            Coverage::gate('app.finance.ledger-accounts.store', 'finance.accounts.manage'),
            Coverage::gate('app.finance.ledger-accounts.status', 'finance.accounts.manage'),

            // Http\Controllers\App\Finance\FeeSetupController
            Coverage::gate('app.finance.fee-setup.index', 'finance.fee_structures.view'),
            Coverage::gate('app.finance.fee-setup.fee-heads.store', 'finance.fee_structures.manage'),
            Coverage::gate('app.finance.fee-setup.receipt-numbering', 'finance.fee_structures.manage'),
            Coverage::gate('app.finance.fee-setup.fee-heads.update', 'finance.fee_structures.manage'),
            Coverage::gate('app.finance.fee-setup.structures.store', 'finance.fee_structures.manage'),
            Coverage::gate('app.finance.fee-setup.students.search', 'finance.fee_structures.manage'),
            Coverage::gate('app.finance.fee-setup.structures.show', 'finance.fee_structures.view'),
            Coverage::gate('app.finance.fee-setup.structures.update', 'finance.fee_structures.manage'),
            Coverage::gate('app.finance.fee-setup.structures.activate', 'finance.fee_structures.manage'),
            Coverage::gate('app.finance.fee-setup.structures.retire', 'finance.fee_structures.manage'),
            Coverage::gate('app.finance.fee-setup.structures.successor', 'finance.fee_structures.manage'),
            Coverage::gate('app.finance.fee-setup.lines.store', 'finance.fee_structures.manage'),
            Coverage::gate('app.finance.fee-setup.lines.update', 'finance.fee_structures.manage'),
            Coverage::gate('app.finance.fee-setup.lines.remove', 'finance.fee_structures.manage'),
            Coverage::gate('app.finance.fee-setup.lines.installments', 'finance.fee_structures.manage'),
            Coverage::gate('app.finance.fee-setup.lines.generate', 'finance.fee_structures.manage'),
            Coverage::gate('app.finance.fee-setup.selections.store', 'finance.fee_structures.manage'),
            Coverage::gate('app.finance.fee-setup.selections.withdraw', 'finance.fee_structures.manage'),

            // Http\Controllers\App\Finance\FeeAssessmentRunController
            Coverage::gate('app.finance.fee-runs.store', 'finance.fee_assessments.run'),
            Coverage::gate('app.finance.fee-runs.preview', 'finance.fee_assessments.run'),
            Coverage::gate('app.finance.fee-runs.items.exclude', 'finance.fee_assessments.run'),
            Coverage::gate('app.finance.fee-runs.items.decide', 'finance.fee_assessments.run')->validatesFirst(),
            Coverage::gate('app.finance.fee-runs.execute', 'finance.fee_assessments.run'),
            Coverage::gate('app.finance.fee-runs.resume', 'finance.fee_assessments.run'),
            Coverage::gate('app.finance.fee-runs.cancel', 'finance.fee_assessments.run'),
            Coverage::gate('app.finance.fee-assessments.void', 'finance.charges.manage'),

            // Http\Controllers\App\Finance\LateFeeController
            Coverage::gate('app.finance.late-fees.rules', 'finance.fee_structures.view'),
            Coverage::gate('app.finance.late-fees.rules.store', 'finance.fee_structures.manage'),
            Coverage::gate('app.finance.late-fees.rules.update', 'finance.fee_structures.manage'),
            Coverage::gate('app.finance.late-fees.rules.status', 'finance.fee_structures.manage'),
            Coverage::gate('app.finance.late-fee-runs.store', 'finance.fee_assessments.run'),
            Coverage::gate('app.finance.late-fee-runs.preview', 'finance.fee_assessments.run'),
            Coverage::gate('app.finance.late-fee-runs.execute', 'finance.fee_assessments.run'),
            Coverage::gate('app.finance.late-fee-runs.resume', 'finance.fee_assessments.run'),
            Coverage::gate('app.finance.late-fee-runs.cancel', 'finance.fee_assessments.run'),
            Coverage::gate('app.finance.late-fee-assessments.void', 'finance.charges.manage'),

            // Http\Controllers\App\Finance\FeeConcessionController
            Coverage::gate('app.finance.concessions.index', 'finance.fee_concessions.view'),
            Coverage::gate('app.finance.concessions.create', 'finance.fee_concessions.request'),
            Coverage::gate('app.finance.concessions.students.search', 'finance.fee_concessions.request'),
            Coverage::gate('app.finance.concessions.store', 'finance.fee_concessions.request'),
            Coverage::gate('app.finance.concessions.settings', 'finance.fee_structures.manage'),
            Coverage::gate('app.finance.concessions.show', 'finance.fee_concessions.view'),
            Coverage::gate('app.finance.concessions.withdraw', 'finance.fee_concessions.request'),
            Coverage::gate('app.finance.concessions.approve', 'finance.fee_concessions.approve'),
            Coverage::gate('app.finance.concessions.reject', 'finance.fee_concessions.approve'),
            Coverage::gate('app.finance.concessions.revoke', 'finance.fee_concessions.approve'),
            Coverage::gate('app.finance.fee-adjustments.cancel', 'finance.fee_concessions.approve'),

            // Http\Controllers\App\Finance\FinancialPeriodController
            Coverage::gate('app.finance.periods.index', 'finance.ledger.view'),
            Coverage::gate('app.finance.periods.close', 'finance.periods.manage'),

            // Http\Controllers\App\Finance\JournalEntryController
            Coverage::gate('app.finance.journal-entries.create', 'finance.ledger.post'),
            Coverage::gate('app.finance.journal-entries.index', 'finance.ledger.view'),
            Coverage::gate('app.finance.journal-entries.store', 'finance.ledger.post'),
            Coverage::gate('app.finance.journal-entries.show', 'finance.ledger.view'),
            Coverage::gate('app.finance.journal-entries.reverse', 'finance.ledger.reverse'),

            // Http\Controllers\App\Finance\ChargeController
            Coverage::gate('app.finance.charges.create', 'finance.charges.manage'),
            Coverage::gate('app.finance.charges.students.search', 'finance.charges.manage'),
            // ORG.7G-1 (ADR 0076 §50): Campus-aware Finance reads (stored Charge Campus).
            Coverage::campus('app.finance.charges.index', 'finance.charges.view', FinanceCampusAccess::class, 'charges'),
            Coverage::gate('app.finance.charges.store', 'finance.charges.manage'),
            Coverage::campus('app.finance.charges.show', 'finance.charges.view', FinanceCampusAccess::class, 'charge'),
            Coverage::gate('app.finance.charges.cancel', 'finance.charges.manage'),

            // Http\Controllers\App\Finance\ManualPaymentController
            // ORG.7G-2 (ADR 0076 §51): Campus payment entry -- one stored Campus, in scope, locked.
            Coverage::campus('app.finance.payments.record.create', 'finance.payments.record', ManualPaymentRecordingService::class, 'outstandingChargesForStudent'),
            Coverage::campus('app.finance.payments.record.students.search', 'finance.payments.record', FinanceCampusAccess::class, 'collectionDebtors'),
            Coverage::campus('app.finance.payments.record.store', 'finance.payments.record', ManualPaymentRecordingService::class, 'record'),

            // Http\Controllers\App\Finance\PaymentRecordController (ORG.8C: correction requests carry capability: middleware)
            // ORG.7G-1: Payment visibility -- one stored Campus across every touched Charge, in scope.
            Coverage::campus('app.finance.payments.index', 'finance.payments.view', FinanceCampusAccess::class, 'collections'),
            Coverage::campus('app.finance.payments.show', 'finance.payments.view', FinanceCampusAccess::class, 'payment'),
            Coverage::campus('app.finance.payments.receipt', 'finance.payments.view', FinanceCampusAccess::class, 'receipt'),

            // Http\Controllers\App\Finance\OutstandingFeesController
            Coverage::campus('app.finance.outstanding.index', 'finance.charges.view', FinanceCampusAccess::class, 'outstandingFees'),
            Coverage::campus('app.finance.debtors.search', 'finance.charges.view', FinanceCampusAccess::class, 'debtors'),

            // Http\Controllers\App\Finance\FeeStatementController

            // Http\Controllers\App\HR\HrController
            Coverage::gate('app.hr.index', ['hr.employees.view', 'hr.employees.manage', 'hr.departments.view', 'hr.positions.view', 'hr.categories.view']),

            // Http\Controllers\App\HR\HrEmployeeController -- ORG.7E-1 (ADR 0076 §43):
            // identity-only create, archive/restore, import, employment
            // lifecycle, rehire and account links carry their declared
            // whole-School `capability:` floor (C1).
            Coverage::campus('app.hr.employees.index', 'hr.employees.view', EmployeeDirectoryService::class, 'search'),
            Coverage::campus('app.hr.employees.onboard', 'hr.employees.manage', HrEmployeeAccess::class, 'campusOptions'),
            Coverage::campus('app.hr.employees.onboard.store', 'hr.employees.manage', EmployeeOnboardingService::class, 'onboard'),

            // Http\Controllers\App\HR\HrEmployeeController
            Coverage::service('app.hr.employees.show', EmployeeProfileWorkspaceService::class, 'build'),
            Coverage::campus('app.hr.employees.overview', 'hr.employees.view', EmployeeOperationalOverviewService::class, 'forEmployee'),
            Coverage::campus('app.hr.employees.edit', 'hr.employees.manage', HrEmployeeAccess::class, 'employee'),
            Coverage::campus('app.hr.employees.update', 'hr.employees.manage', HrEmployeeAccess::class, 'employee'),

            // Http\Controllers\App\HR\HrEmployeePersonalDetailController
            Coverage::gate('app.hr.employees.personal-detail.update', 'hr.employees.personal.manage'),

            // Http\Controllers\App\HR\HrEmployeeAddressController
            Coverage::gate('app.hr.employees.addresses.store', 'hr.employees.personal.manage'),
            Coverage::gate('app.hr.employees.addresses.destroy', 'hr.employees.personal.manage'),

            // Http\Controllers\App\HR\HrEmployeeEmergencyContactController
            Coverage::gate('app.hr.employees.emergency-contacts.store', 'hr.employees.personal.manage'),
            Coverage::gate('app.hr.employees.emergency-contacts.destroy', 'hr.employees.personal.manage'),
            Coverage::gate('app.hr.employees.emergency-contacts.set-primary', 'hr.employees.personal.manage'),

            // Http\Controllers\App\HR\HrEmployeeNoteController
            Coverage::gate('app.hr.employees.notes.store', 'hr.employees.notes.manage'),
            Coverage::gate('app.hr.employees.notes.destroy', 'hr.employees.notes.manage'),

            // Http\Controllers\App\HR\HrEmployeeAssignmentController (ORG.7E-1: Campus-adopted)
            Coverage::campus('app.hr.employees.assignments.store', 'hr.employees.assignments.manage', HrEmployeeAccess::class, 'employment'),
            Coverage::campus('app.hr.employees.assignments.end', 'hr.employees.assignments.manage', HrEmployeeAccess::class, 'assignment'),
            Coverage::campus('app.hr.employees.assignments.set-primary', 'hr.employees.assignments.manage', HrEmployeeAccess::class, 'assignment'),
            Coverage::campus('app.hr.employees.assignments.set-manager', 'hr.employees.assignments.manage', HrEmployeeAccess::class, 'assignment'),
            Coverage::campus('app.hr.employees.assignments.manager-candidates', 'hr.employees.assignments.manage', HrEmployeeAccess::class, 'assignment'),

            // Http\Controllers\App\HR\HrEmployeeAccountLinkController: declared whole-School floors (C1, ORG.7E-1).

            // Http\Controllers\App\HR\HrEmployeeQualificationController
            Coverage::gate('app.hr.employees.qualifications.store', 'hr.employees.qualifications.manage'),
            Coverage::gate('app.hr.employees.qualifications.destroy', 'hr.employees.qualifications.manage'),
            Coverage::gate('app.hr.employees.qualifications.verify', 'hr.employees.qualifications.manage'),
            Coverage::gate('app.hr.employees.qualifications.reject', 'hr.employees.qualifications.manage'),

            // Http\Controllers\App\HR\HrEmployeeExperienceController
            Coverage::gate('app.hr.employees.experience.store', 'hr.employees.qualifications.manage'),
            Coverage::gate('app.hr.employees.experience.destroy', 'hr.employees.qualifications.manage'),

            // Http\Controllers\App\HR\HrEmployeeCertificationController
            Coverage::gate('app.hr.employees.certifications.store', 'hr.employees.qualifications.manage'),
            Coverage::gate('app.hr.employees.certifications.destroy', 'hr.employees.qualifications.manage'),
            Coverage::gate('app.hr.employees.certifications.verify', 'hr.employees.qualifications.manage'),
            Coverage::gate('app.hr.employees.certifications.reject', 'hr.employees.qualifications.manage'),

            // Http\Controllers\App\HR\HrEmployeeDocumentController
            Coverage::service('app.hr.employees.hr-document-records.store', HighlySensitiveDocumentStepUp::class, 'requireFor'),
            Coverage::service('app.hr.employees.hr-document-records.archive', HighlySensitiveDocumentStepUp::class, 'requireFor'),

            // Http\Controllers\App\HR\HrDepartmentController
            Coverage::gate('app.hr.departments.index', 'hr.departments.view'),
            Coverage::gate('app.hr.departments.store', 'hr.departments.manage'),
            Coverage::gate('app.hr.departments.archive', 'hr.departments.manage'),
            Coverage::gate('app.hr.departments.reactivate', 'hr.departments.manage'),

            // Http\Controllers\App\HR\HrPositionController
            Coverage::gate('app.hr.positions.index', 'hr.positions.view'),
            Coverage::gate('app.hr.positions.store', 'hr.positions.manage'),
            Coverage::gate('app.hr.positions.archive', 'hr.positions.manage'),
            Coverage::gate('app.hr.positions.reactivate', 'hr.positions.manage'),

            // Http\Controllers\App\HR\HrEmployeeCategoryController
            Coverage::gate('app.hr.categories.index', 'hr.categories.view'),
            Coverage::gate('app.hr.categories.store', 'hr.categories.manage'),
            Coverage::gate('app.hr.categories.archive', 'hr.categories.manage'),
            Coverage::gate('app.hr.categories.reactivate', 'hr.categories.manage'),

            // Http\Controllers\App\HostelController
            Coverage::gate('app.hostels.index', 'hostel.directory.view'),
            Coverage::gate('app.hostels.create', 'hostel.directory.manage'),
            Coverage::gate('app.hostels.store', 'hostel.directory.manage'),
            Coverage::gate('app.hostels.show', 'hostel.directory.view'),
            Coverage::gate('app.hostels.update', 'hostel.directory.manage'),
            Coverage::gate('app.hostels.rooms.store', 'hostel.directory.manage'),

            // Http\Controllers\App\Fees\OperationalFeeController
            Coverage::gate('app.hostels.fee-head.update', 'hostel.directory.manage'),

            // Http\Controllers\App\HostelRoomController
            Coverage::gate('app.hostel-rooms.show', 'hostel.directory.view'),
            Coverage::gate('app.hostel-rooms.update', 'hostel.directory.manage'),
            Coverage::gate('app.hostel-rooms.beds.store', 'hostel.directory.manage'),

            // Http\Controllers\App\Fees\OperationalFeeController
            Coverage::gate('app.hostel-rooms.fee-head.update', 'hostel.directory.manage'),

            // Http\Controllers\App\HostelRoomController
            Coverage::gate('app.hostel-beds.update', 'hostel.directory.manage'),

            // Http\Controllers\App\HostelResidencyController
            Coverage::campus('app.hostel-residency.index', 'hostel.residency.view', HostelResidencyAccess::class, 'residencies'),
            Coverage::campusLookup('app.hostel-residency.create', 'hostel.residency.manage', 'Data-free form page; Students and Beds come from the scoped searches.'),
            Coverage::campus('app.hostel-residency.search-students', 'hostel.residency.manage', HostelResidencyAccess::class, 'residentCandidates'),
            Coverage::campus('app.hostel-residency.search-beds', 'hostel.residency.manage', HostelResidencyAccess::class, 'beds'),

            // Http\Controllers\App\Fees\OperationalFeeController

            // Http\Controllers\App\HostelResidencyController
            Coverage::campus('app.hostel-residency.store', 'hostel.residency.manage', HostelResidencyAccess::class, 'writeGuard'),
            Coverage::campus('app.hostel-residency.end', 'hostel.residency.manage', HostelResidencyAccess::class, 'residency'),

            // Http\Controllers\App\InventoryItemController
            Coverage::gate('app.inventory-items.index', 'inventory.directory.view'),
            Coverage::gate('app.inventory-items.create', 'inventory.directory.manage'),
            Coverage::gate('app.inventory-items.store', 'inventory.directory.manage'),
            Coverage::gate('app.inventory-items.update', 'inventory.directory.manage'),

            // Http\Controllers\App\InventoryLocationController
            Coverage::gate('app.inventory-locations.index', 'inventory.directory.view'),
            Coverage::gate('app.inventory-locations.create', 'inventory.directory.manage'),
            Coverage::gate('app.inventory-locations.store', 'inventory.directory.manage'),
            Coverage::gate('app.inventory-locations.update', 'inventory.directory.manage'),

            // Http\Controllers\App\InventoryStockController
            Coverage::campus('app.inventory-stock.index', 'inventory.stock.view', InventoryStockAccess::class, 'balances'),
            Coverage::campusLookup('app.inventory-stock.receive.create', 'inventory.stock.manage', 'Data-free form page; Locations come from the scoped search (search-locations).'),
            Coverage::campus('app.inventory-stock.receive.store', 'inventory.stock.manage', InventoryStockAccess::class, 'location'),
            Coverage::campusLookup('app.inventory-stock.issue.create', 'inventory.stock.manage', 'Data-free form page; Locations come from the scoped search (search-locations).'),
            Coverage::campus('app.inventory-stock.issue.store', 'inventory.stock.manage', InventoryStockAccess::class, 'location'),
            Coverage::campusLookup('app.inventory-stock.transfer.create', 'inventory.stock.manage', 'Data-free form page; Locations come from the scoped search (search-locations).'),
            Coverage::campus('app.inventory-stock.transfer.store', 'inventory.stock.manage', InventoryStockAccess::class, 'location'),
            Coverage::campusLookup('app.inventory-stock.search-items', 'inventory.stock.manage', 'Lookup of the School-wide Item catalogue (CampusScopeCatalogue::DOMAINS inventory sharedLookups).'),
            Coverage::campus('app.inventory-stock.search-locations', 'inventory.stock.manage', InventoryStockAccess::class, 'searchLocations'),
            Coverage::campusLookup('app.inventory-stock.write-off.create', 'inventory.stock.manage', 'Data-free form page (reasons are code-owned); Locations come from the scoped search (search-locations).'),
            Coverage::campus('app.inventory-stock.write-off.store', 'inventory.stock.manage', InventoryStockAccess::class, 'location'),
            Coverage::campus('app.inventory-stock.register', 'inventory.stock.view', InventoryStockHistory::class, 'register'), // scoped via InventoryStockAccess::movements()
            Coverage::campus('app.inventory-stock.monthly', 'inventory.stock.view', InventoryStockHistory::class, 'month'), // scoped via InventoryStockAccess::locations()
            Coverage::campusLookup('app.inventory-stock.search-departments', 'inventory.stock.manage', 'Lookup of active HR Departments (id, code, name): School-wide ones and those on in-scope Campuses (CampusScopeCatalogue::DOMAINS inventory sharedLookups).'),

            // Http\Controllers\App\Canteen\CanteenOutletController
            Coverage::gate('app.canteen-outlets.index', 'canteen.directory.view'),
            Coverage::gate('app.canteen-outlets.create', 'canteen.directory.manage'),
            Coverage::gate('app.canteen-outlets.search-inventory-locations', 'canteen.directory.manage'),
            Coverage::gate('app.canteen-outlets.store', 'canteen.directory.manage'),
            Coverage::gate('app.canteen-outlets.update', 'canteen.directory.manage'),

            // Http\Controllers\App\Canteen\CanteenItemController
            Coverage::gate('app.canteen-items.index', 'canteen.directory.view'),
            Coverage::gate('app.canteen-items.create', 'canteen.directory.manage'),
            Coverage::gate('app.canteen-items.search-inventory-items', 'canteen.directory.manage'),
            Coverage::gate('app.canteen-items.store', 'canteen.directory.manage'),
            Coverage::gate('app.canteen-items.show', 'canteen.directory.view'),
            Coverage::gate('app.canteen-items.update', 'canteen.directory.manage'),
            Coverage::gate('app.canteen-items.recipe.store', 'canteen.directory.manage'),
            Coverage::gate('app.canteen-items.recipe.destroy', 'canteen.directory.manage'),

            // Http\Controllers\App\Canteen\CanteenBillingConfigurationController
            Coverage::gate('app.canteen-settings.edit', 'canteen.settings.view'),
            Coverage::gate('app.canteen-settings.update', 'canteen.settings.manage'),

            // Http\Controllers\App\Canteen\CanteenOrderController
            // ORG.7D-3 (ADR 0076 §39): Canteen orders are Campus-adopted (by the Outlet's Campus).
            Coverage::campus('app.canteen-orders.index', 'canteen.orders.view', CanteenOrderAccess::class, 'orders'),
            Coverage::campus('app.canteen-orders.create', 'canteen.orders.manage', CanteenOrderAccess::class, 'outlets'),
            Coverage::campus('app.canteen-orders.search-students', 'canteen.orders.manage', CanteenOrderAccess::class, 'studentCandidates'),
            Coverage::campusLookup('app.canteen-orders.search-items', 'canteen.orders.manage', 'The order form\'s menu picker: the School-wide Item catalogue (code, name, price; not personal data).'),
            Coverage::campus('app.canteen-orders.store', 'canteen.orders.manage', CanteenOrderAccess::class, 'writeGuard'),
            Coverage::campus('app.canteen-orders.show', 'canteen.orders.view', CanteenOrderAccess::class, 'order'),
            Coverage::campus('app.canteen-orders.fulfill', 'canteen.orders.manage', CanteenOrderAccess::class, 'writeGuard'),
            Coverage::campus('app.canteen-orders.cancel', 'canteen.orders.manage', CanteenOrderAccess::class, 'writeGuard'),
            Coverage::campus('api.v1.schools.canteen-orders.index', 'canteen.orders.view', CanteenOrderAccess::class, 'orders'),
            Coverage::campus('api.v1.schools.canteen-orders.store', 'canteen.orders.manage', CanteenOrderAccess::class, 'writeGuard'),
            Coverage::campus('api.v1.schools.canteen-orders.search-students', 'canteen.orders.manage', CanteenOrderAccess::class, 'studentCandidates'),
            Coverage::campusLookup('api.v1.schools.canteen-orders.search-items', 'canteen.orders.manage', 'The order form\'s menu picker: the School-wide Item catalogue (code, name, price; not personal data).'),
            Coverage::campus('api.v1.schools.canteen-orders.show', 'canteen.orders.view', CanteenOrderAccess::class, 'order'),
            Coverage::campus('api.v1.schools.canteen-orders.fulfill', 'canteen.orders.manage', CanteenOrderAccess::class, 'writeGuard'),
            Coverage::campus('api.v1.schools.canteen-orders.cancel', 'canteen.orders.manage', CanteenOrderAccess::class, 'writeGuard'),

            // Http\Controllers\App\Timetable\TimetablePeriodController
            Coverage::gate('app.timetable-periods.index', 'timetable.periods.view'),
            Coverage::gate('app.timetable-periods.create', 'timetable.periods.manage'),
            Coverage::gate('app.timetable-periods.store', 'timetable.periods.manage'),
            Coverage::gate('app.timetable-periods.edit', 'timetable.periods.manage'),
            Coverage::gate('app.timetable-periods.update', 'timetable.periods.manage'),

            // Http\Controllers\App\Syllabus\SyllabusUnitController
            Coverage::gate('app.syllabus.index', 'syllabus.view'),
            Coverage::gate('app.syllabus.store', 'syllabus.manage'),
            Coverage::gate('app.syllabus.update', 'syllabus.manage'),

            // Http\Controllers\App\CurriculumDelivery\CurriculumDeliveryController
            // ORG.7D-1 (ADR 0076 §37): administrative Curriculum Delivery is Campus-adopted.
            Coverage::campus('app.syllabus-delivery.index', 'curriculum.delivery.view', CurriculumDeliveryAccess::class, 'offerings'),
            Coverage::campus('app.syllabus-delivery.store', 'curriculum.delivery.manage', CurriculumDeliveryAccess::class, 'writeGuard'),
            Coverage::campus('app.syllabus-delivery.update', 'curriculum.delivery.manage', CurriculumDeliveryAccess::class, 'writeGuard'),
            Coverage::campus('app.syllabus-delivery.transition', 'curriculum.delivery.manage', CurriculumDeliveryAccess::class, 'writeGuard'),
            Coverage::campus('api.v1.schools.subject-offerings.curriculum-deliveries.index', 'curriculum.delivery.view', CurriculumDeliveryAccess::class, 'offering'),
            Coverage::campus('api.v1.schools.subject-offerings.curriculum-deliveries.store', 'curriculum.delivery.manage', CurriculumDeliveryAccess::class, 'writeGuard'),
            Coverage::campus('api.v1.schools.curriculum-deliveries.show', 'curriculum.delivery.view', CurriculumDeliveryAccess::class, 'delivery'),
            Coverage::campus('api.v1.schools.curriculum-deliveries.update', 'curriculum.delivery.manage', CurriculumDeliveryAccess::class, 'writeGuard'),
            Coverage::campus('api.v1.schools.curriculum-deliveries.transition', 'curriculum.delivery.manage', CurriculumDeliveryAccess::class, 'writeGuard'),

            // Http\Controllers\App\CurriculumDelivery\MyCurriculumDeliveryController
            Coverage::owned('app.my-curriculum-delivery.index', TeacherDeliveryAccess::class, 'scope', 'teaching ownership: the acting teacher\'s own contexts'),
            Coverage::owned('app.my-curriculum-delivery.store', TeacherDeliveryAccess::class, 'guard', 'teaching ownership: the acting teacher\'s own contexts')->validatesFirst(),
            Coverage::owned('app.my-curriculum-delivery.update', TeacherDeliveryAccess::class, 'guard', 'teaching ownership: the acting teacher\'s own contexts'),
            Coverage::owned('app.my-curriculum-delivery.transition', TeacherDeliveryAccess::class, 'guard', 'teaching ownership: the acting teacher\'s own contexts')->validatesFirst(),

            // Http\Controllers\App\TeachingAssignments\TeachingAssignmentController
            Coverage::gate('app.teaching-assignments.index', 'teaching.assignments.view'),
            Coverage::gate('app.teaching-assignments.store', 'teaching.assignments.manage'),
            Coverage::gate('app.teaching-assignments.end', 'teaching.assignments.manage'),

            // Http\Controllers\App\TeachingAssignments\ElectiveTeachingAssignmentController
            Coverage::gate('app.elective-teaching-assignments.index', 'teaching.assignments.view'),
            Coverage::gate('app.elective-teaching-assignments.store', 'teaching.assignments.manage'),
            Coverage::gate('app.elective-teaching-assignments.end', 'teaching.assignments.manage'),

            // Http\Controllers\App\Leave\LeaveAdminController
            Coverage::gate('app.leave.configuration', 'hr.leave.view'),
            Coverage::service('app.leave.settings.update', LeaveYearService::class, 'setStartMonth')->validatesFirst(),
            Coverage::service('app.leave.year-start-changes.store', LeaveYearService::class, 'scheduleStartChange')->validatesFirst(),
            Coverage::service('app.leave.years.store', LeaveYearService::class, 'open')->validatesFirst(),
            Coverage::service('app.leave.types.store', LeaveTypeService::class, 'create')->validatesFirst(),
            Coverage::service('app.leave.types.status', LeaveTypeService::class, 'setStatus')->validatesFirst(),
            Coverage::service('app.leave.policies.store', LeavePolicyService::class, 'create')->validatesFirst(),
            Coverage::service('app.leave.policies.retire', LeavePolicyService::class, 'retire'),
            Coverage::service('app.leave.calendar.weekdays', StaffCalendarService::class, 'setWeeklyPattern')->validatesFirst(),
            Coverage::service('app.leave.calendar.holidays.store', StaffCalendarService::class, 'addHoliday')->validatesFirst(),
            Coverage::service('app.leave.calendar.holidays.remove', StaffCalendarService::class, 'removeHoliday'),
            Coverage::gate('app.leave.entitlements', 'hr.leave.view'),
            Coverage::service('app.leave.assignments.store', LeavePolicyAssignmentService::class, 'assign')->validatesFirst(),
            Coverage::service('app.leave.assignments.end', LeavePolicyAssignmentService::class, 'end')->validatesFirst(),
            Coverage::service('app.leave.allocations.store', LeaveLedgerService::class, 'allocate')->validatesFirst(),
            Coverage::service('app.leave.adjustments.store', LeaveLedgerService::class, 'adjust')->validatesFirst(),
            Coverage::service('app.leave.allocation-runs.store', LeaveLedgerService::class, 'executeRun')->validatesFirst(),
            Coverage::gate('app.leave.requests', 'hr.leave.view'),
            Coverage::gate('app.leave.monthly', 'hr.leave.view'),
            Coverage::service('app.leave.requests.store', LeaveRequestService::class, 'submitOnBehalf')->validatesFirst(),
            Coverage::gate('app.leave.requests.show', 'hr.leave.view'),
            Coverage::service('app.leave.requests.approve', LeaveRequestService::class, 'approve'),
            Coverage::service('app.leave.requests.reject', LeaveRequestService::class, 'approve')->validatesFirst(),
            Coverage::service('app.leave.requests.withdraw', LeaveRequestService::class, 'approve')->validatesFirst(),
            Coverage::service('app.leave.requests.cancel', LeaveRequestService::class, 'approve')->validatesFirst(),
            Coverage::gate('app.leave.year-close', 'hr.leave.view'),
            Coverage::service('app.leave.year-close.store', LeaveYearCloseService::class, 'execute')->validatesFirst(),

            // Http\Controllers\App\Leave\LeaveApprovalsController
            Coverage::gate('app.leave.approvals', 'hr.leave.approve'),
            Coverage::gate('app.leave.approvals.approve', 'hr.leave.approve'),
            Coverage::gate('app.leave.approvals.reject', 'hr.leave.approve'),

            // Http\Controllers\App\SelfService\StaffSelfServiceController
            Coverage::owned('app.my-leave.index', LeaveRequestReadService::class, 'ownOverview', 'acting employee: the actor\'s own leave', 'hr.leave.self'),
            Coverage::owned('app.my-leave.requests.store', LeaveRequestService::class, 'submitOwn', 'acting employee: the actor\'s own leave')->validatesFirst(),
            Coverage::owned('app.my-leave.requests.show', LeaveRequestReadService::class, 'ownShow', 'acting employee: the actor\'s own leave', 'hr.leave.self'),
            Coverage::owned('app.my-leave.requests.withdraw', LeaveRequestService::class, 'withdrawOwn', 'acting employee: the actor\'s own leave')->validatesFirst(),
            Coverage::owned('app.my-leave.requests.cancel', LeaveRequestService::class, 'withdrawOwn', 'acting employee: the actor\'s own leave')->validatesFirst(),
            Coverage::owned('app.my-staff-attendance.index', StaffAttendanceReadService::class, 'own', 'acting employee: the actor\'s own staff attendance', 'hr.staff_attendance.self'),
            Coverage::owned('app.my-payslips.index', PayslipReadService::class, 'ownPayslips', 'acting employee: the actor\'s own payslips', 'payroll.payslips.self'),
            Coverage::owned('app.my-payslips.show', PayslipReadService::class, 'renderOwn', 'acting employee: the actor\'s own payslips', 'payroll.payslips.self'),

            // Http\Controllers\App\StaffAttendance\StaffAttendanceAdminController
            Coverage::gate('app.staff-attendance.register', 'hr.staff_attendance.view'),
            Coverage::service('app.staff-attendance.register.store', StaffAttendanceService::class, 'recordRegister')->validatesFirst(),
            Coverage::gate('app.staff-attendance.history', 'hr.staff_attendance.view'),
            Coverage::gate('app.staff-attendance.monthly', 'hr.staff_attendance.view'),
            Coverage::service('app.staff-attendance.records.store', StaffAttendanceService::class, 'record')->validatesFirst(),
            Coverage::service('app.staff-attendance.records.corrections.store', StaffAttendanceService::class, 'correct')->validatesFirst(),

            // Http\Controllers\App\Analytics\CurriculumCoverageController
            Coverage::service('app.analytics.curriculum-coverage', AnalyticsReadGate::class, 'read'),

            // Http\Controllers\App\Compliance\AuditLogController
            Coverage::service('app.compliance.audit-log', AuditLogReviewService::class, 'review'),

            // Http\Controllers\App\Automation\AutomationController
            Coverage::service('app.automation', AutomationReadService::class, 'overview'),

            // Http\Controllers\App\DataHealthController
            Coverage::gate('app.data-health.index', ['enrollments.view', 'academics.years.view', 'academics.structure.view', 'timetable.schedule.view', 'teaching.assignments.view']),
            Coverage::gate('app.data-health.show', ['enrollments.view', 'academics.years.view', 'academics.structure.view', 'timetable.schedule.view', 'teaching.assignments.view']),

            // Http\Controllers\App\Automation\AutomationController
            Coverage::service('app.automation.rules.enable', AutomationRuleService::class, 'enable'),
            Coverage::service('app.automation.rules.disable', AutomationRuleService::class, 'disable'),
            Coverage::service('app.automation.rules.take-ownership', AutomationRuleService::class, 'takeOwnership'),

            // Http\Controllers\App\LMS\MyLearningContentController
            Coverage::owned('app.my-learning-content.index', TeacherLearningContentAccess::class, 'scope', 'teaching ownership: the acting teacher\'s own offerings', 'lms.content.teacher'),
            Coverage::owned('app.my-learning-content.store', TeacherLearningContentAccess::class, 'guard', 'teaching ownership: the acting teacher\'s own offerings')->validatesFirst(),
            Coverage::owned('app.my-learning-content.update', TeacherLearningContentAccess::class, 'guard', 'teaching ownership: the acting teacher\'s own offerings'),
            Coverage::owned('app.my-learning-content.publish', TeacherLearningContentAccess::class, 'guard', 'teaching ownership: the acting teacher\'s own offerings'),
            Coverage::owned('app.my-learning-content.archive', TeacherLearningContentAccess::class, 'guard', 'teaching ownership: the acting teacher\'s own offerings'),

            // Http\Controllers\App\LMS\LearningContentController
            // ORG.7D-1 (ADR 0076 §37): administrative LMS is Campus-adopted (through the owning Offering).
            Coverage::campus('app.learning-content.index', 'lms.content.view', LmsAdministrationAccess::class, 'offerings'),
            Coverage::campus('app.learning-content.store', 'lms.content.manage', LmsAdministrationAccess::class, 'offering'),
            Coverage::campus('app.learning-content.update', 'lms.content.manage', LmsAdministrationAccess::class, 'content'),
            Coverage::campus('app.learning-content.publish', 'lms.content.manage', LmsAdministrationAccess::class, 'content'),
            Coverage::campus('app.learning-content.archive', 'lms.content.manage', LmsAdministrationAccess::class, 'content'),
            Coverage::campus('api.v1.schools.subject-offerings.learning-content.index', 'lms.content.view', LmsAdministrationAccess::class, 'offering'),
            Coverage::campus('api.v1.schools.subject-offerings.learning-content.store', 'lms.content.manage', LmsAdministrationAccess::class, 'offering'),
            Coverage::campus('api.v1.schools.learning-content.show', 'lms.content.view', LmsAdministrationAccess::class, 'content'),
            Coverage::campus('api.v1.schools.learning-content.update', 'lms.content.manage', LmsAdministrationAccess::class, 'content'),
            Coverage::campus('api.v1.schools.learning-content.publish', 'lms.content.manage', LmsAdministrationAccess::class, 'content'),
            Coverage::campus('api.v1.schools.learning-content.archive', 'lms.content.manage', LmsAdministrationAccess::class, 'content'),

            // Http\Controllers\App\LMS\MyAssignmentController
            Coverage::owned('app.my-assignments.index', TeacherAssignmentAccess::class, 'scope', 'teaching ownership: the acting teacher\'s own offerings', 'lms.assignments.teacher'),
            Coverage::owned('app.my-assignments.store', TeacherAssignmentAccess::class, 'guard', 'teaching ownership: the acting teacher\'s own offerings')->validatesFirst(),
            Coverage::owned('app.my-assignments.update', TeacherAssignmentAccess::class, 'guard', 'teaching ownership: the acting teacher\'s own offerings'),
            Coverage::owned('app.my-assignments.publish', TeacherAssignmentAccess::class, 'guard', 'teaching ownership: the acting teacher\'s own offerings'),
            Coverage::owned('app.my-assignments.close', TeacherAssignmentAccess::class, 'guard', 'teaching ownership: the acting teacher\'s own offerings'),

            // Http\Controllers\App\LMS\AssignmentController
            Coverage::campus('app.assignments.index', 'lms.assignments.view', LmsAdministrationAccess::class, 'offerings'),
            Coverage::campus('app.assignments.store', 'lms.assignments.manage', LmsAdministrationAccess::class, 'offering'),
            Coverage::campus('app.assignments.update', 'lms.assignments.manage', LmsAdministrationAccess::class, 'assignment'),
            Coverage::campus('app.assignments.publish', 'lms.assignments.manage', LmsAdministrationAccess::class, 'assignment'),
            Coverage::campus('app.assignments.close', 'lms.assignments.manage', LmsAdministrationAccess::class, 'assignment'),
            Coverage::campus('api.v1.schools.subject-offerings.assignments.index', 'lms.assignments.view', LmsAdministrationAccess::class, 'offering'),
            Coverage::campus('api.v1.schools.subject-offerings.assignments.store', 'lms.assignments.manage', LmsAdministrationAccess::class, 'offering'),
            Coverage::campus('api.v1.schools.assignments.show', 'lms.assignments.view', LmsAdministrationAccess::class, 'assignment'),
            Coverage::campus('api.v1.schools.assignments.update', 'lms.assignments.manage', LmsAdministrationAccess::class, 'assignment'),
            Coverage::campus('api.v1.schools.assignments.publish', 'lms.assignments.manage', LmsAdministrationAccess::class, 'assignment'),
            Coverage::campus('api.v1.schools.assignments.close', 'lms.assignments.manage', LmsAdministrationAccess::class, 'assignment'),

            // Http\Controllers\App\Examinations\ExaminationController
            Coverage::gate('app.examinations.index', 'examinations.definitions.view'),
            Coverage::gate('app.examinations.store', 'examinations.definitions.manage'),
            Coverage::gate('app.examinations.update', 'examinations.definitions.manage'),

            // Http\Controllers\App\Examinations\ExaminationPaperController
            // ORG.7D-1 (ADR 0076 §37): Examination Papers are Campus-adopted (marks are not).
            Coverage::campus('app.examinations.papers.index', 'examinations.papers.view', ExaminationPaperAccess::class, 'papers'),
            Coverage::campus('app.examinations.papers.store', 'examinations.papers.manage', ExaminationPaperAccess::class, 'offering'),
            Coverage::campus('app.examinations.papers.update', 'examinations.papers.manage', ExaminationPaperAccess::class, 'paperOf'),
            Coverage::campus('api.v1.schools.examinations.examination-papers.index', 'examinations.papers.view', ExaminationPaperAccess::class, 'papers'),
            Coverage::campus('api.v1.schools.examinations.examination-papers.store', 'examinations.papers.manage', ExaminationPaperAccess::class, 'offering'),
            Coverage::campus('api.v1.schools.examination-papers.show', 'examinations.papers.view', ExaminationPaperAccess::class, 'paper'),
            Coverage::campus('api.v1.schools.examination-papers.update', 'examinations.papers.manage', ExaminationPaperAccess::class, 'paper'),

            // Http\Controllers\App\Examinations\GradeScaleController
            Coverage::gate('app.examinations.grade-scales.index', 'examinations.grade_scales.view'),
            Coverage::gate('app.examinations.grade-scales.store', 'examinations.grade_scales.manage'),
            Coverage::gate('app.examinations.grade-scales.update', 'examinations.grade_scales.manage'),
            Coverage::gate('app.examinations.grade-scales.bands.store', 'examinations.grade_scales.manage'),
            Coverage::gate('app.examinations.grade-scales.bands.update', 'examinations.grade_scales.manage'),
            Coverage::gate('app.examinations.grade-scales.bands.destroy', 'examinations.grade_scales.manage'),

            // Http\Controllers\App\Attendance\AttendanceController
            // ORG.7D-1 (ADR 0076 §37): administrative Attendance is Campus-adopted.
            Coverage::campus('app.attendance.index', 'attendance.view', AttendanceAdministrationAccess::class, 'sessions'),
            Coverage::campus('app.attendance.take', 'attendance.manage', AttendanceAdministrationAccess::class, 'entries'),
            Coverage::campus('app.attendance.roster', 'attendance.manage', AttendanceAdministrationAccess::class, 'entry'),

            // Http\Controllers\App\Attendance\AttendanceExceptionController
            Coverage::campus('app.attendance.exceptions', 'attendance.view', AttendanceExceptionReadService::class, 'list'),
            // ORG.9C-1 (ADR 0076 §69): Http\Controllers\App\Attendance\AttendanceOperationsController
            Coverage::campus('app.attendance.registers', 'attendance.view', AttendanceOperationsReadService::class, 'registerStatus'),
            Coverage::campus('app.attendance.students.show', 'attendance.view', AttendanceOperationsReadService::class, 'studentHistory'),

            // Http\Controllers\App\Attendance\AttendanceController
            Coverage::campus('app.attendance.store', 'attendance.manage', AttendanceAdministrationAccess::class, 'writeGuard'),
            Coverage::campus('app.attendance.show', 'attendance.view', AttendanceAdministrationAccess::class, 'session'),
            Coverage::campus('app.attendance.correct', 'attendance.manage', AttendanceAdministrationAccess::class, 'writeGuard'),
            Coverage::campus('api.v1.schools.attendance-sessions.index', 'attendance.view', AttendanceAdministrationAccess::class, 'sessions'),
            Coverage::campus('api.v1.schools.attendance-sessions.store', 'attendance.manage', AttendanceAdministrationAccess::class, 'writeGuard'),
            Coverage::campus('api.v1.schools.attendance-sessions.scheduled-classes', 'attendance.manage', AttendanceAdministrationAccess::class, 'entries'),
            Coverage::campus('api.v1.schools.attendance-sessions.roster-preview', 'attendance.manage', AttendanceAdministrationAccess::class, 'entry'),
            Coverage::campus('api.v1.schools.attendance-sessions.show', 'attendance.view', AttendanceAdministrationAccess::class, 'session'),
            Coverage::campus('api.v1.schools.attendance-records.correct', 'attendance.manage', AttendanceAdministrationAccess::class, 'writeGuard'),

            // Http\Controllers\App\Attendance\MyAttendanceController
            Coverage::owned('app.my-attendance.index', TeacherAttendanceAccess::class, 'scope', 'teaching ownership: the acting teacher\'s own sections'),
            Coverage::owned('app.my-attendance.take', TeacherAttendanceAccess::class, 'scope', 'teaching ownership: the acting teacher\'s own sections'),
            Coverage::owned('app.my-attendance.store', TeacherAttendanceAccess::class, 'guard', 'teaching ownership: the acting teacher\'s own sections'),
            Coverage::owned('app.my-attendance.show', TeacherAttendanceAccess::class, 'scope', 'teaching ownership: the acting teacher\'s own sections'),
            Coverage::owned('app.my-attendance.correct', TeacherAttendanceAccess::class, 'guard', 'teaching ownership: the acting teacher\'s own sections'),

            // Http\Controllers\App\Timetable\TimetableEntryController
            // ORG.7D-1 (ADR 0076 §37): Timetable schedule is Campus-adopted.
            Coverage::campus('app.timetable-schedule.index', 'timetable.schedule.view', TimetableScheduleAccess::class, 'entries'),
            Coverage::campusLookup('app.timetable-schedule.create', 'timetable.schedule.manage', 'Data-free form page; Offerings, Sections and Rooms come from the scoped searches.'),
            Coverage::campus('app.timetable-schedule.search-subject-offerings', 'timetable.schedule.manage', TimetableScheduleAccess::class, 'offerings'),
            Coverage::campus('app.timetable-schedule.search-sections', 'timetable.schedule.manage', TimetableScheduleAccess::class, 'sections'),
            Coverage::campusLookup('app.timetable-schedule.search-teachers', 'timetable.schedule.manage', 'The scheduling form\'s teacher lookup: active Employees, id and name only (CampusScopeCatalogue::DOMAINS timetable sharedLookups).'),
            Coverage::campus('app.timetable-schedule.search-rooms', 'timetable.schedule.manage', TimetableScheduleAccess::class, 'rooms'),
            Coverage::gate('app.timetable-schedule.search-periods', 'timetable.periods.view'),
            Coverage::campusLookup('app.timetable-schedule.search-schedule-periods', 'timetable.schedule.manage', 'The scheduling form\'s lookup of the School-wide bell schedule: active Periods (CampusScopeCatalogue::DOMAINS timetable sharedLookups).'),
            Coverage::campus('app.timetable-schedule.store', 'timetable.schedule.manage', TimetableScheduleAccess::class, 'offering'),
            Coverage::campus('app.timetable-schedule.edit', 'timetable.schedule.manage', TimetableScheduleAccess::class, 'entry'),
            Coverage::campus('app.timetable-schedule.update', 'timetable.schedule.manage', TimetableScheduleAccess::class, 'entry'),
            Coverage::campus('app.timetable-schedule.activate', 'timetable.schedule.manage', TimetableScheduleAccess::class, 'entry'),
            Coverage::campus('app.timetable-schedule.deactivate', 'timetable.schedule.manage', TimetableScheduleAccess::class, 'entry'),
            Coverage::campus('api.v1.schools.timetable-entries.index', 'timetable.schedule.view', TimetableScheduleAccess::class, 'entries'),
            Coverage::campus('api.v1.schools.timetable-entries.store', 'timetable.schedule.manage', TimetableScheduleAccess::class, 'offering'),
            Coverage::campus('api.v1.schools.timetable-entries.search-subject-offerings', 'timetable.schedule.manage', TimetableScheduleAccess::class, 'offerings'),
            Coverage::campus('api.v1.schools.timetable-entries.search-sections', 'timetable.schedule.manage', TimetableScheduleAccess::class, 'sections'),
            Coverage::campusLookup('api.v1.schools.timetable-entries.search-teachers', 'timetable.schedule.manage', 'The scheduling form\'s teacher lookup: active Employees, id and name only (CampusScopeCatalogue::DOMAINS timetable sharedLookups).'),
            Coverage::campus('api.v1.schools.timetable-entries.search-rooms', 'timetable.schedule.manage', TimetableScheduleAccess::class, 'rooms'),
            Coverage::campusLookup('api.v1.schools.timetable-entries.search-schedule-periods', 'timetable.schedule.manage', 'The scheduling form\'s lookup of the School-wide bell schedule: active Periods (CampusScopeCatalogue::DOMAINS timetable sharedLookups).'),
            Coverage::campus('api.v1.schools.timetable-entries.show', 'timetable.schedule.view', TimetableScheduleAccess::class, 'entry'),
            Coverage::campus('api.v1.schools.timetable-entries.update', 'timetable.schedule.manage', TimetableScheduleAccess::class, 'entry'),
            Coverage::campus('api.v1.schools.timetable-entries.activate', 'timetable.schedule.manage', TimetableScheduleAccess::class, 'entry'),
            Coverage::campus('api.v1.schools.timetable-entries.deactivate', 'timetable.schedule.manage', TimetableScheduleAccess::class, 'entry'),

            // Http\Controllers\App\Payroll\PayrollController
            Coverage::gate('app.payroll.index', ['payroll.structures.view', 'payroll.compensation.view', 'payroll.runs.view', 'payroll.periods.manage', 'payroll.accounting.manage']),

            // Http\Controllers\App\Payroll\SalaryComponentController
            Coverage::service('app.payroll.components.index', PayrollStructureReadService::class, 'listComponents'),
            Coverage::service('app.payroll.components.store', PayrollStructureAdministrationService::class, 'createComponent')->validatesFirst(),
            Coverage::service('app.payroll.components.deactivate', PayrollStructureAdministrationService::class, 'deactivateComponent'),

            // Http\Controllers\App\Payroll\SalaryStructureController
            Coverage::service('app.payroll.structures.create', PayrollStructureReadService::class, 'listComponents'),
            Coverage::service('app.payroll.structures.index', PayrollStructureReadService::class, 'listStructures'),
            Coverage::service('app.payroll.structures.store', PayrollStructureAdministrationService::class, 'createDraftStructure')->validatesFirst(),
            Coverage::service('app.payroll.structures.show', PayrollStructureReadService::class, 'getStructureDetail'),
            Coverage::service('app.payroll.structures.components.store', PayrollStructureAdministrationService::class, 'addStructureComponent'),
            Coverage::service('app.payroll.structures.activate', PayrollStructureAdministrationService::class, 'activateStructure'),

            // Http\Controllers\App\Payroll\PayrollPeriodController
            Coverage::gate('app.payroll.periods.index', 'payroll.periods.manage'),
            Coverage::service('app.payroll.periods.store', PayrollPeriodAdministrationService::class, 'createPeriod')->validatesFirst(),
            Coverage::service('app.payroll.periods.open', PayrollPeriodAdministrationService::class, 'open'),
            Coverage::service('app.payroll.periods.close', PayrollPeriodAdministrationService::class, 'close'),

            // Http\Controllers\App\Payroll\PayrollRunController
            Coverage::service('app.payroll.periods.runs.store', PayrollRunAdministrationService::class, 'createRun'),
            Coverage::service('app.payroll.runs.show', PayrollStructureReadService::class, 'listComponents'),
            Coverage::service('app.payroll.runs.calculate', PayrollRunAdministrationService::class, 'calculate'),
            Coverage::service('app.payroll.runs.manual-overrides.store', PayrollRunAdministrationService::class, 'recordManualOverride'),
            Coverage::service('app.payroll.runs.correction-deltas.store', PayrollRunAdministrationService::class, 'recordCorrectionDelta'),
            Coverage::service('app.payroll.runs.correction.store', PayrollRunAdministrationService::class, 'createCorrectionRun'),
            Coverage::service('app.payroll.runs.hrx-inputs.difference-checks', PayrollHrxInputReadService::class, 'checkDifferences'),
            Coverage::gate('app.payroll.runs.approve', 'payroll.runs.approve'),

            // Http\Controllers\App\Payroll\PayrollRunPostingController
            Coverage::gate('app.payroll.runs.post', 'payroll.runs.post'),
            Coverage::gate('app.payroll.runs.reverse', 'payroll.runs.reverse'),

            // Http\Controllers\App\Payroll\PayslipController
            Coverage::gate('app.payroll.runs.payslips.show', 'payroll.compensation.sensitive.view'),

            // Http\Controllers\App\Payroll\CompensationController
            Coverage::gate('app.payroll.compensation.index', 'payroll.compensation.view'),
            Coverage::gate('app.payroll.compensation.search', 'payroll.compensation.view'),
            Coverage::service('app.payroll.compensation.show', PayrollCompensationReadService::class, 'listAssignments'),
            Coverage::gate('app.payroll.compensation.store', 'payroll.compensation.sensitive.manage'),
            Coverage::gate('app.payroll.compensation.values', 'payroll.compensation.sensitive.view'),

            // Http\Controllers\App\Payroll\PayrollAccountingConfigurationController
            Coverage::gate('app.payroll.accounting.show', 'payroll.accounting.manage'),
            Coverage::service('app.payroll.accounting.update', PayrollAccountingAdministrationService::class, 'configure')->validatesFirst(),

            // Http\Controllers\App\Payroll\Statutory\StatutoryEmployeeController
            Coverage::gate('app.payroll.statutory.employees.search', 'payroll.statutory.view'),
            Coverage::gate('app.payroll.statutory.employees.show', 'payroll.statutory.view'),
            Coverage::service('app.payroll.statutory.employees.identifiers.reveal', StatutoryIdentifierAdminService::class, 'reveal'),
            Coverage::service('app.payroll.statutory.employees.pf-status.store', EmployeePfStatusAdminService::class, 'configure')->validatesFirst(),
            Coverage::service('app.payroll.statutory.employees.esi-coverage.store', EmployeeEsiCoverageAdminService::class, 'correct')->validatesFirst(),
            Coverage::service('app.payroll.statutory.employees.tax-profile.store', EmployeeTaxProfileAdminService::class, 'configure')->validatesFirst(),
            Coverage::service('app.payroll.statutory.employees.identifiers.store', StatutoryIdentifierAdminService::class, 'set')->validatesFirst(),

            // Http\Controllers\App\Payroll\Statutory\StatutoryAccountingConfigurationController
            Coverage::service('app.payroll.statutory.accounting.show', StatutoryAccountingAdministrationService::class, 'view'),
            Coverage::service('app.payroll.statutory.accounting.update', StatutoryAccountingAdministrationService::class, 'configure')->validatesFirst(),

            // Http\Controllers\App\Payroll\Statutory\StatutoryExportController
            Coverage::gate('app.payroll.statutory.runs.exports.show', 'payroll.statutory.exports.generate'),
            Coverage::service('app.payroll.statutory.runs.exports.ecr', StatutoryEcrExportService::class, 'generate'),
            Coverage::service('app.payroll.statutory.runs.exports.esi-worksheet', StatutoryEsiContributionWorksheetExportService::class, 'generate'),
            Coverage::service('app.payroll.statutory.runs.exports.tds-draft-statement', StatutoryTdsDraftStatementExportService::class, 'generate'),
        ];
    }

    /**
     * C5 -- context establishment, token acceptance and legal-gated notice
     * pages, where ordinary School capability coverage cannot apply.
     *
     * @return array<string, Coverage>
     */
    public static function special(): array
    {
        $entries = [
            Coverage::special(
                'api.v1.schools.context',
                'CLAUDE.md rule 19; ADR 0020',
                'Establishes API School context: re-verifies an active membership server-side and returns only the actor\'s own capabilities in that School.',
                SchoolContextTest::class,
            ),
            Coverage::special(
                'app.schools.activate',
                'CLAUDE.md rules 19-20; ADR 0020',
                'Selects the session School: stored only after an active membership is re-verified, and re-validated on every request (ResolveSchoolContext).',
                SchoolContextRequiredTest::class,
            ),
            Coverage::special(
                'invitations.show',
                'Phase 5D.3 (docs/communication-hub/PHASE-5D-3-FINAL-INTEGRATION.md); CLAUDE.md rule 19',
                'One-time Guardian invitation token: the token is the authority; no School context or capability exists yet.',
                InvitationAcceptanceHubTest::class,
            ),
            Coverage::special(
                'invitations.store',
                'Phase 5D.3 (docs/communication-hub/PHASE-5D-3-FINAL-INTEGRATION.md); CLAUDE.md rule 19',
                'Accepts a one-time Guardian invitation token; no School capability exists before acceptance.',
                InvitationAcceptanceHubTest::class,
            ),
            Coverage::special(
                'staff-invitations.show',
                'ADR 0059; CLAUDE.md rule 92',
                'One-time staff invitation selector: the invitation is the authority; no School capability exists yet.',
                StaffInvitationAcceptanceTest::class,
            ),
            Coverage::special(
                'staff-invitations.store',
                'ADR 0059; ADR 0071 §24; CLAUDE.md rule 94',
                'Accepts a staff invitation; acceptance re-validates the issuer\'s CURRENT grant authority inside the transaction.',
                StaffInvitationAcceptanceTest::class,
            ),
            Coverage::special(
                'app.payroll.statutory.index',
                'Checkpoint 9.6I; E45 (statutory payroll legal gate); StaffNavigation',
                'Legal-gated notice page: renders the actor\'s own capability flags only; rule status needs payroll.statutory.view and every statutory destination re-checks its own capability.',
                StatutoryPayrollUiTest::class,
            )->forEveryMember(),
        ];

        $keyed = [];
        foreach ($entries as $entry) {
            $keyed[$entry->route] = $entry;
        }

        return $keyed;
    }
}
