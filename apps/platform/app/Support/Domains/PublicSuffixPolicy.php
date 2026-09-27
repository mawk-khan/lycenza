<?php

namespace App\Support\Domains;

use Pdp\Rules;
use RuntimeException;

/**
 * ADR 0054 section 3.3: the Public Suffix List, from the committed, pinned
 * snapshot in resources/public-suffix-list (source, version and sha256 in
 * snapshot.json). Never fetched at runtime. The file's sha256 is verified
 * against snapshot.json before it is parsed, so an edited or truncated
 * list fails closed instead of silently accepting a suffix.
 *
 * A hostname is claimable only when the list resolves it to a KNOWN suffix
 * (ICANN or PRIVATE section) and a registrable domain: the suffix itself
 * (`com`, `co.uk`, `github.io`, `foo.ck`) is refused, and so is a name
 * under a TLD the snapshot does not know (a stale snapshot refuses a new
 * suffix; it never accepts one). Registrability is never approximated by
 * counting dots.
 */
final class PublicSuffixPolicy
{
    private ?Rules $rules = null;

    public function __construct(private readonly ?string $directory = null) {}

    /**
     * @throws HostnameRejected
     */
    public function assertRegistrable(string $hostname): void
    {
        $resolved = $this->rules()->resolve($hostname);
        $suffix = $resolved->suffix();

        if ($resolved->registrableDomain()->value() === null) {
            throw new HostnameRejected(HostnameRejected::PUBLIC_SUFFIX);
        }

        if (! $suffix->isKnown()) {
            throw new HostnameRejected(HostnameRejected::UNKNOWN_SUFFIX);
        }
    }

    /** The registrable domain (ICANN + PRIVATE sections), or null for a suffix or an unknown TLD. */
    public function registrableDomain(string $hostname): ?string
    {
        $resolved = $this->rules()->resolve($hostname);

        return $resolved->suffix()->isKnown() ? $resolved->registrableDomain()->value() : null;
    }

    /** @return array{source: string, version: string, commit: string, retrieved: string, sha256: string} */
    public function snapshot(): array
    {
        $meta = json_decode((string) file_get_contents($this->directory().'/snapshot.json'), true, 4, JSON_THROW_ON_ERROR);

        return [
            'source' => (string) ($meta['source'] ?? ''),
            'version' => (string) ($meta['version'] ?? ''),
            'commit' => (string) ($meta['commit'] ?? ''),
            'retrieved' => (string) ($meta['retrieved'] ?? ''),
            'sha256' => (string) ($meta['sha256'] ?? ''),
        ];
    }

    private function rules(): Rules
    {
        if ($this->rules !== null) {
            return $this->rules;
        }

        $path = $this->directory().'/public_suffix_list.dat';
        $content = @file_get_contents($path);
        $expected = $this->snapshot()['sha256'];

        if (! is_string($content) || $expected === '' || ! hash_equals($expected, hash('sha256', $content))) {
            throw new RuntimeException('The pinned Public Suffix List snapshot is missing or does not match snapshot.json.');
        }

        return $this->rules = Rules::fromString($content);
    }

    private function directory(): string
    {
        return $this->directory ?? resource_path('public-suffix-list');
    }
}
