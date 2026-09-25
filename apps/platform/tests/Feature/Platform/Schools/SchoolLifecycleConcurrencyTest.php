<?php

namespace Tests\Feature\Platform\Schools;

use App\Domain\Automation\Infrastructure\AutomationExecution;
use App\Domain\Automation\Infrastructure\AutomationRuleInstance;
use App\Domain\Communications\Infrastructure\CommunicationDelivery;
use App\Models\School;
use App\Models\SchoolElevation;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * Phase 0N.9 (ADR 0047 sections 7-8): lifecycle races between two real OS
 * processes against real PostgreSQL. The holder's work stays uncommitted
 * and the contender is positively observed blocked on its lock before the
 * holder commits (ForcesConcurrentOverlap) -- never slept.
 *
 * The invariant: the School row is the linearization point. A lifecycle
 * change locks it FOR UPDATE; every business effect's claim reads it FOR
 * SHARE in its own transaction. Once a suspension commits, no later claim
 * proceeds on a stale "active" -- and a claim that committed first is
 * in-flight work the suspension waited for.
 */
class SchoolLifecycleConcurrencyTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures, ForcesConcurrentOverlap, SchoolLifecycleTestHelpers;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    /** @var list<string> */
    private array $userIds = [];

    /** @var list<string> */
    private array $schoolIds = [];

    protected function tearDown(): void
    {
        $admin = DB::connection('pgsql_admin');
        $admin->table('school_elevations')->whereIn('school_id', $this->schoolIds)->orWhereIn('actor_user_id', $this->userIds)->delete();
        $admin->table('platform_audit_events')->whereIn('actor_user_id', $this->userIds)->orWhereIn('subject_id', $this->schoolIds)->delete();
        $admin->table('schools')->whereIn('id', $this->schoolIds)->delete();
        $admin->table('platform_role_assignments')->whereIn('user_id', $this->userIds)->delete();
        $admin->table('user_mfa_recovery_codes')->whereIn('user_id', $this->userIds)->delete();
        $admin->table('user_mfa_factors')->whereIn('user_id', $this->userIds)->delete();
        $admin->table('users')->whereIn('id', $this->userIds)->delete();

        parent::tearDown();
    }

    private function track(User|School ...$models): void
    {
        foreach ($models as $model) {
            $model instanceof School ? $this->schoolIds[] = $model->id : $this->userIds[] = $model->id;
        }
    }

    /** A root with one single-use recovery code for the child process. */
    private function root(): array
    {
        $root = $this->platformAdmin();
        $this->track($root);

        return [$root, $this->issueRecoveryCodes($root, 1)[0]];
    }

    private function provisioningSchoolWithAdmin(): School
    {
        $school = $this->createSchool(['status' => 'provisioning']);
        $admin = $this->createUser();
        $this->track($school, $admin);
        $this->assignSchoolRole($this->createMembership($admin, $school), 'school_admin');

        return $school;
    }

    private function activeSchool(): School
    {
        $school = $this->createSchool();
        $this->track($school);

        return $school;
    }

    private function script(string ...$args): array
    {
        return ['php', __DIR__.'/../../../Support/school-lifecycle-op.php', ...$args];
    }

    #[Test]
    public function two_simultaneous_activations_activate_once(): void
    {
        $school = $this->provisioningSchoolWithAdmin();
        [$rootA, $codeA] = $this->root();
        [$rootB, $codeB] = $this->root();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('activate', $rootA->id, $school->id, $codeA),
            $this->script('activate', $rootB->id, $school->id, $codeB),
        );

        $this->assertSame('activated', $holder);
        $this->assertSame('rejected:invalid_transition', $contender);
        $this->assertSame('active', $school->fresh()->status);
        $this->assertSame(1, DB::table('platform_audit_events')->where('subject_id', $school->id)->where('event_type', 'platform.school.activated')->count());
    }

    #[Test]
    public function a_bootstrap_replacement_racing_the_activation_finds_the_path_closed(): void
    {
        $school = $this->provisioningSchoolWithAdmin();
        $replacement = $this->createUser();
        $this->track($replacement);
        [$rootA, $codeA] = $this->root();
        [$rootB, $codeB] = $this->root();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('activate', $rootA->id, $school->id, $codeA),
            $this->script('replace', $rootB->id, $school->id, $replacement->email, $codeB),
        );

        $this->assertSame('activated', $holder);
        $this->assertSame('rejected:bootstrap_closed', $contender);
        $this->assertSame(0, SchoolMembership::query()->where('school_id', $school->id)->where('user_id', $replacement->id)->count());
    }

    #[Test]
    public function an_elevation_start_waiting_on_a_suspension_is_refused(): void
    {
        $school = $this->activeSchool();
        [$rootA, $codeA] = $this->root();
        [$rootB, $codeB] = $this->root();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('suspend', $rootA->id, $school->id, $codeA),
            $this->script('elevate', $rootB->id, $school->id, $codeB),
        );

        $this->assertSame('suspended', $holder);
        $this->assertSame('rejected:target_inactive', $contender);
        $this->assertSame(0, SchoolElevation::query()->where('school_id', $school->id)->count());
    }

    #[Test]
    public function a_suspension_waiting_on_an_elevation_start_terminates_it(): void
    {
        $school = $this->activeSchool();
        [$rootA, $codeA] = $this->root();
        [$rootB, $codeB] = $this->root();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('elevate', $rootB->id, $school->id, $codeB),
            $this->script('suspend', $rootA->id, $school->id, $codeA),
        );

        $this->assertSame('started', $holder);
        $this->assertSame('suspended', $contender);
        $elevation = SchoolElevation::query()->where('school_id', $school->id)->sole();
        $this->assertSame('terminated', $elevation->status);
        $this->assertSame('school_suspended', $elevation->end_reason);
    }

    #[Test]
    public function a_communication_claim_waiting_on_a_suspension_defers_and_sends_nothing(): void
    {
        [$sender, $school] = $this->createSchoolAdmin('school_admin');
        $this->track($sender, $school);
        $message = $this->createMessage($this->createThread($school, $sender), $sender);
        $delivery = $this->createDelivery($this->createRecipient($message, $sender));
        [$root, $code] = $this->root();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('suspend', $root->id, $school->id, $code),
            $this->script('deliver-communication', $school->id, $delivery->id),
        );

        $this->assertSame('suspended', $holder);
        $this->assertSame('delivery:queued', $contender);
        $held = app(TenantContext::class)->withSchool($school, fn () => CommunicationDelivery::query()->findOrFail($delivery->id));
        $this->assertSame(0, $held->attempts);
    }

    #[Test]
    public function a_webhook_claim_waiting_on_a_suspension_defers_and_makes_no_http_call(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->track($user, $school);
        $delivery = app(TenantContext::class)->withSchool($school, function () use ($school) {
            $endpoint = WebhookEndpoint::query()->create([
                'school_id' => $school->id, 'name' => 'race', 'url' => 'https://8.8.8.8/hook', 'secret_encrypted' => 'secret', 'status' => 'active',
            ]);
            $eventId = (string) Str::uuid7();
            DB::table('domain_event_outbox')->insert([
                'id' => $eventId, 'school_id' => $school->id, 'event_type' => 'school.setting.changed.v1', 'event_version' => 1,
                'correlation_id' => (string) Str::uuid7(), 'payload' => json_encode(['key' => 'x']), 'occurred_at' => now(), 'available_at' => now(),
                'status' => 'dispatched', 'dispatched_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);

            return WebhookDelivery::query()->create([
                'school_id' => $school->id, 'webhook_endpoint_id' => $endpoint->id, 'event_id' => $eventId,
                'event_type' => 'school.setting.changed.v1', 'status' => 'pending',
            ]);
        });
        [$root, $code] = $this->root();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('suspend', $root->id, $school->id, $code),
            $this->script('deliver-webhook', $school->id, $delivery->id),
        );

        $this->assertSame('suspended', $holder);
        $this->assertSame('delivery:retrying', $contender);
        $this->assertSame(0, DB::table('webhook_delivery_attempts')->where('webhook_delivery_id', $delivery->id)->count(), 'No HTTP attempt was made.');
    }

    #[Test]
    public function an_automation_run_waiting_on_a_suspension_is_skipped(): void
    {
        $school = $this->activeSchool();
        $execution = app(TenantContext::class)->withSchool($school, function () use ($school) {
            $instance = AutomationRuleInstance::query()->create(['school_id' => $school->id, 'rule_type' => 'race.test.rule', 'status' => 'disabled']);

            return AutomationExecution::query()->create([
                'school_id' => $school->id, 'rule_instance_id' => $instance->id, 'trigger_key' => (string) Str::uuid(),
                'trigger_event_type' => 'race.event', 'subject_type' => 'academic_year', 'subject_id' => (string) Str::uuid(),
                'status' => AutomationExecution::STATUS_PENDING,
            ]);
        });
        [$root, $code] = $this->root();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('suspend', $root->id, $school->id, $code),
            $this->script('run-automation', $school->id, $execution->id),
        );

        $this->assertSame('suspended', $holder);
        $this->assertSame('ran', $contender);
        $fresh = app(TenantContext::class)->withSchool($school, fn () => $execution->fresh());
        $this->assertSame('skipped', $fresh->status);
        $this->assertSame('school_suspended', $fresh->outcome_code);
    }

    #[Test]
    public function a_suspension_waits_for_an_in_flight_business_claim(): void
    {
        $school = $this->activeSchool();
        [$root, $code] = $this->root();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('hold-operational', $school->id),
            $this->script('suspend', $root->id, $school->id, $code),
        );

        $this->assertSame('held:active', $holder, 'The claim saw an active School and committed first.');
        $this->assertSame('suspended', $contender, 'The suspension waited for it, then committed.');
        $this->assertSame('suspended', $school->fresh()->status);
    }
}
