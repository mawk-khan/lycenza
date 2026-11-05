<?php

namespace App\Domain\HR\Application\Separation;

use App\Domain\HR\Infrastructure\EmploymentSeparationCase;
use App\Models\School;
use App\Support\Tenancy\SchoolTimezone;
use Carbon\CarbonImmutable;

/**
 * HR.16A (R-HR14-2, owner decision 2026-11-05): an employment's status ON A
 * PAST DATE, from evidence only. The only dated evidence of a notice period
 * is an HR.12 case: approval puts the employment into notice
 * (`decided_at`, School-local), a cancellation ends it the day before
 * (`cancelled_at`), finalization ends the employment itself.
 *
 *   EMPLOYED        covered by the employment, outside every notice interval
 *   NOTICE_PERIOD   inside an approved case's notice interval
 *   NOT_RECORDED    the employment is (or was) in notice or ended through a
 *                   path older than HR.12, so its notice history cannot be
 *                   known: never guessed
 *
 * The caller passes only employments covering the date. Data, never
 * authorization. MUST run inside the School's TenantContext.
 */
final class SeparationNoticeHistory
{
    public const string EMPLOYED = 'Employed';

    public const string NOTICE_PERIOD = 'Notice period';

    public const string NOT_RECORDED = 'Employed (notice history not recorded)';

    /** Current statuses that imply a notice period or separation happened, so missing evidence is unknown, not "none". */
    private const array NEEDS_EVIDENCE = ['notice_period', 'separated', 'terminated', 'retired'];

    /**
     * @param  array<string, string>  $currentStatuses  employment id => its status today
     * @return array<string, string> lower-cased employment id => status on $date
     */
    public function statusesOn(School $school, array $currentStatuses, string $date): array
    {
        if ($currentStatuses === []) {
            return [];
        }
        $zone = SchoolTimezone::resolve($school);
        $local = fn ($at) => $at === null ? null : CarbonImmutable::parse($at)->setTimezone($zone)->toDateString();
        $cases = EmploymentSeparationCase::query()->where('school_id', $school->id)
            ->whereIn('employment_record_id', array_keys($currentStatuses))
            ->whereNotNull('decided_at')->whereIn('status', ['approved', 'finalized', 'completed', 'cancelled'])
            ->get(['employment_record_id', 'decided_at', 'cancelled_at'])
            ->groupBy(fn (EmploymentSeparationCase $c) => strtolower($c->employment_record_id));

        $result = [];
        foreach ($currentStatuses as $id => $status) {
            $id = strtolower($id);
            $evidence = $cases->get($id, collect());
            $inNotice = $evidence->contains(function (EmploymentSeparationCase $c) use ($local, $date): bool {
                $start = $local($c->decided_at);
                $end = $c->cancelled_at === null ? null : CarbonImmutable::parse($local($c->cancelled_at))->subDay()->toDateString();

                return $start !== null && $start <= $date && ($end === null || $date <= $end);
            });
            $result[$id] = match (true) {
                $inNotice => self::NOTICE_PERIOD,
                $evidence->isEmpty() && in_array($status, self::NEEDS_EVIDENCE, true) => self::NOT_RECORDED,
                default => self::EMPLOYED,
            };
        }

        return $result;
    }
}
