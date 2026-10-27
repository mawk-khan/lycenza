<?php

use App\Domain\AcademicStructure\Application\AcademicYearService;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Campuses\Application\CampusAdministrationService;
use App\Domain\Canteen\Application\CanteenOrderAccess;
use App\Domain\Canteen\Application\CanteenOrderLineData;
use App\Domain\Canteen\Application\CanteenOrderService;
use App\Domain\Canteen\Application\PlaceCanteenOrderData;
use App\Domain\Fees\Application\AssessChargeData;
use App\Domain\Fees\Application\ChargeCampusAttribution;
use App\Domain\Fees\Application\ChargeService;
use App\Domain\Hostel\Application\HostelResidencyAccess;
use App\Domain\Hostel\Application\HostelResidencyService;
use App\Domain\Hostel\Infrastructure\HostelBed;
use App\Domain\Library\Application\LibraryCirculationAccess;
use App\Domain\Library\Application\LibraryCopyService;
use App\Domain\Library\Application\LibraryLoanService;
use App\Domain\Library\Infrastructure\LibraryCopy;
use App\Domain\Payments\Application\ChargeAllocationInput;
use App\Domain\Payments\Application\ManualPaymentRecordingService;
use App\Domain\Payments\Application\RecordManualPaymentData;
use App\Domain\Payments\Domain\ManualPaymentMethod;
use App\Domain\Students\Application\CampusStudentOnboardingService;
use App\Domain\Students\Application\CampusStudentTransferService;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Application\StudentNameEditService;
use App\Domain\Students\Application\StudentService;
use App\Domain\Students\Application\StudentSubjectEnrollmentService;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Domain\Transport\Application\TransportRiderAccess;
use App\Domain\Transport\Application\TransportRouteService;
use App\Domain\Transport\Application\TransportStudentAssignmentService;
use App\Domain\Transport\Infrastructure\TransportRoute;
use App\Models\School;
use App\Models\User;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Authorization\Campus\CampusScopeResolver;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Concurrency\HeldTransaction;

// Standalone bootstrap script for StudentEligibilityConcurrencyTest
// (ORG.7D-4, ADR 0076 §40): one Campus-scoped Student-dependent write, or
// one change to the state that decides Student operational eligibility, in a
// GENUINELY separate OS process (HeldTransaction holder or contender).
//
// Usage:
//   php student-eligibility-op.php hostel-assign <schoolId> <userId> <studentId> <bedId>
//   php student-eligibility-op.php canteen-place <schoolId> <userId> <studentId> <outletId> <itemId>
//   php student-eligibility-op.php canteen-fulfil <schoolId> <userId> <orderId>
//   php student-eligibility-op.php canteen-cancel <schoolId> <userId> <orderId>
//   php student-eligibility-op.php hostel-student-lock <schoolId> <studentId>
//   php student-eligibility-op.php library-checkout <schoolId> <userId> <studentId> <copyId>
//   php student-eligibility-op.php library-copy-move <schoolId> <adminUserId> <copyId> <campusId>
//   php student-eligibility-op.php transport-assign <schoolId> <userId> <studentId> <routeId>
//   php student-eligibility-op.php route-campus <schoolId> <adminUserId> <routeId> <campusId>
//   php student-eligibility-op.php transfer <schoolId> <enrollmentId> <sectionId>
//   php student-eligibility-op.php withdraw <schoolId> <enrollmentId> <actorId>              (ORG.8E: a whole-School actor)
//   php student-eligibility-op.php student-inactivate <schoolId> <studentId> <actorId>       (ORG.8E)
//   php student-eligibility-op.php enrollment-cancel <schoolId> <enrollmentId> <actorId>     (ORG.8E)
//   php student-eligibility-op.php year-activate <schoolId> <yearId>
//   php student-eligibility-op.php year-close <schoolId> <yearId>
//   php student-eligibility-op.php campus-inactivate <schoolId> <adminUserId> <campusId>
//   php student-eligibility-op.php student-rename <schoolId> <userId> <studentId> <firstName>   (ORG.7F-2)
//   php student-eligibility-op.php onboard <schoolId> <userId> <sectionId> <firstName>          (ORG.7F-2; prints the allocated number)
//   php student-eligibility-op.php section-inactivate <schoolId> <sectionId>                    (ORG.7F-2)
//   php student-eligibility-op.php manual-renumber <schoolId> <studentId> <studentNumber>        (SIS 2.3; a typed number correction)
//   php student-eligibility-op.php create-student <schoolId> <firstName> [<acknowledgement>]    (SIS 2.3; prints the allocated number)
//   php student-eligibility-op.php campus-transfer <schoolId> <userId> <studentId> <sectionId>  (ORG.7F-3)
//   php student-eligibility-op.php elective-enroll <schoolId> <studentId> <offeringId>          (ORG.7F-3)
//   php student-eligibility-op.php manual-charge <schoolId> <studentId> <yearId> <receivableId> <revenueId>  (ORG.7G-1A)
//   php student-eligibility-op.php enrollment-cancel <schoolId> <enrollmentId>                  (ORG.7G-1A)
//   php student-eligibility-op.php campus-payment <schoolId> <userId> <settlementId> <chargeId:amount,...>  (ORG.7G-2)

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = array_slice($argv, 1);
$operation = array_shift($args);
$school = School::query()->findOrFail(array_shift($args));
$context = $app->make(TenantContext::class);

