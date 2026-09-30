<?php

namespace Tests\Feature\Platform\Schools;

use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0N.9 structural guards for ADR 0047. Behaviour is proven in
 * SchoolLifecycleTest, SchoolSuspensionEnforcementTest,
 * SchoolLifecycleConcurrencyTest and
 * Postgres\SchoolLifecycleDatabaseInvariantsTest; these fail on the shape
 * of a change that would quietly widen what the platform may do to a
 * School, or add a School business path that ignores suspension.
 */
class SchoolLifecycleArchitectureGuardTest extends TestCase
{
    /** Queued jobs that perform no School business effect of their own. */
    private const JOBS_WITHOUT_OWN_EFFECT = [
        'ProcessOutboxEventJob.php',     // routes to consumers, which gate their own effects
        'RecordSchoolAuditPingJob.php',  // Phase 0B demo: writes audit evidence only
        'WorkerCanaryJob.php',           // Phase 0O.5A: no-op worker-class heartbeat, no School
        'ApplyEmailEventJob.php',        // Phase 0O.9A: records provider evidence and suppression; never sends
        'IssueAccountRecoveryJob.php',   // Phase 0O.10A: identity-level (ADR 0056), no School; its email is submitted by SubmitEmailMessageJob
    ];

    /** Business jobs and where their execution-time lifecycle check lives. */
    private const BUSINESS_JOBS = [
        'DeliverWebhookJob.php' => 'app/Jobs/DeliverWebhookJob.php',
        'ProcessCommunicationDeliveryJob.php' => 'app/Jobs/ProcessCommunicationDeliveryJob.php',
        'RunAutomationExecutionJob.php' => 'app/Domain/Automation/Application/AutomationExecutionService.php',
        // Phase 0O.8A: a non-active School's domain checks are skipped (ADR 0054 section 7.1).
        'CheckSchoolDomainJob.php' => 'app/Domain/Platform/Application/Domains/SchoolDomainCheckService.php',
        // Phase 0O.9A: a non-active School's email waits (ADR 0055 section 10).
        'SubmitEmailMessageJob.php' => 'app/Support/Email/EmailSubmissionService.php',
        // FEE.2: a non-operational School PAUSES an assessment run; no item changes (ADR 0062 section 13).
        'ExecuteFeeAssessmentRunJob.php' => 'app/Domain/Fees/Application/FeeAssessmentItemExecutor.php',
    ];

    /** Commands that walk every School and why they may include non-active ones. */
    private const SCHOOL_WALKERS_INCLUDING_NON_ACTIVE = [
        'RedispatchDueAutomationExecutions.php', // an execution of a suspended School becomes terminal `skipped`
        'PruneWebhookDeliveries.php',            // retention maintenance
        'PruneIdempotencyRecords.php',           // retention maintenance
        'RedispatchDueEmailMessages.php',        // expiry purges sealed email content on time; submits only for active Schools
        'PruneEmailRecords.php',                 // retention maintenance
        'RetryMailMessage.php',                  // one named School; the submission claim re-checks the lifecycle
        'RekeyMailSuppressions.php',             // reads a stored recipient to re-key suppression; no School effect
        'SendFakeEmailEvent.php',                // local/testing only; one named School
        'ProvisionSchoolAdminAccount.php',       // one named School, which must be `provisioning` (ADR 0059 section 5)
        'PruneStaffAccountCredentials.php',      // technical credential cleanup (ADR 0059 section 21)
        'BackfillPaymentReceipts.php',           // one named School; refused unless active, and each receipt re-checks the lifecycle (ADR 0062 I2)
    ];

    #[Test]
    public function only_the_lifecycle_service_creates_a_school_and_nothing_deletes_one(): void
    {
        foreach ($this->phpFiles(app_path()) as $file) {
            $source = (string) file_get_contents($file);
            $relative = Str::after($file, base_path().'/');

            if ($relative !== 'app/Domain/Platform/Application/Schools/SchoolLifecycleService.php') {
                foreach (['School::query()->create(', 'School::create(', 'School::forceCreate(', "table('schools')->insert"] as $creation) {
                    $this->assertStringNotContainsString($creation, $source, "{$relative} must not create a School");
                }
            }

            foreach (['$school->delete()', "table('schools')->delete", 'School::query()->whereKey($school->id)->delete', 'School::destroy('] as $deletion) {
                $this->assertStringNotContainsString($deletion, $source, "{$relative} must not delete a School");
            }
        }
    }

    #[Test]
    public function the_bootstrap_service_is_the_only_platform_code_that_writes_school_memberships(): void
    {
        foreach ($this->phpFiles(app_path('Domain/Platform')) as $file) {
            $source = (string) file_get_contents($file);

            if (Str::endsWith($file, 'Schools/SchoolBootstrapAdministrationService.php')) {
                continue;
            }

            $this->assertStringNotContainsString('MembershipRoleAssignment', $source, "{$file} must not touch School role assignments");
            $this->assertStringNotContainsString('SchoolMembership::query()->create', $source, "{$file} must not create School memberships");

            // Everything else in the Platform domain may only READ
            // memberships (elevation's member check, activation's
            // administrator check) -- and only these two do.
            if (str_contains($source, 'SchoolMembership')) {
                $this->assertContains(basename($file), ['SchoolElevationService.php', 'SchoolLifecycleAuthority.php'], "{$file} must not use School memberships");
            }
        }

        $authority = (string) file_get_contents(app_path('Domain/Platform/Application/Schools/SchoolLifecycleAuthority.php'));
        $this->assertStringNotContainsString("'school_admin'", $authority, 'Activation qualifies administrators by capability, never by role name.');
        $this->assertStringNotContainsString("'platform_super_admin'", $authority);
    }

