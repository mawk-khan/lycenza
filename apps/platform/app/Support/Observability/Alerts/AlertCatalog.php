<?php

namespace App\Support\Observability\Alerts;

use App\Support\Email\EmailKind;
use App\Support\Email\EmailPurpose;
use App\Support\Observability\Metrics\MetricCatalog;

/**
 * Phase 0O.5A (ADR 0051 §14.4): the required alerts as deterministic
 * definitions -- OBS-01..OBS-26 (ADR 0051), OBS-27 (ADR 0053), OBS-28..30
 * (ADR 0054), OBS-31..38 (ADR 0055, production email) and OBS-39..41
 * (ADR 0056, account recovery). The application sends NO notification:
 * the deployment's backend evaluates the exported rules
 * (AlertRulesExporter) and routes them.
 *
 * Thresholds:
 * - A (contract): O10 recovery objectives and quarterly drills;
 * - B (runtime cadence): `observability.thresholds` -- 300 s is five missed
 *   one-minute sweep/canary cycles, 1800 s the existing outbox critical
 *   window, 26 h a daily task plus margin;
 * - C (operator): `observability.alerts.*`, each validated against its
 *   safeguard bounds; an unset C value leaves that tier explicitly
 *   DISABLED (reported, never silently assumed).
 */
final class AlertCatalog
{
    private const DAY = 86400;

