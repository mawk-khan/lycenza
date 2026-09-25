<?php

namespace App\Support\Operations;

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

        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
