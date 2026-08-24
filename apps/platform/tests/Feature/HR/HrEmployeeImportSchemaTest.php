<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeImportRow;
use App\Domain\HR\Application\Exceptions\EmployeeImportUnknownFieldException;
use App\Domain\HR\Application\Exceptions\EmployeeImportValidationException;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 8A.12 -- REQUIRED schema proof (checkpoint brief section 72):
 * `EmployeeImportRow::fromArray()` is the untrusted-input boundary
 * itself -- a strict allow-list, never a generic mass-assignment.
 */
class HrEmployeeImportSchemaTest extends TestCase
{
    #[Test]
    public function a_valid_minimal_row_is_accepted(): void
    {
        $row = EmployeeImportRow::fromArray(['full_name' => 'Asha Verma']);

        $this->assertSame('Asha Verma', $row->fullName);
        $this->assertNull($row->userId);
        $this->assertFalse($row->hasPersonalData());
        $this->assertFalse($row->hasEmploymentData());
        $this->assertFalse($row->hasAssignmentData());
    }

    #[Test]
    public function optional_supported_fields_are_accepted(): void
    {
        $row = EmployeeImportRow::fromArray([
            'full_name' => 'Asha Verma',
            'personal_email' => 'asha@example.com',
            'employment_type' => 'permanent',
            'employment_starts_on' => '2026-01-01',
            'position_code' => 'TCH',
        ]);

        $this->assertTrue($row->hasPersonalData());
        $this->assertTrue($row->hasEmploymentData());
        $this->assertTrue($row->hasAssignmentData());
    }

    #[Test]
    public function an_unknown_field_is_rejected(): void
    {
        $this->expectException(EmployeeImportUnknownFieldException::class);

        EmployeeImportRow::fromArray(['full_name' => 'Asha Verma', 'favorite_color' => 'blue']);
    }

    #[Test]
    public function a_caller_supplied_school_id_is_rejected(): void
    {
        $this->expectException(EmployeeImportUnknownFieldException::class);

        EmployeeImportRow::fromArray(['full_name' => 'Asha Verma', 'school_id' => (string) Str::uuid()]);
    }

    #[Test]
    public function a_caller_supplied_employee_id_is_rejected(): void
    {
        $this->expectException(EmployeeImportUnknownFieldException::class);

        EmployeeImportRow::fromArray(['full_name' => 'Asha Verma', 'employee_id' => (string) Str::uuid()]);
    }

    #[Test]
    public function a_caller_supplied_employee_number_is_rejected(): void
    {
        $this->expectException(EmployeeImportUnknownFieldException::class);

        EmployeeImportRow::fromArray(['full_name' => 'Asha Verma', 'employee_number' => 'EMP-000001']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function highlySensitiveOrOwnershipFieldProvider(): array
    {
        return [
            'government_id' => ['government_id'],
            'bank_account_number' => ['bank_account_number'],
            'tax_identifier' => ['tax_identifier'],
            'storage_path' => ['storage_path'],
            'storage_disk' => ['storage_disk'],
            'uploaded_by_user_id' => ['uploaded_by_user_id'],
            'record_status' => ['record_status'],
            'status' => ['status'],
            'manager_assignment_id' => ['manager_assignment_id'],
        ];
    }

    #[Test]
    #[DataProvider('highlySensitiveOrOwnershipFieldProvider')]
    public function unsupported_highly_sensitive_or_ownership_fields_are_rejected(string $field): void
    {
        $this->expectException(EmployeeImportUnknownFieldException::class);

        EmployeeImportRow::fromArray(['full_name' => 'Asha Verma', $field => 'x']);
    }

    #[Test]
    public function a_blank_required_name_is_rejected(): void
    {
        $this->expectException(EmployeeImportValidationException::class);

        EmployeeImportRow::fromArray(['full_name' => '   ']);
    }

    #[Test]
    public function a_missing_name_is_rejected(): void
    {
        $this->expectException(EmployeeImportValidationException::class);

        EmployeeImportRow::fromArray(['personal_email' => 'a@example.com']);
    }

    #[Test]
    public function a_unicode_name_is_accepted_unmodified(): void
    {
        $row = EmployeeImportRow::fromArray(['full_name' => 'José García-Núñez 田中太郎']);

        $this->assertSame('José García-Núñez 田中太郎', $row->fullName);
    }

    #[Test]
    public function full_name_is_stored_exactly_as_supplied_never_normalized_or_split(): void
    {
        $row = EmployeeImportRow::fromArray(['full_name' => '  Jane   Doe  ']);

        // The DTO preserves the raw (trimmed-of-outer-whitespace-by-the
        // generic stringOrNull() helper) value -- internal whitespace
        // collapsing/case-folding is a DUPLICATE-MATCHING concern only
        // (EmployeeDuplicateDetector::normalizeName()), never applied
        // to the value that will actually be stored.
        $this->assertSame('Jane   Doe', $row->fullName);
    }

    #[Test]
    public function employment_type_and_starts_on_must_be_supplied_together(): void
    {
        $this->expectException(EmployeeImportValidationException::class);

        EmployeeImportRow::fromArray(['full_name' => 'Asha Verma', 'employment_type' => 'permanent']);
    }

    #[Test]
    public function an_unsupported_employment_type_value_is_rejected(): void
    {
        $this->expectException(EmployeeImportValidationException::class);

        EmployeeImportRow::fromArray([
            'full_name' => 'Asha Verma', 'employment_type' => 'freelance', 'employment_starts_on' => '2026-01-01',
        ]);
    }

    #[Test]
    public function a_malformed_date_is_rejected(): void
    {
        $this->expectException(EmployeeImportValidationException::class);

        EmployeeImportRow::fromArray([
            'full_name' => 'Asha Verma', 'employment_type' => 'permanent', 'employment_starts_on' => '01/01/2026',
        ]);
    }

    #[Test]
    public function assignment_data_without_employment_data_is_rejected(): void
    {
        $this->expectException(EmployeeImportValidationException::class);

        EmployeeImportRow::fromArray(['full_name' => 'Asha Verma', 'position_code' => 'TCH']);
    }

    #[Test]
    public function assignment_starts_on_defaults_to_employment_starts_on_when_not_supplied(): void
    {
        $row = EmployeeImportRow::fromArray([
            'full_name' => 'Asha Verma', 'employment_type' => 'permanent',
            'employment_starts_on' => '2026-01-01', 'position_code' => 'TCH',
        ]);

        $this->assertSame('2026-01-01', $row->assignmentStartsOn());
    }
}
