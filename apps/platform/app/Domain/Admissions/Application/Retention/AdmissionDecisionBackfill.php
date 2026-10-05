<?php

namespace App\Domain\Admissions\Application\Retention;

use App\Domain\Admissions\Infrastructure\AdmissionApplication;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * E21.3C (E21.2G AD2): sets `terminal_at` once for a rejected/withdrawn
 * application that predates the column, ONLY from trustworthy evidence.
 * Only `platform:admission-decisions-backfill` calls this.
 *
 * Evidence (the only source): the immutable School audit event of the
 * terminal transition itself -- `admission_application.rejected` or
 * `admission_application.withdrawn` with this application as subject,
 * written by AdmissionApplicationService in the transition's transaction.
 * It is used only when there is EXACTLY ONE such event and it names the
 * application's current status; its `occurred_at` becomes `terminal_at`.
 * Anything else (no event, e.g. pruned by D1 or a raw import; several;
 * a mismatch) stays NULL and `unresolved`: the application is kept. Never
 * `updated_at`, never a guess.
 *
 * Deterministic and rerunnable: an already-set row is never touched (the
 * database refuses a change), so the same input gives the same result.
 * Bounded batches in id order, one School context at a time. Counts only.
 */
final class AdmissionDecisionBackfill
{
    public function __construct(private readonly TenantContext $context) {}

    /** @return array{mapped: int, already_mapped: int, unresolved: int, error: int} */
    public function run(School $school, int $batch, bool $dryRun): array
    {
        return $this->context->withSchool($school, function () use ($school, $batch, $dryRun): array {
            $result = ['mapped' => 0, 'already_mapped' => 0, 'unresolved' => 0, 'error' => 0];
            $result['already_mapped'] = DB::table('admission_applications')->where('school_id', $school->id)
                ->whereIn('status', TerminalApplicationRetentionService::TERMINAL)->whereNotNull('terminal_at')->count();

            DB::table('admission_applications')->where('school_id', $school->id)->whereIn('status', TerminalApplicationRetentionService::TERMINAL)
                ->whereNull('terminal_at')->select('id', 'status')->orderBy('id')
                ->chunkById($batch, function ($applications) use ($school, $dryRun, &$result): void {
                    foreach ($applications as $application) {
                        $events = DB::table('school_audit_events')->where('school_id', $school->id)
                            ->where('subject_type', AdmissionApplication::class)->where('subject_id', $application->id)
                            ->whereIn('event_type', ['admission_application.rejected', 'admission_application.withdrawn'])
                            ->get(['event_type', 'occurred_at', 'retention_recorded_at']);

                        if ($events->count() !== 1 || $events->first()->event_type !== 'admission_application.'.$application->status) {
                            $result['unresolved']++;

                            continue;
                        }
                        if ($dryRun) {
                            $result['mapped']++;

                            continue;
                        }

                        try {
                            $result['mapped'] += DB::table('admission_applications')->where('id', $application->id)
                                ->where('status', $application->status)->whereNull('terminal_at')
                                // E21-RH.7: the decision counts from when the database recorded its audit event (provenance), never earlier.
                                ->update(['terminal_at' => $events->first()->occurred_at, 'retention_recorded_at' => $events->first()->retention_recorded_at]);
                        } catch (QueryException) {
                            $result['error']++;
                        }
                    }
                });

            return $result;
        });
    }
}
