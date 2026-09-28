<?php

namespace Tests\Feature\Observability;

use App\Support\Observability\Alerts\AlertCatalog;
use App\Support\Observability\Alerts\AlertRule;
use App\Support\Observability\Alerts\AlertRulesExporter;
use App\Support\Observability\Alerts\InvalidAlertThreshold;
use App\Support\Observability\Alerts\MetricSnapshot;
use App\Support\Observability\Alerts\Severity;
use App\Support\Observability\Metrics\MetricCatalog;
use App\Support\Observability\Metrics\Series;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0O.5A (ADR 0051 §14): the 26 alerts as deterministic conditions,
 * evaluated here against fixture metric values -- no monitoring backend.
 */
class AlertCatalogTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private function rule(string $id): AlertRule
    {
        foreach (AlertCatalog::all() as $rule) {
            if ($rule->id === $id) {
                return $rule;
            }
        }
        $this->fail("{$id} missing");
    }

    /**
     * @param  array<int, array{0: string, 1: array<string, string>, 2: float}>  $values
     * @param  array<int, array{0: string, 1: string, 2: array<string, string>, 3: float}>  $increases
     */
    private function snapshot(array $values = [], array $increases = [], bool $up = true): MetricSnapshot
    {
        $v = [];
        foreach ($values as [$name, $labels, $value]) {
            $v[Series::key($name, $labels)] = $value;
        }
        $i = [];
        foreach ($increases as [$window, $name, $labels, $value]) {
            $i[$window.'|'.Series::key($name, $labels)] = $value;
        }

        return new MetricSnapshot(self::NOW, $v, $i, $up);
    }

    /** A fully healthy platform: nothing may fire. */
    private function healthy(): array
    {
        $values = [
            ['lycenza_readiness_status', ['dependency' => 'postgresql'], 1],
            ['lycenza_readiness_status', ['dependency' => 'redis'], 1],
            ['lycenza_ai_gateway_ready', [], 1],
            ['lycenza_backup_recovery_point_timestamp_seconds', ['backup_store' => 'postgresql'], self::NOW - 120],
            ['lycenza_backup_last_success_timestamp_seconds', ['backup_store' => 'postgresql'], self::NOW - 3600],
            ['lycenza_backup_last_success_timestamp_seconds', ['backup_store' => 'objects'], self::NOW - 3600],
            ['lycenza_restore_drill_last_success_timestamp_seconds', [], self::NOW - 30 * 86400],
            ['lycenza_restore_drill_last_result', [], 1],
            ['lycenza_verification_last_result', ['check' => 'verify_storage'], 1],
            ['lycenza_reconciliation_last_success_timestamp_seconds', ['recovery_source' => 'outbox'], self::NOW - 30],
            ['lycenza_reconciliation_last_success_timestamp_seconds', ['recovery_source' => 'automation'], self::NOW - 30],
        ];
        foreach (MetricCatalog::scheduledTasks() as $task) {
            $values[] = ['lycenza_scheduler_task_last_success_timestamp_seconds', ['scheduled_task' => $task], self::NOW - 30];
        }
        foreach (MetricCatalog::queues() as $queue) {
            $values[] = ['lycenza_queue_heartbeat_last_success_timestamp_seconds', ['queue' => $queue], self::NOW - 20];
            $values[] = ['lycenza_queue_oldest_pending_age_seconds', ['queue' => $queue], 3];
        }

        return $values;
    }

    #[Test]
    public function the_catalog_is_exactly_obs_01_to_obs_41_with_existing_runbooks(): void
    {
        $ids = array_map(fn (AlertRule $r) => $r->id, AlertCatalog::all());
        $this->assertSame(array_map(fn ($n) => sprintf('OBS-%02d', $n), range(1, 41)), $ids);

        foreach (AlertCatalog::all() as $rule) {
            $this->assertFileExists(dirname(base_path(), 2).'/docs/operations/'.$rule->runbook, "{$rule->id} runbook");
            $this->assertContains($rule->source, ['A', 'B', 'C', 'A/C', 'B/C'], $rule->id);
            foreach ($rule->tiers as $tier) {
                preg_match_all('/lycenza_[a-z_]+/', $tier['expr'], $names);
                foreach ($names[0] as $name) {
                    $base = (string) preg_replace('/_(bucket|sum|count)$/', '', $name);
                    $this->assertArrayHasKey($base, MetricCatalog::definitions(), "{$rule->id} uses {$name}");
                }
            }
        }
    }

    #[Test]
    public function email_alerts_never_page_and_fire_on_their_conditions(): void
    {
        config(['observability.alerts' => [...config('observability.alerts'),
            'email_failure_ratio_per_hour' => 0.5, 'email_hard_bounces_per_hour' => 10, 'email_complaints_per_hour' => 2, 'email_webhook_auth_failures_per_15m' => 20,
        ]]);

        foreach (range(31, 38) as $n) {
            foreach ($this->rule("OBS-{$n}")->tiers as $tier) {
                $this->assertNotSame(Severity::Sev1, $tier['severity'], "OBS-{$n} must never page as SEV-1");
            }
            $this->assertSame('EMAIL-DELIVERABILITY.md', $this->rule("OBS-{$n}")->runbook);
        }

        $gauge = fn (string $class, float $age) => $this->snapshot([['lycenza_email_oldest_pending_age_seconds', ['message_class' => $class], $age]]);
        $this->assertNull($this->rule('OBS-31')->evaluate($gauge('account_invitation', 1800)));
        $this->assertSame(Severity::Sev2, $this->rule('OBS-31')->evaluate($gauge('account_invitation', 1801)));
        $this->assertNull($this->rule('OBS-31')->evaluate($gauge('school_communication', 99999)), 'standard mail never raises the critical alert');
        $this->assertSame(Severity::Sev3, $this->rule('OBS-32')->evaluate($gauge('school_communication', 1801)));

        $this->assertSame(Severity::Sev2, $this->rule('OBS-34')->evaluate($this->snapshot([], [['15m', 'lycenza_email_submission_attempts_total', ['message_class' => 'account_invitation', 'outcome' => 'auth_failure'], 1]])));
        $this->assertNull($this->rule('OBS-37')->evaluate($this->snapshot([], [['1h', 'lycenza_email_messages_total', ['message_class' => 'school_communication', 'outcome' => 'complained'], 2]])));
        $this->assertSame(Severity::Sev2, $this->rule('OBS-37')->evaluate($this->snapshot([], [['1h', 'lycenza_email_messages_total', ['message_class' => 'school_communication', 'outcome' => 'complained'], 3]])));
        $this->assertSame(Severity::Sev3, $this->rule('OBS-36')->evaluate($this->snapshot([], [['1h', 'lycenza_email_messages_total', ['message_class' => 'school_communication', 'outcome' => 'bounced'], 11]])));

        // Event staleness only when an event feed exists and mail is being submitted.
        $submitted = [['1h', 'lycenza_email_messages_total', ['message_class' => 'school_communication', 'outcome' => 'submitted'], 5]];
        $this->assertNull($this->rule('OBS-35')->evaluate($this->snapshot([], $submitted)), 'no event feed configured (SMTP): no staleness alert');
        $this->assertSame(Severity::Sev3, $this->rule('OBS-35')->evaluate($this->snapshot([['lycenza_email_last_event_timestamp_seconds', [], self::NOW - 86401]], $submitted)));
        $this->assertNull($this->rule('OBS-35')->evaluate($this->snapshot([['lycenza_email_last_event_timestamp_seconds', [], self::NOW - 86401]])));
    }

    #[Test]
    public function custom_domain_alerts_warn_and_escalate_but_never_page(): void
    {
        $suspended = fn (float $n) => $this->snapshot([], [['1h', 'lycenza_domain_transitions_total', ['to' => 'suspended'], $n]]);
        $days = fn (float $d) => $this->snapshot([['lycenza_domain_certificate_min_days_remaining', [], $d]]);
        $stuck = fn (float $s) => $this->snapshot([['lycenza_domain_indeterminate_max_age_seconds', [], $s]]);

        $this->assertNull($this->rule('OBS-28')->evaluate($suspended(0)));
        $this->assertSame(Severity::Sev3, $this->rule('OBS-28')->evaluate($suspended(1)));

        $this->assertNull($this->rule('OBS-29')->evaluate($days(22)));
        $this->assertNull($this->rule('OBS-29')->evaluate($this->snapshot([])), 'no active certificate recorded: nothing to warn about');
        $this->assertSame(Severity::Sev3, $this->rule('OBS-29')->evaluate($days(21)));
        $this->assertSame(Severity::Sev2, $this->rule('OBS-29')->evaluate($days(7)));
        $this->assertSame(Severity::Sev2, $this->rule('OBS-29')->evaluate($days(-1)));

        $this->assertNull($this->rule('OBS-30')->evaluate($stuck(3 * 86400 - 1)));
        $this->assertSame(Severity::Sev3, $this->rule('OBS-30')->evaluate($stuck(3 * 86400)));

        foreach (['OBS-28', 'OBS-29', 'OBS-30'] as $id) {
            foreach ($this->rule($id)->tiers as $tier) {
                $this->assertNotSame(Severity::Sev1, $tier['severity'], "{$id}: one School's domain is never the platform");
            }
        }
    }

    #[Test]
    public function service_authentication_failures_and_key_age_warn_but_never_page(): void
    {
        $age = fn (float $days, string $metric = 'lycenza_service_signing_key_age_days', string $service = 'platform') => $this->snapshot([[$metric, ['service' => $service], $days]]);
        $auth = fn (string $outcome) => $this->snapshot([], [['15m', 'lycenza_service_auth_total', ['direction' => 'gateway_to_platform', 'service' => 'unknown', 'outcome' => $outcome], 1.0]]);

        $this->assertNull($this->rule('OBS-27')->evaluate($age(75)));
        $this->assertSame(Severity::Sev3, $this->rule('OBS-27')->evaluate($age(76)));
        $this->assertSame(Severity::Sev3, $this->rule('OBS-27')->evaluate($age(80, 'lycenza_service_verification_key_max_age_days', 'ai-gateway')));
        foreach (['unknown_kid', 'bad_signature', 'key_expired', 'rejected_by_receiver'] as $outcome) {
            $this->assertSame(Severity::Sev3, $this->rule('OBS-27')->evaluate($auth($outcome)), $outcome);
        }
        $this->assertNull($this->rule('OBS-27')->evaluate($auth('replayed')), 'a refused replay is counted, not a key problem');
    }

    #[Test]
    public function a_healthy_platform_fires_nothing(): void
    {
        $snapshot = $this->snapshot($this->healthy());

        foreach (AlertCatalog::all() as $rule) {
            $this->assertNull($rule->evaluate($snapshot), $rule->id.' fired on a healthy platform');
        }
    }

    #[Test]
    public function contract_thresholds_protect_the_recovery_objectives_exactly(): void
    {
        $at = fn (float $age) => $this->snapshot(array_merge($this->healthy(), [['lycenza_backup_recovery_point_timestamp_seconds', ['backup_store' => 'postgresql'], self::NOW - $age]]));
        $this->assertNull($this->rule('OBS-20')->evaluate($at(600)));
        $this->assertSame(Severity::Sev2, $this->rule('OBS-20')->evaluate($at(601)));
        $this->assertSame(Severity::Sev2, $this->rule('OBS-20')->evaluate($at(900)));
        $this->assertSame(Severity::Sev1, $this->rule('OBS-20')->evaluate($at(901)));

        $objects = fn (float $age) => $this->snapshot(array_merge($this->healthy(), [['lycenza_backup_last_success_timestamp_seconds', ['backup_store' => 'objects'], self::NOW - $age]]));
        $this->assertNull($this->rule('OBS-22')->evaluate($objects(20 * 3600)));
        $this->assertSame(Severity::Sev2, $this->rule('OBS-22')->evaluate($objects(20 * 3600 + 1)));
        $this->assertSame(Severity::Sev1, $this->rule('OBS-22')->evaluate($objects(24 * 3600 + 1)));

        $drill = fn (float $days, float $result = 1) => $this->snapshot(array_merge($this->healthy(), [
            ['lycenza_restore_drill_last_success_timestamp_seconds', [], self::NOW - $days * 86400],
            ['lycenza_restore_drill_last_result', [], $result],
        ]));
        $this->assertNull($this->rule('OBS-23')->evaluate($drill(92)));
        $this->assertSame(Severity::Sev3, $this->rule('OBS-23')->evaluate($drill(93)));
        $this->assertSame(Severity::Sev2, $this->rule('OBS-23')->evaluate($drill(121)));
        $this->assertSame(Severity::Sev2, $this->rule('OBS-23')->evaluate($drill(10, 0)), 'a FAIL result is SEV-2 at once');
    }

    #[Test]
    public function absent_backup_evidence_is_an_alert_never_healthy(): void
    {
        $withoutEvidence = array_values(array_filter($this->healthy(), fn ($v) => ! str_starts_with($v[0], 'lycenza_backup_') && ! str_starts_with($v[0], 'lycenza_restore_drill_')));
        $snapshot = $this->snapshot($withoutEvidence);

        $this->assertSame(Severity::Sev2, $this->rule('OBS-20')->evaluate($snapshot));
        $this->assertSame(Severity::Sev2, $this->rule('OBS-21')->evaluate($snapshot));
        $this->assertSame(Severity::Sev2, $this->rule('OBS-22')->evaluate($snapshot));
        $this->assertSame(Severity::Sev3, $this->rule('OBS-23')->evaluate($snapshot), 'no drill yet: overdue (the real drill is outstanding)');
    }

    #[Test]
    public function runtime_cadence_thresholds_fire_where_the_code_says(): void
    {
        $tasksAt = function (float $age) {
            $values = array_values(array_filter($this->healthy(), fn ($v) => $v[0] !== 'lycenza_scheduler_task_last_success_timestamp_seconds'));
            foreach (MetricCatalog::scheduledTasks() as $task) {
                $values[] = ['lycenza_scheduler_task_last_success_timestamp_seconds', ['scheduled_task' => $task], self::NOW - (in_array($task, MetricCatalog::DAILY_TASKS, true) ? 3600 : $age)];
            }

            return $this->snapshot($values);
        };
        $this->assertNull($this->rule('OBS-05')->evaluate($tasksAt(300)));
        $this->assertSame(Severity::Sev2, $this->rule('OBS-05')->evaluate($tasksAt(301)));
        $this->assertSame(Severity::Sev1, $this->rule('OBS-05')->evaluate($tasksAt(1801)));

        // One task stale while the others run: OBS-06, not OBS-05.
        $one = $this->snapshot(array_merge($this->healthy(), [['lycenza_scheduler_task_last_success_timestamp_seconds', ['scheduled_task' => 'expire-school-elevations'], self::NOW - 400]]));
        $this->assertSame(Severity::Sev3, $this->rule('OBS-06')->evaluate($one));
        $this->assertNull($this->rule('OBS-05')->evaluate($one));

        // A daily task is stale only after 26 h.
        $daily = fn (float $age) => $this->snapshot(array_merge($this->healthy(), [['lycenza_scheduler_task_last_success_timestamp_seconds', ['scheduled_task' => 'idempotency-prune'], self::NOW - $age]]));
        $this->assertNull($this->rule('OBS-06')->evaluate($daily(25 * 3600)));
        $this->assertSame(Severity::Sev3, $this->rule('OBS-06')->evaluate($daily(26 * 3600 + 1)));

        // Worker class: the canary heartbeat for one queue.
        $worker = fn (float $age) => $this->snapshot(array_merge($this->healthy(), [['lycenza_queue_heartbeat_last_success_timestamp_seconds', ['queue' => 'notifications'], self::NOW - $age]]));
        $this->assertNull($this->rule('OBS-08')->evaluate($worker(300)));
        $this->assertSame(Severity::Sev2, $this->rule('OBS-08')->evaluate($worker(301)));

        foreach (['OBS-15' => 'lycenza_webhook_oldest_overdue_age_seconds', 'OBS-17' => 'lycenza_communication_oldest_overdue_age_seconds', 'OBS-19' => 'lycenza_automation_oldest_overdue_age_seconds', 'OBS-11' => 'lycenza_outbox_oldest_pending_age_seconds'] as $id => $metric) {
            $at = fn (float $age) => $this->snapshot(array_merge($this->healthy(), [[$metric, [], $age]]));
            $this->assertNull($this->rule($id)->evaluate($at(300)), $id);
            $this->assertSame(Severity::Sev3, $this->rule($id)->evaluate($at(301)), $id);
            $this->assertSame(Severity::Sev2, $this->rule($id)->evaluate($at(1801)), $id);
        }
    }

    #[Test]
    public function the_duplicate_scheduler_signal_is_deterministic(): void
    {
        $runs = fn (float $inTenMinutes) => $this->snapshot($this->healthy(), [['10m', 'lycenza_scheduler_task_runs_total', ['scheduled_task' => 'outbox-dispatch', 'outcome' => 'success'], $inTenMinutes]]);

        $this->assertNull($this->rule('OBS-07')->evaluate($runs(10)), 'one scheduler: ~1 run per minute');
        $this->assertNull($this->rule('OBS-07')->evaluate($runs(15)), '1.5x is tolerated (jitter, a catch-up run)');
        $this->assertSame(Severity::Sev3, $this->rule('OBS-07')->evaluate($runs(20)), 'two schedulers: ~2 runs per minute');
    }

    #[Test]
    public function event_rate_alerts_use_windowed_increases(): void
    {
        $this->assertSame(Severity::Sev3, $this->rule('OBS-10')->evaluate($this->snapshot($this->healthy(), [['15m', 'lycenza_queue_jobs_failed_total', ['queue' => 'default'], 1]])));
        $this->assertSame(Severity::Sev2, $this->rule('OBS-14')->evaluate($this->snapshot($this->healthy(), [['1h', 'lycenza_outbox_failed_events', [], 1]])));
        $this->assertSame(Severity::Sev2, $this->rule('OBS-12')->evaluate($this->snapshot($this->healthy(), [['5m', 'lycenza_reconciliation_runs_total', ['recovery_source' => 'outbox', 'outcome' => 'failure'], 1]])));
        $this->assertSame(Severity::Sev2, $this->rule('OBS-26')->evaluate($this->snapshot($this->healthy(), [['15m', 'lycenza_metrics_collection_errors_total', ['component' => 'outbox'], 1]])));
        $this->assertSame(Severity::Sev2, $this->rule('OBS-26')->evaluate($this->snapshot($this->healthy(), up: false)));
        $this->assertSame(Severity::Sev1, $this->rule('OBS-02')->evaluate($this->snapshot(array_merge($this->healthy(), [['lycenza_readiness_status', ['dependency' => 'postgresql'], 0]]))));
        $this->assertSame(Severity::Sev3, $this->rule('OBS-04')->evaluate($this->snapshot(array_merge($this->healthy(), [['lycenza_ai_gateway_ready', [], 0]]))), 'the optional Gateway never pages');
        $this->assertSame(Severity::Sev2, $this->rule('OBS-24')->evaluate($this->snapshot(array_merge($this->healthy(), [['lycenza_verification_last_result', ['check' => 'verify_storage'], 0]]))));
    }

    #[Test]
    public function operator_values_are_disabled_when_unset_and_refused_when_malformed(): void
    {
        foreach (['OBS-16', 'OBS-18', 'OBS-25'] as $id) {
            $this->assertFalse($this->rule($id)->enabled(), "{$id} is disabled until the operator sets it");
            $this->assertStringContainsString("{$id} DISABLED", (new AlertRulesExporter)->yaml());
        }

        config(['observability.alerts.webhook_final_failures_per_hour' => 25]);
        $this->assertTrue($this->rule('OBS-16')->enabled());
        $this->assertSame(Severity::Sev3, $this->rule('OBS-16')->evaluate($this->snapshot($this->healthy(), [['1h', 'lycenza_webhook_deliveries_finished_total', ['outcome' => 'abandoned'], 26]])));

        foreach (['not-a-number', '-1', '0', '99999999'] as $bad) {
            config(['observability.alerts.webhook_final_failures_per_hour' => $bad]);
            try {
                AlertCatalog::all();
                $this->fail("{$bad} must be refused");
            } catch (InvalidAlertThreshold $e) {
                $this->assertStringNotContainsString($bad, $e->getMessage() === '' ? 'x' : str_replace(['1 and', '100000'], '', $e->getMessage()));
            }
        }

        $this->assertSame(1, Artisan::call('platform:alerts-export', ['--no-interaction' => true]), 'a malformed operator value fails the export');
    }

    #[Test]
    public function account_recovery_alerts_never_page_and_fire_on_their_conditions(): void
    {
        config(['observability.alerts' => [...config('observability.alerts'),
            'account_recovery_requests_per_hour' => 100, 'account_recovery_invalid_resets_per_hour' => 20,
        ]]);

        foreach (range(39, 41) as $n) {
            foreach ($this->rule("OBS-{$n}")->tiers as $tier) {
                $this->assertNotSame(Severity::Sev1, $tier['severity'], "OBS-{$n} must never page as SEV-1");
            }
            $this->assertSame('ACCOUNT-RECOVERY.md', $this->rule("OBS-{$n}")->runbook);
        }

        $requests = fn (float $n) => $this->snapshot([], [['1h', 'lycenza_account_recovery_requests_total', ['outcome' => 'accepted'], $n]]);
        $this->assertNull($this->rule('OBS-39')->evaluate($requests(100)));
        $this->assertSame(Severity::Sev3, $this->rule('OBS-39')->evaluate($requests(101)));

        $invalid = fn (float $n) => $this->snapshot([], [['1h', 'lycenza_account_recovery_resets_total', ['outcome' => 'invalid'], $n]]);
        $this->assertNull($this->rule('OBS-40')->evaluate($invalid(20)));
        $this->assertSame(Severity::Sev3, $this->rule('OBS-40')->evaluate($invalid(21)));

        $state = fn (float $enabled, float $available) => $this->snapshot([
            ['lycenza_account_recovery_enabled', ['state' => 'enabled'], $enabled],
            ['lycenza_account_recovery_enabled', ['state' => 'available'], $available],
        ]);
        $this->assertSame(Severity::Sev2, $this->rule('OBS-41')->evaluate($state(1, 0)));
        $this->assertNull($this->rule('OBS-41')->evaluate($state(1, 1)));
        $this->assertNull($this->rule('OBS-41')->evaluate($state(0, 0)), 'disabled recovery is a complete, safe mode');

        // Without operator values the spike tiers are explicitly disabled.
        config(['observability.alerts.account_recovery_requests_per_hour' => null, 'observability.alerts.account_recovery_invalid_resets_per_hour' => null]);
        $this->assertSame([], $this->rule('OBS-39')->tiers);
        $this->assertSame([], $this->rule('OBS-40')->tiers);
    }

    #[Test]
    public function alert_names_stay_unique_with_every_operator_value_enabled(): void
    {
        config(['observability.alerts' => [...config('observability.alerts'),
            'failed_jobs_high_per_15m' => 20, 'webhook_final_failures_per_hour' => 25, 'communication_failure_ratio_per_hour' => 0.2,
            'storage_failures_per_15m' => 5, 'security_rejections_per_15m' => 500,
            'email_failure_ratio_per_hour' => 0.2, 'email_hard_bounces_per_hour' => 50, 'email_complaints_per_hour' => 5, 'email_webhook_auth_failures_per_15m' => 100,
            'account_recovery_requests_per_hour' => 500, 'account_recovery_invalid_resets_per_hour' => 50,
        ]]);

        preg_match_all('/- alert: (\S+)/', (new AlertRulesExporter)->yaml(), $names);
        $this->assertCount(55, $names[1]); // 38 + the two OBS-27 tiers (ADR 0053) + OBS-28, two OBS-29 tiers and OBS-30 (ADR 0054) + OBS-31..38 (ADR 0055) + OBS-39..41 (ADR 0056)
        $this->assertSame($names[1], array_values(array_unique($names[1])));
    }

    #[Test]
    public function the_committed_rule_file_is_the_generated_one(): void
    {
        $committed = (string) file_get_contents(dirname(base_path(), 2).'/docs/operations/alerts/lycenza-alerts.rules.yml');

        $this->assertSame((new AlertRulesExporter)->yaml(), $committed, 'regenerate with: php artisan platform:alerts-export --output=docs/operations/alerts/lycenza-alerts.rules.yml');
        foreach (['datadog', 'newrelic', 'new relic', 'splunk', 'cloudwatch', 'pagerduty', 'slack', 'grafana cloud', 'azure monitor', 'stackdriver'] as $vendor) {
            $this->assertStringNotContainsStringIgnoringCase($vendor, $committed);
        }
    }

    #[Test]
    public function the_dashboard_specification_names_only_catalog_metrics(): void
    {
        $spec = (string) file_get_contents(dirname(base_path(), 2).'/docs/operations/dashboards.md');
        preg_match_all('/lycenza_[a-z_]+/', $spec, $names);

        $this->assertNotEmpty($names[0]);
        foreach (array_unique($names[0]) as $name) {
            $this->assertArrayHasKey((string) preg_replace('/_(bucket|sum|count)$/', '', $name), MetricCatalog::definitions(), $name);
        }
    }
}
