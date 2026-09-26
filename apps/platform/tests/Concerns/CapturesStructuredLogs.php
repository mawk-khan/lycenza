<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;

/**
 * Phase 0O.5A: captures what the PRODUCTION log pipeline actually emits.
 * The capture channel uses the same taps as the production `stderr`
 * channel, with the production (JSON) format selected, and a Monolog
 * TestHandler instead of the stream -- so assertions see the final
 * formatted lines, after every processor and formatter ran.
 */
trait CapturesStructuredLogs
{
    protected function captureLogs(string $format = 'json'): void
    {
        config([
            'observability.logging.format' => $format,
            'logging.channels.capture' => [
                'driver' => 'monolog',
                'handler' => TestHandler::class,
                'tap' => config('logging.channels.stderr.tap', []),
                'level' => 'debug',
            ],
            'logging.default' => 'capture',
        ]);

        app('log')->forgetChannel('capture');
        app('log')->forgetChannel();
    }

    protected function captureHandler(): TestHandler
    {
        foreach (Log::channel('capture')->getLogger()->getHandlers() as $handler) {
            if ($handler instanceof TestHandler) {
                return $handler;
            }
        }

        $this->fail('capture handler missing');
    }

    /** Every captured line exactly as the production formatter wrote it. */
    protected function capturedOutput(): string
    {
        return implode('', array_map(fn ($record) => (string) $record->formatted, $this->captureHandler()->getRecords()));
    }

    /** @return list<array<string, mixed>> */
    protected function capturedJson(): array
    {
        return array_map(function ($record) {
            $decoded = json_decode(trim((string) $record->formatted), true);
            $this->assertIsArray($decoded, 'every production log line is one JSON object: '.$record->formatted);

            return $decoded;
        }, $this->captureHandler()->getRecords());
    }

    protected function assertNoCanaryLogged(string ...$canaries): void
    {
        $output = $this->capturedOutput();
        $this->assertNotSame('', $output, 'nothing was logged -- the proof would be vacuous');

        foreach ($canaries as $canary) {
            $this->assertStringNotContainsString($canary, $output);
        }
    }
}
