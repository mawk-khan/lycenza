<?php

namespace Tests\Feature\Students;

use App\Domain\Students\Application\Movements\StudentMovementRegister;
use App\Domain\Students\Application\Movements\StudentMovementType;
use App\Http\Controllers\App\StudentMovementController;
use App\Support\Authorization\Campus\CampusScopeCatalogue;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * SIS.EXP-2: the movement register is a read-only projection of the
 * canonical Student history -- never a second movement engine, an export,
 * a report builder, or a reader of the audit ledger.
 */
class StudentMovementRegisterArchitectureTest extends TestCase
{
    /** The class's code without its comments (a docblock may name what the code never touches). */
    private function source(string $class): string
    {
        $tokens = token_get_all((string) file_get_contents((string) (new ReflectionClass($class))->getFileName()));

        return implode('', array_map(fn ($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t, $tokens));
    }

    #[Test]
    public function the_register_reads_only_the_canonical_history_and_writes_nothing(): void
    {
        $register = $this->source(StudentMovementRegister::class);
        preg_match_all('/\b(?:FROM|JOIN)\s+(?!LATERAL\b)([a-z_]+)/i', $register, $tables);
        // The register's own CTEs (pl, dest, ro, ranked, ...) are not tables.
        preg_match_all('/\b([a-z_]+)\s+AS\s+(?:MATERIALIZED\s+)?\(/i', $register, $ctes);
        $this->assertEqualsCanonicalizing(
            ['enrollment_rollover_items', 'student_enrollments', 'student_lifecycle_events'],
            array_values(array_diff(array_unique(array_map('strtolower', $tables[1])), array_map('strtolower', $ctes[1]))),
            'the SQL projection reads placements, executed rollovers and lifecycle events only',
        );
        foreach (['school_audit_events', 'AuditRecorder', 'guardian', 'date_of_birth', 'acted_by_user_id', 'charges', 'payments'] as $absent) {
            $this->assertStringNotContainsStringIgnoringCase($absent, $register, "the register never reads {$absent}");
        }
        foreach ([$register, $this->source(StudentMovementController::class)] as $source) {
            foreach (['->insert(', '->update(', '->delete(', '->save(', '->create(', '->forceFill(', 'DB::statement', 'lockForUpdate', "lock('"] as $write) {
                $this->assertStringNotContainsString($write, $source, "the register never writes ({$write})");
            }
        }
    }

    #[Test]
    public function there_is_exactly_one_get_route_and_no_mutation_export_or_print(): void
    {
        $routes = array_values(array_filter(RouteFacade::getRoutes()->getRoutes(), fn (Route $r) => str_starts_with((string) $r->getName(), 'app.students.movements')));
        $this->assertCount(1, $routes);
        $this->assertSame(['GET', 'HEAD'], $routes[0]->methods());
        $this->assertContains('capability:enrollments.view', $routes[0]->gatherMiddleware());
        $this->assertSame([], array_filter(RouteFacade::getRoutes()->getRoutes(), fn (Route $r) => str_contains($r->uri(), 'movements') && preg_match('/export|csv|xlsx|pdf|print|download/i', $r->uri())));
        foreach ([$this->source(StudentMovementController::class), (string) file_get_contents(resource_path('js/Pages/App/Students/Movements.vue'))] as $source) {
            $this->assertDoesNotMatchRegularExpression('/\b(csv|xlsx|download|window\.print)\b/i', $source);
        }
    }

    #[Test]
    public function it_is_a_declared_whole_school_operation_with_no_adoption_change(): void
    {
        $this->assertSame('enrollments.view', CampusScopeCatalogue::WHOLE_SCHOOL_OPERATIONS['app.students.movements']['capability']);
        $this->assertTrue(CampusScopeCatalogue::isAdopted('enrollments.view'), 'the existing adoption is unchanged');
        $this->assertTrue(CampusScopeCatalogue::isAdopted('students.view'));
    }

    #[Test]
    public function the_vocabulary_and_sorts_are_closed(): void
    {
        $this->assertSame(
            ['initial_enrollment', 're_enrollment', 'student_reactivated', 'section_change', 'campus_transfer', 'promotion', 'retention', 'withdrawal', 'placement_completed', 'student_deactivated'],
            array_map(fn (StudentMovementType $t) => $t->value, StudentMovementType::cases()),
        );
        $this->assertSame(['date_desc', 'date_asc', 'student', 'type'], array_keys(StudentMovementRegister::SORTS));
        foreach (StudentMovementType::cases() as $type) {
            $this->assertContains($type->category(), StudentMovementType::CATEGORIES);
        }
    }
}