    /**
     * @return list<AlertRule>
     */
    public static function all(): array
    {
        $t = config('observability.thresholds');
        $warn = (int) $t['backlog_warning_seconds'];
        $high = (int) $t['backlog_high_seconds'];
        $minuteStale = (int) $t['minute_task_stale_seconds'];
        $minuteCritical = (int) $t['minute_task_critical_seconds'];
        $dailyStale = (int) $t['daily_task_stale_seconds'];
        $workerStale = (int) $t['worker_heartbeat_stale_seconds'];

        $readinessFor = self::operator('readiness_for_seconds', 30, 600);
        $dependencyFor = self::operator('dependency_for_seconds', 30, 600);
        $gatewayFor = self::operator('gateway_unready_for_seconds', 60, 3600);
        $failedJobsHigh = self::operator('failed_jobs_high_per_15m', 1, 100000);
        $webhookFinal = self::operator('webhook_final_failures_per_hour', 1, 100000);
        $commRatio = self::operator('communication_failure_ratio_per_hour', 0.001, 1.0);
        $storageFailures = self::operator('storage_failures_per_15m', 1, 100000);
        $securityRejections = self::operator('security_rejections_per_15m', 1, 10000000);
        $telemetryFor = self::operator('telemetry_down_for_seconds', 60, 3600);
        $emailRatio = self::operator('email_failure_ratio_per_hour', 0.001, 1.0);
        $emailHardBounces = self::operator('email_hard_bounces_per_hour', 1, 1000000);
        $emailComplaints = self::operator('email_complaints_per_hour', 1, 1000000);
        $emailWebhookAuth = self::operator('email_webhook_auth_failures_per_15m', 1, 1000000);
        $recoveryRequests = self::operator('account_recovery_requests_per_hour', 1, 10000000);
        $recoveryInvalid = self::operator('account_recovery_invalid_resets_per_hour', 1, 10000000);

        $minuteTasks = array_values(array_diff(MetricCatalog::scheduledTasks(), MetricCatalog::DAILY_TASKS));
        $minuteRegex = implode('|', $minuteTasks);
        $dailyRegex = implode('|', MetricCatalog::DAILY_TASKS);
        $queues = MetricCatalog::queues();
        $criticalList = array_values(array_map(fn (EmailPurpose $p) => $p->value, array_filter(EmailPurpose::cases(), fn (EmailPurpose $p) => $p->kind() === EmailKind::Critical)));
        $criticalClasses = implode('|', $criticalList);

        $taskAge = fn (MetricSnapshot $s, string $task) => $s->age('lycenza_scheduler_task_last_success_timestamp_seconds', ['scheduled_task' => $task]);
        $allMinuteTasksOlderThan = fn (MetricSnapshot $s, int $seconds) => array_reduce($minuteTasks, fn (bool $carry, string $task) => $carry && (($taskAge($s, $task) ?? INF) > $seconds), true);
        $gaugeAbove = fn (string $name, float $limit, array $labels = []) => fn (MetricSnapshot $s) => ($s->value($name, $labels) ?? 0) > $limit;
        $ageAbove = fn (string $name, float $limit, array $labels = []) => fn (MetricSnapshot $s) => ($s->age($name, $labels) ?? INF) > $limit;

        return [
            self::rule('OBS-01', 'Web readiness failing: the application cannot serve traffic.', 'C', 'MAINTENANCE-WINDOW-RELEASE.md', [
                self::tier(Severity::Sev1, 'absent(lycenza_readiness_status) or min(lycenza_readiness_status) == 0', $readinessFor ?? 120,
                    fn (MetricSnapshot $s) => ! $s->scrapeUp || $s->all('lycenza_readiness_status') === [] || min(array_column($s->all('lycenza_readiness_status'), 'value')) == 0),
            ]),
            self::rule('OBS-02', 'PostgreSQL unavailable to the application.', 'C', 'BACKUP-AND-RESTORE.md', [
                self::tier(Severity::Sev1, 'lycenza_readiness_status{dependency="postgresql"} == 0', $dependencyFor ?? 120,
                    fn (MetricSnapshot $s) => $s->value('lycenza_readiness_status', ['dependency' => 'postgresql']) === 0.0),
            ]),
            self::rule('OBS-03', 'Redis unavailable to the application.', 'C', 'REDIS-LOSS-RECOVERY.md', [
                self::tier(Severity::Sev1, 'lycenza_readiness_status{dependency="redis"} == 0', $dependencyFor ?? 120,
                    fn (MetricSnapshot $s) => $s->value('lycenza_readiness_status', ['dependency' => 'redis']) === 0.0),
            ]),
            self::rule('OBS-04', 'AI Gateway not ready (optional subsystem; never pages).', 'C', 'PRODUCTION-IMAGES-AND-PROCESSES.md', [
                self::tier(Severity::Sev3, 'lycenza_ai_gateway_ready == 0', $gatewayFor ?? 600,
                    fn (MetricSnapshot $s) => $s->value('lycenza_ai_gateway_ready') === 0.0),
            ]),
            self::rule('OBS-05', 'Scheduler stopped: every minute-cadence task heartbeat is stale.', 'B', 'MAINTENANCE-WINDOW-RELEASE.md', [
                self::tier(Severity::Sev2, "min by () (time() - lycenza_scheduler_task_last_success_timestamp_seconds{scheduled_task=~\"{$minuteRegex}\"}) > {$minuteStale} or absent(lycenza_scheduler_task_last_success_timestamp_seconds{scheduled_task=~\"{$minuteRegex}\"})", 0,
                    fn (MetricSnapshot $s) => $allMinuteTasksOlderThan($s, $minuteStale)),
                self::tier(Severity::Sev1, "min by () (time() - lycenza_scheduler_task_last_success_timestamp_seconds{scheduled_task=~\"{$minuteRegex}\"}) > {$minuteCritical}", 0,
                    fn (MetricSnapshot $s) => $allMinuteTasksOlderThan($s, $minuteCritical)),
            ]),
            self::rule('OBS-06', 'A scheduled task is stale while the scheduler runs.', 'B', 'PRODUCTION-IMAGES-AND-PROCESSES.md', [
                self::tier(Severity::Sev3, "(time() - lycenza_scheduler_task_last_success_timestamp_seconds{scheduled_task=~\"{$minuteRegex}\"}) > {$minuteStale} or (time() - lycenza_scheduler_task_last_success_timestamp_seconds{scheduled_task=~\"{$dailyRegex}\"}) > {$dailyStale}", 0,
                    function (MetricSnapshot $s) use ($minuteTasks, $taskAge, $minuteStale, $dailyStale) {
                        foreach ($minuteTasks as $task) {
                            $age = $taskAge($s, $task);
                            if ($age !== null && $age > $minuteStale) {
                                return true;
                            }
                        }
                        foreach (MetricCatalog::DAILY_TASKS as $task) {
                            $age = $taskAge($s, $task);
                            if ($age !== null && $age > $dailyStale) {
                                return true;
                            }
                        }

                        return false;
                    }),
            ]),
            self::rule('OBS-07', 'Duplicate scheduler suspected: minute tasks run more than ~1.5x per minute.', 'B', 'PRODUCTION-IMAGES-AND-PROCESSES.md', [
                self::tier(Severity::Sev3, "max by (scheduled_task) (rate(lycenza_scheduler_task_runs_total{outcome=\"success\",scheduled_task=~\"{$minuteRegex}\"}[10m])) * 60 > 1.5", 0,
                    function (MetricSnapshot $s) use ($minuteTasks) {
                        foreach ($minuteTasks as $task) {
                            if ($s->increase('lycenza_scheduler_task_runs_total', '10m', ['scheduled_task' => $task, 'outcome' => 'success']) / 10 > 1.5) {
                                return true;
                            }
                        }

                        return false;
                    }),
            ]),
            self::rule('OBS-08', 'A required worker class is not processing (canary heartbeat stale).', 'B', 'PRODUCTION-IMAGES-AND-PROCESSES.md', [
                self::tier(Severity::Sev2, "(time() - lycenza_queue_heartbeat_last_success_timestamp_seconds) > {$workerStale} or absent(lycenza_queue_heartbeat_last_success_timestamp_seconds{queue=\"default\"}) or absent(lycenza_queue_heartbeat_last_success_timestamp_seconds{queue=\"integrations\"}) or absent(lycenza_queue_heartbeat_last_success_timestamp_seconds{queue=\"notifications\"})", 0,
                    function (MetricSnapshot $s) use ($queues, $workerStale) {
                        foreach ($queues as $queue) {
                            if (($s->age('lycenza_queue_heartbeat_last_success_timestamp_seconds', ['queue' => $queue]) ?? INF) > $workerStale) {
                                return true;
                            }
                        }

                        return false;
                    }),
            ]),
            self::rule('OBS-09', 'Oldest queued job is too old.', 'B', 'FAILED-JOBS.md', [
                self::tier(Severity::Sev3, "max(lycenza_queue_oldest_pending_age_seconds) > {$warn}", 0, fn (MetricSnapshot $s) => self::maxOf($s, 'lycenza_queue_oldest_pending_age_seconds') > $warn),
                self::tier(Severity::Sev2, "max(lycenza_queue_oldest_pending_age_seconds) > {$high}", 0, fn (MetricSnapshot $s) => self::maxOf($s, 'lycenza_queue_oldest_pending_age_seconds') > $high),
            ]),
            self::rule('OBS-10', 'Jobs failed permanently.', $failedJobsHigh === null ? 'B' : 'B/C', 'FAILED-JOBS.md', self::present([
                self::tier(Severity::Sev3, 'sum(increase(lycenza_queue_jobs_failed_total[15m])) > 0', 0, fn (MetricSnapshot $s) => $s->increase('lycenza_queue_jobs_failed_total', '15m') > 0),
                $failedJobsHigh === null ? null : self::tier(Severity::Sev2, "sum(increase(lycenza_queue_jobs_failed_total[15m])) > {$failedJobsHigh}", 0, fn (MetricSnapshot $s) => $s->increase('lycenza_queue_jobs_failed_total', '15m') > $failedJobsHigh),
            ])),
            self::rule('OBS-11', 'Outbox dispatch backlog.', 'B', 'REDIS-LOSS-RECOVERY.md', [
                self::tier(Severity::Sev3, 'lycenza_outbox_oldest_pending_age_seconds > '.(int) config('observability.outbox_degraded_after_seconds'), 0, $gaugeAbove('lycenza_outbox_oldest_pending_age_seconds', (int) config('observability.outbox_degraded_after_seconds'))),
                self::tier(Severity::Sev2, 'lycenza_outbox_oldest_pending_age_seconds > '.(int) config('observability.outbox_critical_after_seconds'), 0, $gaugeAbove('lycenza_outbox_oldest_pending_age_seconds', (int) config('observability.outbox_critical_after_seconds'))),
            ]),
            self::rule('OBS-12', 'Outbox reconciliation not succeeding.', 'B', 'REDIS-LOSS-RECOVERY.md', [
                self::tier(Severity::Sev2, "(time() - lycenza_reconciliation_last_success_timestamp_seconds{recovery_source=\"outbox\"}) > {$minuteStale} or absent(lycenza_reconciliation_last_success_timestamp_seconds{recovery_source=\"outbox\"}) or increase(lycenza_reconciliation_runs_total{recovery_source=\"outbox\",outcome=\"failure\"}[5m]) > 0", 0,
                    fn (MetricSnapshot $s) => ($s->age('lycenza_reconciliation_last_success_timestamp_seconds', ['recovery_source' => 'outbox']) ?? INF) > $minuteStale
                        || $s->increase('lycenza_reconciliation_runs_total', '5m', ['recovery_source' => 'outbox', 'outcome' => 'failure']) > 0),
            ]),
            self::rule('OBS-13', 'Stale unacknowledged outbox events persist beyond reconciliation.', 'B', 'REDIS-LOSS-RECOVERY.md', [
                self::tier(Severity::Sev3, 'lycenza_outbox_stale_events > 0', 1800, $gaugeAbove('lycenza_outbox_stale_events', 0)),
            ]),
            self::rule('OBS-14', 'Outbox events exhausted (failed, terminal).', 'B', 'REDIS-LOSS-RECOVERY.md', [
                self::tier(Severity::Sev2, 'delta(lycenza_outbox_failed_events[1h]) > 0', 0, fn (MetricSnapshot $s) => $s->increase('lycenza_outbox_failed_events', '1h') > 0),
            ]),
            self::rule('OBS-15', 'Webhook deliveries overdue (eligible, not picked up).', 'B', 'WEBHOOK-FAILURES.md', [
                self::tier(Severity::Sev3, "lycenza_webhook_oldest_overdue_age_seconds > {$warn}", 0, $gaugeAbove('lycenza_webhook_oldest_overdue_age_seconds', $warn)),
                self::tier(Severity::Sev2, "lycenza_webhook_oldest_overdue_age_seconds > {$high}", 0, $gaugeAbove('lycenza_webhook_oldest_overdue_age_seconds', $high)),
            ]),
            self::rule('OBS-16', 'Webhook deliveries failing or abandoned above the operator baseline.', 'C', 'WEBHOOK-FAILURES.md', $webhookFinal === null ? [] : [
                self::tier(Severity::Sev3, "sum(increase(lycenza_webhook_deliveries_finished_total{outcome=~\"failed|abandoned\"}[1h])) > {$webhookFinal}", 0,
                    fn (MetricSnapshot $s) => $s->increase('lycenza_webhook_deliveries_finished_total', '1h', ['outcome' => 'failed']) + $s->increase('lycenza_webhook_deliveries_finished_total', '1h', ['outcome' => 'abandoned']) > $webhookFinal),
            ], $webhookFinal === null ? 'operator value ALERT_WEBHOOK_FINAL_FAILURES_PER_HOUR not set' : null),
            self::rule('OBS-17', 'Communication deliveries overdue (eligible, not picked up).', 'B', 'COMMUNICATION-FAILURES.md', [
                self::tier(Severity::Sev3, "lycenza_communication_oldest_overdue_age_seconds > {$warn}", 0, $gaugeAbove('lycenza_communication_oldest_overdue_age_seconds', $warn)),
                self::tier(Severity::Sev2, "lycenza_communication_oldest_overdue_age_seconds > {$high}", 0, $gaugeAbove('lycenza_communication_oldest_overdue_age_seconds', $high)),
            ]),
            self::rule('OBS-18', 'Communication delivery failure ratio above the operator baseline.', 'C', 'COMMUNICATION-FAILURES.md', $commRatio === null ? [] : [
                self::tier(Severity::Sev3, "sum(increase(lycenza_communication_deliveries_finished_total{outcome=~\"failed|bounced|rejected\"}[1h])) / sum(increase(lycenza_communication_deliveries_finished_total[1h])) > {$commRatio}", 0,
                    function (MetricSnapshot $s) use ($commRatio) {
                        $total = $s->increase('lycenza_communication_deliveries_finished_total', '1h');
                        $failed = 0.0;
                        foreach (['failed', 'bounced', 'rejected'] as $outcome) {
                            $failed += $s->increase('lycenza_communication_deliveries_finished_total', '1h', ['outcome' => $outcome]);
                        }

                        return $total > 0 && $failed / $total > $commRatio;
                    }),
            ], $commRatio === null ? 'operator value ALERT_COMMUNICATION_FAILURE_RATIO_PER_HOUR not set' : null),
            self::rule('OBS-19', 'Automation recovery not running or executions overdue.', 'B', '../modules/AUTOMATION.md', [
                self::tier(Severity::Sev3, "(time() - lycenza_reconciliation_last_success_timestamp_seconds{recovery_source=\"automation\"}) > {$minuteStale} or lycenza_automation_oldest_overdue_age_seconds > {$warn}", 0,
                    fn (MetricSnapshot $s) => ($s->age('lycenza_reconciliation_last_success_timestamp_seconds', ['recovery_source' => 'automation']) ?? INF) > $minuteStale
                        || ($s->value('lycenza_automation_oldest_overdue_age_seconds') ?? 0) > $warn),
                self::tier(Severity::Sev2, "lycenza_automation_oldest_overdue_age_seconds > {$high}", 0, $gaugeAbove('lycenza_automation_oldest_overdue_age_seconds', $high)),
            ]),
            self::rule('OBS-20', 'PostgreSQL recovery point too old (protects RPO <= 15 min).', 'A', 'BACKUP-AND-RESTORE.md', [
                self::tier(Severity::Sev2, '(time() - lycenza_backup_recovery_point_timestamp_seconds{backup_store="postgresql"}) > 600 or absent(lycenza_backup_recovery_point_timestamp_seconds{backup_store="postgresql"})', 0, $ageAbove('lycenza_backup_recovery_point_timestamp_seconds', 600, ['backup_store' => 'postgresql'])),
                self::tier(Severity::Sev1, '(time() - lycenza_backup_recovery_point_timestamp_seconds{backup_store="postgresql"}) > 900', 0,
                    fn (MetricSnapshot $s) => ($s->age('lycenza_backup_recovery_point_timestamp_seconds', ['backup_store' => 'postgresql']) ?? 0) > 900),
            ]),
            self::rule('OBS-21', 'PostgreSQL base backup stale or failed.', 'A/C', 'BACKUP-AND-RESTORE.md', [
                self::tier(Severity::Sev2, '(time() - lycenza_backup_last_success_timestamp_seconds{backup_store="postgresql"}) > 93600 or absent(lycenza_backup_last_success_timestamp_seconds{backup_store="postgresql"}) or lycenza_backup_last_failure_timestamp_seconds{backup_store="postgresql"} > lycenza_backup_last_success_timestamp_seconds{backup_store="postgresql"}', 0,
                    fn (MetricSnapshot $s) => ($s->age('lycenza_backup_last_success_timestamp_seconds', ['backup_store' => 'postgresql']) ?? INF) > 26 * 3600
                        || ($s->value('lycenza_backup_last_failure_timestamp_seconds', ['backup_store' => 'postgresql']) ?? 0) > ($s->value('lycenza_backup_last_success_timestamp_seconds', ['backup_store' => 'postgresql']) ?? 0)),
                self::tier(Severity::Sev1, '(time() - lycenza_backup_last_success_timestamp_seconds{backup_store="postgresql"}) > 180000', 0,
                    fn (MetricSnapshot $s) => ($s->age('lycenza_backup_last_success_timestamp_seconds', ['backup_store' => 'postgresql']) ?? 0) > 50 * 3600),
            ]),
            self::rule('OBS-22', 'Independent object-storage copy stale or failed (protects RPO <= 24 h).', 'A', 'BACKUP-AND-RESTORE.md', [
                self::tier(Severity::Sev2, '(time() - lycenza_backup_last_success_timestamp_seconds{backup_store="objects"}) > 72000 or absent(lycenza_backup_last_success_timestamp_seconds{backup_store="objects"}) or lycenza_backup_last_failure_timestamp_seconds{backup_store="objects"} > lycenza_backup_last_success_timestamp_seconds{backup_store="objects"}', 0,
                    fn (MetricSnapshot $s) => ($s->age('lycenza_backup_last_success_timestamp_seconds', ['backup_store' => 'objects']) ?? INF) > 20 * 3600
                        || ($s->value('lycenza_backup_last_failure_timestamp_seconds', ['backup_store' => 'objects']) ?? 0) > ($s->value('lycenza_backup_last_success_timestamp_seconds', ['backup_store' => 'objects']) ?? 0)),
                self::tier(Severity::Sev1, '(time() - lycenza_backup_last_success_timestamp_seconds{backup_store="objects"}) > 86400', 0,
                    fn (MetricSnapshot $s) => ($s->age('lycenza_backup_last_success_timestamp_seconds', ['backup_store' => 'objects']) ?? 0) > 24 * 3600),
            ]),
            self::rule('OBS-23', 'Restore drill overdue or failed (quarterly contract).', 'A', 'RESTORE-DRILL-RECORD.md', [
                self::tier(Severity::Sev3, '(time() - lycenza_restore_drill_last_success_timestamp_seconds) > 7948800 or absent(lycenza_restore_drill_last_success_timestamp_seconds)', 0,
                    fn (MetricSnapshot $s) => ($s->age('lycenza_restore_drill_last_success_timestamp_seconds') ?? INF) > 92 * self::DAY),
                self::tier(Severity::Sev2, '(time() - lycenza_restore_drill_last_success_timestamp_seconds) > 10368000 or lycenza_restore_drill_last_result == 0', 0,
                    fn (MetricSnapshot $s) => ($s->age('lycenza_restore_drill_last_success_timestamp_seconds') ?? 0) > 120 * self::DAY || $s->value('lycenza_restore_drill_last_result') === 0.0),
            ]),
            self::rule('OBS-24', 'Object-storage validation failing.', $storageFailures === null ? 'B' : 'B/C', 'BACKUP-AND-RESTORE.md', self::present([
                self::tier(Severity::Sev2, 'lycenza_verification_last_result{check="verify_storage"} == 0', 0, fn (MetricSnapshot $s) => $s->value('lycenza_verification_last_result', ['check' => 'verify_storage']) === 0.0),
                $storageFailures === null ? null : self::tier(Severity::Sev2, "sum(increase(lycenza_storage_operation_failures_total[15m])) > {$storageFailures}", 0, fn (MetricSnapshot $s) => $s->increase('lycenza_storage_operation_failures_total', '15m') > $storageFailures, 'FailureRate'),
            ])),
            self::rule('OBS-25', 'Security-boundary stress (401/403/429 and partner auth failures above baseline).', 'C', '../security/INTEGRATION-SECURITY.md', $securityRejections === null ? [] : [
                self::tier(Severity::Sev3, "sum(increase(lycenza_http_rejections_total{code=~\"401|403|429\"}[15m])) + sum(increase(lycenza_partner_auth_failures_total[15m])) > {$securityRejections}", 0,
                    function (MetricSnapshot $s) use ($securityRejections) {
                        $total = $s->increase('lycenza_partner_auth_failures_total', '15m');
                        foreach (['401', '403', '429'] as $code) {
                            $total += $s->increase('lycenza_http_rejections_total', '15m', ['code' => $code]);
                        }

                        return $total > $securityRejections;
                    }),
            ], $securityRejections === null ? 'operator value ALERT_SECURITY_REJECTIONS_PER_15M not set' : null),
            self::rule('OBS-26', 'Telemetry collection failing.', 'C', 'TELEMETRY-COLLECTION.md', [
                self::tier(Severity::Sev2, 'up{job="lycenza-metrics"} == 0 or increase(lycenza_metrics_collection_errors_total[15m]) > 0', $telemetryFor ?? 300,
                    fn (MetricSnapshot $s) => ! $s->scrapeUp || $s->increase('lycenza_metrics_collection_errors_total', '15m') > 0),
            ]),
            // ADR 0053 (Phase 0O.7A): service-to-service authentication. The AI
            // Gateway is optional, so this never pages (SEV-3 only).
            self::rule('OBS-27', 'Service-to-service authentication failing, or a service key near its 90-day limit.', 'A', 'SERVICE-KEY-ROTATION.md', [
                self::tier(Severity::Sev3, 'sum(increase(lycenza_service_auth_total{outcome=~"unknown_kid|bad_signature|key_expired|rejected_by_receiver"}[15m])) > 0', 0,
                    fn (MetricSnapshot $s) => array_sum(array_map(fn (string $o) => $s->increase('lycenza_service_auth_total', '15m', ['outcome' => $o]), ['unknown_kid', 'bad_signature', 'key_expired', 'rejected_by_receiver'])) > 0),
                self::tier(Severity::Sev3, 'max(lycenza_service_signing_key_age_days) >= 76 or max(lycenza_service_verification_key_max_age_days) >= 76', 0,
                    fn (MetricSnapshot $s) => max(self::maxOf($s, 'lycenza_service_signing_key_age_days'), self::maxOf($s, 'lycenza_service_verification_key_max_age_days')) >= 76, 'KeyAge'),
            ]),
            // ADR 0054 (Phase 0O.8A): custom School domains. One School's domain
            // is never the platform: nothing here pages as SEV-1, and none of
            // it touches readiness.
            self::rule('OBS-28', 'A custom School domain was suspended (ownership, routing or TLS drift confirmed).', 'A', 'CUSTOM-DOMAINS.md', [
                self::tier(Severity::Sev3, 'sum(increase(lycenza_domain_transitions_total{to="suspended"}[1h])) > 0', 0,
                    fn (MetricSnapshot $s) => $s->increase('lycenza_domain_transitions_total', '1h', ['to' => 'suspended']) > 0),
            ]),
            self::rule('OBS-29', 'An active custom domain\'s certificate is close to expiry (edge renewal not happening).', 'A', 'CUSTOM-DOMAINS.md', [
                self::tier(Severity::Sev3, 'min(lycenza_domain_certificate_min_days_remaining) <= 21', 0,
                    fn (MetricSnapshot $s) => ($s->value('lycenza_domain_certificate_min_days_remaining') ?? INF) <= 21),
                self::tier(Severity::Sev2, 'min(lycenza_domain_certificate_min_days_remaining) <= 7', 0,
                    fn (MetricSnapshot $s) => ($s->value('lycenza_domain_certificate_min_days_remaining') ?? INF) <= 7),
            ]),
            self::rule('OBS-30', 'Custom-domain checks indeterminate for 3 days (resolver or network trouble; never suspends).', 'A', 'CUSTOM-DOMAINS.md', [
                self::tier(Severity::Sev3, 'max(lycenza_domain_indeterminate_max_age_seconds) >= 259200', 0,
                    fn (MetricSnapshot $s) => ($s->value('lycenza_domain_indeterminate_max_age_seconds') ?? 0) >= 3 * self::DAY),
            ]),
            // ADR 0055 (Phase 0O.9A): production email. Email is optional for
            // readiness: nothing here pages as SEV-1.
            self::rule('OBS-31', 'Critical email (account invitations) waiting too long to reach the provider.', 'B', 'EMAIL-DELIVERABILITY.md', [
                self::tier(Severity::Sev2, "max(lycenza_email_oldest_pending_age_seconds{message_class=~\"{$criticalClasses}\"}) > {$high}", 0,
                    fn (MetricSnapshot $s) => max(array_map(fn (string $c) => $s->value('lycenza_email_oldest_pending_age_seconds', ['message_class' => $c]) ?? 0, $criticalList)) > $high),
            ]),
            self::rule('OBS-32', 'Standard email (School communications) backlog too old.', 'B', 'EMAIL-DELIVERABILITY.md', [
                self::tier(Severity::Sev3, "max(lycenza_email_oldest_pending_age_seconds{message_class=\"school_communication\"}) > {$high}", 0,
                    fn (MetricSnapshot $s) => ($s->value('lycenza_email_oldest_pending_age_seconds', ['message_class' => 'school_communication']) ?? 0) > $high),
            ]),
            self::rule('OBS-33', 'Email submission failure ratio above the operator baseline.', 'C', 'EMAIL-DELIVERABILITY.md', $emailRatio === null ? [] : [
                self::tier(Severity::Sev3, "sum(increase(lycenza_email_submission_attempts_total{outcome=~\"transient_failure|permanent_failure\"}[1h])) / sum(increase(lycenza_email_submission_attempts_total[1h])) > {$emailRatio}", 0,
                    function (MetricSnapshot $s) use ($emailRatio) {
                        $total = $s->increase('lycenza_email_submission_attempts_total', '1h');
                        $failed = $s->increase('lycenza_email_submission_attempts_total', '1h', ['outcome' => 'transient_failure']) + $s->increase('lycenza_email_submission_attempts_total', '1h', ['outcome' => 'permanent_failure']);

                        return $total > 0 && $failed / $total > $emailRatio;
                    }),
            ], $emailRatio === null ? 'operator value ALERT_EMAIL_FAILURE_RATIO_PER_HOUR not set' : null),
            self::rule('OBS-34', 'Email provider refusing the configured credentials or TLS (sending paused).', 'A', 'EMAIL-DELIVERABILITY.md', [
                self::tier(Severity::Sev2, 'sum(increase(lycenza_email_submission_attempts_total{outcome="auth_failure"}[15m])) > 0', 0,
                    fn (MetricSnapshot $s) => $s->increase('lycenza_email_submission_attempts_total', '15m', ['outcome' => 'auth_failure']) > 0),
            ]),
            self::rule('OBS-35', 'No provider email events while email is being submitted (event feed stale).', 'A', 'EMAIL-DELIVERABILITY.md', [
                self::tier(Severity::Sev3, '(time() - max(lycenza_email_last_event_timestamp_seconds)) > 86400 and sum(increase(lycenza_email_messages_total{outcome="submitted"}[1h])) > 0', 0,
                    fn (MetricSnapshot $s) => $s->value('lycenza_email_last_event_timestamp_seconds') !== null
                        && ($s->age('lycenza_email_last_event_timestamp_seconds') ?? INF) > self::DAY
                        && $s->increase('lycenza_email_messages_total', '1h', ['outcome' => 'submitted']) > 0),
            ]),
            self::rule('OBS-36', 'Hard-bounce spike above the operator baseline (list quality or a sending problem).', 'C', 'EMAIL-DELIVERABILITY.md', $emailHardBounces === null ? [] : [
                self::tier(Severity::Sev3, "sum(increase(lycenza_email_messages_total{outcome=\"bounced\"}[1h])) > {$emailHardBounces}", 0,
                    fn (MetricSnapshot $s) => $s->increase('lycenza_email_messages_total', '1h', ['outcome' => 'bounced']) > $emailHardBounces),
            ], $emailHardBounces === null ? 'operator value ALERT_EMAIL_HARD_BOUNCES_PER_HOUR not set' : null),
            self::rule('OBS-37', 'Complaint spike above the operator baseline (sending reputation at risk).', 'C', 'EMAIL-DELIVERABILITY.md', $emailComplaints === null ? [] : [
                self::tier(Severity::Sev2, "sum(increase(lycenza_email_messages_total{outcome=\"complained\"}[1h])) > {$emailComplaints}", 0,
                    fn (MetricSnapshot $s) => $s->increase('lycenza_email_messages_total', '1h', ['outcome' => 'complained']) > $emailComplaints),
            ], $emailComplaints === null ? 'operator value ALERT_EMAIL_COMPLAINTS_PER_HOUR not set' : null),
            self::rule('OBS-38', 'Email provider-event webhook authentication failures above the operator baseline.', 'C', 'EMAIL-DELIVERABILITY.md', $emailWebhookAuth === null ? [] : [
                self::tier(Severity::Sev3, "sum(increase(lycenza_email_webhook_requests_total{outcome=\"unauthenticated\"}[15m])) > {$emailWebhookAuth}", 0,
                    fn (MetricSnapshot $s) => $s->increase('lycenza_email_webhook_requests_total', '15m', ['outcome' => 'unauthenticated']) > $emailWebhookAuth),
            ], $emailWebhookAuth === null ? 'operator value ALERT_EMAIL_WEBHOOK_AUTH_FAILURES_PER_15M not set' : null),
            // ADR 0056 (Phase 0O.10A): account recovery. Never SEV-1, never readiness.
            self::rule('OBS-39', 'Account-recovery request spike above the operator baseline (enumeration or abuse attempt).', 'C', 'ACCOUNT-RECOVERY.md', $recoveryRequests === null ? [] : [
                self::tier(Severity::Sev3, "sum(increase(lycenza_account_recovery_requests_total[1h])) > {$recoveryRequests}", 0,
                    fn (MetricSnapshot $s) => $s->increase('lycenza_account_recovery_requests_total', '1h') > $recoveryRequests),
            ], $recoveryRequests === null ? 'operator value ALERT_ACCOUNT_RECOVERY_REQUESTS_PER_HOUR not set' : null),
            self::rule('OBS-40', 'Invalid password-reset submissions above the operator baseline.', 'C', 'ACCOUNT-RECOVERY.md', $recoveryInvalid === null ? [] : [
                self::tier(Severity::Sev3, "sum(increase(lycenza_account_recovery_resets_total{outcome=\"invalid\"}[1h])) > {$recoveryInvalid}", 0,
                    fn (MetricSnapshot $s) => $s->increase('lycenza_account_recovery_resets_total', '1h', ['outcome' => 'invalid']) > $recoveryInvalid),
            ], $recoveryInvalid === null ? 'operator value ALERT_ACCOUNT_RECOVERY_INVALID_RESETS_PER_HOUR not set' : null),
            self::rule('OBS-41', 'Account recovery is enabled but critical email is unavailable (no recovery email can be sent).', 'A', 'ACCOUNT-RECOVERY.md', [
                self::tier(Severity::Sev2, 'max(lycenza_account_recovery_enabled{state="enabled"}) == 1 and max(lycenza_account_recovery_enabled{state="available"}) == 0', 300,
                    fn (MetricSnapshot $s) => $s->value('lycenza_account_recovery_enabled', ['state' => 'enabled']) === 1.0 && $s->value('lycenza_account_recovery_enabled', ['state' => 'available']) === 0.0),
            ]),
        ];
    }

