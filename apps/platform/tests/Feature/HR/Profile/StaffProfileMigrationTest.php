<?php

namespace Tests\Feature\HR\Profile;

use App\Models\School;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * HR.2A: the staff-profile migration maps a legacy free-text HR document
 * category only where the meaning is certain (`id_proof` on a Highly
 * Sensitive record -> `identity_document`), keeps a legacy path-only record
 * untouched, and refuses -- changing nothing -- on anything it would have to
 * guess. Run on the admin connection inside a transaction that is always
 * rolled back (the LmsOwnershipMigrationRollbackTest pattern).
 */
class StaffProfileMigrationTest extends TestCase
{
    use CreatesTenancyFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    protected function tearDown(): void
    {
        $this->deleteSchoolAsAdmin($this->school);

        parent::tearDown();
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2027_01_27_090000_create_hr_staff_profile_foundation.php');
    }

    private function onAdminAndRollBack(callable $callback): void
    {
        $admin = DB::connection('pgsql_admin');
        $default = DB::getDefaultConnection();
        $admin->beginTransaction();
        DB::setDefaultConnection('pgsql_admin');

        try {
            $callback();
        } finally {
            DB::setDefaultConnection($default);
            $admin->rollBack();
        }
    }

    private function legacyRecord(string $employeeId, string $category, string $tier): string
    {
        $id = (string) Str::uuid7();
        DB::connection('pgsql_admin')->table('employee_documents')->insert([
            'id' => $id, 'school_id' => $this->school->id, 'employee_id' => $employeeId, 'category' => $category, 'classification_tier' => $tier,
            'storage_disk' => 'local', 'storage_path' => "schools/{$this->school->id}/employee-documents/{$id}.pdf", 'original_filename' => 'legacy.pdf',
            'mime_type' => 'application/pdf', 'size_bytes' => 10, 'uploaded_at' => now(), 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    #[Test]
    public function a_certain_legacy_category_is_mapped_and_the_legacy_path_is_kept(): void
    {
        $this->school = $this->createSchool();
        $employee = $this->createEmployee($this->school);

        $this->onAdminAndRollBack(function () use ($employee) {
            $this->migration()->down();
            $id = $this->legacyRecord($employee->id, 'id_proof', 'highly_sensitive');

            $this->migration()->up();

            $row = DB::connection('pgsql_admin')->table('employee_documents')->where('id', $id)->first();
            $this->assertSame(['identity_document', null], [$row->category, $row->document_id]);
            $this->assertNotNull($row->storage_path, 'pre-HR.2A path metadata stays untouched');
        });
    }

    #[Test]
    public function an_unmappable_or_weakly_classified_legacy_record_refuses_the_migration_and_changes_nothing(): void
    {
        $this->school = $this->createSchool();
        $employee = $this->createEmployee($this->school);

        $this->onAdminAndRollBack(function () use ($employee) {
            $this->migration()->down();
            $this->legacyRecord($employee->id, 'mystery_scan', 'restricted');
            $this->legacyRecord($employee->id, 'id_proof', 'restricted');

            try {
                $this->migration()->up();
                $this->fail('legacy categories were guessed');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('refused (nothing changed)', $e->getMessage());
                $this->assertStringContainsString('"mystery_scan"', $e->getMessage());
                $this->assertStringContainsString('not classified Highly Sensitive', $e->getMessage());
            }
            $this->assertFalse(Schema::connection('pgsql_admin')->hasTable('employee_reference_codes'), 'nothing was created');
        });
    }
}
