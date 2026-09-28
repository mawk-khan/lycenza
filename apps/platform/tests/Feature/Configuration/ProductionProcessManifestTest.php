<?php

namespace Tests\Feature\Configuration;

use App\Console\Commands\RecoverQueuedWork;
use App\Support\Observability\QueueName;
use Illuminate\Console\Scheduling\Schedule;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Phase 0O.4A (ADR 0050 sections 1, 3, 4): the provider-neutral production
 * process manifest (deploy/processes.json) and the image entrypoint agree
 * with what the code actually needs.
 *
 * - Every queue the application dispatches to has a worker (no job can be
 *   stranded on an unworked queue), and no worker names an unused queue.
 * - Exactly one scheduler, marked singleton.
 * - Long-running roles never receive the database admin credentials; only
 *   the release step and the operator console do.
 * - Worker timeouts stay below every queue connection's retry_after.
 * - Every secret-shaped setting the configuration reads is in a secret group.
 */
class ProductionProcessManifestTest extends TestCase
{
    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $manifest = json_decode((string) file_get_contents(base_path('deploy/processes.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($manifest);

        return $manifest;
    }

    /** @return list<array<string, mixed>> */
    private function processes(): array
    {
        return $this->manifest()['processes'];
    }

    /** @return list<string> */
    private function workedQueues(): array
    {
        return array_values(array_filter(array_map(fn ($p) => $p['queue'] ?? null, $this->processes())));
    }

    /**
     * The queues application code dispatches to: every QueueName case
     * referenced from app/ (literal queue names are refused outright).
     *
     * @return list<string>
     */
    private function dispatchedQueues(): array
    {
        $queues = [];

        foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
            $source = $file->getContents();

            if (str_ends_with($file->getRealPath(), 'Support/Observability/QueueName.php')) {
                continue;
            }

            preg_match_all('/QueueName::(\w+)\b(?!\s*\()/', $source, $cases);
            foreach ($cases[1] as $case) {
                $queues[] = constant(QueueName::class.'::'.$case)->value;
            }

            $this->assertDoesNotMatchRegularExpression(
                '/->onQueue\(\s*[\'"]/',
                $source,
                $file->getRelativePathname().' dispatches to a literal queue name; use QueueName so the worker manifest can be checked.',
            );
        }

        return array_values(array_unique($queues));
    }

    #[Test]
    public function every_dispatched_queue_has_exactly_one_worker_role_and_no_worker_is_idle(): void
    {
        $dispatched = $this->dispatchedQueues();
        $worked = $this->workedQueues();

        $this->assertContains('default', $dispatched);
        $this->assertContains('integrations', $dispatched);
        $this->assertContains('notifications', $dispatched);

        sort($dispatched);
        $sortedWorked = $worked;
        sort($sortedWorked);
        $this->assertSame($dispatched, $sortedWorked, 'Worker queues must equal the queues the code dispatches to.');
        $this->assertSame(array_unique($worked), $worked, 'One worker role per queue.');

        // Reserved cases are not dispatched to (and so need no worker).
        foreach ([QueueName::Ai, QueueName::Low, QueueName::Critical] as $reserved) {
            $this->assertNotContains($reserved->value, $dispatched);
        }
    }

    #[Test]
    public function the_entrypoint_accepts_exactly_the_manifest_queues(): void
    {
        $entrypoint = (string) file_get_contents(base_path('deploy/entrypoint.sh'));
        $worked = $this->workedQueues();
        sort($worked);

        $this->assertMatchesRegularExpression('/^\s*(\S+)\) ;;$/m', $entrypoint);
        preg_match('/^\s*([a-z|]+)\) ;;$/m', $entrypoint, $match);
        $accepted = explode('|', $match[1]);
        sort($accepted);
        $this->assertSame($worked, $accepted);

        foreach ($this->processes() as $process) {
            if (($process['image'] ?? null) === 'app') {
                $this->assertContains($process['command'][0], ['web', 'worker', 'scheduler', 'console'], $process['role']);
                $this->assertStringContainsString($process['command'][0].')', $entrypoint);
            }
        }
    }

    #[Test]
    public function exactly_one_singleton_scheduler(): void
    {
        $schedulers = array_values(array_filter($this->processes(), fn ($p) => $p['command'] === ['scheduler']));

        $this->assertCount(1, $schedulers);
        $this->assertTrue($schedulers[0]['singleton'] ?? false);
        $this->assertSame([], array_values(array_filter($this->processes(), fn ($p) => ($p['singleton'] ?? false) && $p['command'] !== ['scheduler'])));

        $this->assertStringContainsString('schedule:work', (string) file_get_contents(base_path('deploy/entrypoint.sh')));
    }

    #[Test]
    public function long_running_roles_never_receive_database_admin_credentials(): void
    {
        $manifest = $this->manifest();
        $this->assertSame(['DB_ADMIN_USERNAME', 'DB_ADMIN_PASSWORD'], $manifest['secret_groups']['database_admin']);

        foreach ($this->processes() as $process) {
            if ($process['long_running']) {
                $this->assertNotContains('database_admin', $process['secret_groups'], $process['role']);
            }
        }

        $admin = array_column(array_filter($this->processes(), fn ($p) => in_array('database_admin', $p['secret_groups'], true)), 'role');
        sort($admin);
        $this->assertSame(['operator-console', 'release'], $admin);

        // The gateway holds only its own signing key; never the application's secrets.
        $gateway = array_values(array_filter($this->processes(), fn ($p) => $p['image'] === 'ai-gateway'));
        $this->assertSame(['gateway'], $gateway[0]['secret_groups']);
        $this->assertFalse($gateway[0]['public']);
    }

    #[Test]
    public function worker_timeout_stays_below_every_retry_after(): void
    {
        preg_match('/queue:work [^\n]*--timeout=(\d+)/', (string) file_get_contents(base_path('deploy/entrypoint.sh')), $match);
        $timeout = (int) $match[1];
        $this->assertGreaterThan(0, $timeout);

        foreach (config('queue.connections') as $name => $connection) {
            if (isset($connection['retry_after'])) {
                $this->assertLessThan((int) $connection['retry_after'], $timeout, "worker --timeout must stay below {$name}.retry_after (CLAUDE.md rule 58)");
            }
        }
    }

    #[Test]
    public function every_secret_shaped_setting_is_in_a_secret_group(): void
    {
        $grouped = array_merge(...array_values($this->manifest()['secret_groups']));

        // Framework defaults for services this application does not use, and
        // non-secret settings whose names merely look secret.
        $notSecrets = [
            'AUTH_PASSWORD_TIMEOUT', 'DB_FOREIGN_KEYS',
            'IDEMPOTENCY_KEY_MAX_LENGTH', 'IDEMPOTENCY_KEY_MIN_LENGTH', 'MFA_PASSWORD_CONFIRMATION_WINDOW_MINUTES',
            'CONTACT_LOOKUP_HMAC_KEY_VERSION', 'STATUTORY_IDENTIFIER_LOOKUP_HMAC_KEY_VERSION', 'WEBHOOKS_SECRET_ROTATION_OVERLAP_HOURS',
            'MEMCACHED_PASSWORD', 'POSTMARK_API_KEY', 'RESEND_API_KEY', 'SLACK_BOT_USER_OAUTH_TOKEN',
            // ADR 0053: PUBLIC verification keys (configuration, not a secret),
            // and the retired shared token, read only so production refuses it.
            'AI_GATEWAY_INBOUND_VERIFICATION_KEYS', 'AI_GATEWAY_SERVICE_TOKEN',
            // ADR 0055: the suppression key ring's key IDS (labels, not keys).
            'MAIL_SUPPRESSION_HMAC_KEY_ID', 'MAIL_SUPPRESSION_HMAC_PREVIOUS_KEY_ID',
        ];

        $names = [];
        $read = [];
        foreach ((new Finder)->files()->in(config_path())->name('*.php') as $file) {
            preg_match_all("/env\\('([A-Z0-9_]*(?:KEY|SECRET|PASSWORD|TOKEN)[A-Z0-9_]*)'/", $file->getContents(), $m);
            $names = [...$names, ...$m[1]];
            preg_match_all("/env\\('([A-Z0-9_]+)'/", $file->getContents(), $all);
            $read = [...$read, ...$all[1]];
        }

        foreach (array_unique($names) as $name) {
            $this->assertTrue(in_array($name, $grouped, true) || in_array($name, $notSecrets, true), "{$name} is secret-shaped but in no secret group");
        }
        foreach ($grouped as $name) {
            if ($name !== 'SERVICE_SIGNING_KEY') { // the Gateway's own setting, not Laravel configuration
                $this->assertContains($name, $read, "{$name} is in a secret group but no configuration reads it");
            }
        }
    }

    #[Test]
    public function the_scheduler_runs_the_recovery_sources(): void
    {
        $scheduled = implode("\n", array_map(fn ($event) => $event->command ?? '', app(Schedule::class)->events()));

        foreach (RecoverQueuedWork::SOURCES as $command) {
            $this->assertStringContainsString($command, $scheduled, "{$command} must be scheduled so recovery is continuous, not only manual");
        }
    }

    #[Test]
    public function health_endpoints_in_the_manifest_exist(): void
    {
        $web = array_values(array_filter($this->processes(), fn ($p) => $p['role'] === 'web'))[0];
        $this->get($web['health']['liveness'])->assertOk();
        $this->assertNotNull(app('router')->getRoutes()->match(request()->create($web['health']['readiness'])));
    }
}
