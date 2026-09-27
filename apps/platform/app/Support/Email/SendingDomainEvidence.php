<?php

namespace App\Support\Email;

use App\Support\Domains\Dns\DnsLookup;
use App\Support\Domains\Dns\DomainDnsResolver;
use App\Support\Domains\PublicSuffixPolicy;
use Illuminate\Contracts\Config\Repository;
use Throwable;

/**
 * ADR 0055 section 17: the sending-domain DNS evidence the application CAN
 * prove, read-only, through the bounded 0O.8A resolver (never a change to
 * DNS). What it cannot prove is reported as such, never assumed:
 * the provider's own domain verification, and the 14 days of 100 %
 * aligned DKIM passes that DMARC `quarantine` requires (DMARC aggregate
 * reports are deployment evidence).
 *
 * Checks (each a closed result code):
 * - SPF: exactly one `v=spf1` record on the return-path domain, ending in
 *   `-all`/`~all` (never `+all`/`?all`), and the return-path domain aligned
 *   with the sending domain's organizational domain;
 * - DKIM: a `v=DKIM1` key at `<selector>._domainkey.<sending domain>` (so
 *   `d=` is the sending domain: aligned), not revoked, RSA >= 2048 bits or
 *   Ed25519;
 * - DMARC: `v=DMARC1` at `_dmarc.<sending domain>` or the organizational
 *   domain; the effective policy (`sp=` for a subdomain covered by the
 *   organizational record) and whether it meets readiness (>= quarantine,
 *   pct 100).
 */
final class SendingDomainEvidence
{
    public function __construct(
        private readonly Repository $config,
        private readonly DomainDnsResolver $dns,
        private readonly PublicSuffixPolicy $suffixes,
        private readonly SenderIdentity $sender,
    ) {}

    /**
     * @return array<string, array{result: string, detail: string|null}>
     */
    public function evaluate(): array
    {
        $domain = $this->sender->sendingDomain();

        if ($domain === null) {
            return ['sending_domain' => ['result' => 'not_configured', 'detail' => null]];
        }

        $organizational = $this->organizational($domain);

        return [
            'sending_domain' => ['result' => 'configured', 'detail' => $domain],
            'spf' => $this->spf($organizational),
            'dkim' => $this->dkim($domain),
            'dmarc' => $this->dmarc($domain, $organizational),
            'provider_domain_verification' => ['result' => 'deployment_evidence', 'detail' => 'not provable by the application'],
            'dkim_alignment_history' => ['result' => 'deployment_evidence', 'detail' => '14 days of 100% aligned DKIM pass come from DMARC aggregate reports'],
            'operator_attestation' => ['result' => (bool) $this->config->get('email.sending_verified') ? 'attested' : 'not_attested', 'detail' => 'MAIL_SENDING_VERIFIED'],
        ];
    }

    /** @param array<string, array{result: string, detail: string|null}> $evidence */
    public static function dnsReady(array $evidence): bool
    {
        return ($evidence['spf']['result'] ?? null) === 'pass'
            && ($evidence['dkim']['result'] ?? null) === 'pass'
            && in_array($evidence['dmarc']['result'] ?? null, ['quarantine', 'reject'], true);
    }

    /** @return array{result: string, detail: string|null} */
    private function spf(string $organizational): array
    {
        $returnPath = SenderIdentity::validSendingDomain((string) $this->config->get('email.dns.return_path_domain'));

        if ($returnPath === null) {
            return ['result' => 'not_configured', 'detail' => 'MAIL_RETURN_PATH_DOMAIN'];
        }

        if ($returnPath !== $organizational && ! str_ends_with($returnPath, '.'.$organizational)) {
            return ['result' => 'not_aligned', 'detail' => $returnPath];
        }

        $lookup = $this->dns->txt($returnPath);
        if (! $lookup->isOk()) {
            return self::unresolved($lookup);
        }

        $records = array_values(array_filter($lookup->txt, fn (string $r) => preg_match('/^v=spf1(\s|$)/i', trim($r)) === 1));

        return match (true) {
            count($records) === 0 => ['result' => 'absent', 'detail' => $returnPath],
            count($records) > 1 => ['result' => 'multiple_records', 'detail' => $returnPath],
            preg_match('/\s[+?]?all\s*$/i', ' '.trim($records[0])) === 1 && preg_match('/\s[-~]all\s*$/i', ' '.trim($records[0])) !== 1 => ['result' => 'permissive', 'detail' => $returnPath],
            preg_match('/\s[-~]all\s*$/i', ' '.trim($records[0])) !== 1 => ['result' => 'no_all_mechanism', 'detail' => $returnPath],
            default => ['result' => 'pass', 'detail' => $returnPath],
        };
    }

