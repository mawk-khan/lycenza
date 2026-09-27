<?php

namespace Tests\Unit\Domains;

use App\Support\Domains\Dns\DnsLookup;
use App\Support\Domains\Dns\NetDns2DomainResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Phase 0O.8A (ADR 0054 section 4.5): the production resolver on the real
 * DNS wire format, against a LOCAL responder (tests/Support/fake-dns-server.php,
 * loopback only -- no external network): split TXT character-strings,
 * several RRs, NXDOMAIN vs NODATA (absent) vs SERVFAIL/REFUSED/timeout
 * (indeterminate, never absent), CNAME chains, loops, depth and the RR cap.
 */
class NetDns2DomainResolverTest extends TestCase
{
    private const TOKEN = 'k7Qm0bY4n2XcV9sLp1aZr8tWd3eFh6gJu5iOy0PqRsT';

    private static ?Process $server = null;

    private static int $port = 0;

    public static function setUpBeforeClass(): void
    {
        $portFile = sys_get_temp_dir().'/dns-port-'.bin2hex(random_bytes(4));
        self::$server = new Process(['php', dirname(__DIR__, 2).'/Support/fake-dns-server.php', $portFile, self::TOKEN]);
        self::$server->start();

        $deadline = microtime(true) + 20;
        while (! is_file($portFile) || (int) file_get_contents($portFile) === 0) {
            if (microtime(true) > $deadline || ! self::$server->isRunning()) {
                throw new \RuntimeException('local DNS responder did not start: '.self::$server->getErrorOutput());
            }
            usleep(10_000);
        }
        self::$port = (int) file_get_contents($portFile);
        unlink($portFile);
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop(0);
    }

    private function resolver(): NetDns2DomainResolver
    {
        return new NetDns2DomainResolver(['127.0.0.1'], self::$port);
    }

    #[Test]
    public function txt_character_strings_are_joined_and_every_rr_is_returned(): void
    {
        $value = 'lycenza-domain-verification='.self::TOKEN;

        $this->assertSame([$value], $this->resolver()->txt('txt-ok.test')->txt);
        $this->assertSame([$value], $this->resolver()->txt('txt-split.test')->txt, 'two character-strings, one logical value');
        $this->assertSame(['v=spf1 -all', $value.'x', 'other'], $this->resolver()->txt('txt-multi.test')->txt);
        $this->assertSame([$value], $this->resolver()->txt('txt-via-cname.test')->txt, 'a CNAME at the record name is followed within bounds');
    }

    #[Test]
    public function absent_and_indeterminate_are_distinguished_by_response_code(): void
    {
        $nx = $this->resolver()->txt('missing.test');
        $this->assertTrue($nx->isAbsent());
        $this->assertSame('nxdomain', $nx->reason);

        $nodata = $this->resolver()->txt('nodata.test');
        $this->assertTrue($nodata->isAbsent());
        $this->assertSame('nodata', $nodata->reason);

        foreach (['servfail.test' => 'servfail', 'refused.test' => 'refused'] as $name => $reason) {
            $lookup = $this->resolver()->txt($name);
            $this->assertTrue($lookup->isIndeterminate(), $name);
            $this->assertSame($reason, $lookup->reason);
        }
    }

    #[Test]
    public function a_silent_resolver_is_indeterminate_within_the_bounds_never_absent(): void
    {
        $started = microtime(true);
        $lookup = $this->resolver()->txt('silent.test');
        $took = microtime(true) - $started;

        $this->assertTrue($lookup->isIndeterminate());
        $this->assertLessThanOrEqual(NetDns2DomainResolver::BUDGET_SECONDS + 1, $took, '10 s per check at most');
        $this->assertLessThanOrEqual(NetDns2DomainResolver::ATTEMPTS * NetDns2DomainResolver::QUERY_TIMEOUT_SECONDS + 1.5, $took, '2 attempts x 2 s');
    }

    #[Test]
    public function addresses_follow_the_cname_chain_and_refuse_loops_depth_and_floods(): void
    {
        $apex = $this->resolver()->addresses('apex.test');
        $this->assertTrue($apex->isOk());
        $this->assertSame(['apex.test'], $apex->chain);
        $this->assertSame(['1.2.3.4', '1.2.3.5', '2606:4700::6810:84e5'], $apex->addresses);

        $cname = $this->resolver()->addresses('cname.test');
        $this->assertSame(['cname.test', 'edge.lycenza-cdn.test'], $cname->chain);
        $this->assertSame(['1.2.3.4'], $cname->addresses);

        $this->assertSame('cname_loop', $this->resolver()->addresses('loop.test')->reason);
        $this->assertTrue($this->resolver()->addresses('loop.test')->isIndeterminate());
        $this->assertSame('cname_depth', $this->resolver()->addresses('deep.test')->reason, 'more than 8 CNAME hops');
        $this->assertSame('too_many_records', $this->resolver()->txt('txt-many.test')->reason, 'more than 32 TXT RRs');
        $this->assertTrue($this->resolver()->addresses('missing.test')->isAbsent());
    }

    #[Test]
    public function no_configured_resolver_is_indeterminate(): void
    {
        $this->assertEquals(DnsLookup::indeterminate('not_configured'), (new NetDns2DomainResolver([]))->txt('txt-ok.test'));
    }
}
