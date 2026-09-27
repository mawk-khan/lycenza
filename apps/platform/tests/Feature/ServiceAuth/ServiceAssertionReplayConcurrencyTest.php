<?php

namespace Tests\Feature\ServiceAuth;

use App\Support\ServiceAuth\Base64Url;
use App\Support\ServiceAuth\ServiceAssertionReplayGuard;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * ADR 0053 section 5.5 (Phase 0O.7A): Laravel consumes each assertion's jti
 * ONCE across every web replica, through the shared production Redis store.
 * Eight separate OS processes -- stand-ins for replicas -- are held at a file
 * barrier and released together on the same jti against the REAL Redis:
 * exactly one is accepted, every other is refused as a replay. The deadlines
 * only bound a broken run; the property is Redis's atomic SET NX, not timing.
 */
class ServiceAssertionReplayConcurrencyTest extends TestCase
{
    private const CONTENDERS = 8;

    #[Test]
    public function concurrent_replicas_accept_one_jti_exactly_once(): void
    {
        $dir = sys_get_temp_dir().'/jti-race-'.bin2hex(random_bytes(6));
        mkdir($dir);
        $jti = Base64Url::encode(random_bytes(16));
        $exp = time() + 60;

        $processes = [];
        for ($n = 0; $n < self::CONTENDERS; $n++) {
            $process = new Process(['php', base_path('tests/Support/consume-service-assertion-jti.php'), $dir, (string) $n, $jti, (string) $exp]);
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
        $this->assertSame(['accepted', ...array_fill(0, self::CONTENDERS - 1, 'rejected:replayed')], $outcomes);

        // The key is bounded: a digest of issuer + jti (never the jti, a School,
        // User, body or secret) that lives only until exp + skew.
        $key = ServiceAssertionReplayGuard::PREFIX.'ai-gateway:'.hash('sha256', $jti);
        $redis = Cache::store('redis');
        $this->assertTrue($redis->has($key));
        $ttl = $redis->getStore()->connection()->ttl($redis->getStore()->getPrefix().$key);
        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(90, $ttl, 'exp (<= 60 s away) + 30 s skew');
        $redis->forget($key);
    }
}
