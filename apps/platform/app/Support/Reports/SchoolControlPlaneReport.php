<?php

namespace App\Support\Reports;

use App\Domain\Identity\Application\Access\SensitiveDataCatalogue;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Integrations\IntegrationHubReadService;
use App\Support\Navigation\NavigationPresentation;
use App\Support\Presentation\BrandResolver;
use App\Support\Setup\OrganizationSetupCenter;
use App\Support\Setup\SetupStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ORG.12H (ADR 0080 §13.2): the School Control Plane report -- one School,
 * one viewer, read-only, recomputed per request, never stored, cached,
 * exported or audited.
 *
 * Every section comes from its existing owner; nothing here holds a second
 * catalogue or rule:
 * - Setup summary and configuration gaps: OrganizationSetupCenter over the
 *   ORG.12A registry (core vs enabled-module progress kept separate;
 *   NOT_APPLICABLE is never a gap or a failure). Its floor
 *   (`school.profile.view`) is the page floor.
 * - Integrations: IntegrationHubReadService (ORG.12G), only for holders of
 *   its floor, with its own per-family visibility and MFA parity.
 * - Presentation: BrandResolver and NavigationPresentation (ORG.12F): the
 *   effective brand source and whether overrides exist -- never permission
 *   state.
 * - Access administration: counts of system role templates and of active
 *   (unrevoked, active membership, enabled account, staff role; a Campus
 *   grant only on an active Campus) whole-School vs Campus role assignments
 *   in THIS School -- never a
 *   person, never another School -- for `school.roles.view` holders.
 * - Sensitive data: the ORG.12E catalogue's status, stated as it is (no
 *   configurable deny policy).
 *
 * A section the viewer cannot read is null; a section whose source fails is
 * reported unavailable (logged by its key and exception class only) and
 * never breaks the page.
 */
class SchoolControlPlaneReport
{
    public const string SENSITIVE_STATUS = 'Sensitive field catalogue available; configurable deny policy not implemented.';