    /** @return array{result: string, detail: string|null} */
    private function dkim(string $domain): array
    {
        $selector = (string) $this->config->get('email.dns.dkim_selector');

        if (preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/i', $selector) !== 1) {
            return ['result' => 'not_configured', 'detail' => 'MAIL_DKIM_SELECTOR'];
        }

        $name = strtolower($selector).'._domainkey.'.$domain;
        $lookup = $this->dns->txt($name);
        if (! $lookup->isOk()) {
            return self::unresolved($lookup);
        }

        foreach ($lookup->txt as $record) {
            $tags = self::tags($record);
            if (strtoupper($tags['v'] ?? 'DKIM1') !== 'DKIM1' || ! array_key_exists('p', $tags)) {
                continue;
            }
            if ($tags['p'] === '') {
                return ['result' => 'revoked', 'detail' => $name];
            }

            $type = strtolower($tags['k'] ?? 'rsa');
            if ($type === 'ed25519') {
                return ['result' => 'pass', 'detail' => $name];
            }

            return self::rsaBits($tags['p']) >= 2048
                ? ['result' => 'pass', 'detail' => $name]
                : ['result' => 'weak_key', 'detail' => $name];
        }

        return ['result' => 'absent', 'detail' => $name];
    }

    /** @return array{result: string, detail: string|null} */
    private function dmarc(string $domain, string $organizational): array
    {
        foreach (array_unique([$domain, $organizational]) as $candidate) {
            $lookup = $this->dns->txt('_dmarc.'.$candidate);
            if ($lookup->isIndeterminate()) {
                return self::unresolved($lookup);
            }
            if (! $lookup->isOk()) {
                continue;
            }

            $records = array_values(array_filter($lookup->txt, fn (string $r) => preg_match('/^v=DMARC1\s*(;|$)/i', trim($r)) === 1));
            if (count($records) !== 1) {
                return ['result' => count($records) === 0 ? 'absent' : 'multiple_records', 'detail' => '_dmarc.'.$candidate];
            }

            $tags = self::tags($records[0]);
            $policy = strtolower($candidate !== $domain ? ($tags['sp'] ?? $tags['p'] ?? '') : ($tags['p'] ?? ''));
            $pct = (int) ($tags['pct'] ?? 100);

            if (! in_array($policy, ['none', 'quarantine', 'reject'], true)) {
                return ['result' => 'invalid', 'detail' => '_dmarc.'.$candidate];
            }

            // Readiness needs the whole stream under the policy.
            return ['result' => $policy !== 'none' && $pct < 100 ? 'partial_'.$policy : $policy, 'detail' => '_dmarc.'.$candidate];
        }

        return ['result' => 'absent', 'detail' => '_dmarc.'.$domain];
    }

    private function organizational(string $domain): string
    {
        try {
            return $this->suffixes->registrableDomain($domain) ?? $domain;
        } catch (Throwable) {
            return $domain;
        }
    }

    /** @return array<string, string> */
    private static function tags(string $record): array
    {
        $tags = [];
        foreach (explode(';', $record) as $part) {
            if (str_contains($part, '=')) {
                [$key, $value] = explode('=', $part, 2);
                $tags[strtolower(trim($key))] = preg_replace('/\s+/', '', trim($value)) ?? '';
            }
        }

        return $tags;
    }

    private static function rsaBits(string $base64): int
    {
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split($base64, 64, "\n")."-----END PUBLIC KEY-----\n";
        $key = @openssl_pkey_get_public($pem);
        $details = $key === false ? false : openssl_pkey_get_details($key);

        return is_array($details) ? (int) ($details['bits'] ?? 0) : 0;
    }

    /** @return array{result: string, detail: string|null} */
    private static function unresolved(DnsLookup $lookup): array
    {
        return ['result' => $lookup->isAbsent() ? 'absent' : 'indeterminate', 'detail' => $lookup->reason];
    }
}
