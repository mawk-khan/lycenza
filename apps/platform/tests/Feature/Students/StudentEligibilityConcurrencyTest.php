<?php

namespace Tests\Feature\Students;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Canteen\Infrastructure\CanteenItem;
use App\Domain\Canteen\Infrastructure\CanteenOrder;
use App\Domain\Canteen\Infrastructure\CanteenOutlet;
use App\Domain\Hostel\Infrastructure\HostelBed;
use App\Domain\Hostel\Infrastructure\HostelResidencyAssignment;
use App\Domain\Library\Infrastructure\LibraryCopy;
use App\Domain\Library\Infrastructure\LibraryLoan;
use App\Domain\Students\Application\StudentOperationalEligibility;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Domain\Transport\Infrastructure\TransportRoute;
use App\Domain\Transport\Infrastructure\TransportStudentAssignment;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesCanteenFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * ORG.7D-4 (ADR 0076 §40): write-time Student operational eligibility is
 * transactionally stable. Two real OS processes against real PostgreSQL;
 * the contender is positively observed BLOCKED on the holder's uncommitted
 * transaction before the holder commits (ForcesConcurrentOverlap), so each
 * test proves one serialization order:
 *
 * - state change first  -> the Campus-scoped write waits, then refuses
 *   generically (it never commits on the stale answer);
 * - write first         -> the state change waits until the write commits.
 *
 * Covers the four state changes that decide eligibility -- a placement
 * transfer, a Student inactivation, an academic-year switch and a Campus
 * deactivation -- for Hostel residency and Canteen orders, and (ORG.7D-5,
 * ADR 0076 §41) Library checkout and Transport rider assignment, plus a Copy
 * move against a checkout and a route Campus change against a rider.
 */
class StudentEligibilityConcurrencyTest extends TestCase
{
    use CreatesCanteenFixtures, CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    /** @var list<string> */
    private array $schoolIds = [];

    /** @var list<string> */
    private array $userIds = [];

    private School $school;

    private Campus $a;

    private AcademicYear $year;

    private AcademicYear $nextYear;

    private User $admin;

    private Section $sectionB;

    private Student $student;

    private StudentEnrollment $placement;

    private HostelBed $bed;

    private CanteenOutlet $outlet;

    private CanteenItem $item;

    private Campus $b;

    private LibraryCopy $copy;

    private TransportRoute $route;

    private User $librarian;

    private User $coordinator;

    private User $warden;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->schoolIds[] = $this->school->id;
        $this->a = $this->createCampus($this->school, ['name' => 'North']);
        $b = $this->b = $this->createCampus($this->school, ['name' => 'South']);
        $grade = $this->createGradeLevel($this->school);
        $year = $this->year = $this->createAcademicYear($this->school, ['code' => 'Y1', 'name' => 'Y1', 'status' => 'active', 'starts_on' => now()->subDays(200)->toDateString(), 'ends_on' => now()->addDays(30)->toDateString()]);
        $this->nextYear = $this->createAcademicYear($this->school, ['code' => 'Y2', 'name' => 'Y2', 'status' => 'draft', 'starts_on' => now()->subDay()->toDateString(), 'ends_on' => now()->addDays(300)->toDateString()]);
        $sectionA = $this->createSection($year, $this->a, $grade, ['code' => 'SA', 'status' => 'active']);
        $this->sectionB = $this->createSection($year, $b, $grade, ['code' => 'SB', 'status' => 'active']);
        $nextB = $this->createSection($this->nextYear, $b, $grade, ['code' => 'NB', 'status' => 'active']);

        // Current Campus A in Y1; already placed on Campus B for Y2.
        $this->student = $this->createStudent($this->school, ['status' => 'active']);
        $this->placement = $this->createStudentEnrollment($this->student, $sectionA, ['status' => 'active', 'starts_on' => now()->subDays(60)->toDateString()]);
        $this->createStudentEnrollment($this->student, $nextB, ['status' => 'active', 'starts_on' => now()->subDay()->toDateString()]);

