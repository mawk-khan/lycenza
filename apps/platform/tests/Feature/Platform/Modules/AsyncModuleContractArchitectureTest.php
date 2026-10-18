<?php

namespace Tests\Feature\Platform\Modules;

use App\Domain\Automation\Application\AutomationTriggerConsumer;
use App\Domain\Platform\Application\Modules\ExecutesModuleWork;
use App\Domain\Platform\Application\Modules\ModuleCatalogue;
use App\Jobs\ApplyEmailEventJob;
use App\Jobs\CheckSchoolDomainJob;
use App\Jobs\DeliverWebhookJob;
use App\Jobs\ExecuteFeeAssessmentRunJob;
use App\Jobs\ExecuteLateFeeRunJob;
use App\Jobs\IssueAccountRecoveryJob;
use App\Jobs\ProcessOutboxEventJob;
use App\Jobs\RecordSchoolAuditPingJob;
use App\Jobs\RunAutomationExecutionJob;
use App\Jobs\SubmitEmailMessageJob;
use App\Jobs\WorkerCanaryJob;
use App\Support\Events\Consumers\NotifyActorOfSettingChangeConsumer;
use App\Support\Events\EventConsumer;
use App\Support\Events\EventConsumerRegistry;
use Illuminate\Contracts\Queue\ShouldQueue;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * ORG.5 (ADR 0076 §12.7(3), §31; CLAUDE.md rule 100): every queued job and
 * every outbox consumer either declares the optional module whose effect it
 * performs (ExecutesModuleWork) and checks ModuleExecutionGuard at its
 * execution point, or is explicitly exempt as core/infrastructure work. A
 * new one fails here until it makes that decision.
 */
class AsyncModuleContractArchitectureTest extends TestCase
{
    /**
     * Core or infrastructure work, with the reason it is not module work.
     *
     * @var array<class-string, string>
     */
    private const EXEMPT = [
        ApplyEmailEventJob::class => 'email-layer infrastructure (provider events); no module effect',
        CheckSchoolDomainJob::class => 'core Organization: custom-domain verification',
        IssueAccountRecoveryJob::class => 'identity-level account recovery, no School',
        ProcessOutboxEventJob::class => 'outbox substrate; each consumer it calls decides for itself',
        RecordSchoolAuditPingJob::class => 'Phase 0B tenancy primitive; core audit',
        SubmitEmailMessageJob::class => 'email-layer infrastructure; the producing module already decided',
        WorkerCanaryJob::class => 'operations canary; no School data',
        NotifyActorOfSettingChangeConsumer::class => 'core Organization: School setting change notice',
    ];

    /**
     * Where each declaring class's module check runs, when not in itself.
     *
     * @var array<class-string, list<string>>
     */
    private const EXECUTION_POINTS = [
        RunAutomationExecutionJob::class => ['app/Domain/Automation/Application/AutomationExecutionService.php'],
        ExecuteFeeAssessmentRunJob::class => ['app/Domain/Fees/Application/FeeAssessmentItemExecutor.php'],
        ExecuteLateFeeRunJob::class => ['app/Domain/Payments/Application/LateFeeItemExecutor.php'],
    ];

    /** @return list<class-string> */
    private function queuedJobs(): array
    {
        $classes = [];
        foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
            if (! preg_match('/^namespace\s+([^;]+);.*?^(?:final\s+|abstract\s+)?class\s+(\w+)/sm', $file->getContents(), $m)) {
                continue;
            }
            $class = $m[1].'\\'.$m[2];
            if (class_exists($class) && is_subclass_of($class, ShouldQueue::class) && ! (new ReflectionClass($class))->isAbstract()) {
                $classes[] = $class;
            }
        }
        sort($classes);

        return $classes;
    }

    /** @return list<class-string> */
    private function registeredConsumers(): array
    {
        $registry = app(EventConsumerRegistry::class);
        $property = (new ReflectionClass($registry))->getProperty('consumers');

        return array_map(fn (EventConsumer $c): string => $c::class, $property->getValue($registry));
    }

    #[Test]
    public function every_queued_job_and_consumer_declares_its_module_or_is_exempt(): void
    {
        $subjects = [...$this->queuedJobs(), ...$this->registeredConsumers()];
        $this->assertNotEmpty($subjects);
        $this->assertContains(DeliverWebhookJob::class, $subjects);
        $this->assertContains(AutomationTriggerConsumer::class, $subjects);

        foreach ($subjects as $class) {
            $declares = is_subclass_of($class, ExecutesModuleWork::class);
            $exempt = array_key_exists($class, self::EXEMPT);

            $this->assertTrue($declares xor $exempt, "{$class} must either implement ExecutesModuleWork or be listed as exempt (not both).");

            if (! $declares) {
                continue;
            }

            $module = $class::executesModule();
            $this->assertTrue(ModuleCatalogue::exists($module) && ! ModuleCatalogue::isCore($module), "{$class} declares {$module}, which is not an optional module.");

            $files = self::EXECUTION_POINTS[$class] ?? [str_replace(base_path().'/', '', (string) (new ReflectionClass($class))->getFileName())];
            $guarded = false;
            foreach ($files as $file) {
                $guarded = $guarded || str_contains((string) file_get_contents(base_path($file)), 'ModuleExecutionGuard::class)->allows(');
            }
            $this->assertTrue($guarded, "{$class}'s execution point must check ModuleExecutionGuard.");
        }
    }

    #[Test]
    public function the_catalogue_names_exactly_the_modules_that_declare_background_work(): void
    {
        $declared = [];
        foreach ([...$this->queuedJobs(), ...$this->registeredConsumers()] as $class) {
            if (is_subclass_of($class, ExecutesModuleWork::class)) {
                $declared[] = $class::executesModule();
            }
        }
        $declared = array_values(array_unique($declared));
        sort($declared);

        $this->assertSame(ModuleCatalogue::ASYNC_MODULES, $declared);
    }

    #[Test]
    public function every_exemption_still_names_a_real_queued_job_or_consumer(): void
    {
        $subjects = [...$this->queuedJobs(), ...$this->registeredConsumers()];

        foreach (array_keys(self::EXEMPT) as $class) {
            $this->assertContains($class, $subjects, "Stale exemption {$class}");
        }
        foreach (array_keys(self::EXECUTION_POINTS) as $class) {
            $this->assertContains($class, $subjects, "Stale execution point {$class}");
        }
    }
}