    public function __construct(
        private readonly OrganizationSetupCenter $setup,
        private readonly IntegrationHubReadService $integrations,
        private readonly BrandResolver $brands,
        private readonly NavigationPresentation $navigation,
        private readonly CapabilityResolver $capabilities,
        private readonly TenantContext $context,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws AuthorizationException as OrganizationSetupReadService::evaluate() (inactive School, no setup floor)
     */
    public function forViewer(School $school, User $actor, bool $assured): array
    {
        $center = $this->setup->forViewer($school, $actor);
        $held = $this->capabilities->schoolCapabilities($actor, $school);
        $reports = OrganizationReportCatalogue::schoolReportsFor($held);
        $may = fn (string $key): bool => in_array($key, $reports, true);

        return [
            'school' => ['name' => $school->name],
            'evaluatedAt' => $center['evaluatedAt'],
            'reports' => $reports,
            'setup' => $this->setupSummary($center),
            'gaps' => $this->gaps($center),
            'integrations' => $may('organization.integration_summary') ? $this->section('organization.integration_summary', fn (): array => $this->integrationSummary($school, $actor, $assured)) : null,
            'presentation' => $may('organization.presentation_summary') ? $this->section('organization.presentation_summary', fn (): array => $this->presentationSummary($school)) : null,
            'access' => $may('organization.access_summary') ? $this->section('organization.access_summary', fn (): array => $this->accessSummary($school)) : null,
        ];
    }

    /**
     * Per category: configured / applicable, with attention, blocked and
     * incomplete counts; the overall core and enabled-module figures are the
     * Center's own (ORG.12B §7 metric definitions).
     *
     * @param  array<string, mixed>  $center
     * @return array<string, mixed>
     */
    private function setupSummary(array $center): array
    {
        $categories = [];
        foreach ($center['categories'] as $category) {
            $count = fn (string $status): int => count(array_filter($category['items'], fn (array $card): bool => $card['status'] === $status));
            $categories[] = [
                'key' => $category['key'],
                'label' => $category['label'],
                'applicable' => count($category['items']),
                'configured' => $count(SetupStatus::CONFIGURED->value),
                'incomplete' => $count(SetupStatus::NOT_CONFIGURED->value) + $count(SetupStatus::PARTIALLY_CONFIGURED->value),
                'attention' => $count(SetupStatus::ATTENTION_REQUIRED->value),
                'blocked' => $count(SetupStatus::BLOCKED->value),
            ];
        }

        return ['overview' => $center['overview'], 'categories' => $categories, 'notEnabled' => array_column($center['notEnabled'], 'title')];
    }

    /**
     * Applicable areas that are not configured, in a fixed order (blocked,
     * attention, partial, not configured; then registry order), each with
     * its owner and the action the Center resolved for this viewer.
     *
     * @param  array<string, mixed>  $center
     * @return list<array<string, mixed>>
     */
    private function gaps(array $center): array
    {
        $rank = [SetupStatus::BLOCKED->value => 0, SetupStatus::ATTENTION_REQUIRED->value => 1, SetupStatus::PARTIALLY_CONFIGURED->value => 2, SetupStatus::NOT_CONFIGURED->value => 3];
        $gaps = [];
        $position = 0;
        foreach ($center['categories'] as $category) {
            foreach ($category['items'] as $card) {
                $position++;
                if (! isset($rank[$card['status']])) {
                    continue;
                }
                $gaps[] = [$rank[$card['status']], $position, [
                    'key' => $card['key'],
                    'title' => $card['title'],
                    'category' => $category['label'],
                    'owner' => $card['owner'],
                    'core' => $card['core'],
                    'status' => $card['status'],
                    'explanation' => $card['explanation'],
                    'prerequisites' => array_column($card['prerequisites'], 'title'),
                    'hiddenPrerequisite' => $card['hiddenPrerequisite'],
                    'action' => $card['action'],
                ]];
            }
        }
        usort($gaps, fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_map(fn (array $gap): array => $gap[2], $gaps);
    }

    /** @return array<string, mixed> */
    private function integrationSummary(School $school, User $actor, bool $assured): array
    {
        $hub = $this->integrations->forViewer($school, $actor, $assured);

        return [
            'counts' => $hub['overview']['counts'],
            'hiddenFamilies' => $hub['overview']['hiddenFamilies'],
            'verificationRequired' => array_column($hub['overview']['verificationRequired'], 'title'),
            'href' => route('app.school-setup.integrations', absolute: false),
        ];
    }

    /** @return array<string, mixed> */
    private function presentationSummary(School $school): array
    {
        $brand = $this->brands->forSchool($school);
        $overrides = $this->navigation->overrides($school);

        return [
            'brandSource' => $brand['source'],
            'networkName' => $brand['network']['name'] ?? null,
            'attention' => $brand['attention'],
            'navigation' => [
                'staff_web' => count($overrides['staff_web'] ?? []),
                'guardian_portal' => count($overrides['guardian_portal'] ?? []),
            ],
            'mediaAvailable' => false,
            'href' => route('app.school-setup.presentation', absolute: false),
        ];
    }

    /** @return array<string, mixed> */
    private function accessSummary(School $school): array
    {
        $templates = Role::query()->where('scope', Role::SCOPE_SCHOOL)->where('is_system', true)->whereNull('retired_at')->count();
        $assignments = $this->inSchool($school, fn () => DB::table('membership_role_assignments as a')
            ->join('school_memberships as m', 'm.id', '=', 'a.school_membership_id')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->leftJoin('campuses as c', fn ($j) => $j->on('c.id', '=', 'a.campus_id')->on('c.school_id', '=', 'a.school_id'))
            ->where('a.school_id', $school->id)->whereNull('a.revoked_at')->where('m.status', 'active')->where('u.is_disabled', false)
            // Staff grants only (rule 25: a Guardian portal grant is never staff), as the Access Center counts.
            ->whereIn('a.role_id', Role::query()->where('scope', Role::SCOPE_SCHOOL)->select('id'))
            ->selectRaw("count(*) filter (where a.campus_id is null) as whole_school, count(*) filter (where a.campus_id is not null and c.status = 'active') as campus")
            ->first());

        return [
            'roleTemplates' => $templates,
            'wholeSchoolAssignments' => (int) ($assignments->whole_school ?? 0),
            'campusAssignments' => (int) ($assignments->campus ?? 0),
            'sensitiveData' => ['families' => count(SensitiveDataCatalogue::FAMILIES), 'status' => self::SENSITIVE_STATUS, 'configurable' => false],
            'href' => route('app.settings.access.index', absolute: false),
        ];
    }

    /**
     * @param  callable(): mixed  $read
     */
    private function inSchool(School $school, callable $read): mixed
    {
        return $this->context->school()?->id === $school->id ? $read() : $this->context->withSchool($school, $read);
    }

    /**
     * @param  callable(): array<string, mixed>  $build
     * @return array<string, mixed>
     */
    private function section(string $key, callable $build): array
    {
        try {
            return ['available' => true, ...$build()];
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Rule 63: the report key and exception class only.
            Log::warning('organization_report.section_failed', ['report' => $key, 'exception' => $e::class]);

            return ['available' => false];
        }
    }
}
