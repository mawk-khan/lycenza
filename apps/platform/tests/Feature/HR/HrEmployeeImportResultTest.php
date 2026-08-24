<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeImportService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A.12 -- REQUIRED result/error-redaction proof (checkpoint
 * brief sections 32/46/47/80). Aggregate counts, input-order row
 * results, and errors that carry field/code/message only -- never a
 * full input-row echo.
 */
class HrEmployeeImportResultTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function batch_result_reports_accurate_aggregate_counts(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $linkedUser = $this->createUser();
        $this->createMembership($linkedUser, $school);

        $result = app(EmployeeImportService::class)->import($school, $actor, [
            ['full_name' => 'Created One'],
            ['full_name' => 'Created Two', 'user_id' => $linkedUser->id],
            ['full_name' => 'Created Two', 'user_id' => $linkedUser->id],
            ['full_name' => 'Created One'],
            ['favorite_color' => 'blue'],
        ]);

        $this->assertSame(5, $result->received);
        $this->assertSame(2, $result->created);
        $this->assertSame(1, $result->exactDuplicates);
        $this->assertSame(1, $result->potentialDuplicates);
        $this->assertSame(1, $result->failed);
        $this->assertCount(5, $result->rows);
    }

    #[Test]
    public function row_results_preserve_input_order(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);

        $result = app(EmployeeImportService::class)->import($school, $actor, [
            ['full_name' => 'Row One'],
            ['full_name' => 'Row Two'],
            ['full_name' => 'Row Three'],
        ]);

        $this->assertSame(1, $result->rows[0]->rowNumber);
        $this->assertSame(2, $result->rows[1]->rowNumber);
        $this->assertSame(3, $result->rows[2]->rowNumber);
    }

    #[Test]
    public function validation_errors_contain_only_field_code_and_message_never_the_full_row(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);

        $result = app(EmployeeImportService::class)->import($school, $actor, [
            ['full_name' => 'Sentinel Person', 'personal_email' => 'sentinel-secret@example.com', 'favorite_color' => 'blue'],
        ]);

        $error = $result->rows[0]->errors[0];
        $this->assertSame(['field', 'code', 'message'], array_keys($error));

        $serialized = json_encode($result->toArray());
        $this->assertStringNotContainsString('sentinel-secret@example.com', $serialized, 'Rejected row values must never appear in the result, even the value that caused the rejection.');
        $this->assertStringNotContainsString('Sentinel Person', $serialized);
    }

    #[Test]
    public function a_failed_domain_operation_error_never_leaks_the_raw_exception_class_or_message(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $inactivePosition = $this->createPosition($school, ['status' => 'inactive']);

        $result = app(EmployeeImportService::class)->import($school, $actor, [[
            'full_name' => 'Asha Verma', 'employment_type' => 'permanent', 'employment_starts_on' => '2026-01-01',
            'position_code' => $inactivePosition->code,
        ]]);

        $serialized = json_encode($result->toArray());
        $this->assertStringNotContainsString('Exception', $serialized);
        $this->assertStringNotContainsString($inactivePosition->id, $serialized, 'A raw internal id must never appear in a user-facing error message.');
    }

    #[Test]
    public function duplicate_exact_result_carries_only_directory_tier_fields(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $linkedUser = $this->createUser();
        $this->createMembership($linkedUser, $school);

        $service = app(EmployeeImportService::class);
        $service->import($school, $actor, [['full_name' => 'Asha Verma', 'user_id' => $linkedUser->id, 'personal_email' => 'sentinel@example.com']]);
        $result = $service->import($school, $actor, [['full_name' => 'Asha Verma', 'user_id' => $linkedUser->id]]);

        $row = $result->rows[0]->toArray();
        $this->assertSame(['row_number', 'status', 'employee_id', 'employee_number', 'errors'], array_keys($row));
        $serialized = json_encode($row);
        $this->assertStringNotContainsString('sentinel@example.com', $serialized);
    }
}
