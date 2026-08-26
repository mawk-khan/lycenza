<?php

namespace Tests\Feature\Documents;

use App\Domain\Documents\Application\CreateDocumentData;
use App\Domain\Documents\Application\DocumentOwner;
use App\Domain\Documents\Application\DocumentService;
use App\Domain\Documents\Infrastructure\Document;
use App\Models\Role;
use App\Models\SchoolMembership;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0E.5 -- REQUIRED rate-limit proof, mirroring
 * `Tests\Feature\HR\HrEmployeeApiRateLimitTest`'s established real-HTTP
 * style: `documents-reads` (120/min), `documents-sensitive-reads`
 * (20/min), `documents-content` (20/min), `documents-writes` (30/min),
 * all School+actor-keyed via the existing `tenantKey()`.
 */
class DocumentHttpRateLimitTest extends TestCase
{
    use CreatesTenancyFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function token($user): string
    {
        return $user->createToken('test-device')->plainTextToken;
    }

    private function createDocument($school, $employee, $actor, string $tier = 'internal'): Document
    {
        return app(TenantContext::class)->withSchool($school, fn () => app(DocumentService::class)->create(
            $school,
            new CreateDocumentData(DocumentOwner::employee($employee->id), $tier, UploadedFile::fake()->createWithContent('report.pdf', 'x')),
            $actor,
        ));
    }

    #[Test]
    public function ordinary_listing_is_throttled_at_120_per_minute(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $token = $this->token($actor);

        for ($i = 0; $i < 120; $i++) {
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/documents")
                ->assertOk();
        }

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/documents")
            ->assertStatus(429);
    }

    #[Test]
    public function sensitive_listing_and_direct_metadata_share_the_stricter_20_per_minute_bucket(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $token = $this->token($actor);
        $document = $this->createDocument($school, $employee, $actor);

        for ($i = 0; $i < 10; $i++) {
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/documents/sensitive")
                ->assertOk();
        }
        for ($i = 0; $i < 10; $i++) {
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->getJson("/api/v1/schools/{$school->id}/documents/{$document->id}")
                ->assertOk();
        }

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/documents/sensitive")
            ->assertStatus(429);
    }

    #[Test]
    public function content_streaming_is_throttled_independently_at_20_per_minute(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $token = $this->token($actor);
        $document = $this->createDocument($school, $employee, $actor);

        for ($i = 0; $i < 20; $i++) {
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->get("/api/v1/schools/{$school->id}/documents/{$document->id}/content")
                ->assertOk();
        }

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->get("/api/v1/schools/{$school->id}/documents/{$document->id}/content")
            ->assertStatus(429);
    }

    #[Test]
    public function exhausting_content_does_not_affect_the_general_reads_bucket(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $token = $this->token($actor);
        $document = $this->createDocument($school, $employee, $actor);

        for ($i = 0; $i < 21; $i++) {
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->get("/api/v1/schools/{$school->id}/documents/{$document->id}/content");
        }

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/documents")
            ->assertOk();
    }

    #[Test]
    public function uploads_and_archive_share_the_30_per_minute_writes_bucket(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $token = $this->token($actor);

        for ($i = 0; $i < 30; $i++) {
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->post("/api/v1/schools/{$school->id}/employees/{$employee->id}/documents", [
                    'file' => UploadedFile::fake()->create("doc-{$i}.pdf", 1, 'application/pdf'),
                    'classification_tier' => 'internal',
                ])
                ->assertCreated();
        }

        $document = $this->createDocument($school, $employee, $actor);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->post("/api/v1/schools/{$school->id}/documents/{$document->id}/archive")
            ->assertStatus(429);
    }

    #[Test]
    public function a_429_uses_the_standard_envelope_with_retry_after(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $token = $this->token($actor);

        for ($i = 0; $i < 120; $i++) {
            $this->withHeader('Authorization', 'Bearer '.$token)->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/documents");
        }

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/schools/{$school->id}/employees/{$employee->id}/documents")
            ->assertStatus(429);

        $this->assertSame(['message', 'status', 'code', 'requestId', 'errors'], array_keys($response->json('error')));
        $this->assertTrue($response->headers->has('Retry-After'));
    }

    #[Test]
    public function the_same_user_has_independent_buckets_per_school(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeA = $this->createEmployee($schoolA);
        $employeeB = $this->createEmployee($schoolB);
        $user = $this->createUser();
        $this->createMembership($user, $schoolA);
        $this->createMembership($user, $schoolB);

        foreach ([$schoolA, $schoolB] as $school) {
            $role = Role::query()->create([
                'key' => 'test.documents_view.'.Str::uuid(),
                'name' => 'Test Documents View',
                'scope' => 'school',
                'is_system' => false,
            ]);
            $role->capabilities()->sync(['hr.employees.documents.view']);
            $membership = SchoolMembership::query()->where('user_id', $user->id)->where('school_id', $school->id)->first();
            $this->assignSchoolRole($membership, $role->key);
        }

        $token = $this->token($user);

        for ($i = 0; $i < 120; $i++) {
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->getJson("/api/v1/schools/{$schoolA->id}/employees/{$employeeA->id}/documents")
                ->assertOk();
        }

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/schools/{$schoolA->id}/employees/{$employeeA->id}/documents")
            ->assertStatus(429);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/schools/{$schoolB->id}/employees/{$employeeB->id}/documents")
            ->assertOk();
    }
}
