<?php

namespace App\Support\Observability;

/**
 * Phase 0C.4 section 41/42: a clean tracing abstraction compatible
 * with the W3C Trace Context spec (`traceparent` header,
 * https://www.w3.org/TR/trace-context/) and OpenTelemetry's data model
 * (trace id / span id), WITHOUT pulling in a full OTel SDK -- PHP OTel
 * auto-instrumentation for Laravel is immature and disproportionately
 * complex for what this checkpoint needs (ADR 0015 already anticipated
 * exactly this: "if full native OpenTelemetry integration ... is
 * immature or introduces disproportionate complexity, implement a
 * clean tracing abstraction compatible with W3C Trace Context").
 *
 * Deliberately DIAGNOSTIC ONLY (section 42): an inbound `traceparent`
 * is parsed and its trace-id is reused (so a trace spans multiple
 * services), but a NEW span-id is always generated locally -- this
 * service never trusts a caller-supplied span-id as identifying
 * anything about ITS OWN execution, and trace context is never
 * consulted for authorization decisions anywhere in this codebase.
 */
final class TraceContext
{
    private function __construct(
        public readonly string $traceId,
        public readonly string $spanId,
        public readonly bool $sampled,
    ) {}

    /**
     * A brand-new trace (no valid inbound traceparent) -- this service
     * is the root of the trace.
     */
    public static function start(): self
    {
        return new self(self::randomHex(16), self::randomHex(8), true);
    }

    /**
     * Parses an inbound `traceparent` header per the W3C spec
     * (`{version}-{trace-id}-{parent-id}-{flags}`, e.g.
     * `00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01`).
     * Reuses the trace-id (continuing the same logical trace across
     * services) but always mints a fresh span-id for this service's
     * own execution -- never adopts the caller's span-id as if it were
     * this service's own span. Falls back to start() for a missing,
     * malformed, or all-zero (invalid per spec) header rather than
     * failing the request -- tracing is diagnostic, never a hard
     * dependency.
     */
    public static function fromHeader(?string $traceparent): self
    {
        if ($traceparent !== null && preg_match('/^([0-9a-f]{2})-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/', $traceparent, $matches) === 1) {
            [, $version, $traceId, , $flags] = $matches;

            if ($version !== 'ff' && $traceId !== str_repeat('0', 32)) {
                return new self($traceId, self::randomHex(8), (hexdec($flags) & 0x01) === 1);
            }
        }

        return self::start();
    }

    /**
     * A child span within the SAME trace -- used when this service
     * makes its own further outbound call and needs a new span-id
     * while keeping the trace-id it received/generated.
     */
    public function childSpan(): self
    {
        return new self($this->traceId, self::randomHex(8), $this->sampled);
    }

    /**
     * A fresh child span for an ALREADY-KNOWN trace-id (e.g. from
     * App\Support\Tenancy\TenantContext::traceId(), when only the bare
     * id -- not a full previously-parsed TraceContext -- is in hand).
     */
    public static function forTraceId(string $traceId, bool $sampled = true): self
    {
        return new self($traceId, self::randomHex(8), $sampled);
    }

    public function toHeader(): string
    {
        $flags = $this->sampled ? '01' : '00';

        return "00-{$this->traceId}-{$this->spanId}-{$flags}";
    }

    private static function randomHex(int $byteLength): string
    {
        return bin2hex(random_bytes($byteLength));
    }
}
