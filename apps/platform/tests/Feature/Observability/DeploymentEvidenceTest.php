<?php

namespace Tests\Feature\Observability;

use App\Support\Observability\Metrics\DeploymentEvidence;
use App\Support\Observability\Metrics\InvalidDeploymentEvidence;
use App\Support\Observability\Metrics\MetricsEndpoint;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0O.5A (ADR 0051 §13): backup and restore-drill evidence comes only
 * from a deployment-controlled, read-only file -- never fabricated from
 * application state, never over HTTP -- validated strictly, failing closed.
 */
class DeploymentEvidenceTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = sys_get_temp_dir().'/evidence_'.bin2hex(random_bytes(6)).'.json';
        config(['observability.metrics.deployment_evidence_file' => $this->file, 'observability.metrics.scrape_token' => str_repeat('t', 40)]);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    private function valid(): array
    {
        $now = time();

        return [
            'schema' => DeploymentEvidence::SCHEMA,
            'backups' => [
                'postgresql' => ['last_success_at' => $now - 3600, 'recovery_point_at' => $now - 120, 'last_failure_at' => null],
                'objects' => ['last_success_at' => $now - 7200, 'last_failure_at' => null],
            ],
            'restore_drill' => ['record_id' => 'DRILL-2026-Q4-1', 'last_result' => 'PASS', 'last_success_at' => $now - 86400, 'duration_seconds' => 5400, 'recovery_point_gap_seconds' => 90],
        ];
    }

    private function scrape(): string
    {
        return (string) app(MetricsEndpoint::class)->respond(Request::create('/metrics', 'GET', server: [
            'LYCENZA_METRICS_LISTENER' => '1', 'HTTP_AUTHORIZATION' => 'Bearer '.str_repeat('t', 40),
        ]))->getContent();
    }

    #[Test]
    public function valid_evidence_is_re_exposed_and_nothing_else(): void
    {
        file_put_contents($this->file, json_encode($this->valid()));
        $body = $this->scrape();

        $this->assertStringContainsString('lycenza_backup_recovery_point_timestamp_seconds{backup_store="postgresql"}', $body);
        $this->assertStringContainsString('lycenza_backup_last_success_timestamp_seconds{backup_store="objects"}', $body);
        $this->assertStringContainsString('lycenza_restore_drill_last_result 1', $body);
        $this->assertStringContainsString('lycenza_restore_drill_duration_seconds 5400', $body);
        $this->assertStringNotContainsString('DRILL-2026-Q4-1', $body, 'the record id is validated, not exported');
        $this->assertStringNotContainsString('collection_errors_total{component="evidence"}', $body);
    }

    #[Test]
    public function no_file_configured_means_no_evidence_never_success(): void
    {
        config(['observability.metrics.deployment_evidence_file' => '']);
        $body = $this->scrape();

        $this->assertStringNotContainsString('lycenza_backup_', $body);
        $this->assertStringNotContainsString('lycenza_restore_drill_', $body);
    }

    /** @return array<string, array{callable(array): mixed}> */
    public static function malformed(): array
    {
        return [
            'not json' => [fn () => 'not json'],
            'wrong schema' => [fn (array $d) => [...$d, 'schema' => 'something/v2']],
            'unknown key' => [fn (array $d) => [...$d, 'backup_successful' => true]],
            'string timestamp' => [fn (array $d) => array_replace_recursive($d, ['backups' => ['postgresql' => ['last_success_at' => 'yesterday']]])],
            'future timestamp' => [fn (array $d) => array_replace_recursive($d, ['backups' => ['objects' => ['last_success_at' => time() + 10 * 86400]]])],
            'ancient timestamp' => [fn (array $d) => array_replace_recursive($d, ['backups' => ['objects' => ['last_success_at' => 100]]])],
            'drill without record' => [fn (array $d) => array_replace_recursive($d, ['restore_drill' => ['record_id' => 'no']])],
            'drill result guessed' => [fn (array $d) => array_replace_recursive($d, ['restore_drill' => ['last_result' => 'OK']])],
            'negative duration' => [fn (array $d) => array_replace_recursive($d, ['restore_drill' => ['duration_seconds' => -5]])],
            'list instead of object' => [fn (array $d) => [...$d, 'backups' => [1, 2]]],
        ];
    }

    #[Test]
    #[DataProvider('malformed')]
    public function malformed_evidence_fails_closed_and_is_counted(callable $mutate): void
    {
        $document = $mutate($this->valid());
        file_put_contents($this->file, is_string($document) ? $document : json_encode($document));

        $body = $this->scrape();

        $this->assertStringNotContainsString('lycenza_backup_', $body);
        $this->assertStringNotContainsString('lycenza_restore_drill_', $body);
        $this->assertStringContainsString('lycenza_metrics_collection_errors_total{component="evidence"} 1', $body);
    }

    #[Test]
    public function an_oversized_file_is_refused(): void
    {
        file_put_contents($this->file, str_repeat(' ', 70000).json_encode($this->valid()));

        $this->expectException(InvalidDeploymentEvidence::class);
        app(DeploymentEvidence::class)->samples();
    }

    #[Test]
    public function the_application_never_fabricates_backup_success(): void
    {
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php'
                && ! str_ends_with($file->getPathname(), 'Metrics/DeploymentEvidence.php')
                && ! str_ends_with($file->getPathname(), 'Metrics/MetricCatalog.php')
                && (str_contains((string) file_get_contents($file->getPathname()), 'lycenza_backup_') || str_contains((string) file_get_contents($file->getPathname()), 'lycenza_restore_drill_'))) {
                $offenders[] = $file->getPathname();
            }
        }

        $this->assertSame([], array_values(array_filter($offenders, fn ($f) => ! str_contains($f, 'Alerts/AlertCatalog.php'))), 'only the evidence reader emits backup/drill metrics');
    }
}
