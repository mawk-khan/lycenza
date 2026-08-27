<?php

namespace Tests\Feature\Transport;

use App\Domain\Transport\Infrastructure\TransportRouteAssignment;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * REQUIRED real-concurrency proof (checkpoint brief section 15): two
 * GENUINELY separate OS processes -- not two sequential calls in one
 * PHP process -- both attempt to assign a DIFFERENT Vehicle/Driver
 * pair to the SAME Route at the same time, against real PostgreSQL.
 * Mirrors LibraryLoanCheckoutConcurrencyTest's exact pattern, adapted
 * for TransportRouteAssignmentService::assign()'s auto-replace
 * semantics: `lockForUpdate()` on the Route row itself serializes the
 * two transactions before either reaches its
 * "previous active assignment" read, so (unlike Student assignment's
 * reject-if-occupied model below) BOTH processes succeed here -- one
 * assignment ends up `ended` (the loser of the lock race, whose
 * assignment the winner's later call replaced), the other stays
 * `active`. The invariant under test is that the database NEVER
 * transiently or permanently holds two simultaneously active
 * assignments for the same Route, proven by the end state, not by
 * either process throwing.
 */
class TransportRouteAssignmentConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            $this->school->delete(); // cascades transport_routes/vehicles/route_assignments
        }

        parent::tearDown();
    }

    #[Test]
    public function two_real_concurrent_processes_assigning_the_same_route_leave_exactly_one_active_assignment(): void
    {
        $this->school = $this->createSchool();
        $context = app(TenantContext::class);

        $route = $this->createTransportRoute($this->school);
        $vehicleA = $this->createTransportVehicle($this->school);
        $vehicleB = $this->createTransportVehicle($this->school);
        $driverA = $this->createEmployee($this->school);
        $driverB = $this->createEmployee($this->school);

        $script = __DIR__.'/../../Support/assign-transport-route.php';
        $processA = new Process(['php', $script, $this->school->id, $route->id, $vehicleA->id, $driverA->id]);
        $processB = new Process(['php', $script, $this->school->id, $route->id, $vehicleB->id, $driverB->id]);
        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        $outputs = [$processA->getOutput(), $processB->getOutput()];
        $assignedCount = count(array_filter($outputs, fn ($o) => str_starts_with($o, 'assigned:')));

        $this->assertSame(2, $assignedCount, 'Both concurrent assign() calls succeed under the auto-replace/row-lock model -- neither is rejected.');

        $activeAssignments = $context->withSchool(
            $this->school,
            fn () => TransportRouteAssignment::query()->where('route_id', $route->id)->where('status', 'active')->count(),
        );
        $this->assertSame(1, $activeAssignments, 'The database must never hold two simultaneously active assignments for the same Route.');

        $totalAssignments = $context->withSchool(
            $this->school,
            fn () => TransportRouteAssignment::query()->where('route_id', $route->id)->count(),
        );
        $this->assertSame(2, $totalAssignments, 'Both assignment rows must exist -- one active, one ended.');
    }
}
