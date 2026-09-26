<?php

namespace App\Support\Operations;

use App\Support\Observability\MetricsRecorder;
use Illuminate\Console\Command;

/**
 * Phase 0O.4A: shared console output for the operator verification
 * commands -- one line per check: status, code and a non-sensitive note.
 * Exit 1 when any check FAILED (OPERATOR_EVIDENCE_REQUIRED is not a failure
 * of the repository, but it is printed so the runbook can collect it).
 *
 * @mixin Command
 */
trait RendersCheckResults
{
    /**
     * @param  list<CheckResult>  $results
     */
    protected function renderResults(array $results): int
    {
        $this->table(['Status', 'Check', 'Note'], array_map(fn (CheckResult $r) => [$r->status, $r->code, $r->note], $results));

        $failed = count(array_filter($results, fn (CheckResult $r) => $r->failed()));
        $evidence = count(array_filter($results, fn (CheckResult $r) => $r->status === CheckResult::EVIDENCE));
        $this->line("failed={$failed} operator_evidence_required={$evidence}");

        // Phase 0O.5A (ADR 0051 §11): the result as a stored gauge in the
        // metrics store -- never a database write (these commands stay
        // read-only). Best effort.
        $check = str_replace(['platform:', '-'], ['', '_'], (string) $this->getName());
        if (in_array($check, ['verify_database', 'verify_storage', 'verify_restore'], true)) {
            app(MetricsRecorder::class)->gauge('lycenza_verification_last_result', $failed === 0 ? 1.0 : 0.0, ['check' => $check]);
        }

        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
