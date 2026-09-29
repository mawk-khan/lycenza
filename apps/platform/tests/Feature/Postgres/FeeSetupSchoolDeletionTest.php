<?php

namespace Tests\Feature\Postgres;

use App\Models\School;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\TestCase;

/**
 * FEE.1: the draft-only child trigger and the RESTRICT/NO ACTION foreign
 * keys must never block an administrative School deletion
 * (TestCase::deleteSchoolAsAdmin, ADR 0047) -- a cascade reaches the lines
 * of an ACTIVE structure at trigger depth > 1 and is let through.
 * Committed data, so not transactional.
 */
class FeeSetupSchoolDeletionTest extends TestCase
{
    protected $connectionsToTransact = [];

    private ?School $school = null;

    protected function tearDown(): void
    {
        $this->deleteSchoolAsAdmin($this->school);

        parent::tearDown();
    }

    #[Test]
    public function a_school_with_an_active_amended_fee_structure_and_selections_can_be_deleted(): void
    {
        $admin = DB::connection('pgsql_admin');
        $this->school = School::factory()->create();
        $sid = $this->school->id;
        $id = fn () => (string) new UuidV7;
        $row = fn (array $r) => [...$r, 'school_id' => $sid, 'created_at' => now(), 'updated_at' => now()];

        $admin->table('academic_years')->insert($row(['id' => $year = $id(), 'name' => 'Y', 'code' => 'Y1', 'starts_on' => '2026-06-01', 'ends_on' => '2027-05-31', 'status' => 'active']));
        $admin->table('grade_levels')->insert($row(['id' => $grade = $id(), 'name' => 'G', 'code' => 'G1', 'sequence' => 1, 'status' => 'active']));
        $admin->table('ledger_accounts')->insert($row(['id' => $asset = $id(), 'code' => 'A', 'name' => 'A', 'type' => 'asset', 'currency' => 'INR', 'status' => 'active']));
        $admin->table('ledger_accounts')->insert($row(['id' => $income = $id(), 'code' => 'I', 'name' => 'I', 'type' => 'income', 'currency' => 'INR', 'status' => 'active']));
        $admin->table('fee_heads')->insert($row(['id' => $head = $id(), 'code' => 'T', 'name' => 'T', 'status' => 'active', 'receivable_ledger_account_id' => $asset, 'revenue_ledger_account_id' => $income, 'currency' => 'INR']));
        $admin->table('students')->insert($row(['id' => $student = $id(), 'student_number' => 'S-1', 'first_name' => 'A', 'last_name' => 'B', 'date_of_birth' => '2015-01-01', 'status' => 'active']));

        $structure = function (?string $supersedes, string $code) use ($admin, $row, $id, $year, $grade, $head, $student): string {
            $admin->table('fee_structures')->insert($row(['id' => $s = $id(), 'academic_year_id' => $year, 'grade_level_id' => $grade, 'code' => $code, 'name' => $code, 'status' => 'draft', 'supersedes_fee_structure_id' => $supersedes]));
            $admin->table('fee_structure_lines')->insert($row(['id' => $l = $id(), 'fee_structure_id' => $s, 'fee_head_id' => $head, 'is_optional' => true, 'frequency' => 'one_time', 'amount' => '10.00', 'currency' => 'INR']));
            $admin->table('fee_structure_installments')->insert($row(['id' => $id(), 'fee_structure_line_id' => $l, 'sequence' => 1, 'label' => 'A', 'billing_period_key' => 'A', 'period_starts_on' => '2026-06-01', 'period_ends_on' => '2027-05-31', 'due_date' => '2026-06-01', 'amount' => '10.00', 'currency' => 'INR']));
            $admin->table('fee_optional_selections')->insert($row(['id' => $sel = $id(), 'student_id' => $student, 'academic_year_id' => $year, 'fee_head_id' => $head, 'fee_structure_line_id' => $l, 'status' => 'active']));
            if ($supersedes === null) {
                $admin->table('fee_optional_selections')->where('id', $sel)->update(['status' => 'withdrawn', 'withdrawn_at' => now()]);
            }

            return $s;
        };

        $v1 = $structure(null, 'V1');
        $admin->table('fee_structures')->where('id', $v1)->update(['status' => 'active', 'activated_at' => now()]);
        $v2 = $structure($v1, 'V2');
        $admin->table('fee_structures')->where('id', $v1)->update(['status' => 'retired', 'retired_at' => now()]);
        $admin->table('fee_structures')->where('id', $v2)->update(['status' => 'active', 'activated_at' => now()]);

        $this->deleteSchoolAsAdmin($this->school);
        $this->school = null;

        foreach (['fee_structures', 'fee_structure_lines', 'fee_structure_installments', 'fee_optional_selections', 'fee_heads'] as $table) {
            $this->assertSame(0, $admin->table($table)->where('school_id', $sid)->count(), $table);
        }
    }
}
