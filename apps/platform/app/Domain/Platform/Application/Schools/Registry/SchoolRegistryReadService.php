<?php

namespace App\Domain\Platform\Application\Schools\Registry;

use App\Domain\AcademicStructure\Application\CurrentAcademicYearResolver;
use App\Domain\Campuses\Application\CampusStructureSummary;
use App\Domain\Platform\Application\Domains\SchoolDomainService;
use App\Models\School;
use App\Models\SchoolDomain;
use App\Support\Authorization\SchoolAdministrators;
use App\Support\Domains\DomainState;
use App\Support\Tenancy\SchoolStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * ORG.3 (ADR 0076 §9.2): the Platform School Registry read model --
 * non-business tenant metadata only, context-neutral.
 *
 * - Platform tables (`schools`, `school_domains`, Group membership) are
 *   read directly and in batch: search, filters and keyset pagination run
 *   on `schools` alone.
 * - RLS-protected structure (campuses, academic years, role grants) is read
 *   only through its owning module, inside ONE School's TenantContext at a
 *   time: CampusStructureSummary, CurrentAcademicYearResolver,
 *   SchoolAdministrators. Never a cross-tenant query, never BYPASSRLS, never
 *   cached; the page size bounds the work.
 * - Readiness and warnings are derived here (SchoolReadiness), never stored.
 *
 * Never: students, guardians, staff HR, payroll, fees, ledger, marks,
 * attendance, documents or `school_audit_events`.
 */
class SchoolRegistryReadService
{
    public const DEFAULT_PER_PAGE = 25;

    public const MIN_PER_PAGE = 10;

    public const MAX_PER_PAGE = 50;

    public const MAX_SEARCH_LENGTH = 100;

    /** List status filter: the lifecycle plus closure (a closed School is `suspended` with `closed_at`). */
    public const STATUS_FILTERS = ['provisioning', 'active', 'suspended', 'closed'];

    public const DOMAIN_NONE = 'none';

    public const DOMAIN_PENDING = 'pending';

    public const DOMAIN_ACTIVE = 'active';

    public const DOMAIN_ATTENTION = 'attention';

    public const DOMAIN_FILTERS = [self::DOMAIN_NONE, self::DOMAIN_PENDING, self::DOMAIN_ACTIVE, self::DOMAIN_ATTENTION];

    private const PENDING_STATES = [DomainState::PendingVerification, DomainState::Verified, DomainState::TlsPending];

    private const COLUMNS = ['id', 'name', 'legal_name', 'slug', 'code', 'status', 'closed_at', 'created_at'];

    public function __construct(
        private readonly CampusStructureSummary $campuses,
        private readonly CurrentAcademicYearResolver $academicYears,
        private readonly SchoolAdministrators $administrators,
        private readonly SchoolDomainService $domains,
    ) {}

    /**
     * @return array{schools: list<array<string, mixed>>, nextCursor: string|null, perPage: int}
     *
     * @throws InvalidArgumentException for a cursor this service did not issue
     */
    public function list(SchoolRegistryFilters $filters): array
    {
        $perPage = max(self::MIN_PER_PAGE, min(self::MAX_PER_PAGE, $filters->perPage));
        $query = School::query()->select(self::COLUMNS)->orderBy('name')->orderBy('id')->limit($perPage + 1);

        $this->applySearch($query, $filters->search);
        $this->applyStatus($query, $filters->status);
        $this->applyDomain($query, $filters->domain);
        if ($filters->groupId !== null) {
            $query->whereIn('id', DB::table('school_group_members')->select('school_id')->where('school_group_id', $filters->groupId));
        }
        if ($filters->cursor !== null) {
            [$name, $id] = self::decodeCursor($filters->cursor);
            $query->whereRaw('(name, id) > (?, ?::uuid)', [$name, $id]);
        }

        $rows = $query->get();
        $hasMore = $rows->count() > $perPage;
        $schools = $rows->take($perPage)->values();

        $ids = $schools->pluck('id')->all();
        $domains = $this->domainStatuses($ids);
        $groups = $this->groups($ids);
        $enabled = $this->domains->enabled();

        $last = $schools->last();

        return [
            'schools' => $schools->map(function (School $school) use ($domains, $groups, $enabled): array {
                $structure = $this->structure($school);
                $domain = $domains[$school->id] ?? self::DOMAIN_NONE;
                $readiness = SchoolReadiness::evaluate($school, $structure['campuses'], $structure['academicYear'], $structure['administrator'], $domain, $enabled);

                return [
                    ...$this->identity($school),
                    'campuses' => $structure['campuses'],
                    'academicYear' => $structure['academicYear'],
                    'domain' => $domain,
                    'groups' => $groups[$school->id] ?? [],
                    'readiness' => $readiness['state'],
                    'warningCount' => count(array_filter($readiness['warnings'], fn (array $w): bool => $w['severity'] === 'warning')),
                ];
            })->all(),
            'nextCursor' => $hasMore && $last instanceof School ? self::encodeCursor($last->name, $last->id) : null,
            'perPage' => $perPage,
        ];
    }

