<?php

namespace App\Domain\Platform\Application\Groups\Reporting;

use App\Domain\Analytics\Application\Group\GroupReportAuthority;
use App\Domain\Analytics\Application\Group\GroupReportAuthorityLostException;
use App\Domain\Analytics\Application\Group\GroupSafeReportGate;
use App\Domain\Analytics\Application\Group\GroupSafeSchoolSummary;
use App\Domain\Analytics\Application\ReadModels\CurriculumCoverageReadModel;
use App\Models\GroupRoleAssignment;
use App\Models\School;
use App\Models\SchoolGroup;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\Mfa\Exceptions\MfaRequiredNotEnrolledException;
use App\Support\Auth\Mfa\Exceptions\MfaStepUpRequiredException;
use App\Support\Auth\Mfa\MfaChallengeService;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Phase 0N.11 (ADR 0048) -- the Group Curriculum Coverage report: the one
 * cross-School read, as a bounded sequence of single-School observations.
 *
 * This Group layer owns authorization, Group membership and eligibility;
 * Analytics (GroupSafeReportGate) owns reading, the School summary and the
 * aggregate. Nothing here queries a tenant table, a read model's data or a
 * source service.
 *
 * Before any School is read: account enabled, Group active,
 * `group.reporting.view` from an unrevoked grant in THIS Group (fresh,
 * never cached; the grant used is recorded), current MFA assurance.
 * Refusals here are the Group view's non-disclosing 404 (or the MFA
 * 403/401) and are not audited.
 *
 * Then, per member School in id order, in ONE short transaction: the
 * grant (still unrevoked, role still holding the capability), the Group
 * (still active) and that School's membership row are read FOR SHARE --
 * the rows a removal, revocation or archive changes, so those serialize
 * with this observation -- and Analytics reads the School under its own
 * context. A School no longer a member is omitted; a non-active one is
 * `unavailable`; no active academic year is its own state. Lost authority
 * or any unexpected error fails the WHOLE report (no partial aggregate),
 * audited `platform.school_group_report.failed`. A final authority check
 * precedes the success audit `platform.school_group_report.viewed`.
 * Nothing is cached or persisted.
 */
class GroupCurriculumCoverageReportService
{
    public const CAPABILITY = GroupSafeReportGate::CAPABILITY;

    public const REPORT_KEY = CurriculumCoverageReadModel::KEY;

    public const VIEWED = 'platform.school_group_report.viewed';

    public const FAILED = 'platform.school_group_report.failed';

