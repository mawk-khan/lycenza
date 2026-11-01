<?php

namespace Tests\Feature\Students;

use App\Domain\Students\Application\Bulk\BulkPlacementOperation;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SIS 11.13: the bulk move is an orchestrator over the single-Student
 * primitives -- never a second placement engine, a generic bulk-action
 * framework, a job subsystem or a CSV mutation path.
 */
class BulkStudentOperationsArchitectureTest extends TestCase
{
    private function source(string $path): string
    {
        return (string) file_get_contents(base_path($path));
    }

    /** @return array<string, string> */
    private function bulkFiles(): array
    {
        $files = [];
        foreach (glob(base_path('app/Domain/Students/Application/Bulk/*.php')) ?: [] as $file) {
            $files[basename($file)] = (string) file_get_contents($file);
        }
        $files['StudentBulkOperationController.php'] = $this->source('app/Http/Controllers/App/StudentBulkOperationController.php');

        return $files;
    }

    #[Test]
    public function placements_are_moved_only_by_the_existing_single_student_primitives(): void
    {
        $service = $this->source('app/Domain/Students/Application/Bulk/BulkStudentPlacementService.php');
        $this->assertStringContainsString('$this->enrollments->transferPlacement(', $service);
        $this->assertStringContainsString('$this->transfers->transfer(', $service);

        foreach ($this->bulkFiles() as $name => $code) {
            foreach (['forceCreate(', '->insert(', '->delete(', '->forceFill(', '->save(', 'DB::table(', 'DB::statement('] as $write) {
                $this->assertStringNotContainsString($write, $code, "{$name} must not write rows itself ({$write})");
            }
            $this->assertDoesNotMatchRegularExpression('/->update\(/', $code, "{$name} must not update rows itself");
        }
    }

    #[Test]
    public function it_touches_no_other_module_queue_or_file_input(): void
    {
        foreach ($this->bulkFiles() as $name => $code) {
            foreach (['Domain\\Hostel', 'Domain\\Transport', 'Domain\\Fees', 'Domain\\Payments', 'Domain\\Attendance', 'Domain\\Documents', 'Application\\Groups', 'Domain\\Communications', 'Domain\\Library'] as $module) {
                $this->assertStringNotContainsString($module, $code, "{$name} must not reach {$module}: its records are never moved by a bulk move");
            }
            foreach (['dispatch(', 'ShouldQueue', 'Bus::', 'Queue::', 'UploadedFile', "'file'", 'fgetcsv', 'str_getcsv'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code, "{$name}: no job subsystem or CSV/file mutation input ({$forbidden})");
            }
        }
    }

    #[Test]
    public function the_controller_holds_no_placement_rule(): void
    {
        $controller = $this->source('app/Http/Controllers/App/StudentBulkOperationController.php');
        foreach (['StudentEnrollmentService', 'transferPlacement', 'CampusStudentTransferService', 'StudentTransferBlocker', 'lockForUpdate', 'sharedLock'] as $rule) {
            $this->assertStringNotContainsString($rule, $controller, "the controller delegates every rule ({$rule})");
        }
    }

    #[Test]
    public function no_bulk_capability_table_or_generic_action_catalogue_exists(): void
    {
        // No `students.bulk.*`-style key: bulk never deserves broader authority than one move.
        preg_match_all("/'key' => '([a-z0-9_.]+)'/", $this->source('database/seeders/CapabilityAndRoleSeeder.php'), $keys);
        $this->assertSame([], array_values(array_filter($keys[1], fn (string $k) => str_contains($k, 'bulk'))));
        foreach (glob(base_path('database/migrations/*.php')) ?: [] as $migration) {
            $this->assertStringNotContainsString('bulk', strtolower(basename($migration)), 'no bulk_jobs or bulk-action table');
        }
        // Exactly the two moves: a third operation is a reviewed change, never configuration.
        $this->assertSame(['section_change', 'campus_transfer'], array_column(BulkPlacementOperation::cases(), 'value'));
    }
}