    /** @return array<string, mixed> one School's overview (the audit summary is added by its own review) */
    public function overview(School $school): array
    {
        $structure = $this->structure($school);
        $domain = $this->domainStatuses([$school->id])[$school->id] ?? self::DOMAIN_NONE;
        $enabled = $this->domains->enabled();

        return [
            ...$this->identity($school),
            'timezone' => $school->timezone,
            'locale' => $school->default_locale,
            'closureReason' => $school->closure_reason,
            'campuses' => $structure['campuses'],
            'campusList' => $this->campuses->campuses($school),
            'academicYear' => $structure['academicYear'],
            'administrator' => $structure['administrator'],
            'domain' => $domain,
            'domainsEnabled' => $enabled,
            'domainStates' => $this->domainStateCounts($school),
            'groups' => $this->groups([$school->id])[$school->id] ?? [],
            ...SchoolReadiness::evaluate($school, $structure['campuses'], $structure['academicYear'], $structure['administrator'], $domain, $enabled),
        ];
    }

    /** @return array{campuses: array{active: int, inactive: int}, academicYear: string|null, administrator: string} */
    private function structure(School $school): array
    {
        return [
            'campuses' => $this->campuses->counts($school),
            'academicYear' => $this->academicYears->tryResolve($school)?->name,
            'administrator' => $this->administrators->readiness($school),
        ];
    }

    /** @return array<string, string|bool|null> */
    private function identity(School $school): array
    {
        return [
            'id' => $school->id,
            'name' => $school->name,
            'legalName' => $school->legal_name,
            'slug' => $school->slug,
            'code' => $school->code,
            'status' => $school->status,
            'closed' => $school->isClosed(),
            'closedAt' => $school->closed_at?->toIso8601String(),
            'createdAt' => $school->created_at?->toIso8601String(),
        ];
    }

    /** @param  Builder<School>  $query */
    private function applySearch(Builder $query, ?string $search): void
    {
        $search = $search === null ? '' : trim($search);
        if ($search === '') {
            return;
        }
        $pattern = '%'.addcslashes(mb_substr($search, 0, self::MAX_SEARCH_LENGTH), '\\%_').'%';

        $query->where(function (Builder $q) use ($pattern): void {
            foreach (['name', 'legal_name', 'slug', 'code'] as $column) {
                $q->orWhere($column, 'ilike', $pattern);
            }
        });
    }

    /** @param  Builder<School>  $query */
    private function applyStatus(Builder $query, ?string $status): void
    {
        match ($status) {
            null => null,
            'closed' => $query->whereNotNull('closed_at'),
            'suspended' => $query->where('status', SchoolStatus::Suspended->value)->whereNull('closed_at'),
            default => $query->where('status', $status),
        };
    }

