<?php

namespace Tests\Feature\HR\Profile;

use App\Models\School;
use App\Models\User;
use App\Support\Operations\CheckResult;
use App\Support\Operations\DatabaseRoleVerifier;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * HR.2A: the staff-profile database backstops, proven with RAW writes as the
 * runtime role (no service in the way).
 */
class StaffProfileDatabaseInvariantsTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->actor = $this->createUser();
    }

    private function inSchool(callable $fn): mixed
    {
        return app(TenantContext::class)->withSchool($this->school, $fn);
    }

    private function sqlstate(callable $write): ?string
    {
        try {
            DB::transaction(fn () => $this->inSchool($write));

            return null;
        } catch (QueryException $e) {
            return (string) $e->getCode();
        }
    }

    private function address(string $employeeId, string $type = 'current'): string
    {
        $id = (string) Str::uuid7();
        DB::table('employee_addresses')->insert(['id' => $id, 'school_id' => $this->school->id, 'employee_id' => $employeeId, 'address_type' => $type, 'address_line1' => '1 Test Lane', 'country_code' => 'IN', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function archive(string $table, string $id): void
    {
        DB::table($table)->where('id', $id)->update(['archived_at' => now(), 'archived_by_user_id' => $this->actor->id, 'archive_reason' => 'no_longer_current']);
    }

    #[Test]
    public function an_archived_record_is_frozen_and_the_runtime_role_cannot_delete_any_profile_record(): void
    {
        $employee = $this->createEmployee($this->school);
        $id = $this->inSchool(fn () => $this->address($employee->id));

        $this->assertSame('23514', $this->sqlstate(fn () => DB::table('employee_addresses')->where('id', $id)->update(['archived_at' => now(), 'archived_by_user_id' => $this->actor->id, 'archive_reason' => 'no_longer_current', 'city' => 'Changed'])), 'archiving changes nothing else');
        $this->assertSame('23514', $this->sqlstate(fn () => DB::table('employee_addresses')->where('id', $id)->update(['archived_at' => now()])), 'all-or-none shape');
        $this->assertSame('23514', $this->sqlstate(fn () => DB::table('employee_addresses')->where('id', $id)->update(['archived_at' => now(), 'archived_by_user_id' => $this->actor->id, 'archive_reason' => 'free text'])), 'closed reason');
        $this->assertNull($this->sqlstate(fn () => $this->archive('employee_addresses', $id)));
        $this->assertSame('23514', $this->sqlstate(fn () => DB::table('employee_addresses')->where('id', $id)->update(['city' => 'Later'])), 'archived = final');
        $this->assertSame('23514', $this->sqlstate(fn () => DB::table('employee_addresses')->insert(['id' => (string) Str::uuid7(), 'school_id' => $this->school->id, 'employee_id' => $employee->id, 'address_type' => 'permanent', 'address_line1' => 'x', 'country_code' => 'IN', 'archived_at' => now(), 'archived_by_user_id' => $this->actor->id, 'archive_reason' => 'superseded', 'created_at' => now(), 'updated_at' => now()])), 'never created archived');

        foreach (['employee_addresses', 'employee_emergency_contacts', 'employee_notes', 'employee_qualifications', 'employee_experience_records', 'employee_certifications', 'employee_documents', 'employee_reference_codes'] as $table) {
            $this->assertSame('42501', $this->sqlstate(fn () => DB::table($table)->where('school_id', $this->school->id)->delete()), "{$table}: no runtime DELETE");
        }
    }

    #[Test]
    public function an_archived_address_frees_its_kind_and_an_archived_contact_is_never_primary(): void
    {
        $employee = $this->createEmployee($this->school);
        $old = $this->inSchool(fn () => $this->address($employee->id));

        $this->assertSame('23505', $this->sqlstate(fn () => $this->address($employee->id)), 'one current address per kind');
        $this->inSchool(fn () => $this->archive('employee_addresses', $old));
        $this->assertNull($this->sqlstate(fn () => $this->address($employee->id)), 'the archived one stays as history beside the new current one');

        $contact = (string) Str::uuid7();
        $this->inSchool(fn () => DB::table('employee_emergency_contacts')->insert(['id' => $contact, 'school_id' => $this->school->id, 'employee_id' => $employee->id, 'name' => 'Contact', 'phone' => '+91 98765 43210', 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]));
        $this->assertSame('23514', $this->sqlstate(fn () => DB::table('employee_emergency_contacts')->where('id', $contact)->update(['archived_at' => now(), 'archived_by_user_id' => $this->actor->id, 'archive_reason' => 'superseded'])), 'an archived contact is never primary');
        $this->assertNull($this->sqlstate(fn () => DB::table('employee_emergency_contacts')->where('id', $contact)->update(['is_primary' => false, 'archived_at' => now(), 'archived_by_user_id' => $this->actor->id, 'archive_reason' => 'superseded'])));
    }

    #[Test]
    public function reference_codes_are_closed_school_unique_never_reused_and_immutable(): void
    {
        [$a, $b] = [$this->createEmployee($this->school), $this->createEmployee($this->school)];
        $code = fn (string $employeeId, string $kind, string $value) => DB::table('employee_reference_codes')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $this->school->id, 'employee_id' => $employeeId, 'kind' => $kind, 'value' => $value,
            'recorded_by_user_id' => $this->actor->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertNull($this->sqlstate(fn () => $code($a->id, 'erp', 'ERP-001')));
        $this->assertSame('23505', $this->sqlstate(fn () => $code($b->id, 'erp', 'ERP-001')), 'School-unique per kind');
        $this->assertNull($this->sqlstate(fn () => $code($b->id, 'staff_reference', 'ERP-001')), 'another kind may hold the same text');
        $this->assertSame('23505', $this->sqlstate(fn () => $code($a->id, 'erp', 'ERP-002')), 'one live code per kind');
        $this->assertSame('23514', $this->sqlstate(fn () => $code($a->id, 'biometric', 'B-1')), 'closed kinds (a biometric code is not one)');
        $this->assertSame('23514', $this->sqlstate(fn () => $code($a->id, 'legacy', 'lower-case')), 'normalized value');

        $id = $this->inSchool(fn () => DB::table('employee_reference_codes')->where('employee_id', $a->id)->value('id'));
        $this->assertSame('23514', $this->sqlstate(fn () => DB::table('employee_reference_codes')->where('id', $id)->update(['value' => 'ERP-009'])), 'immutable value');
        $this->assertNull($this->sqlstate(fn () => DB::table('employee_reference_codes')->where('id', $id)->update(['retired_at' => now(), 'retired_by_user_id' => $this->actor->id, 'retire_reason' => 'replaced'])));
        $this->assertSame('23514', $this->sqlstate(fn () => DB::table('employee_reference_codes')->where('id', $id)->update(['retire_reason' => 'no_longer_used'])), 'retired = final');
        $this->assertSame('23505', $this->sqlstate(fn () => $code($b->id, 'erp', 'ERP-001')), 'a retired value is never reused');
        $this->assertNull($this->sqlstate(fn () => $code($a->id, 'erp', 'ERP-002')), 'a new live code after retirement');
    }

    #[Test]
    public function an_hr_document_record_references_a_governed_document_of_its_own_employee(): void
    {
        $employee = $this->createEmployee($this->school);
        $document = $this->createDocumentForEmployee($employee, ['classification_tier' => 'sensitive']);
        $row = fn (array $over) => array_merge([
            'id' => (string) Str::uuid7(), 'school_id' => $this->school->id, 'employee_id' => $employee->id, 'document_id' => $document->id,
            'category' => 'employment_contract', 'classification_tier' => 'restricted', 'original_filename' => 'f.pdf', 'mime_type' => 'application/pdf',
            'size_bytes' => 10, 'uploaded_at' => now(), 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ], $over);

        $this->assertSame('23514', $this->sqlstate(fn () => DB::table('employee_documents')->insert($row(['document_id' => null, 'storage_disk' => 'local', 'storage_path' => 'x/y.pdf']))), 'never a new raw path');
        $this->assertSame('23514', $this->sqlstate(fn () => DB::table('employee_documents')->insert($row(['category' => 'other']))), 'closed category');
        $this->assertSame('23514', $this->sqlstate(fn () => DB::table('employee_documents')->insert($row(['category' => 'identity_document']))), 'an identity document is Highly Sensitive');
        $foreign = $this->createDocumentForEmployee($this->createEmployee($other = $this->createSchool()));
        $this->assertSame('23503', $this->sqlstate(fn () => DB::table('employee_documents')->insert($row(['document_id' => $foreign->id]))), 'same-School Document only');
        $this->assertNull($this->sqlstate(fn () => DB::table('employee_documents')->insert($row([]))));
        $id = $this->inSchool(fn () => DB::table('employee_documents')->where('document_id', $document->id)->value('id'));
        $this->assertSame('23514', $this->sqlstate(fn () => DB::table('employee_documents')->where('id', $id)->update(['document_id' => $this->createDocumentForEmployee($employee)->id])), 'the file never changes');
        $this->assertNull($this->sqlstate(fn () => DB::table('employee_documents')->where('id', $id)->update(['status' => 'archived'])));
        $this->assertSame('23514', $this->sqlstate(fn () => DB::table('employee_documents')->where('id', $id)->update(['category' => 'appointment_letter'])), 'archived = final');
        unset($other);
    }

    #[Test]
    public function reference_codes_are_tenant_isolated_and_fail_closed(): void
    {
        $employee = $this->createEmployee($this->school);
        $this->inSchool(fn () => DB::table('employee_reference_codes')->insert(['id' => (string) Str::uuid7(), 'school_id' => $this->school->id, 'employee_id' => $employee->id, 'kind' => 'erp', 'value' => 'E-1', 'recorded_by_user_id' => $this->actor->id, 'created_at' => now(), 'updated_at' => now()]));

        $other = $this->createSchool();
        $this->assertSame(0, app(TenantContext::class)->withSchool($other, fn () => DB::table('employee_reference_codes')->count()));
        DB::select('select set_config(?, ?, true)', [TenantRls::SESSION_VAR, '']);
        $this->assertSame(0, DB::table('employee_reference_codes')->count(), 'no tenant context: nothing');
        $this->assertSame('23503', $this->sqlstate(fn () => DB::table('employee_reference_codes')->insert(['id' => (string) Str::uuid7(), 'school_id' => $this->school->id, 'employee_id' => $this->createEmployee($other)->id, 'kind' => 'erp', 'value' => 'E-2', 'recorded_by_user_id' => $this->actor->id, 'created_at' => now(), 'updated_at' => now()])), 'composite same-School key');
    }

    #[Test]
    public function the_verifier_checks_the_profile_backstops(): void
    {
        $checks = collect(app(DatabaseRoleVerifier::class)->verify())->keyBy('code');

        $this->assertSame(CheckResult::PASS, $checks['hr_profile_invariants']->status);
        $this->assertSame(CheckResult::PASS, $checks['runtime_destructive_privileges_restricted']->status);
    }
}
