<?php

namespace Tests\Unit\Domains;

use App\Support\Domains\Probe\ProbeProof;
use App\Support\Domains\Probe\ProbeResult;
use App\Support\Domains\Probe\StreamDomainProber;
use Illuminate\Config\Repository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Phase 0O.8A (ADR 0054 section 6.2): the production TLS readiness probe
 * against a LOCAL TLS "edge" (tests/Support/tls-probe-server.php) with
 * throwaway certificates from a throwaway CA -- no public CA, provider or
 * network. The only test-only input is WHICH CA file is trusted; nothing
 * about verification can be switched off.
 */
class StreamDomainProberTest extends TestCase
{
    private const HOST = 'erp.northfield.org';

    /** A throwaway key, assembled at run time (no secret-shaped literal in the source). */
    private const PROBE_KEY_PART = 'unit-test-probe-';

    private static string $dir = '';

    private static function probeKey(): string
    {
        return str_repeat(self::PROBE_KEY_PART, 3);
    }

    /** @var list<Process> */
    private array $servers = [];

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir().'/probe-pki-'.bin2hex(random_bytes(4));
        mkdir(self::$dir);
        $d = self::$dir;

        file_put_contents("{$d}/ca.cnf", <<<CNF
            [ ca ]
            default_ca = test_ca
            [ test_ca ]
            database = {$d}/index.txt
            new_certs_dir = {$d}
            serial = {$d}/serial
            default_md = sha256
            policy = any
            copy_extensions = copy
            unique_subject = no
            [ any ]
            commonName = supplied
            [ leaf ]
            basicConstraints = CA:FALSE
            keyUsage = digitalSignature, keyEncipherment
            extendedKeyUsage = serverAuth
            CNF);
        touch("{$d}/index.txt");
        file_put_contents("{$d}/serial", "1000\n");

        self::openssl(['req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', "{$d}/ca.key", '-out', "{$d}/ca.crt", '-days', '2', '-subj', '/CN=Lycenza Throwaway Test CA']);
        self::openssl(['req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', "{$d}/rogue-ca.key", '-out', "{$d}/rogue-ca.crt", '-days', '2', '-subj', '/CN=Untrusted Rogue CA']);

