<?php

namespace Tests\Support;

use Symfony\Component\Process\Process;

/**
 * A tiny, real, separate-process HTTP receiver for webhook Proof B
 * (Phase 0C.3 section 58) -- behaves like a genuinely independent
 * third-party application, not a Laravel test double. Binds
 * 127.0.0.1 only; reachable at all only because
 * WEBHOOKS_ALLOW_LOOPBACK_FOR_TESTS is set for the duration of the
 * test (see start()), itself double-guarded by SsrfSafeUrlValidator's
 * environment(['local','testing']) check.
 *
 * Configurable per-request response behaviour (section 59) via a
 * `plan.json` file the receiver script reads on every request: each
 * entry describes how to answer the Nth request (1-indexed); the last
 * entry repeats for any request beyond the plan's length. Every
 * request is also durably logged to `deliveries.jsonl` so the test can
 * assert on headers/body/count afterward.
 */
class LocalWebhookReceiver
{
    private ?Process $process = null;

    private readonly string $dir;

    private readonly int $port;

    public function __construct()
    {
        $this->port = random_int(20000, 40000);
        $this->dir = sys_get_temp_dir().'/school-os-webhook-receiver-'.uniqid();
        mkdir($this->dir);
        touch($this->logFile());
        $this->setPlan([['status' => 200]]);

        file_put_contents($this->dir.'/receive.php', <<<'PHP'
            <?php
            $dir = __DIR__;
            $countFile = $dir.'/count.txt';
            $count = ((int) @file_get_contents($countFile)) + 1;
            file_put_contents($countFile, (string) $count);

            $plan = json_decode(file_get_contents($dir.'/plan.json'), true);
            $step = $plan[min($count, count($plan)) - 1];

            $body = file_get_contents('php://input');
            $entry = [
                'attempt' => $count,
                'headers' => [
                    'signature' => $_SERVER['HTTP_X_SCHOOLOS_SIGNATURE'] ?? null,
                    'signature_version' => $_SERVER['HTTP_X_SCHOOLOS_SIGNATURE_VERSION'] ?? null,
                    'timestamp' => $_SERVER['HTTP_X_SCHOOLOS_TIMESTAMP'] ?? null,
                    'delivery_id' => $_SERVER['HTTP_X_SCHOOLOS_DELIVERY_ID'] ?? null,
                    'event_id' => $_SERVER['HTTP_X_SCHOOLOS_EVENT_ID'] ?? null,
                    'event_type' => $_SERVER['HTTP_X_SCHOOLOS_EVENT_TYPE'] ?? null,
                ],
                'body' => $body,
            ];
            file_put_contents($dir.'/deliveries.jsonl', json_encode($entry)."\n", FILE_APPEND);

            if (isset($step['sleep_ms'])) {
                usleep($step['sleep_ms'] * 1000);
            }

            if (isset($step['redirect_to'])) {
                header('Location: '.$step['redirect_to']);
                http_response_code($step['status'] ?? 302);
                exit;
            }

            if (isset($step['retry_after'])) {
                header('Retry-After: '.$step['retry_after']);
            }

            http_response_code($step['status'] ?? 200);
            echo json_encode(['received' => true]);
            PHP
        );

        $this->process = new Process(['php', '-S', "127.0.0.1:{$this->port}", '-t', $this->dir]);
        $this->process->start();

        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline && ! @fsockopen('127.0.0.1', $this->port)) {
            usleep(50_000);
        }

        config(['webhooks.allow_loopback_for_tests' => true]);
    }

    public function url(): string
    {
        return "http://127.0.0.1:{$this->port}/receive.php";
    }

    /**
     * @param  array<int, array{status?: int, retry_after?: int|string, sleep_ms?: int, redirect_to?: string}>  $steps
     */
    public function setPlan(array $steps): void
    {
        file_put_contents($this->dir.'/plan.json', json_encode($steps));
    }

    public function requestCount(): int
    {
        return count(array_filter(explode("\n", trim(file_get_contents($this->logFile())))));
    }

    /**
     * @return array<int, array{attempt: int, headers: array<string, string|null>, body: string}>
     */
    public function deliveries(): array
    {
        $lines = array_filter(explode("\n", trim(file_get_contents($this->logFile()))));

        return array_map(fn (string $line) => json_decode($line, true), $lines);
    }

    public function stop(): void
    {
        $this->process?->stop();
    }

    private function logFile(): string
    {
        return $this->dir.'/deliveries.jsonl';
    }
}
