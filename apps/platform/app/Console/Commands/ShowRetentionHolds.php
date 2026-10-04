<?php

namespace App\Console\Commands;

use App\Support\Retention\RetentionHolds;
use Illuminate\Console\Command;

/**
 * E21-RH.3 (ADR 0066 §6): the authoritative retention holds -- the active
 * ones, or with `--history` every placement and release, with the
 * database-recorded maintenance login, path, reasons and references.
 * Operator console only (migration/owner connection; read-only).
 */
class ShowRetentionHolds extends Command
{
    protected $signature = 'platform:retention-holds {--history : Include released holds}';

    protected $description = 'Lists the authoritative retention holds, optionally with their history (E21-RH.3; operator only, read-only).';

    public function handle(RetentionHolds $holds): int
    {
        $rows = $holds->history(activeOnly: ! $this->option('history'));
        $this->table(
            ['Hold', 'Scope', 'School', 'Reason', 'Reference', 'Via', 'Placed by', 'Placed at', 'Released at', 'Released by', 'Release reason', 'Release reference'],
            array_map(fn (object $h) => [
                $h->id, $h->scope, $h->school_id ?? '-', $h->reason_code, $h->reference ?? '-', $h->placed_via, $h->placed_by_login, $h->placed_at,
                $h->released_at ?? 'ACTIVE', $h->released_by_login ?? '-', $h->release_reason_code ?? '-', $h->release_reference ?? '-',
            ], $rows),
        );
        $this->info(count($rows).' hold(s) shown'.($this->option('history') ? ' (with history)' : ' (active only)').'.');

        return self::SUCCESS;
    }
}