        self::leaf('valid', self::HOST, 'ca', '-days', '30');
        self::leaf('expired', self::HOST, 'ca', '-startdate', '20200101000000Z', '-enddate', '20200201000000Z');
        self::leaf('future', self::HOST, 'ca', '-startdate', '20990101000000Z', '-enddate', '20990201000000Z');
        self::leaf('other-name', 'erp.someone-else.org', 'ca', '-days', '30');
        self::leaf('untrusted', self::HOST, 'rogue-ca', '-days', '30');
    }

    public static function tearDownAfterClass(): void
    {
        array_map('unlink', glob(self::$dir.'/*') ?: []);
        @rmdir(self::$dir);
    }

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $server->stop(0);
        }
    }

    /** @param list<string> $args */
    private static function openssl(array $args): void
    {
        $process = new Process(['openssl', ...$args]);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new \RuntimeException('openssl '.implode(' ', $args).': '.$process->getErrorOutput());
        }
    }

    private static function leaf(string $name, string $host, string $ca, string ...$validity): void
    {
        $d = self::$dir;
        self::openssl(['req', '-newkey', 'rsa:2048', '-nodes', '-keyout', "{$d}/{$name}.key", '-out', "{$d}/{$name}.csr", '-subj', "/CN={$host}", '-addext', "subjectAltName=DNS:{$host}"]);
        self::openssl(['ca', '-batch', '-config', "{$d}/ca.cnf", '-extensions', 'leaf', '-cert', "{$d}/{$ca}.crt", '-keyfile', "{$d}/{$ca}.key", '-in', "{$d}/{$name}.csr", '-out', "{$d}/{$name}.crt", '-notext', ...$validity]);
    }

    private function serve(string $leaf, string $mode): int
    {
        $portFile = self::$dir.'/port-'.bin2hex(random_bytes(4));
        $server = new Process(['php', dirname(__DIR__, 2).'/Support/tls-probe-server.php', $portFile, self::$dir."/{$leaf}.crt", self::$dir."/{$leaf}.key", $mode, self::probeKey()]);
        $server->start();
        $this->servers[] = $server;

        $deadline = microtime(true) + 20;
        while (! is_file($portFile) || (int) file_get_contents($portFile) === 0) {
            if (microtime(true) > $deadline || ! $server->isRunning()) {
                $this->fail('local TLS edge did not start: '.$server->getErrorOutput());
            }
            usleep(10_000);
        }

        return (int) file_get_contents($portFile);
    }

    private function probe(string $leaf, string $mode, ?string $key = null): ProbeResult
    {
        $prober = new StreamDomainProber(new ProbeProof(new Repository(['domains' => ['probe_key' => $key ?? self::probeKey()]])), $this->serve($leaf, $mode), self::$dir.'/ca.crt');

        return $prober->probe(self::HOST, ['127.0.0.1']);
    }

    #[Test]
    public function a_trusted_valid_exact_name_certificate_and_the_right_proof_pass_with_public_evidence_only(): void
    {
        $result = $this->probe('valid', 'proof');

        $this->assertSame(ProbeResult::PASS, $result->outcome, $result->reason);
        $this->assertNotNull($result->notAfter);
        $this->assertTrue($result->notAfter->isFuture());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $result->fingerprint);
        $this->assertSame('Lycenza Throwaway Test CA', $result->issuer);
    }

    #[Test]
    public function every_tls_failure_is_tls_invalid(): void
    {
        foreach (['expired', 'future', 'other-name', 'untrusted'] as $leaf) {
            $result = $this->probe($leaf, 'proof');
            $this->assertSame(ProbeResult::TLS_INVALID, $result->outcome, $leaf);
            $this->assertContains($result->reason, ProbeResult::REASONS, $leaf);
        }

        $this->assertSame('hostname_mismatch', $this->probe('other-name', 'proof')->reason);
        $this->assertSame(ProbeResult::TLS_INVALID, $this->probe('valid', 'old-tls')->outcome, 'TLS below 1.2 never passes');
    }

    #[Test]
    public function a_valid_tls_answer_that_is_not_this_deployments_proof_is_proof_mismatch(): void
    {
        $this->assertSame(ProbeResult::PROOF_MISMATCH, $this->probe('valid', 'wrong-proof')->outcome, 'another deployment (another key)');
        $this->assertSame(ProbeResult::PROOF_MISMATCH, $this->probe('valid', 'not-found')->outcome);
        $this->assertSame(ProbeResult::PROOF_MISMATCH, $this->probe('valid', 'redirect')->outcome, 'redirects are never followed');
        $this->assertSame(ProbeResult::PROOF_MISMATCH, $this->probe('valid', 'proof', '')->outcome, 'no probe key: nothing can prove anything');
    }

    #[Test]
    public function network_failures_are_indeterminate(): void
    {
        $prober = new StreamDomainProber(new ProbeProof(new Repository(['domains' => ['probe_key' => self::probeKey()]])), 1, self::$dir.'/ca.crt');
        $refused = $prober->probe(self::HOST, ['127.0.0.1']);
        $this->assertSame(ProbeResult::INDETERMINATE, $refused->outcome, 'nothing listening at the edge address');
        $this->assertSame('connect', $refused->reason);

        $this->assertSame(ProbeResult::INDETERMINATE, $prober->probe(self::HOST, [])->outcome, 'no edge address');

        $started = microtime(true);
        $hang = $this->probe('valid', 'hang');
        $this->assertSame(ProbeResult::INDETERMINATE, $hang->outcome, 'a silent edge times out');
        $this->assertLessThan(StreamDomainProber::READ_TIMEOUT_SECONDS + StreamDomainProber::CONNECT_TIMEOUT_SECONDS + 2, microtime(true) - $started);
    }

    #[Test]
    public function the_nonce_is_fresh_every_time_and_the_proof_is_bound_to_the_hostname(): void
    {
        $proof = new ProbeProof(new Repository(['domains' => ['probe_key' => self::probeKey()]]));
        $a = ProbeProof::nonce();
        $b = ProbeProof::nonce();

        $this->assertNotSame($a, $b);
        $this->assertMatchesRegularExpression(ProbeProof::NONCE_PATTERN, $a);
        $this->assertNotSame($proof->for(self::HOST, $a), $proof->for(self::HOST, $b));
        $this->assertNotSame($proof->for(self::HOST, $a), $proof->for('erp.someone-else.org', $a));
        $this->assertNull($proof->for(self::HOST, 'short'), 'a malformed nonce proves nothing');
        $this->assertStringNotContainsString(self::probeKey(), (string) $proof->for(self::HOST, $a));
    }
}
