<?php

namespace App\Console\Commands;

use App\Support\Observability\OperationalStatus;
use App\Support\Observability\OperationalStatusService;
use Illuminate\Console\Command;

/**
 * Phase 0C.4 section 54: CLI-accessible equivalent of
 * `GET /internal/operations/status` for an operator with shell access
 * but no HTTP session -- same OperationalStatusService::full(), same
 * component set, no separate diagnostic logic to keep in sync.
 */
class ShowOperationsStatus extends Command
{
    protected $signature = 'platform:operations-status {--json : Output raw JSON instead of a table}';

    protected $description = 'Print the current cross-tenant operational status (database, redis, scheduler, queues, outbox, webhooks, AI Gateway).';

    public function handle(OperationalStatusService $service): int
    {
        $components = $service->full();
        $overall = OperationalStatus::worstOf(array_map(fn ($c) => $c->status, $components));

        if ($this->option('json')) {
            $this->line(json_encode([
                'status' => $overall->value,
                'components' => array_map(fn ($c) => $c->toArray(), $components),
            ], JSON_PRETTY_PRINT));

            return $overall === OperationalStatus::Unhealthy ? self::FAILURE : self::SUCCESS;
        }

        $this->info("Overall: {$overall->value}");
        $this->newLine();

        $this->table(
            ['Component', 'Status', 'Reason', 'Detail'],
            array_map(fn ($c) => [
                $c->component,
                $c->status->value,
                $c->reason ?? '-',
                json_encode($c->detail),
            ], $components),
        );

        return $overall === OperationalStatus::Unhealthy ? self::FAILURE : self::SUCCESS;
    }
}
