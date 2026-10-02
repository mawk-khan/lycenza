<?php

namespace Tests\Feature\Operations;

use App\Domain\Documents\Application\CreateDocumentData;
use App\Domain\Documents\Application\DocumentOwner;
use App\Domain\Documents\Application\DocumentService;
use App\Support\Operations\CheckResult;
use App\Support\Operations\DatabaseRoleVerifier;
use App\Support\Operations\RestoreValidator;
use App\Support\Operations\StorageInspector;
use App\Support\Settings\SchoolSettingsService;
use App\Support\Tenancy\TenantContext;
use Aws\Command;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0O.4A (ADR 0050 sections 7, 8, 11): the read-only operator
 * verification commands. They print codes and statuses only, never a
 * credential, endpoint, error text, filename or payload, and never write.
 */
class OperatorVerificationCommandsTest extends TestCase
{
    use CreatesTenancyFixtures;

    private const CANARY = 'canary-operator-secret-8f2a6c';

    /** @return array<string, CheckResult> */
    private function byCode(array $results): array
    {
        return collect($results)->keyBy(fn (CheckResult $r) => $r->code)->all();
    }

    /** @return list<string> */
    private function writes(callable $callback): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $writes = array_values(array_filter(array_column(DB::getQueryLog(), 'query'), fn ($q) => preg_match('/^\s*(insert|update|delete|alter|create|drop|grant|revoke|truncate)\b/i', $q)));
        DB::disableQueryLog();

