<?php

use App\Domain\AcademicStructure\Application\AcademicYearService;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Campuses\Application\CampusAdministrationService;
use App\Domain\Canteen\Application\CanteenOrderAccess;
use App\Domain\Canteen\Application\CanteenOrderLineData;
use App\Domain\Canteen\Application\CanteenOrderService;
use App\Domain\Canteen\Application\PlaceCanteenOrderData;
use App\Domain\Hostel\Application\HostelResidencyAccess;
use App\Domain\Hostel\Application\HostelResidencyService;
use App\Domain\Hostel\Infrastructure\HostelBed;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Application\StudentService;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\Campus\CampusScopeResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
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
//   php student-eligibility-op.php transfer <schoolId> <enrollmentId> <sectionId>
//   php student-eligibility-op.php withdraw <schoolId> <enrollmentId>
//   php student-eligibility-op.php student-inactivate <schoolId> <studentId>
//   php student-eligibility-op.php year-activate <schoolId> <yearId>
//   php student-eligibility-op.php year-close <schoolId> <yearId>
//   php student-eligibility-op.php campus-inactivate <schoolId> <adminUserId> <campusId>

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
            case 'withdraw':
                $app->make(StudentEnrollmentService::class)->withdraw(StudentEnrollment::query()->findOrFail($args[0]), now()->toDateString());

                return 'ok';
            case 'year-close':
                $app->make(AcademicYearService::class)->close(AcademicYear::query()->findOrFail($args[0]));

                return 'ok';
            case 'transfer':
                [$enrollmentId, $sectionId] = $args;
                $app->make(StudentEnrollmentService::class)->transferPlacement(StudentEnrollment::query()->findOrFail($enrollmentId), Section::query()->findOrFail($sectionId), 'T'.random_int(1000, 9999), now()->toDateString());

                return 'ok';
            case 'student-inactivate':
                $app->make(StudentService::class)->changeStatus(Student::query()->findOrFail($args[0]), 'inactive');

                return 'ok';
            case 'year-activate':
                $app->make(AcademicYearService::class)->activate(AcademicYear::query()->findOrFail($args[0]));

                return 'ok';
            case 'campus-inactivate':
                [$adminId, $campusId] = $args;
                $app->make(CampusAdministrationService::class)->update($school, $campusId, ['status' => 'inactive'], User::query()->findOrFail($adminId));

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