        $this->bed = $this->createHostelBed($this->createHostelRoom($this->createHostel($this->school, $this->a, ['status' => 'active']), ['status' => 'active']), ['status' => 'active']);
        $this->outlet = $this->createCanteenOutlet($this->school, $this->createInventoryLocation($this->school, ['campus_id' => $this->a->id, 'status' => 'active']), ['campus_id' => $this->a->id, 'status' => 'active']);
        $this->item = $this->createCanteenItem($this->school, ['price' => '10.00', 'status' => 'active']);
        $this->warden = $this->campusUser('hostel_warden');
        $this->operator = $this->campusUser('canteen_operator');
        $this->copy = $this->createLibraryCopy($this->createLibraryTitle($this->school), ['campus_id' => $this->a->id]);
        $this->route = $this->createTransportRoute($this->school, ['campus_id' => $this->a->id, 'status' => 'active']);
        $this->librarian = $this->campusUser('librarian');
        $this->coordinator = $this->campusUser('transport_coordinator');
        $this->admin = $this->createUser();
        $this->userIds[] = $this->admin->id;
        $this->assignSchoolRole($this->createMembership($this->admin, $this->school), 'school_admin');
    }

    protected function tearDown(): void
    {
        $this->purgeSchoolsAsAdmin($this->schoolIds);
        DB::connection('pgsql_admin')->table('users')->whereIn('id', $this->userIds)->delete();

        parent::tearDown();
    }

    private function campusUser(string $role): User
    {
        $user = $this->createUser();
        $this->userIds[] = $user->id;
        $this->assignSchoolRole($this->createMembership($user, $this->school), $role, $this->a->id);

        return $user;
    }

    /** @return array<int, string> */
    private function op(string $operation, string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/student-eligibility-op.php', $operation, $this->school->id, ...$args];
    }

    /** @return array<string, array<int, string>> */
    private function stateChanges(): array
    {
        return [
            'transfer' => $this->op('transfer', $this->placement->id, $this->sectionB->id),
            'withdraw' => $this->op('withdraw', $this->placement->id),
            'inactivate' => $this->op('student-inactivate', $this->student->id),
            'year' => $this->op('year-activate', $this->nextYear->id),
            'close' => $this->op('year-close', $this->year->id),
            'campus' => $this->op('campus-inactivate', $this->admin->id, $this->a->id),
        ];
    }

    private function assignResidency(): array
    {
        return $this->op('hostel-assign', $this->warden->id, $this->student->id, $this->bed->id);
    }

    private function placeOrder(): array
    {
        return $this->op('canteen-place', $this->operator->id, $this->student->id, $this->outlet->id, $this->item->id);
    }

    private function rows(string $model): int
    {
        return app(TenantContext::class)->withSchool($this->school, fn () => $model::query()->count());
    }

    /** @return array<string, array{0: string}> */
    public static function stateChange(): array
    {
        return [
            'placement transfer' => ['transfer'],
            'placement withdrawal' => ['withdraw'],
            'Student inactivation' => ['inactivate'],
            'academic-year switch' => ['year'],
            'academic-year close' => ['close'],
            'Campus deactivation' => ['campus'],
        ];
    }

    #[Test]
    #[DataProvider('stateChange')]
    public function a_residency_waiting_on_a_committed_state_change_is_refused(string $change): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder($this->stateChanges()[$change], $this->assignResidency());

        $this->assertSame('ok', $holder);
        $this->assertSame('rejected:HostelResidencyUnavailableException', $contender);
        $this->assertSame(0, $this->rows(HostelResidencyAssignment::class), 'no residency commits on the stale answer');
    }

    #[Test]
    #[DataProvider('stateChange')]
    public function a_state_change_waits_for_a_residency_already_proven(string $change): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder($this->assignResidency(), $this->stateChanges()[$change]);

        $this->assertSame('ok', $holder);
        $this->assertSame('ok', $contender, 'the state change then proceeds: one coherent order, write first');
        $this->assertSame(1, $this->rows(HostelResidencyAssignment::class));
    }

    #[Test]
    #[DataProvider('stateChange')]
    public function an_order_waiting_on_a_committed_state_change_is_refused(string $change): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder($this->stateChanges()[$change], $this->placeOrder());

        $this->assertSame('ok', $holder);
        $this->assertSame('rejected:CanteenStudentNotFoundException', $contender);
        $this->assertSame(0, $this->rows(CanteenOrder::class));
    }

    #[Test]
    #[DataProvider('stateChange')]
    public function a_state_change_waits_for_an_order_already_proven(string $change): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder($this->placeOrder(), $this->stateChanges()[$change]);

        $this->assertSame('ok', $holder);
        $this->assertSame('ok', $contender);
        $this->assertSame(1, $this->rows(CanteenOrder::class));
    }

    #[Test]
    public function a_fulfilment_waiting_on_a_campus_deactivation_is_refused(): void
    {
        $order = $this->createCanteenOrderRaw($this->school, $this->student, $this->outlet);

        [$holder, $contender] = $this->raceWithHeldHolder($this->stateChanges()['campus'], $this->op('canteen-fulfil', $this->operator->id, $order->id));

        $this->assertSame('ok', $holder);
        $this->assertSame('rejected:CanteenOrderNotFoundException', $contender);
        $this->assertSame('pending', app(TenantContext::class)->withSchool($this->school, fn () => CanteenOrder::query()->findOrFail($order->id)->status));
    }

    #[Test]
    public function the_assertion_refuses_to_run_outside_the_consuming_write_transaction(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $this->expectException(LogicException::class);

        app(StudentOperationalEligibility::class)->lockOperationalOn($this->school, $this->student->id, $this->a->id);
    }

    #[Test]
    public function a_cancellation_waiting_on_a_campus_deactivation_is_refused(): void
    {
        $order = $this->createCanteenOrderRaw($this->school, $this->student, $this->outlet);

        [$holder, $contender] = $this->raceWithHeldHolder($this->stateChanges()['campus'], $this->op('canteen-cancel', $this->operator->id, $order->id));

        $this->assertSame('ok', $holder);
        $this->assertSame('rejected:CanteenOrderNotFoundException', $contender);
        $this->assertSame('pending', app(TenantContext::class)->withSchool($this->school, fn () => CanteenOrder::query()->findOrFail($order->id)->status));
    }

    #[Test]
    public function a_campus_deactivation_waits_for_a_cancellation_already_proven(): void
    {
        $order = $this->createCanteenOrderRaw($this->school, $this->student, $this->outlet);

        [$holder, $contender] = $this->raceWithHeldHolder($this->op('canteen-cancel', $this->operator->id, $order->id), $this->stateChanges()['campus']);

        $this->assertSame('ok', $holder);
        $this->assertSame('ok', $contender);
        $this->assertSame('cancelled', app(TenantContext::class)->withSchool($this->school, fn () => CanteenOrder::query()->findOrFail($order->id)->status));
    }

    /**
     * Security review (ADR 0076 §40.5), amended by ORG.7F-2 (§46): a
     * Campus-scoped assignment holds the Student row (FOR NO KEY UPDATE) and
     * then waits on the Enrollment. A transfer now share-locks the Student
     * FIRST -- before its Sections and the Enrollment -- so it waits for the
     * assignment instead of holding the Enrollment the assignment needs: no
     * cycle in either order. The transfer completes once the lock is released,
     * and the assignment that then runs sees the move and refuses.
     */
    #[Test]
    public function a_transfer_waits_for_the_hostel_student_lock_and_never_deadlocks(): void
    {
        [$holder, $transfer] = $this->raceWithHeldHolder($this->op('hostel-student-lock', $this->student->id), $this->stateChanges()['transfer']);

        $this->assertSame('ok', $holder);
        $this->assertSame('ok', $transfer, 'the transfer completes after the Hostel Student lock is released');

        $assign = new Process($this->assignResidency());
        $assign->run();
        $this->assertSame('rejected:HostelResidencyUnavailableException', trim($assign->getOutput()));
    }

    /** @return array<int, string> */
    private function checkout(): array
    {
        return $this->op('library-checkout', $this->librarian->id, $this->student->id, $this->copy->id);
    }

    /** @return array<int, string> */
    private function ride(): array
    {
        return $this->op('transport-assign', $this->coordinator->id, $this->student->id, $this->route->id);
    }

    #[Test]
    #[DataProvider('stateChange')]
    public function a_checkout_waiting_on_a_committed_state_change_is_refused(string $change): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder($this->stateChanges()[$change], $this->checkout());

        $this->assertSame('ok', $holder);
        $this->assertSame('rejected:LibraryCheckoutUnavailableException', $contender);
        $this->assertSame(0, $this->rows(LibraryLoan::class));
    }

    #[Test]
    #[DataProvider('stateChange')]
    public function a_state_change_waits_for_a_checkout_already_proven(string $change): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder($this->checkout(), $this->stateChanges()[$change]);

        $this->assertSame('ok', $holder);
        $this->assertSame('ok', $contender);
        $this->assertSame(1, $this->rows(LibraryLoan::class));
    }

    #[Test]
    #[DataProvider('stateChange')]
    public function a_rider_assignment_waiting_on_a_committed_state_change_is_refused(string $change): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder($this->stateChanges()[$change], $this->ride());

        $this->assertSame('ok', $holder);
        $this->assertSame('rejected:TransportAssignmentUnavailableException', $contender);
        $this->assertSame(0, $this->rows(TransportStudentAssignment::class));
    }

    #[Test]
    #[DataProvider('stateChange')]
    public function a_state_change_waits_for_a_rider_assignment_already_proven(string $change): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder($this->ride(), $this->stateChanges()[$change]);

        $this->assertSame('ok', $holder);
        $this->assertSame('ok', $contender);
        $this->assertSame(1, $this->rows(TransportStudentAssignment::class));
    }

    #[Test]
    public function a_checkout_waiting_on_a_copy_move_uses_the_new_campus(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder($this->op('library-copy-move', $this->admin->id, $this->copy->id, $this->b->id), $this->checkout());

        $this->assertSame('ok', $holder);
        $this->assertSame('rejected:LibraryCheckoutUnavailableException', $contender, 'the Copy moved out of the librarian\'s Campus first');
        $this->assertSame(0, $this->rows(LibraryLoan::class));
    }

    #[Test]
    public function a_copy_move_waiting_on_a_checkout_is_refused_and_the_loan_keeps_its_campus(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder($this->checkout(), $this->op('library-copy-move', $this->admin->id, $this->copy->id, $this->b->id));

        $this->assertSame('ok', $holder);
        $this->assertSame('rejected:ValidationException', $contender);
        $this->assertSame([$this->a->id, $this->a->id], app(TenantContext::class)->withSchool($this->school, fn () => [
            LibraryCopy::query()->findOrFail($this->copy->id)->campus_id, LibraryLoan::query()->sole()->campus_id,
        ]));
    }

    #[Test]
    public function a_rider_assignment_waiting_on_a_route_campus_change_uses_the_new_campus(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder($this->op('route-campus', $this->admin->id, $this->route->id, $this->b->id), $this->ride());

        $this->assertSame('ok', $holder);
        $this->assertSame('rejected:TransportAssignmentUnavailableException', $contender);
        $this->assertSame(0, $this->rows(TransportStudentAssignment::class));
    }

    #[Test]
    public function a_route_campus_change_waiting_on_a_rider_is_refused(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder($this->ride(), $this->op('route-campus', $this->admin->id, $this->route->id, $this->b->id));

        $this->assertSame('ok', $holder);
        $this->assertSame('rejected:ValidationException', $contender);
        $this->assertSame($this->a->id, app(TenantContext::class)->withSchool($this->school, fn () => TransportRoute::query()->findOrFail($this->route->id)->campus_id));
    }
}
