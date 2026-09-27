<?php

namespace Tests\Feature\CustomDomains;

use App\Models\School;
use App\Models\User;
use App\Support\Auth\CrossHostHandoff;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Phase 0O.8A (ADR 0054 amendment): a cross-host sign-in ticket is redeemed
 * ONCE across every web replica, through the shared production Redis store.
 * Eight separate OS processes are held at a file barrier and released
 * together on the same ticket against the REAL Redis: exactly one redeems.
 * The deadlines only bound a broken run; the property is Redis's atomic
 * SET NX (Cache::add), not timing.
 */
class CrossHostHandoffConcurrencyTest extends TestCase
{
    private const CONTENDERS = 8;

    #[Test]
    public function concurrent_replicas_redeem_one_ticket_exactly_once(): void
    {
        config(['domains.handoff_store' => 'redis']);
        $user = new User;
        $user->id = (string) Str::uuid7();
        $school = new School;
        $school->id = (string) Str::uuid7();
        $ticket = app(CrossHostHandoff::class)->issue($user, 'source-session-id', $school, 'erp.northfield.org', null);

        $dir = sys_get_temp_dir().'/handoff-race-'.bin2hex(random_bytes(6));
        mkdir($dir);
        $processes = [];
        for ($n = 0; $n < self::CONTENDERS; $n++) {
            $process = new Process(['php', base_path('tests/Support/consume-session-handoff.php'), $dir, (string) $n, $ticket]);
            $process->setTimeout(120);
            $process->start();
            $processes[] = $process;
        }

        try {
            $deadline = microtime(true) + 90;
            while (count(glob("{$dir}/ready-*") ?: []) < self::CONTENDERS) {
                $this->assertLessThan($deadline, microtime(true), 'contenders never reached the barrier');
                foreach ($processes as $process) {
                    $this->assertTrue($process->isRunning() || $process->isSuccessful(), $process->getErrorOutput());
                }
                usleep(1000);
            }
            touch("{$dir}/go");

            $outcomes = [];
            foreach ($processes as $process) {
                $process->wait();
                $outcomes[] = trim($process->getOutput());
            }
        } finally {
            foreach ($processes as $process) {
                $process->stop(0);
            }
            array_map('unlink', glob("{$dir}/*") ?: []);
            rmdir($dir);
        }

        sort($outcomes);
        $this->assertSame(['redeemed', ...array_fill(0, self::CONTENDERS - 1, 'refused')], $outcomes);

        // The stored ticket is gone; only its short-lived `used` marker remains,
        // keyed by a digest -- never the ticket itself.
        $key = 'session-handoff:'.hash('sha256', $ticket);
        $redis = Cache::store('redis');
        $this->assertNull($redis->get($key));
        $ttl = $redis->getStore()->connection()->ttl($redis->getStore()->getPrefix().$key.':used');
        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(CrossHostHandoff::LIFETIME_SECONDS, $ttl);
        $redis->forget($key.':used');
    }

    #[Test]
    public function a_ticket_lives_at_most_sixty_seconds_in_the_real_store(): void
    {
        config(['domains.handoff_store' => 'redis']);
        $user = new User;
        $user->id = (string) Str::uuid7();
        $ticket = app(CrossHostHandoff::class)->issue($user, 'source-session-id', null, 'app.lycenza-platform.com', null);

        $redis = Cache::store('redis');
        $key = 'session-handoff:'.hash('sha256', $ticket);
        $ttl = $redis->getStore()->connection()->ttl($redis->getStore()->getPrefix().$key);
        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(60, $ttl);
        $this->assertStringNotContainsString($ticket, (string) json_encode($redis->get($key)), 'the store holds a digest-keyed record, never the ticket');
        $redis->forget($key);
    }
}