        return $writes;
    }

    #[Test]
    public function the_database_role_model_verifies_against_the_real_test_database(): void
    {
        $results = [];
        $this->assertSame([], $this->writes(function () use (&$results) {
            $results = $this->byCode(app(DatabaseRoleVerifier::class)->verify());
        }), 'verification must never write');

        foreach ([
            'postgres_version_supported', 'runtime_connection_uses_runtime_role', 'runtime_role_exists', 'runtime_role_attributes',
            'runtime_role_not_owner_member', 'runtime_schema_access', 'runtime_table_privileges', 'runtime_destructive_privileges_restricted',
            'default_privileges_for_migration_role', 'tenant_tables_force_rls', 'platform_root_boundary',
            'retention_functions_narrow', 'finance_period_functions_narrow',
        ] as $code) {
            $this->assertArrayHasKey($code, $results);
            $this->assertSame(CheckResult::PASS, $results[$code]->status, $code);
        }

        // The test database server offers no TLS: outside production that
        // is operator evidence, not a pass.
        $this->assertContains($results['runtime_connection_encrypted']->status, [CheckResult::PASS, CheckResult::EVIDENCE]);
    }

    #[Test]
    public function the_documented_non_rls_school_tables_are_exactly_the_real_ones(): void
    {
        $actual = collect(DB::select(<<<'SQL'
            select c.relname from pg_class c join pg_namespace n on n.oid = c.relnamespace
            where n.nspname = 'public' and c.relkind = 'r'
              and exists (select 1 from pg_attribute a where a.attrelid = c.oid and a.attname = 'school_id' and not a.attisdropped)
              and not (c.relrowsecurity and c.relforcerowsecurity)
            order by 1
            SQL))->pluck('relname')->all();

        $documented = DatabaseRoleVerifier::NON_RLS_SCHOOL_TABLES;
        sort($documented);
        $this->assertSame($documented, $actual, 'No stale or missing entry: every other school_id table has forced RLS.');
    }

    #[Test]
    public function the_verify_database_command_prints_codes_only(): void
    {
        $exit = Artisan::call('platform:verify-database');
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('tenant_tables_force_rls', $output);
        $this->assertStringContainsString('failed=0', $output);
        // Credentials never appear (a host name such as `postgres` can
        // legitimately collide with a check code, so it is not asserted).
        foreach ([config('database.connections.pgsql.password'), config('database.connections.pgsql_admin.password')] as $secret) {
            if (is_string($secret) && strlen($secret) > 3) {
                $this->assertStringNotContainsString($secret, $output);
            }
        }
    }

    private function fakeS3(array $responses): void
    {
        $mock = new MockHandler($responses);
        $client = new S3Client(['region' => 'us-east-1', 'version' => 'latest', 'handler' => $mock, 'credentials' => ['key' => 'k', 'secret' => self::CANARY]]);

        $this->app->bind(StorageInspector::class, fn () => new class($client) extends StorageInspector
        {
            public function __construct(private readonly S3Client $fake) {}

            protected function client(): ?S3Client
            {
                return $this->fake;
            }
        });
    }

    private function productionStorage(): void
    {
        config([
            'documents.disk' => 's3', 'communications.attachments.disk' => 's3',
            'filesystems.disks.s3.bucket' => 'lycenza-production-objects',
            'filesystems.disks.s3.endpoint' => 'https://objects.example.net',
        ]);
    }

    private function awsError(string $code): callable
    {
        return fn (Command $command) => new S3Exception(self::CANARY.' provider detail', $command, ['code' => $code]);
    }

    #[Test]
    public function a_compliant_bucket_passes_and_the_independent_copy_stays_operator_evidence(): void
    {
        $this->productionStorage();
        $this->fakeS3([
            new Result([]),
            new Result(['Status' => 'Enabled']),
            new Result(['ServerSideEncryptionConfiguration' => ['Rules' => [['ApplyServerSideEncryptionByDefault' => ['SSEAlgorithm' => 'AES256']]]]]),
            new Result(['PublicAccessBlockConfiguration' => ['BlockPublicAcls' => true, 'IgnorePublicAcls' => true, 'BlockPublicPolicy' => true, 'RestrictPublicBuckets' => true]]),
        ]);

        $results = $this->byCode(app(StorageInspector::class)->inspect());
        foreach (['storage_disks_are_s3', 'storage_bucket_not_local', 'storage_endpoint_https', 'storage_no_public_visibility',
            'storage_bucket_reachable', 'storage_versioning_enabled', 'storage_default_encryption', 'storage_public_access_blocked'] as $code) {
            $this->assertSame(CheckResult::PASS, $results[$code]->status, $code);
        }
        $this->assertSame(CheckResult::EVIDENCE, $results['storage_independent_backup']->status);
    }

    #[Test]
    public function missing_versioning_or_encryption_fails_and_unsupported_calls_need_evidence(): void
    {
        $this->productionStorage();
        $this->fakeS3([
            new Result([]),
            new Result(['Status' => 'Suspended']),
            $this->awsError('ServerSideEncryptionConfigurationNotFoundError'),
            $this->awsError('NotImplemented'),
        ]);

        $exit = Artisan::call('platform:verify-storage');
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertMatchesRegularExpression('/FAIL\s+\|\s+storage_versioning_enabled/', $output);
        $this->assertMatchesRegularExpression('/FAIL\s+\|\s+storage_default_encryption/', $output);
        $this->assertMatchesRegularExpression('/OPERATOR_EVIDENCE_REQUIRED\s+\|\s+storage_public_access_blocked/', $output);
        $this->assertStringNotContainsString(self::CANARY, $output);
        $this->assertStringNotContainsString('objects.example.net', $output);
    }

    #[Test]
    public function an_unreachable_bucket_fails_without_printing_the_error(): void
    {
        $this->productionStorage();
        $this->fakeS3([$this->awsError('AccessDenied')]);

        $exit = Artisan::call('platform:verify-storage');
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertMatchesRegularExpression('/FAIL\s+\|\s+storage_bucket_reachable/', $output);
        $this->assertStringNotContainsString(self::CANARY, $output);
        $this->assertStringNotContainsString('AccessDenied', $output);
    }

    #[Test]
    public function local_storage_configuration_fails(): void
    {
        config(['documents.disk' => 'local', 'filesystems.disks.s3.bucket' => 'school-os-local', 'filesystems.disks.s3.endpoint' => 'http://minio:9000']);
        $this->fakeS3([$this->awsError('NoSuchBucket')]);

        $results = $this->byCode(app(StorageInspector::class)->inspect());
        foreach (['storage_disks_are_s3', 'storage_bucket_not_local', 'storage_endpoint_https'] as $code) {
            $this->assertSame(CheckResult::FAIL, $results[$code]->status, $code);
        }
    }

    #[Test]
    public function the_restore_validator_checks_a_database_read_only_and_samples_documents(): void
    {
        Storage::fake('local');
        $school = $this->createSchool();
        $other = $this->createSchool();
        $this->createCampus($school);
        $this->createCampus($other);
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.documents.manage', 'hr.employees.documents.view']);
        $document = app(TenantContext::class)->withSchool($school, fn () => app(DocumentService::class)->create(
            $school,
            new CreateDocumentData(DocumentOwner::employee($employee->id), 'internal', UploadedFile::fake()->createWithContent('restore-canary-name.pdf', 'exact-content-bytes')),
            $actor,
        ));

        // The observed recovery point comes from tables readable without a School context.
        app(SchoolSettingsService::class)->set($school, 'communications.digest_frequency', 'weekly');

        $results = [];
        $this->assertSame([], $this->writes(function () use (&$results) {
            $results = $this->byCode(app(RestoreValidator::class)->validate(25));
        }), 'restore validation must never write');

        foreach (['application_boot', 'migrations_applied', 'rls_fails_closed_without_school', 'rls_isolates_schools', 'observed_recovery_point'] as $code) {
            $this->assertSame(CheckResult::PASS, $results[$code]->status, $code.' '.$results[$code]->note);
        }
        $this->assertSame(CheckResult::PASS, $results['document_objects_readable']->status);
        $this->assertSame('1/1', $results['document_objects_readable']->note);

        // A missing object is detected.
        Storage::disk('local')->delete($document->storage_path);
        $results = $this->byCode(app(RestoreValidator::class)->validate(25));
        $this->assertSame(CheckResult::FAIL, $results['document_objects_readable']->status);
        $this->assertSame('0/1', $results['document_objects_readable']->note);

        $exit = Artisan::call('platform:verify-restore', ['--sample-documents' => 25]);
        $output = Artisan::output();
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('validation_duration_seconds=', $output);
        foreach (['restore-canary-name', $document->storage_path, 'exact-content-bytes'] as $private) {
            $this->assertStringNotContainsString($private, $output);
        }
    }

    #[Test]
    public function a_zero_sample_needs_operator_evidence(): void
    {
        $results = $this->byCode(app(RestoreValidator::class)->validate(0));
        $this->assertSame(CheckResult::EVIDENCE, $results['document_objects_readable']->status);
    }
}