    /**
     * Domain status precedence: attention (a suspended domain) > active (an
     * active primary domain) > pending (a claim being verified) > none.
     *
     * @param  Builder<School>  $query
     */
    private function applyDomain(Builder $query, ?string $domain): void
    {
        if ($domain === null) {
            return;
        }
        $exists = fn (callable $where) => fn ($sub) => $where($sub->selectRaw('1')->from('school_domains')->whereColumn('school_domains.school_id', 'schools.id'));
        $suspended = $exists(fn ($q) => $q->where('state', DomainState::Suspended->value));
        $activePrimary = $exists(fn ($q) => $q->where('state', DomainState::Active->value)->where('is_primary', true));
        $pending = $exists(fn ($q) => $q->whereIn('state', array_map(fn (DomainState $s) => $s->value, self::PENDING_STATES)));

        match ($domain) {
            self::DOMAIN_ATTENTION => $query->whereExists($suspended),
            self::DOMAIN_ACTIVE => $query->whereNotExists($suspended)->whereExists($activePrimary),
            self::DOMAIN_PENDING => $query->whereNotExists($suspended)->whereNotExists($activePrimary)->whereExists($pending),
            default => $query->whereNotExists($suspended)->whereNotExists($activePrimary)->whereNotExists($pending),
        };
    }

    /**
     * @param  list<string>  $schoolIds
     * @return array<string, string>
     */
    private function domainStatuses(array $schoolIds): array
    {
        $statuses = [];
        $rows = SchoolDomain::query()->whereIn('school_id', $schoolIds)
            ->whereIn('state', [DomainState::Suspended->value, DomainState::Active->value, ...array_map(fn (DomainState $s) => $s->value, self::PENDING_STATES)])
            ->get(['school_id', 'state', 'is_primary']);

        foreach ($rows->groupBy('school_id') as $schoolId => $domains) {
            $statuses[(string) $schoolId] = match (true) {
                $domains->contains(fn (SchoolDomain $d) => $d->state === DomainState::Suspended) => self::DOMAIN_ATTENTION,
                $domains->contains(fn (SchoolDomain $d) => $d->state === DomainState::Active && $d->is_primary) => self::DOMAIN_ACTIVE,
                $domains->contains(fn (SchoolDomain $d) => in_array($d->state, self::PENDING_STATES, true)) => self::DOMAIN_PENDING,
                default => self::DOMAIN_NONE,
            };
        }

        return $statuses;
    }

    /** @return array<string, int> domain state => count (states only, never hostnames) */
    private function domainStateCounts(School $school): array
    {
        return SchoolDomain::query()->where('school_id', $school->id)
            ->selectRaw('state, count(*) as total')->groupBy('state')->orderBy('state')
            ->toBase()->get()
            ->mapWithKeys(fn ($row) => [(string) $row->state => (int) $row->total])->all();
    }

    /**
     * @param  list<string>  $schoolIds
     * @return array<string, list<array{id: string, name: string}>>
     */
    private function groups(array $schoolIds): array
    {
        return DB::table('school_group_members')
            ->join('school_groups', 'school_groups.id', '=', 'school_group_members.school_group_id')
            ->whereIn('school_group_members.school_id', $schoolIds)
            ->where('school_groups.status', 'active')
            ->orderBy('school_groups.name')
            ->get(['school_group_members.school_id', 'school_groups.id', 'school_groups.name'])
            ->groupBy('school_id')
            ->map(fn ($rows) => $rows->map(fn ($row): array => ['id' => (string) $row->id, 'name' => (string) $row->name])->values()->all())
            ->all();
    }

    public static function isValidCursor(string $cursor): bool
    {
        try {
            self::decodeCursor($cursor);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    private static function encodeCursor(string $name, string $id): string
    {
        return rtrim(strtr(base64_encode(json_encode([$name, $id], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /** @return array{0: string, 1: string} */
    private static function decodeCursor(string $cursor): array
    {
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        $parts = $decoded === false ? null : json_decode($decoded, true);

        if (! is_array($parts) || count($parts) !== 2 || ! is_string($parts[0] ?? null) || ! is_string($parts[1] ?? null)
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $parts[1]) !== 1) {
            throw new InvalidArgumentException('Invalid School Registry cursor.');
        }

        return [$parts[0], $parts[1]];
    }
}