    public function __construct(
        private readonly CapabilityResolver $capabilities,
        private readonly MfaChallengeService $mfa,
        private readonly GroupSafeReportGate $gate,
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @return array<string, mixed> the fixed Group report DTO
     */
    public function generate(Request $request, User $actor, SchoolGroup $group): array
    {
        if ($this->context->schoolId() !== null) {
            throw new LogicException('A Group report never runs with a School context set.');
        }

        $grant = $this->authorizingGrant($actor, $group);

        if ($grant === null) {
            throw new NotFoundHttpException;
        }

        if (! $this->mfa->userHasActiveFactor($actor)) {
            throw new MfaRequiredNotEnrolledException;
        }

        if (! $this->mfa->hasValidAssurance($request)) {
            throw new MfaStepUpRequiredException;
        }

        $authority = new GroupReportAuthority($actor, $group->id, $grant->id, self::REPORT_KEY);
        $generatedAt = now();
        $rows = [];
        $read = [];

        try {
            foreach ($this->memberSchoolIds($group) as $schoolId) {
                $row = DB::transaction(function () use ($authority, $grant, $group, $schoolId, &$read): ?array {
                    return $this->observe($authority, $grant, $group, $schoolId, $read);
                });

                if ($row !== null) {
                    $rows[] = $row;
                }
            }

            if ($this->authorizingGrant($actor, $group->fresh() ?? $group)?->id !== $grant->id) {
                throw new GroupReportAuthorityLostException('The Group reporting authority changed during the report.');
            }

            $summaries = array_values(array_filter(array_map(fn (array $r) => $r['summary'], $rows)));
            $totals = $this->gate->aggregate(self::REPORT_KEY, array_values(array_filter($summaries, fn (GroupSafeSchoolSummary $s) => $s->contributes())));
        } catch (GroupReportAuthorityLostException) {
            $this->failed($request, $actor, $group, $grant, 'authority_lost', $read);

            throw new NotFoundHttpException;
        } catch (Throwable $e) {
            $this->failed($request, $actor, $group, $grant, 'source_error', $read);

            throw new GroupReportFailedException(previous: $e);
        } finally {
            // Defence in depth: Analytics already restored "no School".
            if ($this->context->schoolId() !== null) {
                $this->context->clear();
            }
        }

        $contributing = array_values(array_map(fn (array $r) => $r['schoolId'], array_filter($rows, fn (array $r) => $r['state'] === 'included')));
        $unavailable = count(array_filter($rows, fn (array $r) => $r['state'] === 'unavailable'));
        $noActiveYear = count(array_filter($rows, fn (array $r) => $r['state'] === 'no_active_academic_year'));

        $this->audit->platform(self::VIEWED, actor: $actor, subject: $group, metadata: [
            'group_role_assignment_id' => $grant->id,
            'report_key' => self::REPORT_KEY,
            'contributing_school_ids' => $contributing,
            'unavailable_count' => $unavailable,
            'no_active_year_count' => $noActiveYear,
        ], ipAddress: $request->ip(), userAgent: $request->userAgent());

        usort($rows, fn (array $a, array $b) => [$a['name'], $a['schoolId']] <=> [$b['name'], $b['schoolId']]);

        return [
            'group' => ['id' => $group->id, 'name' => $group->name],
            'reportKey' => self::REPORT_KEY,
            'generatedAt' => $generatedAt->toIso8601String(),
            'schools' => array_map(fn (array $r) => [
                'schoolId' => $r['schoolId'],
                'name' => $r['name'],
                'state' => $r['state'],
                'observedAt' => $r['observedAt'],
                'coverage' => $r['state'] === 'included' ? $r['summary']->toArray() : null,
            ], $rows),
            'totals' => $totals + [
                'contributingSchools' => count($contributing),
                'unavailableSchools' => $unavailable,
                'noActiveYearSchools' => $noActiveYear,
            ],
        ];
    }

    /**
     * The one unrevoked grant in this active Group whose role carries the
     * reporting capability -- fresh, never cached, never another Group's.
     */
    public function authorizingGrant(User $actor, SchoolGroup $group): ?GroupRoleAssignment
    {
        if ($actor->isDisabled() || $group->status !== SchoolGroup::STATUS_ACTIVE || ! $this->capabilities->canInGroup($actor, self::CAPABILITY, $group)) {
            return null;
        }

        return GroupRoleAssignment::query()->active()
            ->where('user_id', $actor->id)
            ->where('school_group_id', $group->id)
            ->whereIn('role_id', DB::table('role_capabilities')->where('capability_key', self::CAPABILITY)->select('role_id'))
            ->orderBy('granted_at')->orderBy('id')
            ->first();
    }

    /**
     * @return list<string>
     */
    private function memberSchoolIds(SchoolGroup $group): array
    {
        return DB::table('school_group_members')->where('school_group_id', $group->id)->orderBy('school_id')->pluck('school_id')->all();
    }

    /**
     * One School's observation, inside the caller's transaction.
     *
     * @param  list<string>  $read  School ids whose tenant data has been read (by reference)
     * @return array{schoolId: string, name: string, state: string, observedAt: string, summary: GroupSafeSchoolSummary|null}|null null when the School is no longer a member
     */
    private function observe(GroupReportAuthority $authority, GroupRoleAssignment $grant, SchoolGroup $group, string $schoolId, array &$read): ?array
    {
        // Lock order Group -> grant -> membership -> School: the order the
        // governance operations take them in (archive: Group then grants),
        // so a racing change waits instead of deadlocking.
        $groupActive = DB::table('school_groups')->where('id', $group->id)->where('status', SchoolGroup::STATUS_ACTIVE)->sharedLock()->exists();
        $grantHeld = $groupActive
            && DB::table('group_role_assignments')->where('id', $grant->id)->whereNull('revoked_at')->sharedLock()->exists()
            && DB::table('role_capabilities')->where('role_id', $grant->role_id)->where('capability_key', self::CAPABILITY)->exists();

        if (! $groupActive || ! $grantHeld) {
            throw new GroupReportAuthorityLostException('The Group reporting authority is no longer held.');
        }

        if (! DB::table('school_group_members')->where('school_group_id', $group->id)->where('school_id', $schoolId)->sharedLock()->exists()) {
            return null;
        }

        $school = School::query()->findOrFail($schoolId);
        $summary = $this->gate->summarize($authority, $school);

        if ($summary !== null) {
            $read[] = $schoolId;
        }

        return [
            'schoolId' => $schoolId,
            'name' => $school->name,
            'state' => match (true) {
                $summary === null => 'unavailable',
                ! $summary->contributes() => 'no_active_academic_year',
                default => 'included',
            },
            'observedAt' => now()->toIso8601String(),
            'summary' => $summary !== null && $summary->contributes() ? $summary : null,
        ];
    }

    /**
     * @param  list<string>  $read
     */
    private function failed(Request $request, User $actor, SchoolGroup $group, GroupRoleAssignment $grant, string $outcome, array $read): void
    {
        $this->audit->platform(self::FAILED, actor: $actor, subject: $group, metadata: [
            'group_role_assignment_id' => $grant->id,
            'report_key' => self::REPORT_KEY,
            'outcome_code' => $outcome,
            'read_school_ids' => $read,
        ], ipAddress: $request->ip(), userAgent: $request->userAgent());
    }
}
