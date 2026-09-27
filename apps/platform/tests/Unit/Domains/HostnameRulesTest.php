<?php

namespace Tests\Unit\Domains;

use App\Support\Domains\HostnameNormalizer;
use App\Support\Domains\HostnamePolicy;
use App\Support\Domains\HostnameRejected;
use App\Support\Domains\PublicSuffixPolicy;
use App\Support\Domains\ReservedHosts;
use Illuminate\Config\Repository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 0O.8A (ADR 0054 sections 3.1-3.4): the ONE canonical hostname form,
 * the v1 ASCII-only IDN boundary, the pinned Public Suffix List (ICANN and
 * PRIVATE sections) and the deployment's reserved hosts.
 */
class HostnameRulesTest extends TestCase
{
    private function psl(): PublicSuffixPolicy
    {
        return new PublicSuffixPolicy(dirname(__DIR__, 3).'/resources/public-suffix-list');
    }

    /** @param array<string, mixed> $config */
    private function policy(array $config = []): HostnamePolicy
    {
        $repository = new Repository(['app' => ['url' => 'https://app.lycenza-platform.com'], 'domains' => $config]);
        $names = new HostnameNormalizer;

        return new HostnamePolicy($names, $this->psl(), new ReservedHosts($repository, $names, $this->psl()));
    }

    #[Test]
    public function a_hostname_is_stored_lowercase_ascii_without_a_terminal_dot(): void
    {
        $names = new HostnameNormalizer;

        $this->assertSame('erp.northfield.org', $names->normalize('ERP.Northfield.ORG.'));
        $this->assertSame('a-b.c-d.example.co.uk', $names->normalize('A-B.C-D.Example.CO.UK'));
        $this->assertSame(str_repeat('a', 63).'.org', $names->normalize(str_repeat('A', 63).'.org'));
        $this->assertSame('erp.northfield.org', $names->canonicalRequestHost('ERP.northfield.org.'));
        $this->assertNull($names->canonicalRequestHost('localhost'), 'a request Host that can never be a stored name');
        $this->assertNull($names->canonicalRequestHost('10.0.0.1'));
    }

    /** @return array<string, array{string, string}> */
    public static function refused(): array
    {
        return [
            'scheme' => ['https://erp.northfield.org', HostnameRejected::SYNTAX],
            'port' => ['erp.northfield.org:443', HostnameRejected::SYNTAX],
            'path' => ['erp.northfield.org/app', HostnameRejected::SYNTAX],
            'query' => ['erp.northfield.org?x=1', HostnameRejected::SYNTAX],
            'fragment' => ['erp.northfield.org#top', HostnameRejected::SYNTAX],
            'userinfo' => ['admin@erp.northfield.org', HostnameRejected::SYNTAX],
            'whitespace' => ['erp.northfield .org', HostnameRejected::SYNTAX],
            'wildcard' => ['*.northfield.org', HostnameRejected::SYNTAX],
            'ipv4 literal' => ['192.0.2.10', HostnameRejected::IP_LITERAL],
            'ipv6 literal' => ['2001:db8::1', HostnameRejected::IP_LITERAL],
            'bracketed ipv6' => ['[2001:db8::1]', HostnameRejected::IP_LITERAL],
            'localhost' => ['localhost', HostnameRejected::SYNTAX],
            'under localhost' => ['erp.localhost', HostnameRejected::LOCAL_NAME],
            'single label' => ['northfield', HostnameRejected::SYNTAX],
            'test tld' => ['erp.northfield.test', HostnameRejected::LOCAL_NAME],
            'internal' => ['erp.corp.internal', HostnameRejected::LOCAL_NAME],
            'local' => ['printer.local', HostnameRejected::LOCAL_NAME],
            'home.arpa' => ['nas.home.arpa', HostnameRejected::LOCAL_NAME],
            'example tld' => ['school.example', HostnameRejected::LOCAL_NAME],
            'invalid tld' => ['school.invalid', HostnameRejected::LOCAL_NAME],
            'onion' => ['abc.onion', HostnameRejected::LOCAL_NAME],
            'ddev' => ['lycenza.ddev.site', HostnameRejected::LOCAL_NAME],
            'underscore' => ['erp_x.northfield.org', HostnameRejected::SYNTAX],
            'empty label' => ['erp..northfield.org', HostnameRejected::SYNTAX],
            'leading hyphen' => ['-erp.northfield.org', HostnameRejected::SYNTAX],
            'trailing hyphen' => ['erp-.northfield.org', HostnameRejected::SYNTAX],
            'label over 63' => [str_repeat('a', 64).'.org', HostnameRejected::SYNTAX],
            'name over 253' => [implode('.', array_fill(0, 5, str_repeat('a', 60))).'.org', HostnameRejected::SYNTAX],
            'numeric tld' => ['erp.northfield.123', HostnameRejected::SYNTAX],
            'unicode (IDN U-label)' => ['bücher.example.de', HostnameRejected::IDN],
            'punycode (IDN A-label)' => ['xn--bcher-kva.northfield.org', HostnameRejected::IDN],
            'punycode tld label' => ['erp.northfield.xn--p1ai', HostnameRejected::IDN],
            'empty' => ['', HostnameRejected::SYNTAX],
        ];
    }

