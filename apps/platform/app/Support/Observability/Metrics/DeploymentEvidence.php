<?php

namespace App\Support\Observability\Metrics;

/**
 * Phase 0O.5A (ADR 0051 §13): the repository side of deployment-fed
 * backup and restore-drill evidence. The application CANNOT originate
 * backup status; the deployment's backup tooling and the operator who ran
 * a drill write one JSON document to a path mounted READ-ONLY into the
 * web role (`OBSERVABILITY_DEPLOYMENT_EVIDENCE_FILE`). The application
 * validates it strictly and re-exposes the values as `lycenza_backup_*` /
 * `lycenza_restore_drill_*` gauges.
 *
 * Trust boundary, stated honestly: authenticity rests on the deployment's
 * file permissions (only the backup tooling/operator can write it); the
 * document is not signed. It is never accepted over HTTP, so there is no
 * public write API and no new service identity (O5 stays open).
 *
 * Fail closed: an unset path yields no evidence; a missing, oversized,
 * malformed or out-of-contract file yields no evidence AND a collection
 * error -- the backup alerts then fire on absence. A drill counts as
 * successful only with an explicit `PASS`, a timestamp and a drill-record
 * id, never because a file exists.
 */
final class DeploymentEvidence
{
    public const SCHEMA = 'lycenza.deployment-evidence/v1';

    private const MAX_BYTES = 65536;

    private const EARLIEST = 1577836800; // 2020-01-01

    private const MAX_DURATION = 30 * 86400;

    /**
     * @return list<array{0: string, 1: array<string, string>, 2: float}>|null samples, or null when unset
     *
     * @throws InvalidDeploymentEvidence when a configured file is unusable
     */
    public function samples(): ?array
    {
        $path = (string) config('observability.metrics.deployment_evidence_file');

        if (trim($path) === '') {
            return null;
        }

        if (! is_file($path) || ! is_readable($path) || filesize($path) > self::MAX_BYTES) {
            throw new InvalidDeploymentEvidence('unreadable');
        }

        $document = json_decode((string) file_get_contents($path), true);

        return $this->validated(is_array($document) ? $document : throw new InvalidDeploymentEvidence('not_json'));
    }

    /**
     * @param  array<mixed>  $document
     * @return list<array{0: string, 1: array<string, string>, 2: float}>
     */
    public function validated(array $document): array
    {
        $this->keys($document, ['schema', 'backups', 'restore_drill']);
        if (($document['schema'] ?? null) !== self::SCHEMA) {
            throw new InvalidDeploymentEvidence('schema');
        }

        $samples = [];
        $backups = $document['backups'] ?? null;
        if ($backups !== null) {
            $this->assoc($backups);
            $this->keys($backups, ['postgresql', 'objects']);

            if (($pg = $backups['postgresql'] ?? null) !== null) {
                $this->assoc($pg);
                $this->keys($pg, ['last_success_at', 'recovery_point_at', 'last_failure_at']);
                $this->push($samples, 'lycenza_backup_last_success_timestamp_seconds', ['backup_store' => 'postgresql'], $this->time($pg['last_success_at'] ?? null));
                $this->push($samples, 'lycenza_backup_recovery_point_timestamp_seconds', ['backup_store' => 'postgresql'], $this->time($pg['recovery_point_at'] ?? null));
                $this->push($samples, 'lycenza_backup_last_failure_timestamp_seconds', ['backup_store' => 'postgresql'], $this->time($pg['last_failure_at'] ?? null));
            }

            if (($objects = $backups['objects'] ?? null) !== null) {
                $this->assoc($objects);
                $this->keys($objects, ['last_success_at', 'last_failure_at']);
                $this->push($samples, 'lycenza_backup_last_success_timestamp_seconds', ['backup_store' => 'objects'], $this->time($objects['last_success_at'] ?? null));
                $this->push($samples, 'lycenza_backup_last_failure_timestamp_seconds', ['backup_store' => 'objects'], $this->time($objects['last_failure_at'] ?? null));
            }
        }

        if (($drill = $document['restore_drill'] ?? null) !== null) {
            $this->assoc($drill);
            $this->keys($drill, ['record_id', 'last_result', 'last_success_at', 'duration_seconds', 'recovery_point_gap_seconds']);

            if (! is_string($drill['record_id'] ?? null) || preg_match('/^DRILL-\d{4}-Q[1-4]-\d{1,3}$/', $drill['record_id']) !== 1) {
                throw new InvalidDeploymentEvidence('drill_record_id');
            }
            if (! in_array($drill['last_result'] ?? null, ['PASS', 'FAIL'], true)) {
                throw new InvalidDeploymentEvidence('drill_result');
            }

            $this->push($samples, 'lycenza_restore_drill_last_result', [], $drill['last_result'] === 'PASS' ? 1.0 : 0.0);
            $this->push($samples, 'lycenza_restore_drill_last_success_timestamp_seconds', [], $this->time($drill['last_success_at'] ?? null));
            $this->push($samples, 'lycenza_restore_drill_duration_seconds', [], $this->duration($drill['duration_seconds'] ?? null));
            $this->push($samples, 'lycenza_restore_drill_recovery_point_age_seconds', [], $this->duration($drill['recovery_point_gap_seconds'] ?? null));
        }

        return $samples;
    }

    /** @param array<mixed> $values */
    private function keys(array $values, array $allowed): void
    {
        if (array_diff(array_keys($values), $allowed) !== []) {
            throw new InvalidDeploymentEvidence('unknown_key');
        }
    }

    private function assoc(mixed $value): void
    {
        if (! is_array($value) || array_is_list($value) && $value !== []) {
            throw new InvalidDeploymentEvidence('shape');
        }
    }

    private function time(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }
        if (! is_int($value) || $value < self::EARLIEST || $value > time() + 86400) {
            throw new InvalidDeploymentEvidence('timestamp');
        }

        return (float) $value;
    }

    private function duration(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }
        if (! is_int($value) || $value < 0 || $value > self::MAX_DURATION) {
            throw new InvalidDeploymentEvidence('duration');
        }

        return (float) $value;
    }

    /**
     * @param  list<array{0: string, 1: array<string, string>, 2: float}>  $samples
     * @param  array<string, string>  $labels
     */
    private function push(array &$samples, string $name, array $labels, ?float $value): void
    {
        if ($value !== null) {
            $samples[] = [$name, $labels, $value];
        }
    }
}
