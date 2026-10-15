<?php

namespace App\Support\DataHealth;

/**
 * A link from a finding to an EXISTING page -- to view the record or to
 * correct it in its owning workflow. Shown only when the viewer holds
 * every capability in `$requires` (the page re-checks its own anyway).
 */
final readonly class DataHealthLink
{
    /**
     * @param  list<string>  $requires
     */
    public function __construct(
        public string $label,
        public string $url,
        public array $requires,
        public bool $isRepair = false,
    ) {}
}
