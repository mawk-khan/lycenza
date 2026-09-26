<?php

namespace App\Console\Commands;

use App\Support\Observability\Alerts\AlertCatalog;
use App\Support\Observability\Alerts\AlertRulesExporter;
use App\Support\Observability\Alerts\InvalidAlertThreshold;
use Illuminate\Console\Command;

/**
 * Phase 0O.5A (ADR 0051 §14): prints (or writes) the provider-neutral alert
 * rules with the CURRENT operator values applied. A malformed operator
 * value fails the export -- it is never silently accepted.
 */
class ExportAlertRules extends Command
{
    protected $signature = 'platform:alerts-export {--output= : Write to this path instead of stdout}';

    protected $description = 'Export the OBS-01..OBS-26 alert rules (Prometheus rule format, provider-neutral).';

    public function handle(AlertRulesExporter $exporter): int
    {
        try {
            $yaml = $exporter->yaml();
        } catch (InvalidAlertThreshold $e) {
            $this->error('Refused: '.$e->getMessage());

            return self::FAILURE;
        }

        $output = (string) $this->option('output');
        if ($output !== '') {
            file_put_contents($output, $yaml);
            $this->info('Wrote '.count(AlertCatalog::all()).' alert definitions.');

            return self::SUCCESS;
        }

        $this->output->write($yaml);

        return self::SUCCESS;
    }
}
