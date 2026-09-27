<?php

namespace App\Support\Domains;

/**
 * Whether a School may claim a hostname (ADR 0054 section 3): the canonical
 * syntax (HostnameNormalizer), then the pinned Public Suffix List, then the
 * deployment's reserved hosts. Returns the canonical hostname to store.
 */
final class HostnamePolicy
{
    public function __construct(
        private readonly HostnameNormalizer $names,
        private readonly PublicSuffixPolicy $suffixes,
        private readonly ReservedHosts $reserved,
    ) {}

    /**
     * @throws HostnameRejected
     */
    public function claimable(string $input): string
    {
        $hostname = $this->names->normalize($input);

        $this->suffixes->assertRegistrable($hostname);

        if ($this->reserved->isReserved($hostname)) {
            throw new HostnameRejected(HostnameRejected::RESERVED);
        }

        return $hostname;
    }
}