try {
    echo HeldTransaction::run(fn () => $context->withSchool($school, function () use ($app, $operation, $args, $school): string {
        $scope = fn (string $userId, string $capability) => $app->make(CampusScopeResolver::class)->campusScope(User::query()->findOrFail($userId), $capability, $school);
        switch ($operation) {
            case 'hostel-assign':
                [$userId, $studentId, $bedId] = $args;
                $guard = $app->make(HostelResidencyAccess::class)->writeGuard($school, $scope($userId, 'hostel.residency.manage'));
                $app->make(HostelResidencyService::class)->assign(Student::query()->findOrFail($studentId), HostelBed::query()->findOrFail($bedId), User::query()->findOrFail($userId), $guard);

                return 'ok';
            case 'canteen-place':
                [$userId, $studentId, $outletId, $itemId] = $args;
                $guard = $app->make(CanteenOrderAccess::class)->writeGuard($school, $scope($userId, 'canteen.orders.manage'));
                $app->make(CanteenOrderService::class)->place($school, new PlaceCanteenOrderData($studentId, $outletId, [new CanteenOrderLineData($itemId, 1)]), User::query()->findOrFail($userId), $guard);

                return 'ok';
            case 'canteen-fulfil':
                [$userId, $orderId] = $args;
                $guard = $app->make(CanteenOrderAccess::class)->writeGuard($school, $scope($userId, 'canteen.orders.manage'));
                $app->make(CanteenOrderService::class)->fulfill($school, $orderId, User::query()->findOrFail($userId), $guard);

                return 'ok';
            case 'canteen-cancel':
                [$userId, $orderId] = $args;
                $guard = $app->make(CanteenOrderAccess::class)->writeGuard($school, $scope($userId, 'canteen.orders.manage'));
                $app->make(CanteenOrderService::class)->cancel($school, $orderId, User::query()->findOrFail($userId), $guard);

                return 'ok';
            case 'hostel-student-lock':
                // Only the Student lock HostelResidencyService::assign() takes first.
                Student::query()->whereKey($args[0])->lock(HostelResidencyService::STUDENT_LOCK)->firstOrFail();

                return 'ok';
            case 'library-checkout':
                [$userId, $studentId, $copyId] = $args;
                $guard = $app->make(LibraryCirculationAccess::class)->writeGuard($school, $scope($userId, 'library.circulation.manage'));
                $app->make(LibraryLoanService::class)->checkout(LibraryCopy::query()->findOrFail($copyId), Student::query()->findOrFail($studentId), now()->addDays(7), User::query()->findOrFail($userId), $guard);

                return 'ok';
            case 'library-copy-move':
                [$adminId, $copyId, $campusId] = $args;
                $app->make(LibraryCopyService::class)->update($school, $copyId, ['campus_id' => $campusId], User::query()->findOrFail($adminId));

                return 'ok';
            case 'transport-assign':
                [$userId, $studentId, $routeId] = $args;
                $guard = $app->make(TransportRiderAccess::class)->writeGuard($school, $scope($userId, 'transport.assignments.manage'));
                $app->make(TransportStudentAssignmentService::class)->assign(Student::query()->findOrFail($studentId), TransportRoute::query()->findOrFail($routeId), null, null, User::query()->findOrFail($userId), $guard);

                return 'ok';
            case 'route-campus':
                [$adminId, $routeId, $campusId] = $args;
                $app->make(TransportRouteService::class)->update($school, $routeId, ['campus_id' => $campusId], User::query()->findOrFail($adminId));

                return 'ok';
            case 'withdraw':
                $actor = User::query()->findOrFail($args[1]);
                $app->make(StudentEnrollmentService::class)->withdraw(StudentEnrollment::query()->findOrFail($args[0]), now()->toDateString(), 'left_school', $actor, new FreshMfaProof($actor->id, CarbonImmutable::now()));

                return 'ok';
            case 'year-close':
                $app->make(AcademicYearService::class)->close(AcademicYear::query()->findOrFail($args[0]));

                return 'ok';
            case 'transfer':
                [$enrollmentId, $sectionId] = $args;
                $app->make(StudentEnrollmentService::class)->transferPlacement(StudentEnrollment::query()->findOrFail($enrollmentId), Section::query()->findOrFail($sectionId), 'T'.random_int(1000, 9999), now()->toDateString());

                return 'ok';
            case 'student-inactivate':
                $actor = User::query()->findOrFail($args[1]);
                $app->make(StudentService::class)->changeStatus(Student::query()->findOrFail($args[0]), 'inactive', 'left_school', $actor, new FreshMfaProof($actor->id, CarbonImmutable::now()));

                return 'ok';
            case 'year-activate':
                $app->make(AcademicYearService::class)->activate(AcademicYear::query()->findOrFail($args[0]));

                return 'ok';
            case 'campus-inactivate':
                [$adminId, $campusId] = $args;
                $app->make(CampusAdministrationService::class)->update($school, $campusId, ['status' => 'inactive'], User::query()->findOrFail($adminId));

                return 'ok';
            case 'student-rename':
                [$userId, $studentId, $firstName] = $args;
                $app->make(StudentNameEditService::class)->edit($school, $scope($userId, 'students.manage'), $studentId, ['first_name' => $firstName], User::query()->findOrFail($userId));

                return 'ok';
            case 'onboard':
                [$userId, $sectionId, $firstName] = $args;
                $enrollment = $app->make(CampusStudentOnboardingService::class)->onboard($school, $scope($userId, 'students.manage'), $scope($userId, 'enrollments.manage'), [
                    'first_name' => $firstName, 'date_of_birth' => '2016-05-01', 'section_id' => $sectionId, 'roll_number' => 'R'.random_int(10000, 99999),
                ], User::query()->findOrFail($userId));

                return 'ok:'.$enrollment->student->student_number;
            case 'manual-renumber':
                // SIS 2.3: a typed number now exists only as a correction of an existing Student.
                [$studentId, $number] = $args;
                $app->make(StudentService::class)->update(Student::query()->findOrFail($studentId), ['student_number' => $number]);

                return 'ok';
            case 'create-student':
                // SIS 2.3: the one creation path (allocated number + duplicate check), whole School.
                [$firstName, $acknowledgement] = $args + [1 => null];
                $student = $app->make(StudentService::class)->create($school, ['first_name' => $firstName, 'last_name' => 'Racer', 'date_of_birth' => '2016-05-01'], null, $acknowledgement);

                return 'ok:'.$student->student_number;
            case 'campus-transfer':
                [$userId, $studentId, $sectionId] = $args;
                $app->make(CampusStudentTransferService::class)->transfer($school, $scope($userId, 'enrollments.manage'), $studentId, $sectionId, 'T'.random_int(1000, 9999), User::query()->findOrFail($userId));

                return 'ok';
            case 'elective-enroll':
                [$studentId, $offeringId] = $args;
                $app->make(StudentSubjectEnrollmentService::class)->enroll(Student::query()->findOrFail($studentId), SubjectOffering::query()->findOrFail($offeringId), now()->toDateString());

                return 'ok';
            case 'manual-charge':
                [$studentId, $yearId, $receivableId, $revenueId] = $args;
                $app->make(ChargeService::class)->assess($school, new AssessChargeData(
                    studentId: $studentId, academicYearId: $yearId, description: 'Race', amount: Money::of('10.00', 'INR'),
                    receivableLedgerAccountId: $receivableId, revenueLedgerAccountId: $revenueId,
                    campus: ChargeCampusAttribution::placementOnAssessmentDate(),
                ));
                // The ledger's deferred balance check must run while the School
                // context is still set (a held holder commits after withSchool()).
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

                return 'ok';
            case 'campus-payment':
                [$userId, $settlementId, $lines] = $args;
                $allocations = [];
                $total = '0.00';
                foreach (explode(',', $lines) as $line) {
                    [$chargeId, $amount] = explode(':', $line);
                    $allocations[] = new ChargeAllocationInput($chargeId, Money::of($amount, 'INR'));
                    $total = bcadd($total, $amount, 2);
                }
                $app->make(ManualPaymentRecordingService::class)->record($school, new RecordManualPaymentData(
                    method: ManualPaymentMethod::Cash, amount: Money::of($total, 'INR'), occurredOn: now($school->timezone)->toDateString(),
                    reference: null, settlementLedgerAccountId: $settlementId, allocations: $allocations, idempotencyKey: (string) Str::uuid(),
                ), User::query()->findOrFail($userId));
                // The deferred allocation/ledger checks must run while the School context is still set.
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

                return 'ok';
            case 'enrollment-cancel':
                $actor = User::query()->findOrFail($args[1]);
                $app->make(StudentEnrollmentService::class)->cancel(StudentEnrollment::query()->findOrFail($args[0]), now()->toDateString(), 'entered_in_error', $actor, new FreshMfaProof($actor->id, CarbonImmutable::now()));

                return 'ok';
            case 'section-inactivate':
                Section::query()->whereKey($args[0])->update(['status' => 'inactive']);

                return 'ok';
            default:
                throw new InvalidArgumentException("Unknown operation {$operation}");
        }
    }));
} catch (Throwable $e) {
    echo 'rejected:'.class_basename($e);
} finally {
    $context->clearAll();
}
