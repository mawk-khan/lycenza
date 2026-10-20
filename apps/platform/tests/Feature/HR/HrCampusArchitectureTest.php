<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeAssignmentService;
use App\Domain\HR\Application\EmployeeLifecycleService;
use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Application\ReportingHierarchyService;
use App\Support\Authorization\Campus\CampusScopeCatalogue;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * ORG.7E-1 (ADR 0076 §43): the HR Campus contract is structural.
 *
 * - Every writer of an Employee's placement, and every Campus-scoped write
 *   whose authority depends on it, takes EmployeePlacementLock FIRST in its
 *   transaction -- one canonical lock and order.
 * - The declared whole-School HR operations re-check whole-School authority
 *   in the service (HrEmployeeAccess::requireWholeSchool), never a Campus
 *   scope; the Campus-aware ones resolve the scope and build the guard.
 * - Every declared whole-School HR route names an adopted HR key.
 */
class HrCampusArchitectureTest extends TestCase
{
    /** Service method => the placement lock it must take (before any other lock or write). */
    private const PLACEMENT_LOCKED = [
        [EmployeeService::class, 'update', 'forEmployeeWrite'],
        [EmployeeService::class, 'archive', 'forEmployeeWrite'],
        [EmployeeService::class, 'restore', 'forEmployeeWrite'],
        [EmployeeAssignmentService::class, 'create', 'forPlacementChange'],
        [EmployeeAssignmentService::class, 'end', 'forPlacementChange'],
        // A scoped set-primary takes the exclusive lock first (security review M2).
        [EmployeeAssignmentService::class, 'setPrimary', 'forEmployeeWrite'],
        [EmploymentService::class, 'end', 'forPlacementChange'],
    ];

    /** Whole-School-only HR service methods. */
    private const WHOLE_SCHOOL = [
        [EmployeeService::class, 'create'],
        [EmployeeService::class, 'archive'],
        [EmployeeService::class, 'restore'],
        [EmployeeService::class, 'linkUser'],
        [EmployeeService::class, 'unlinkUser'],
        [EmploymentService::class, 'create'],
        [EmploymentService::class, 'update'],
        [EmploymentService::class, 'end'],
        [EmployeeLifecycleService::class, 'separate'],
        [EmployeeLifecycleService::class, 'rehire'],
    ];

    /** Campus-aware HR service methods: scope resolved, guard built. */
    private const CAMPUS_AWARE = [
        [EmployeeService::class, 'update'],
        [EmployeeAssignmentService::class, 'create'],
        [EmployeeAssignmentService::class, 'end'],
        [EmployeeAssignmentService::class, 'setPrimary'],
        [ReportingHierarchyService::class, 'setManager'],
    ];

    private function body(string $class, string $method): string
    {
        $m = new ReflectionMethod($class, $method);
        $lines = file((string) $m->getFileName());

        return implode('', array_slice($lines, $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));
    }

    #[Test]
    public function placement_writers_take_the_canonical_placement_lock_first(): void
    {
        foreach (self::PLACEMENT_LOCKED as [$class, $method, $lock]) {
            $body = $this->body($class, $method);
            $transaction = strpos($body, 'DB::transaction(');
            $this->assertNotFalse($transaction, "{$class}::{$method} writes inside a transaction");
            $at = strpos($body, "placement->{$lock}(", (int) $transaction);
            $this->assertNotFalse($at, "{$class}::{$method} takes EmployeePlacementLock::{$lock}()");
            foreach (['refuseOwnEmployee(', 'lockForUpdate(', '->update(', 'sharedLock('] as $later) {
                $other = strpos($body, $later, (int) $transaction);
                if ($other !== false) {
                    $this->assertGreaterThan($at, $other, "{$class}::{$method}: the placement lock comes before {$later}");
                }
            }
        }
        // Employment creation keeps the stronger Employee FOR UPDATE it always took.
        $this->assertStringContainsString("Employee::query()->where('id', \$employee->id)->lockForUpdate()", $this->body(EmploymentService::class, 'createRecord'));
    }

    #[Test]
    public function whole_school_hr_operations_recheck_whole_school_authority(): void
    {
        foreach (self::WHOLE_SCHOOL as [$class, $method]) {
            $body = $this->body($class, $method);
            $this->assertStringContainsString('access->requireWholeSchool(', $body, "{$class}::{$method} is a whole-School HR operation");
            $this->assertStringNotContainsString('authorizeCampusScopeFor(', $body, "{$class}::{$method} must not accept a Campus scope");
        }
        foreach (self::CAMPUS_AWARE as [$class, $method]) {
            $body = $this->body($class, $method);
            $this->assertStringContainsString('authorizeCampusScopeFor(', $body, "{$class}::{$method} resolves the actor's CampusScope");
            $this->assertStringContainsString('access->writeGuard(', $body, "{$class}::{$method} builds the Campus write guard");
        }
    }

    #[Test]
    public function the_unauthorized_write_primitives_are_reached_only_through_an_authorizing_service(): void
    {
        $allowed = [
            'app/Domain/HR/Application/EmployeeService.php',
            'app/Domain/HR/Application/EmploymentService.php',
            'app/Domain/HR/Application/EmployeeAssignmentService.php',
            'app/Domain/HR/Application/EmployeeOnboardingService.php',
        ];
        foreach (Finder::create()->files()->in([app_path(), base_path('routes')])->name('*.php') as $file) {
            $path = str_replace(base_path().'/', '', (string) $file->getRealPath());
            if (preg_match('/->(createRecord|setPrimaryRecord)\(/', $file->getContents()) === 1) {
                $this->assertContains($path, $allowed, "{$path} calls an HR write primitive that carries no authorization of its own (ADR 0076 §43)");
            }
        }
    }

    #[Test]
    public function every_declared_whole_school_hr_operation_names_an_adopted_hr_key(): void
    {
        $hr = array_filter(CampusScopeCatalogue::WHOLE_SCHOOL_OPERATIONS, fn (array $d) => str_starts_with($d['capability'], 'hr.'));
        $this->assertCount(24, $hr, 'ORG.7E-1 declared 24 whole-School HR operations (ADR 0076 §43): update the report when this changes.');
        foreach ($hr as $route => $declaration) {
            $this->assertSame('hr', CampusScopeCatalogue::ADOPTED[$declaration['capability']], "{$route} names an adopted HR key");
        }
    }
}
