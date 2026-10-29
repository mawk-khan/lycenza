<?php

namespace App\Support\Integrations;

use App\Domain\Platform\Application\Modules\SchoolModuleEntitlements;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Setup\OrganizationSetupRegistry;
use App\Support\Setup\SetupReadinessProviders;
use App\Support\Setup\SetupStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;

/**
 * ORG.12G (ADR 0080 §12): the Integration & Device Readiness Hub's view of
 * ONE School for one viewer. Read-only, recomputed on every request, never
 * cached, stored or audited.
 *
 * - Authority: an ACTIVE School and the Hub floor (`school.settings.view`)
 *   School-wide in it, through an active membership. A platform role, an
 *   elevation, a Group/Network grant or a Campus grant confers neither.
 * - Status, in order: exists (NOT_AVAILABLE, never evaluated) -> entitled
 *   (NOT_APPLICABLE, never evaluated) -> the owning module's provider
 *   (configured / healthy), inside TenantContext::withSchool($school). A
 *   provider that throws is ERROR -- the page never fails because one
 *   family's readiness (or the setup area) could not be read; only the
 *   exception class and family key are logged.
 * - Visibility: an evaluated family is returned only to a viewer holding
 *   ALL its view capabilities; NOT_AVAILABLE and NOT_APPLICABLE families are
 *   product facts every Hub viewer may see. Hidden families are counted,
 *   never named. A family whose owning page needs current MFA assurance
 *   (`sensitiveRead`) is evaluated only when the request holds it; otherwise
 *   it is listed by title as needing verification, without a status.
 * - Links are resolved here, from the destination page's own capabilities
 *   (`open`/`manage`); the destination stays authoritative.
 * - The ORG.12A `integrations.integrations` setup area is reported from its
 *   one existing provider -- no parallel setup state.
 * - Nothing is called outside the database: no provider, device, endpoint,
 *   DNS or HTTP request on page load, and no "test connection".
 */
class IntegrationHubReadService
{
    public const string FLOOR_CAPABILITY = 'school.settings.view';

    public const string SETUP_AREA = 'integrations.integrations';

    public const string ERROR_EXPLANATION = 'This status could not be read right now. Try again later.';

