<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Infrastructure\Employee;
use App\Models\School;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A.14 -- REQUIRED comprehensive negative-disclosure proof
 * (checkpoint brief section 36): one Employee populated with
 * distinctive sentinel values across every Restricted/Highly Sensitive
 * data family, then every read endpoint is called with a DELIBERATELY
 * limited capability set, proving each response contains ONLY what
 * that specific capability set authorizes -- no frontend involved,
 * every assertion is against the raw HTTP JSON body.
 */
class HrEmployeeApiNegativeDisclosureTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function token($user): string
    {
        return $user->createToken('test-device')->plainTextToken;
    }

    private const SENTINELS = [
        'personal_email' => 'sentinel-negdis@example.com',
        'credential_number' => 'SENTINEL-CRED-99887766',
        'storage_path' => 'sentinel/negdis/storage/path.pdf',
        'document_category' => 'sentinel-negdis-category',
    ];

    #[Test]
    public function directory_reveals_none_of_the_sentinel_values(): void
    {
        [$school, $employee, $setupActor] = $this->populatedEmployee();
        $directoryActor = $this->createUserWithCapabilities($school, ['hr.employees.view']);

        $raw = $this->withHeader('Authorization', 'Bearer '.$this->token($directoryActor))
            ->getJson("/api/v1/schools/{$school->id}/employees")
            ->assertOk()
            ->getContent();

        foreach (self::SENTINELS as $sentinel) {
            $this->assertStringNotContainsString($sentinel, $raw, "Directory leaked sentinel value: {$sentinel}");
        }
    }

    #[Test]
    public function profile_without_documents_capability_reveals_no_document_sentinel(): void
    {
        [$school, $employee, $setupActor] = $this->populatedEmployee();
        $limitedActor = $this->createUserWithCapabilities($school, ['hr.employees.personal.view']);

        $raw = $this->withHeader('Authorization', 'Bearer '.$this->token($limitedActor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}")
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(self::SENTINELS['personal_email'], $raw, 'personal.view DOES authorize personal contact data.');
        $this->assertStringNotContainsString(self::SENTINELS['credential_number'], $raw, 'qualifications.view was not granted.');
        $this->assertStringNotContainsString(self::SENTINELS['storage_path'], $raw);
        $this->assertStringNotContainsString(self::SENTINELS['document_category'], $raw, 'documents.view was not granted.');
    }

    #[Test]
    public function profile_never_reveals_storage_paths_regardless_of_capability(): void
    {
        [$school, $employee, $setupActor] = $this->populatedEmployee();
        $fullActor = $this->fullHrActor($school);

        $raw = $this->withHeader('Authorization', 'Bearer '.$this->token($fullActor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}")
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(self::SENTINELS['document_category'], $raw, 'The restricted document IS visible with full capabilities.');
        $this->assertStringNotContainsString(self::SENTINELS['storage_path'], $raw, 'storage_path is never exposed, even with every capability.');
    }

    #[Test]
    public function activity_timeline_reveals_no_raw_sentinel_value(): void
    {
        [$school, $employee, $setupActor] = $this->populatedEmployee();
        $fullActor = $this->fullHrActor($school);

        $raw = $this->withHeader('Authorization', 'Bearer '.$this->token($fullActor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/activity")
            ->assertOk()
            ->getContent();

        foreach (self::SENTINELS as $sentinel) {
            $this->assertStringNotContainsString($sentinel, $raw, "Timeline leaked sentinel value: {$sentinel}");
        }
    }

    #[Test]
    public function sensitive_document_endpoint_only_reveals_safe_fields_to_sensitive_view(): void
    {
        [$school, $employee, $setupActor] = $this->populatedEmployee();
        $this->createEmployeeDocument($employee, [
            'classification_tier' => 'highly_sensitive',
            'category' => 'sentinel-highly-sensitive-category',
            'storage_path' => 'sentinel/hs/storage.pdf',
        ]);
        $sensitiveActor = $this->fullHrActor($school);

        $raw = $this->withHeader('Authorization', 'Bearer '.$this->token($sensitiveActor))
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/sensitive-documents")
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('sentinel-highly-sensitive-category', $raw);
        $this->assertStringNotContainsString('sentinel/hs/storage.pdf', $raw);
    }

    /**
     * @return array{0: School, 1: Employee, 2: User}
     */
    private function populatedEmployee(): array
    {
        $school = $this->createSchool();
        $setupActor = $this->fullHrActor($school);
        $employee = app(EmployeeService::class)->create($school, ['full_name' => 'Negative Disclosure Sentinel'], $setupActor);
        $this->createEmployeePersonalDetail($employee, ['personal_email' => self::SENTINELS['personal_email']]);
        $this->createEmployeeAddress($employee);
        $this->createEmployeeEmergencyContact($employee);
        $this->createEmployeeQualification($employee);
        $this->createEmployeeCertification($employee, ['credential_number' => self::SENTINELS['credential_number']]);
        $this->createEmployeeDocument($employee, [
            'classification_tier' => 'restricted',
            'category' => self::SENTINELS['document_category'],
            'storage_path' => self::SENTINELS['storage_path'],
        ]);

        return [$school, $employee, $setupActor];
    }
}
