<?php

namespace App\Domain\Admissions\Application\Retention;

use App\Models\School;
use App\Support\Retention\ReferencingRows;
use App\Support\Retention\RetentionUnit;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * E21.3C (E21.2G AD2, project-adopted, pending legal ratification): a
 * NON-converted terminal application (`rejected` or `withdrawn`) is kept
 * ADMISSIONS_TERMINAL_RETENTION_YEARS (adopted 1) calendar years after its
 * canonical `terminal_at`, then deleted. Only
 * `platform:admissions-retention-prune` calls this, never a request.
 *
 * - The trigger is `terminal_at` (UTC timestamp, set by the database in the
 *   terminal transition), never `updated_at`. A terminal application
 *   without it (it predates the column and the backfill found no
 *   trustworthy evidence) is `unresolved` and kept.
 * - Converted applications follow the Student core record (E21.3B,
 *   ConvertedApplicationRetentionService); live applications (draft,
 *   submitted, accepted) are working state. Neither is touched here.
 * - The unit is the APPLICANT, one per transaction: lock the applicant
 *   FOR UPDATE, then its applications, recheck, delete the expired terminal
 *   applications, and delete the applicant only once no application of it
 *   remains. A new application for the same applicant takes FOR KEY SHARE on
 *   it, so it either commits first (the applicant stays) or waits.
 *   Lock order: applicant -> application (the lifecycle and conversion
 *   paths lock only the application).
 * - Any other row referencing an expiring application, or the released
 *   applicant, keeps them (`dependency_blocked`; ReferencingRows). No
 *   Admissions row owns a Document or a Finance record.
 * - A held School is counted only. Counts only, never applicant details.
 */
final class TerminalApplicationRetentionService
{
    public const TERMINAL = ['rejected', 'withdrawn'];

    private const TABLE = 'admission_applications';

    public function __construct(
        private readonly TenantContext $context,
        private readonly ReferencingRows $references,
    ) {}

    /**
     * @param  CarbonInterface  $cutoff  UTC: an application that ended strictly before it is eligible
     * @return array{eligible: int, deleted: int, held: int, unresolved: int, dependency_blocked: int, errors: int}
     */
    public function prune(School $school, CarbonInterface $cutoff, int $batch, bool $dryRun, bool $held): array
    {
        return $this->context->withSchool($school, function () use ($school, $cutoff, $batch, $dryRun, $held): array {
            $at = $cutoff->copy()->utc()->format('Y-m-d H:i:s');
            $result = ['eligible' => 0, 'deleted' => 0, 'unresolved' => 0, 'dependency_blocked' => 0, 'errors' => 0];
            $result['unresolved'] = DB::table(self::TABLE)->where('school_id', $school->id)->whereIn('status', self::TERMINAL)->whereNull('terminal_at')->count();

            DB::table('applicants as p')->where('p.school_id', $school->id)->select('p.id')
                ->whereExists(fn (Builder $q) => $this->expired($q->selectRaw('1')->from(self::TABLE.' as a')->whereColumn('a.applicant_id', 'p.id'), $at))
                ->chunkById($batch, function ($applicants) use ($school, $at, $dryRun, $held, &$result): void {
                    foreach ($applicants as $applicant) {
                        RetentionUnit::purge(
                            $result,
                            $dryRun || $held,
                            fn (): bool => $this->lock($applicant->id, $at),
                            fn (): array => array_filter([$this->blocker($school->id, $applicant->id, $at)]),
                            fn (): ?array => $this->purge($school->id, $applicant->id, $at),
                        );
                    }
                }, 'p.id', 'id');

            $result['held'] = 0;
            if ($held) {
                $result['held'] = $result['eligible'];
                $result['dependency_blocked'] = 0;
            }

            return $result;
        });
    }

    /** Narrows `a` to terminal applications that ended strictly before `$at`. */
    private function expired(Builder $query, string $at): Builder
    {
        return $query->whereIn('a.status', self::TERMINAL)->whereNotNull('a.terminal_at')->where('a.terminal_at', '<', $at);
    }

    private function lock(string $applicantId, string $at): bool
    {
        if (DB::table('applicants')->where('id', $applicantId)->lockForUpdate()->first(['id']) === null) {
            return false;
        }
        DB::table(self::TABLE)->where('applicant_id', $applicantId)->orderBy('id')->lockForUpdate()->get(['id']);

        return $this->expired(DB::table(self::TABLE.' as a')->where('a.applicant_id', $applicantId), $at)->exists();
    }

    private function blocker(string $schoolId, string $applicantId, string $at): ?string
    {
        $expired = $this->expired(DB::table(self::TABLE.' as a')->where('a.applicant_id', $applicantId), $at)->orderBy('a.id')->pluck('a.id')->all();
        $released = ! DB::table(self::TABLE)->where('applicant_id', $applicantId)->whereNotIn('id', $expired)->exists();

        return $this->references->first(self::TABLE, $schoolId, $expired)
            ?? ($released ? $this->references->first('applicants', $schoolId, [$applicantId], [self::TABLE]) : null);
    }

    /** @return list<object>|null */
    private function purge(string $schoolId, string $applicantId, string $at): ?array
    {
        $deleted = $this->expired(DB::table(self::TABLE.' as a')->where('a.school_id', $schoolId)->where('a.applicant_id', $applicantId), $at)->delete();
        if ($deleted === 0) {
            return null;
        }

        // The applicant leaves only once no application of it remains.
        DB::table('applicants as p')->where('p.school_id', $schoolId)->where('p.id', $applicantId)
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from(self::TABLE.' as a')->whereColumn('a.applicant_id', 'p.id'))
            ->delete();

        return [];
    }
}