    /**
     * An operator value from `observability.alerts.{key}`: null (unset:
     * the tier is disabled, explicitly) or a number within [$min, $max].
     */
    public static function operator(string $key, float $min, float $max): ?float
    {
        $raw = config("observability.alerts.{$key}");

        if ($raw === null || $raw === '') {
            return null;
        }

        if (! is_numeric($raw) || (float) $raw < $min || (float) $raw > $max) {
            throw new InvalidAlertThreshold("observability.alerts.{$key} must be a number between {$min} and {$max}.");
        }

        return (float) $raw;
    }

    /** @param list<array{severity: Severity, expr: string, for: string, when: \Closure, suffix: string}> $tiers */
    private static function rule(string $id, string $summary, string $source, string $runbook, array $tiers, ?string $disabled = null): AlertRule
    {
        return new AlertRule($id, $summary, $source, $runbook, $tiers, $tiers === [] ? ($disabled ?? 'no enabled tier') : null);
    }

    /**
     * @param  string  $suffix  distinguishes two tiers of the SAME severity (alert names must be unique)
     * @return array{severity: Severity, expr: string, for: string, when: \Closure, suffix: string}
     */
    private static function tier(Severity $severity, string $expr, float $forSeconds, \Closure $when, string $suffix = ''): array
    {
        return ['severity' => $severity, 'expr' => $expr, 'for' => ((int) $forSeconds).'s', 'when' => $when, 'suffix' => $suffix];
    }

    /**
     * @param  array<int, array{severity: Severity, expr: string, for: string, when: \Closure, suffix: string}|null>  $tiers
     * @return list<array{severity: Severity, expr: string, for: string, when: \Closure, suffix: string}>
     */
    private static function present(array $tiers): array
    {
        return array_values(array_filter($tiers, fn ($tier) => $tier !== null));
    }

    private static function maxOf(MetricSnapshot $s, string $name): float
    {
        $values = array_column($s->all($name), 'value');

        return $values === [] ? 0.0 : (float) max($values);
    }
}
