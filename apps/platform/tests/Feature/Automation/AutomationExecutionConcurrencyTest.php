<?php

namespace Tests\Feature\Automation;

use App\Domain\Automation\Application\AutomationFeatureGate;
use App\Domain\Automation\Application\AutomationRuleService;
use App\Domain\Automation\Application\Catalog\AcademicYearSetupReviewRule;
use App\Domain\Automation\Infrastructure\AutomationExecution;
use App\Domain\Automation\Infrastructure\AutomationExecutionAttempt;
use App\Domain\Automation\Infrastructure\AutomationReviewItem;
use App\Models\FeatureFlagSchoolOverride;
use App\Models\School;
use App\Support\FeatureFlags\FeatureFlagResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * Phase 0L.6 -- two real OS processes run the SAME execution at the same
 * time (a duplicate dispatch racing the redispatch command, or two
 * workers). Overlap is forced and verified (ForcesConcurrentOverlap): the
 * holder's claim stays uncommitted and the contender is observed blocked
 * on it. Exactly one process acts; one review item and one attempt exist.
 * Commits for real, so it cleans up its School afterwards.
 */
class AutomationExecutionConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            try {
                $this->deleteSchoolAsAdmin($this->school);
            } catch (\Throwable) {
                // Best-effort only, like the other real-process concurrency tests.
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function two_real_processes_running_one_execution_produce_exactly_one_effect(): void
    {
        $this->school = $this->createSchool();
        $school = $this->school;
        $owner = $this->createUser();
        $this->assignSchoolRole($this->createMembership($owner, $school), 'school_admin');
        $context = app(TenantContext::class);

        $context->withSchool($school, fn () => FeatureFlagSchoolOverride::query()->create([
            'school_id' => $school->id, 'feature_flag_key' => AutomationFeatureGate::FLAG, 'enabled' => true,
        ]));
        app(FeatureFlagResolver::class)->forgetCache(AutomationFeatureGate::FLAG, $school);
        $instance = app(AutomationRuleService::class)->enable($school, AcademicYearSetupReviewRule::KEY, $owner);

        $execution = $context->withSchool($school, fn () => AutomationExecution::query()->create([
            'school_id' => $school->id, 'rule_instance_id' => $instance->id, 'trigger_key' => (string) Str::uuid7(),
            'trigger_event_type' => 'academic_year.activated.v1', 'subject_type' => 'academic_year',
            'subject_id' => (string) Str::uuid7(), 'status' => AutomationExecution::STATUS_PENDING,
        ]));

        $script = __DIR__.'/../../Support/run-automation-execution.php';
        $outputs = $this->raceWithHeldHolder(
            ['php', $script, $school->id, $execution->id],
            ['php', $script, $school->id, $execution->id],
        );

        $this->assertSame(['acted', 'noop'], $outputs, 'The held process acts; the blocked one loses its claim.');

        $items = $context->withSchool($school, fn () => AutomationReviewItem::query()->where('execution_id', $execution->id)->count());
        $attempts = $context->withSchool($school, fn () => AutomationExecutionAttempt::query()->where('execution_id', $execution->id)->count());
        $fresh = $context->withSchool($school, fn () => AutomationExecution::query()->findOrFail($execution->id));

        $this->assertSame(1, $items);
        $this->assertSame(1, $attempts);
        $this->assertSame(AutomationExecution::STATUS_SUCCEEDED, $fresh->status);
        $this->assertSame(1, $fresh->attempts);
    }
}
