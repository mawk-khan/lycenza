<?php

namespace Tests\Concerns;

/**
 * SIS 11.H1 (SIS 11.R1 §8.3): the integration tests that need a REAL
 * S3-compatible object store (MinIO locally on the bin/safe-test stack,
 * versitygw in CI). Only those tests use this; every other storage test
 * runs on a fake disk everywhere.
 *
 * - The store's endpoint (`filesystems.disks.s3.endpoint`) is probed with a
 *   TCP connect, nothing else.
 * - Reachable: the test runs normally.
 * - Unreachable OUTSIDE CI (e.g. canonical DDEV, which has no object store):
 *   the test is SKIPPED with this reason -- never a silent pass.
 * - Unreachable IN CI (`CI=true` or `GITHUB_ACTIONS=true`): the test FAILS,
 *   so a missing CI service can never turn these into skips.
 */
trait RequiresObjectStorage
{
    protected function requireObjectStorage(): void
    {
        $endpoint = (string) config('filesystems.disks.s3.endpoint');
        $host = (string) (parse_url($endpoint, PHP_URL_HOST) ?: '');
        $port = (int) (parse_url($endpoint, PHP_URL_PORT) ?: (str_starts_with($endpoint, 'https') ? 443 : 80));

        $socket = $host === '' ? false : @fsockopen($host, $port, $errno, $error, 1.0);
        if ($socket !== false) {
            fclose($socket);

            return;
        }

        $reason = "requires the real S3-compatible object storage integration service (MinIO/versitygw) at '{$endpoint}', which is not reachable here";
        if (getenv('CI') === 'true' || getenv('GITHUB_ACTIONS') === 'true') {
            $this->fail("Object storage is required in CI and was not reachable: {$reason}.");
        }

        $this->markTestSkipped("SKIPPED: {$reason}. CI runs this test against versitygw.");
    }
}
