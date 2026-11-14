<?php

namespace App\Domain\HR\Application\Separation;

use App\Domain\HR\Application\HrSelfAdministrationGuard;
use App\Domain\HR\Infrastructure\EmploymentSeparationCase;
use App\Domain\Identity\Application\Staff\StaffAccessState;
use App\Models\School;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Illuminate\Container\Attributes\Tag;
use Illuminate\Support\Facades\DB;

/**
 * HR.12: the offboarding obligations of one separation case, each answered
 * by its owner (SeparationObligationSource), plus HR's own reporting-line
 * consequence and the owners that record no staff obligation at all.
 *
 * Nothing here is attested by HR: an OPEN answer comes from the owner's data,
 * and an owner without a staff-obligation integration is NOT_INTEGRATED --
 * never shown as cleared (owner decision 2026-11-04: no fake Inventory,
 * Library or Finance clearance).
 */
final class SeparationObligations
{
    public const string SCHOOL_ACCESS = 'school_access';

    public const string DIRECT_REPORTS = 'direct_reports';

    /**
     * Owners with no staff obligation record today (the HR.15 / owner
     * follow-ups): their answer is fixed, never inferred.
     */
    public const array NOT_INTEGRATED = [
        'library_loans' => ['Library', 'Library items on loan', 'Library loans are recorded for Students only, so staff borrowing cannot be checked here.'],
        'inventory_assets' => ['Inventory', 'School property issued', 'Inventory records no person on a movement, so issued property cannot be checked here.'],
        'finance_dues' => ['Finance', 'Money owed to or by the School', 'Finance records no staff obligations; final settlement stays with Payroll and Finance.'],
    ];

    /** @param  iterable<SeparationObligationSource>  $sources */
    public function __construct(
        private readonly TenantContext $context,
        private readonly StaffAccessState $access,
        private readonly HrSelfAdministrationGuard $selfAdministration,
        #[Tag(SeparationObligationSource::TAG)] private readonly iterable $sources = [],
    ) {}

    /** @return list<SeparationObligation> */
    public function forCase(School $school, EmploymentSeparationCase $case): array
    {
        return $this->context->withSchool($school, function () use ($school, $case): array {
            $employment = DB::table('employment_records as er')->join('employees as e', 'e.id', '=', 'er.employee_id')
                ->where('er.school_id', $school->id)->where('er.id', $case->employment_record_id)
                ->first(['er.id', 'er.employee_id', 'e.user_id']);
            if ($employment === null) {
                return [];
            }

            $finalized = in_array($case->status, ['finalized', 'completed'], true);
            $subject = new SeparationSubject(
                (string) $employment->id,
                (string) $employment->employee_id,
                $employment->user_id === null ? null : (string) $employment->user_id,
                ($case->final_ends_on ?? $case->planned_last_day)->toDateString(),
                $finalized,
            );

            $obligations = [
                ...$this->asked('HR', 'direct_reports', 'Direct reports', fn () => [$this->directReports($school, $subject)]),
                ...$this->asked('Identity', self::SCHOOL_ACCESS, 'School staff access', fn () => [$this->schoolAccess($school, $subject, $case->access_retained_by_user_id !== null, $case->retainedGrantIds())]),
            ];
            foreach ($this->sources as $source) {
                array_push($obligations, ...$this->asked($source->owner(), strtolower($source->owner()).'_obligations', $source->owner().' obligations', fn () => $source->obligations($school, $subject)));
            }
            foreach (self::NOT_INTEGRATED as $code => [$owner, $label, $detail]) {
                $obligations[] = new SeparationObligation($code, $owner, $label, SeparationObligation::NOT_INTEGRATED, null, $detail);
            }

            return $obligations;
        });
    }

    /**
     * Blocking = real open work, or an owner that could not be asked: unknown
     * is never clear (HR.15F, fail closed).
     *
     * @param  list<SeparationObligation>  $obligations
     */
    public static function blocking(array $obligations): array
    {
        return array_values(array_filter($obligations, fn (SeparationObligation $o) => in_array($o->state, [SeparationObligation::OPEN, SeparationObligation::UNAVAILABLE], true)));
    }

    /**
     * HR.15F: one owner's answer, contained. It runs in its own savepoint, so a
     * failed read can never poison the caller's transaction (completion runs
     * inside one); a failure becomes an UNAVAILABLE obligation that blocks
     * completion and is reported, never a silent zero.
     *
     * @param  callable(): list<SeparationObligation>  $read
     * @return list<SeparationObligation>
     */
    private function asked(string $owner, string $code, string $label, callable $read): array
    {
        try {
            return DB::transaction(fn () => $read());
        } catch (\Throwable $e) {
            report($e);

            return [new SeparationObligation($code, $owner, $label, SeparationObligation::UNAVAILABLE, null,
                "{$owner} could not be checked just now, so this is not treated as clear. Reload to try again.")];
        }
    }

