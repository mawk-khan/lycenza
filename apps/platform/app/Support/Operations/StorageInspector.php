<?php

namespace App\Support\Operations;

use App\Support\Configuration\ProductionConfigurationGuard;
use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Phase 0O.4A (ADR 0050 section 8): read-only operator inspection of the
 * production object store. Never runs at application start, never mutates
 * a bucket, a policy or versioning, never prints a credential, endpoint or
 * error text -- codes and statuses only.
 *
 * What the portable S3 API can prove it checks (reachability, versioning,
 * default encryption where supported); what it cannot -- a provider's
 * public-access block where unsupported, and the independent backup copy --
 * is reported OPERATOR_EVIDENCE_REQUIRED, never assumed.
 */
class StorageInspector
{
    /**
     * @return list<CheckResult>
     */
    public function inspect(): array
    {
        $bucket = (string) config('filesystems.disks.s3.bucket');
        $endpoint = trim((string) config('filesystems.disks.s3.endpoint'));

        $results = [
            CheckResult::of('storage_disks_are_s3', config('documents.disk') === 's3' && config('communications.attachments.disk') === 's3'),
            CheckResult::of('storage_bucket_not_local', $bucket !== '' && ! in_array($bucket, ProductionConfigurationGuard::NON_PRODUCTION_BUCKETS, true)),
            CheckResult::of('storage_endpoint_https', $endpoint === '' || str_starts_with(strtolower($endpoint), 'https://')),
            CheckResult::of('storage_no_public_visibility', config('filesystems.disks.s3.visibility') !== 'public'),
        ];

        $client = $this->client();
        if ($client === null) {
            return [...$results, CheckResult::of('storage_bucket_reachable', false)];
        }

        try {
            $client->headBucket(['Bucket' => $bucket]);
            $results[] = CheckResult::of('storage_bucket_reachable', true);
        } catch (Throwable) {
            return [...$results, CheckResult::of('storage_bucket_reachable', false)];
        }

        try {
            $status = $client->getBucketVersioning(['Bucket' => $bucket])->get('Status');
            $results[] = CheckResult::of('storage_versioning_enabled', $status === 'Enabled');
        } catch (Throwable) {
            $results[] = new CheckResult('storage_versioning_enabled', CheckResult::EVIDENCE);
        }

        try {
            $rules = $client->getBucketEncryption(['Bucket' => $bucket])->get('ServerSideEncryptionConfiguration');
            $results[] = CheckResult::of('storage_default_encryption', ! empty($rules['Rules'] ?? []));
        } catch (AwsException $e) {
            $results[] = $e->getAwsErrorCode() === 'ServerSideEncryptionConfigurationNotFoundError'
                ? CheckResult::of('storage_default_encryption', false)
                : new CheckResult('storage_default_encryption', CheckResult::EVIDENCE);
        } catch (Throwable) {
            $results[] = new CheckResult('storage_default_encryption', CheckResult::EVIDENCE);
        }

        try {
            $block = (array) $client->getPublicAccessBlock(['Bucket' => $bucket])->get('PublicAccessBlockConfiguration');
            $results[] = CheckResult::of('storage_public_access_blocked', ($block['BlockPublicAcls'] ?? false) && ($block['IgnorePublicAcls'] ?? false)
                && ($block['BlockPublicPolicy'] ?? false) && ($block['RestrictPublicBuckets'] ?? false));
        } catch (Throwable) {
            $results[] = new CheckResult('storage_public_access_blocked', CheckResult::EVIDENCE);
        }

        $results[] = new CheckResult('storage_independent_backup', CheckResult::EVIDENCE);

        return $results;
    }

    protected function client(): ?S3Client
    {
        try {
            $disk = Storage::disk('s3');

            return $disk instanceof AwsS3V3Adapter ? $disk->getClient() : null;
        } catch (Throwable) {
            return null;
        }
    }
}