    #[Test]
    public function lifecycle_evidence_goes_to_the_platform_ledger_only(): void
    {
        foreach ($this->phpFiles(app_path('Domain/Platform/Application/Schools')) as $file) {
            $this->assertStringNotContainsString('->school(', (string) file_get_contents($file), "{$file} must not write the School audit ledger");
        }
    }

    #[Test]
    public function every_school_business_job_checks_the_lifecycle_at_execution_time(): void
    {
        foreach (glob(app_path('Jobs/*.php')) as $file) {
            $name = basename($file);

            if (in_array($name, self::JOBS_WITHOUT_OWN_EFFECT, true)) {
                continue;
            }

            $this->assertArrayHasKey($name, self::BUSINESS_JOBS, "{$name} is a new queued job: decide whether it is a School business effect (then it must check SchoolOperationalGuard at execution time) and list it here.");
            $this->assertStringContainsString('SchoolOperationalGuard', (string) file_get_contents(base_path(self::BUSINESS_JOBS[$name])), "{$name}'s effect must re-check the School lifecycle when it runs");
        }

        // No blanket refusal in the shared job middleware: safety work
        // (audit, elevation expiry, pruning) must keep running.
        $middleware = (string) file_get_contents(app_path('Support/Tenancy/SetTenantContextForJob.php'));
        $this->assertStringNotContainsString('isActive', $middleware);
        $this->assertStringNotContainsString('SchoolOperationalGuard', $middleware);
    }

    #[Test]
    public function every_command_walking_schools_skips_non_active_ones_or_says_why_not(): void
    {
        foreach (glob(app_path('Console/Commands/*.php')) as $file) {
            $source = (string) file_get_contents($file);

            if (! str_contains($source, 'School::query()')) {
                continue;
            }

            if (in_array(basename($file), self::SCHOOL_WALKERS_INCLUDING_NON_ACTIVE, true)) {
                continue;
            }

            $this->assertStringContainsString("School::query()->where('status', SchoolStatus::Active->value)", $source, basename($file).' walks Schools: skip non-active ones or allowlist it with a reason');
        }
    }

    #[Test]
    public function outbox_consumers_and_other_business_boundaries_gate_non_active_schools(): void
    {
        $gated = [
            'app/Support/Events/Consumers/NotifyActorOfSettingChangeConsumer.php' => '$school->isActive()',
            'app/Domain/Automation/Application/AutomationTriggerConsumer.php' => 'SchoolOperationalGuard',
            'app/Domain/Communications/Application/CommunicationDeliveryFactory.php' => 'SchoolOperationalGuard',
            'app/Domain/Identity/Application/GuardianAccountActivationService.php' => 'SchoolOperationalGuard',
            'app/Support/Ai/AiGatewayClient.php' => 'isOperational',
            'app/Http/Controllers/Api/Internal/AiToolController.php' => '$school->isActive()',
            'app/Http/Controllers/Api/Internal/AiCompletionAuthorizationController.php' => '$school->isActive()',
        ];

        foreach ($gated as $path => $needle) {
            $this->assertStringContainsString($needle, (string) file_get_contents(base_path($path)), "{$path} must refuse a non-active School");
        }

        // The fanout records delivery rows (evidence) and lets the delivery
        // job defer them; it deliberately has no gate of its own.
        $this->assertStringNotContainsString('SchoolOperationalGuard', (string) file_get_contents(app_path('Support/Events/Consumers/WebhookFanoutConsumer.php')));
    }

    #[Test]
    public function there_is_no_archive_or_delete_route_and_the_surface_is_web_only_and_context_neutral(): void
    {
        $routes = app(Router::class)->getRoutes();

        foreach ($routes->getRoutes() as $route) {
            $uri = $route->uri();

            if (Str::startsWith($uri, 'app/platform/schools')) {
                $this->assertNotContains('DELETE', $route->methods(), $uri);
                $this->assertStringNotContainsString('archive', $uri.json_encode($route->wheres));
                $this->assertStringNotContainsString('delete', $uri.json_encode($route->wheres));

                foreach ($route->gatherMiddleware() as $middleware) {
                    $this->assertFalse(is_string($middleware) && str_starts_with($middleware, 'school-context'), $uri);
                }
            }

            if (Str::startsWith($uri, 'api/')) {
                $this->assertStringNotContainsString('PlatformSchoolAdmin', (string) $route->getActionName());
            }
        }

        $this->assertContains('capability:platform.schools.manage,platform', $routes->getByName('app.platform.schools.index')->gatherMiddleware());
        $this->assertContains('throttle:platform-school-lifecycle', $routes->getByName('app.platform.schools.perform')->gatherMiddleware());
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $dir): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
