<?php

namespace App\Domain\Payroll\Events;

use App\Support\Events\OutboxedEventDefaults;
use App\Support\Events\ShouldBeOutboxed;

/**
 * Phase 9.10 -- raised exactly once by `PayrollRunService::createRun()`/
 * `createCorrectionRun()`, from inside the same DB transaction that
 * inserts the `payroll_runs` row (ADR 0025's transactional outbox --
 * see App\Listeners\RecordDomainEventToOutbox), mirroring
 * `App\Domain\Finance\Events\JournalEntryPosted`'s exact
 * payload-minimization shape. One event type covers BOTH `run_kind`
 * values (`correctsPayrollRunId` distinguishes a correction) rather
 * than two near-identical event classes created merely for symmetry.
 * Payload carries ids and non-sensitive metadata only -- never a
 * figure from `payroll_run_results`. Internal-only: NOT registered in
 * App\Support\Webhooks\WebhookEventRegistry.
 */
class PayrollRunCreated implements ShouldBeOutboxed
{
    use OutboxedEventDefaults;

    public function __construct(
        public readonly string $schoolId,
        public readonly string $payrollRunId,
        public readonly string $payrollPeriodId,
        public readonly string $runKind,
        public readonly ?string $correctsPayrollRunId,
    ) {}

    public function eventType(): string
    {
        return 'payroll_run.created.v1';
    }

    public function eventVersion(): int
    {
        return 1;
    }

    public function schoolId(): ?string
    {
        return $this->schoolId;
    }

    public function payload(): array
    {
        return [
            'payrollRunId' => $this->payrollRunId,
            'payrollPeriodId' => $this->payrollPeriodId,
            'runKind' => $this->runKind,
            'correctsPayrollRunId' => $this->correctsPayrollRunId,
        ];
    }
}
