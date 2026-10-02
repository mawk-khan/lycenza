<?php

namespace App\Domain\Guardians\Application\Retention;

use App\Domain\Guardians\Infrastructure\Guardian;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * E21.3C (E21.2G G1): sets `guardians.no_relationship_since` once for a
 * Guardian that predates the marker and has no relationship today, ONLY
 * from complete, trustworthy evidence. Only `platform:guardian-markers-backfill`
 * calls this. A Guardian with a relationship needs nothing (no clock runs).
 *
 * Evidence: the immutable School audit ledger, which StudentGuardianRelationshipService
 * and GuardianService write in the same transaction as each change:
 * - `guardian.created` for this Guardian must exist. Audit expiry (D1)
 *   removes the OLDEST events first, so if the creation event survives,
 *   every later event about the Guardian survives too: the history is
 *   complete.
 * - Every relationship ever linked (`student_guardian.linked`, by
 *   `metadata.guardianId`) must have its own `student_guardian.unlinked`
 *   event. A relationship that left without one (D7 retention, an E21.3B
 *   core purge, a raw import) leaves its end unknown: unresolved.
 * - The marker is then the LAST unlink's `occurred_at` (or the creation
 *   time when the Guardian was never linked).
 * Anything else stays NULL and `unresolved`: the Guardian is kept. Never
 * `updated_at`. Deterministic and rerunnable (a set marker is never
 * changed; the database refuses it). Bounded batches, one School context,
 * counts only.
 */
final class GuardianMarkerBackfill
{
    public function __construct(private readonly TenantContext $context) {}

    /** @return array{mapped: int, already_mapped: int, unresolved: int, error: int} */
    public function run(School $school, int $batch, bool $dryRun): array
    {
        return $this->context->withSchool($school, function () use ($school, $batch, $dryRun): array {
            $result = ['mapped' => 0, 'already_mapped' => 0, 'unresolved' => 0, 'error' => 0];
            $unrelated = fn () => DB::table('guardians as g')->where('g.school_id', $school->id)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('student_guardian_relationships as r')->whereColumn('r.guardian_id', 'g.id'));
            $result['already_mapped'] = $unrelated()->whereNotNull('g.no_relationship_since')->count();

            $unrelated()->whereNull('g.no_relationship_since')->select('g.id')
                ->chunkById($batch, function ($guardians) use ($school, $dryRun, &$result): void {
                    foreach ($guardians as $guardian) {
                        $since = $this->evidence($school->id, $guardian->id);
                        if ($since === null) {
                            $result['unresolved']++;

                            continue;
                        }
                        if ($dryRun) {
                            $result['mapped']++;

                            continue;
                        }

                        try {
                            $result['mapped'] += DB::table('guardians')->where('id', $guardian->id)->whereNull('no_relationship_since')
                                ->update(['no_relationship_since' => $since]);
                        } catch (QueryException) {
                            // A relationship committed meanwhile, or the evidence is in the future: the database refuses.
                            $result['error']++;
                        }
                    }
                }, 'g.id', 'id');

            return $result;
        });
    }

    /** The time the Guardian last had no relationship, from complete audit evidence, or null. */
    private function evidence(string $schoolId, string $guardianId): ?string
    {
        $audit = fn () => DB::table('school_audit_events')->where('school_id', $schoolId);

        $created = $audit()->where('event_type', 'guardian.created')->where('subject_type', Guardian::class)->where('subject_id', $guardianId)->min('occurred_at');
        if ($created === null) {
            return null;
        }

        $linked = $audit()->where('event_type', 'student_guardian.linked')->whereRaw("metadata->>'guardianId' = ?", [$guardianId])->pluck('subject_id')->all();
        $unlinked = $audit()->where('event_type', 'student_guardian.unlinked')->whereRaw("metadata->>'guardianId' = ?", [$guardianId])->get(['subject_id', 'occurred_at']);

        if (array_diff($linked, $unlinked->pluck('subject_id')->all()) !== []) {
            return null;
        }

        return (string) ($unlinked->max('occurred_at') ?? $created);
    }
}
