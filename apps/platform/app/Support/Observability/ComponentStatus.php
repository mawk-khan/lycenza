<?php

namespace App\Support\Observability;

/**
 * One component's check result. `detail` is internal-diagnostics-only
 * (section 10/60) -- callers building a PUBLIC readiness response must
 * use only `status`/`component`, never `detail` or `reason`, which may
 * legitimately contain operationally-useful-but-not-public information
 * (e.g. an age in seconds, a pending count) — never a hostname,
 * database name, credential, or stack trace (OperationalStatusService
 * is responsible for keeping `detail` itself free of those regardless
 * of audience, since even authenticated internal diagnostics should
 * not need raw infrastructure addresses to be useful).
 */
final class ComponentStatus
{
    /**
     * @param  array<string, mixed>  $detail
     */
    public function __construct(
        public readonly string $component,
        public readonly OperationalStatus $status,
        public readonly ?string $reason = null,
        public readonly array $detail = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'component' => $this->component,
            'status' => $this->status->value,
            'reason' => $this->reason,
            'detail' => $this->detail,
        ];
    }
}