    public function __construct(
        private readonly OrganizationIntegrationCatalogue $catalogue,
        private readonly IntegrationReadinessProviders $providers,
        private readonly OrganizationSetupRegistry $setupRegistry,
        private readonly SetupReadinessProviders $setupProviders,
        private readonly CapabilityResolver $capabilities,
        private readonly SchoolModuleEntitlements $entitlements,
        private readonly TenantContext $context,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws AuthorizationException when the School is not active or the viewer lacks the Hub floor in it
     */
    public function forViewer(School $school, User $actor, bool $assured = false): array
    {
        if (! $school->isActive()) {
            throw new AuthorizationException('This action is unauthorized.');
        }
        $held = $this->capabilities->schoolCapabilities($actor, $school);
        if (! in_array(self::FLOOR_CAPABILITY, $held, true)) {
            throw new AuthorizationException('This action is unauthorized.');
        }
        $entitled = $this->entitlements->entitledModules($school);

        $verify = [];
        [$families, $setup] = $this->context->withSchool($school, function () use ($school, $held, $entitled, $assured, &$verify): array {
            return [$this->evaluateFamilies($school, $held, $entitled, $assured, $verify), $this->setupArea($school, $held, $entitled)];
        });

        $visible = array_values(array_filter($families));
        $sections = [];
        foreach (IntegrationSection::cases() as $section) {
            $items = array_values(array_filter($visible, fn (array $family): bool => $family['section'] === $section->value));
            if ($items !== []) {
                $sections[] = ['key' => $section->value, 'label' => $section->label(), 'items' => $items];
            }
        }
        $counts = [];
        foreach (IntegrationStatus::cases() as $status) {
            $counts[$status->value] = count(array_filter($visible, fn (array $family): bool => $family['status'] === $status->value));
        }

        return [
            'school' => ['name' => $school->name],
            'evaluatedAt' => now()->toIso8601String(),
            'overview' => [
                'counts' => $counts,
                'hiddenFamilies' => count($families) - count($visible) - count($verify),
                'verificationRequired' => $verify,
                'setupArea' => $setup,
            ],
            'sections' => $sections,
        ];
    }

    /**
     * @param  list<string>  $held
     * @param  list<string>  $entitled
     * @param  list<array{key: string, title: string, href: ?string}>  $verify  filled with families awaiting MFA assurance
     * @return list<array<string, mixed>|null> one entry per catalogue family; null = not shown with a status
     */
    private function evaluateFamilies(School $school, array $held, array $entitled, bool $assured, array &$verify): array
    {
        $families = [];
        foreach ($this->catalogue->all() as $definition) {
            if (! $definition->available) {
                $families[] = $this->card($definition, IntegrationStatus::NOT_AVAILABLE, (string) $definition->gate, $held);

                continue;
            }
            if (array_diff($definition->entitlementModules, $entitled) !== []) {
                $families[] = $this->card($definition, IntegrationStatus::NOT_APPLICABLE, 'This optional module is not enabled for the School.', $held);

                continue;
            }
            if (array_diff($definition->viewCapabilities, $held) !== []) {
                $families[] = null;

                continue;
            }
            if ($definition->sensitiveRead && ! $assured) {
                $families[] = null;
                $verify[] = ['key' => $definition->key, 'title' => $definition->title, 'href' => $definition->route === null ? null : route($definition->route, absolute: false)];

                continue;
            }
            try {
                $readiness = $this->providers->for($definition->key)->evaluate($definition->key, $school);
                $families[] = $this->card($definition, $readiness->status, $readiness->explanation, $held);
            } catch (\Throwable $e) {
                self::logFailure($definition->key, $e);
                $families[] = $this->card($definition, IntegrationStatus::ERROR, self::ERROR_EXPLANATION, $held);
            }
        }

        return $families;
    }

    /**
     * @param  list<string>  $held
     * @return array<string, mixed>
     */
    private function card(IntegrationDefinition $definition, IntegrationStatus $status, string $explanation, array $held): array
    {
        $evaluated = ! in_array($status, [IntegrationStatus::NOT_AVAILABLE, IntegrationStatus::NOT_APPLICABLE], true);
        $canOpen = $definition->route !== null && array_intersect($definition->openCapabilities, $held) !== [];
        $canManage = $definition->manageCapabilities !== [] && array_diff($definition->manageCapabilities, $held) === [];
        $kind = match (true) {
            ! $evaluated || ! $canOpen => 'none',
            $canManage => 'configure',
            default => 'view',
        };

        return [
            'key' => $definition->key,
            'title' => $definition->title,
            'description' => $definition->description,
            'section' => $definition->section->value,
            'owner' => $definition->owner,
            'managedBy' => $definition->managedBy,
            'status' => $status->value,
            'explanation' => $explanation,
            'action' => ['kind' => $kind, 'href' => $kind === 'none' ? null : route((string) $definition->route, absolute: false)],
        ];
    }

    /**
     * The ORG.12A setup area, from its one provider: NOT_APPLICABLE while its
     * module is not entitled, otherwise only for a viewer who may see it
     * there (all its view capabilities).
     *
     * @param  list<string>  $held
     * @param  list<string>  $entitled
     * @return array<string, mixed>|null
     */
    private function setupArea(School $school, array $held, array $entitled): ?array
    {
        $definition = $this->setupRegistry->find(self::SETUP_AREA);
        if ($definition === null) {
            return null;
        }
        // As in ORG.12A: NOT_APPLICABLE is shown to every viewer; an
        // applicable area only to a viewer holding all its view capabilities.
        if (array_diff($definition->entitlementModules, $entitled) !== []) {
            $status = SetupStatus::NOT_APPLICABLE;
            $explanation = 'This optional module is not enabled for the School.';
        } elseif (array_diff($definition->viewCapabilities, $held) !== []) {
            return null;
        } else {
            try {
                $readiness = $this->setupProviders->for(self::SETUP_AREA)->evaluate(self::SETUP_AREA, $school);
            } catch (\Throwable $e) {
                self::logFailure(self::SETUP_AREA, $e);

                return ['title' => $definition->title, 'status' => null, 'explanation' => self::ERROR_EXPLANATION, 'href' => null];
            }
            $status = $readiness->status;
            $explanation = $readiness->explanation;
        }

        return [
            'title' => $definition->title,
            'status' => $status->value,
            'explanation' => $explanation,
            'href' => in_array('school.profile.view', $held, true) ? route('app.school-setup.readiness', absolute: false) : null,
        ];
    }

    /**
     * Rule 63: the family key and exception class only -- never the message,
     * which may one day carry configuration or payload text.
     */
    private static function logFailure(string $key, \Throwable $e): void
    {
        Log::warning('integration_hub.readiness_failed', ['family' => $key, 'exception' => $e::class]);
    }
}