    /**
     * Identity's answer (StaffAccessState), never a write: flagged, off-boarded
     * only under Staff accounts (owner decision 2026-11-04). Ending the
     * employment already ends teacher and line-manager authority
     * (ActingEmployee needs a current employment).
     *
     * HR.R1-C35 WO-20 (HRC-4, D-C35-2): "kept" is bound to the SET of grants
     * recorded with it ($boundGrantIds). It reads RETAINED only while every
     * live grant is one of them (a grant since revoked leaves the rest kept);
     * a grant outside the set -- a new role, or a revoke-then-grant, whatever
     * the count -- is OPEN again (ATTENTION before finalization). A record
     * from before WO-20 binds nothing and is never RETAINED (D-WO20-2), and is
     * resolved by off-boarding, never by recording again (D-WO20-1).
     *
     * A bound set is checked against its holders too (review M2): the live
     * grants of the memberships holding the kept grants, whatever account
     * the Employee is linked to now, so unlinking or relinking the Employee
     * never turns kept access with an extra role into CLEAR -- but only for
     * accounts still believed to be the person (an uncorrected link, rules
     * 145 and 152): an account corrected as someone else is never asked to be
     * off-boarded (delta review M-A).
     *
     * @param  list<string>|null  $boundGrantIds
     */
    public function schoolAccess(School $school, SeparationSubject $subject, bool $retained, ?array $boundGrantIds = null): SeparationObligation
    {
        $live = $subject->userId === null ? [] : $this->access->activeSchoolGrantIds($school, $subject->userId);
        if ($retained && $boundGrantIds !== null) {
            $person = $this->selfAdministration->uncorrectedLinkedAccounts($school->id, $subject->employeeId);
            $live = array_values(array_unique([...$live, ...$this->access->activeSchoolGrantIdsOfHolders($school, $boundGrantIds, $person)]));
        }
        $grants = count($live);

        if ($grants === 0) {
            return new SeparationObligation(self::SCHOOL_ACCESS, 'Identity', 'School staff access', SeparationObligation::CLEAR, 0,
                $subject->userId === null && ! $retained ? 'No user account is linked to this employee.' : null);
        }
        $unresolved = $subject->finalized ? SeparationObligation::OPEN : SeparationObligation::ATTENTION;
        if ($retained && $boundGrantIds === null) {
            return new SeparationObligation(self::SCHOOL_ACCESS, 'Identity', 'School staff access', $unresolved, $grants,
                'Kept access was recorded before the kept roles were stored, so this record cannot show which roles were kept. Off-board the account under Settings → Staff accounts.');
        }
        if ($retained && array_diff($live, $boundGrantIds) !== []) {
            return new SeparationObligation(self::SCHOOL_ACCESS, 'Identity', 'School staff access', $unresolved, $grants,
                'The account holds a School role that was not among the roles recorded as kept. Off-board it under Settings → Staff accounts.');
        }
        if ($retained) {
            // HR.15E: kept is not revoked -- the roles are still active.
            return new SeparationObligation(self::SCHOOL_ACCESS, 'Identity', 'School staff access', SeparationObligation::RETAINED, $grants,
                'Kept on purpose: an authorized person recorded that these roles stay. They are still active.');
        }

        return new SeparationObligation(self::SCHOOL_ACCESS, 'Identity', 'School staff access', $unresolved, $grants,
            'The account still holds School roles. Off-board it under Settings → Staff accounts, or record that the access is kept.');
    }

    /**
     * HR's own consequence: the people this employment manages. Before
     * finalization, the lines that end with it; after, the reports it left
     * with no manager today. A successor is never assigned here (HR.3A).
     */
    private function directReports(School $school, SeparationSubject $subject): SeparationObligation
    {
        $today = SchoolTimezone::today($school);

        if (! $subject->finalized) {
            $count = DB::table('reporting_relationships')->where('school_id', $school->id)
                ->where('manager_employment_id', $subject->employmentRecordId)->whereNull('voided_at')
                ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>', $subject->lastDay))
                ->count();

            return new SeparationObligation(self::DIRECT_REPORTS, 'HR', 'Direct reports', $count > 0 ? SeparationObligation::ATTENTION : SeparationObligation::CLEAR, $count,
                $count > 0 ? 'These reporting lines end with this employment and the reports are left with no manager. Assign a successor under Reporting lines if one is needed.' : null);
        }

        $count = DB::table('reporting_relationships as rr')
            ->join('employment_records as er', 'er.id', '=', 'rr.subordinate_employment_id')
            ->where('rr.school_id', $school->id)->where('rr.manager_employment_id', $subject->employmentRecordId)
            ->where('rr.ended_via', 'manager_employment_ended')->whereNull('rr.voided_at')
            ->whereIn('er.status', ['active', 'notice_period'])
            ->where(fn ($q) => $q->whereNull('er.ends_on')->orWhere('er.ends_on', '>=', $today))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('reporting_relationships as cur')
                ->whereColumn('cur.subordinate_employment_id', 'rr.subordinate_employment_id')->whereNull('cur.voided_at')
                ->where('cur.starts_on', '<=', $today)->where(fn ($e) => $e->whereNull('cur.ends_on')->orWhere('cur.ends_on', '>=', $today)))
            ->distinct()->count('rr.subordinate_employment_id');

        return new SeparationObligation(self::DIRECT_REPORTS, 'HR', 'Direct reports left without a manager', $count > 0 ? SeparationObligation::ATTENTION : SeparationObligation::CLEAR, $count,
            $count > 0 ? 'No successor was assigned automatically. Assign one under Reporting lines if one is needed.' : null);
    }
}