    #[Test]
    #[DataProvider('refused')]
    public function every_non_hostname_is_refused_with_a_closed_reason(string $input, string $reason): void
    {
        try {
            (new HostnameNormalizer)->normalize($input);
            $this->fail("{$input} was accepted");
        } catch (HostnameRejected $e) {
            $this->assertSame($reason, $e->reason);
            $this->assertContains($e->reason, HostnameRejected::REASONS);
            $this->assertStringNotContainsString($input === '' ? "\0" : $input, $e->getMessage(), 'the message never echoes the input');
        }
    }

    #[Test]
    public function the_public_suffix_list_refuses_icann_and_private_suffixes_and_unknown_tlds(): void
    {
        $policy = $this->policy();

        foreach (['com', 'org', 'co.uk', 'ac.in', 'github.io', 'blogspot.com', 's3.amazonaws.com', 'foo.ck', 'cloudfront.net'] as $suffix) {
            try {
                $policy->claimable($suffix);
                $this->fail("{$suffix} is a public suffix");
            } catch (HostnameRejected $e) {
                $this->assertContains($e->reason, [HostnameRejected::PUBLIC_SUFFIX, HostnameRejected::SYNTAX], $suffix);
            }
        }

        foreach (['erp.northfield.zzzqqq', 'northfield.notarealtld'] as $unknown) {
            try {
                $policy->claimable($unknown);
                $this->fail("{$unknown} has no known suffix");
            } catch (HostnameRejected $e) {
                $this->assertSame(HostnameRejected::UNKNOWN_SUFFIX, $e->reason);
            }
        }

        // Registrable domains and any name under one are fine -- including
        // under multi-label ICANN and PRIVATE suffixes and the *.ck wildcard.
        foreach (['northfield.org', 'erp.northfield.org', 'northfield.co.uk', 'erp.northfield.co.uk', 'northfield.github.io', 'www.ck', 'school.ac.in'] as $ok) {
            $this->assertSame($ok, $policy->claimable($ok));
        }
    }

    #[Test]
    public function the_pinned_snapshot_is_verified_before_use_and_a_tampered_one_fails_closed(): void
    {
        $snapshot = $this->psl()->snapshot();
        $this->assertSame('https://publicsuffix.org/list/public_suffix_list.dat', $snapshot['source']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $snapshot['sha256']);
        $this->assertSame($snapshot['sha256'], hash_file('sha256', dirname(__DIR__, 3).'/resources/public-suffix-list/public_suffix_list.dat'));

        $dir = sys_get_temp_dir().'/psl-'.bin2hex(random_bytes(4));
        mkdir($dir);
        copy(dirname(__DIR__, 3).'/resources/public-suffix-list/snapshot.json', $dir.'/snapshot.json');
        file_put_contents($dir.'/public_suffix_list.dat', "// ===BEGIN ICANN DOMAINS===\nuk\n// ===END ICANN DOMAINS===\n");

        try {
            (new PublicSuffixPolicy($dir))->assertRegistrable('northfield.co.uk');
            $this->fail('a snapshot that does not match its recorded sha256 must never be used');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('snapshot', $e->getMessage());
        } finally {
            array_map('unlink', glob($dir.'/*') ?: []);
            rmdir($dir);
        }
    }

    #[Test]
    public function reserved_hosts_cover_the_platform_domain_aliases_internal_hosts_and_edge(): void
    {
        $policy = $this->policy([
            'platform_aliases' => ['www.lycenza-app.net'],
            'internal_hosts' => ['platform-internal.lycenza-ops.net'],
            'reserved_hosts' => ['status.northfield.org'],
            'reserved_suffixes' => ['lycenza-mail.org'],
            'edge' => ['cname_target' => 'edge.lycenza-cdn.net'],
        ]);

        foreach ([
            'app.lycenza-platform.com',       // the platform host itself
            'lycenza-platform.com',           // its registrable domain
            'school.lycenza-platform.com',    // anything under the platform's domain
            'www.lycenza-app.net',            // an alias
            'other.lycenza-app.net',          // under the alias's registrable domain
            'platform-internal.lycenza-ops.net',
            'status.northfield.org',          // exact reserved
            'lycenza-mail.org', 'x.lycenza-mail.org',
            'edge.lycenza-cdn.net', 'a.edge.lycenza-cdn.net',
        ] as $reserved) {
            try {
                $policy->claimable($reserved);
                $this->fail("{$reserved} is reserved");
            } catch (HostnameRejected $e) {
                $this->assertSame(HostnameRejected::RESERVED, $e->reason, $reserved);
            }
        }

        // Exact names only: a sibling of an exactly reserved name is fine.
        $this->assertSame('erp.northfield.org', $policy->claimable('erp.northfield.org'));
        $this->assertSame('lycenza-cdn.net', $policy->claimable('lycenza-cdn.net'), 'only the edge target subtree, not its whole registrable domain');
    }
}
