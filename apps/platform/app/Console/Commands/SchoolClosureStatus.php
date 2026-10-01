<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Support\Retention\TenantClosureReadiness;
use Illuminate\Console\Command;

/**
 * E21.2F (E21-D11): READ-ONLY closure readiness for one School. It shows:
 * - whether the School is closed or held;
 * - the blocking gates;
 * - per retention category, how many tables still hold rows and why they
 *   are kept.
 *
 * It never deletes; there is no School purge command. It prints category
 * codes and counts only, never a record.
 */
class SchoolClosureStatus extends Command
{
    protected $signature = 'platform:school-closure-status {school : The School id or slug}';

    protected $description = 'Show what still blocks a future tenant purge of one School (E21-D11; read-only).';

    public function handle(TenantClosureReadiness $readiness): int
    {
        $key = (string) $this->argument('school');
        $school = School::query()->where(fn ($q) => preg_match('/^[0-9a-f-]{36}$/i', $key) === 1 ? $q->whereKey($key) : $q->where('slug', $key))->first();

        if ($school === null) {
            $this->error('No such School.');

            return self::FAILURE;
        }

        $report = $readiness->report($school);

        $this->line('Closed: '.($report['closed'] ? 'yes' : 'no').'; legal hold: '.($report['held'] ? 'yes' : 'no').'; purge-ready: '.($report['purge_ready'] ? 'yes' : 'NO'));
        $this->line('Blocking gates: '.implode(', ', $report['gates']));
        if ($report['unclassified'] !== []) {
            $this->line('Unclassified tables (fail closed): '.implode(', ', $report['unclassified']));
        }
        $this->table(['Category', 'Status', 'Tables with rows', 'Outcome', 'Not before'], array_map(
            fn (array $c) => [$c['category'], $c['status'], $c['tables_with_rows'], $c['outcome'], $c['not_before'] ?? '-'],
            $report['categories'],
        ));

        return self::SUCCESS;
    }
}
