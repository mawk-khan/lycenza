<?php

namespace Tests\Unit\Observability;

use App\Support\Observability\TraceContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0C.4 section 41/42. Mirrors services/ai/tests/test_trace_propagation.py
 * exactly -- both implementations of the W3C Trace Context contract
 * must agree on parsing/formatting so a trace-id survives a Laravel ->
 * FastAPI -> Laravel round trip.
 */
class TraceContextTest extends TestCase
{
    #[Test]
    public function start_produces_a_valid_w3c_traceparent(): void
    {
        $trace = TraceContext::start();

        $this->assertMatchesRegularExpression('/^00-[0-9a-f]{32}-[0-9a-f]{16}-01$/', $trace->toHeader());
    }

    #[Test]
    public function from_header_reuses_the_trace_id_but_mints_a_fresh_span_id(): void
    {
        $inbound = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';

        $trace = TraceContext::fromHeader($inbound);

        $this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $trace->traceId);
        $this->assertNotSame('00f067aa0ba902b7', $trace->spanId);
        $this->assertTrue($trace->sampled);
    }

    #[Test]
    public function from_header_respects_the_unsampled_flag(): void
    {
        $trace = TraceContext::fromHeader('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-00');

        $this->assertFalse($trace->sampled);
    }

    #[Test]
    public function from_header_falls_back_to_a_new_trace_for_a_malformed_header(): void
    {
        $trace = TraceContext::fromHeader('not-a-valid-traceparent');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $trace->traceId);
    }

    #[Test]
    public function from_header_falls_back_to_a_new_trace_for_an_all_zero_trace_id(): void
    {
        $inbound = '00-00000000000000000000000000000000-00f067aa0ba902b7-01';

        $trace = TraceContext::fromHeader($inbound);

        $this->assertNotSame('00000000000000000000000000000000', $trace->traceId);
    }

    #[Test]
    public function from_header_falls_back_to_a_new_trace_for_a_missing_header(): void
    {
        $trace = TraceContext::fromHeader(null);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $trace->traceId);
    }

    #[Test]
    public function child_span_keeps_the_trace_id_but_changes_the_span_id(): void
    {
        $root = TraceContext::start();
        $child = $root->childSpan();

        $this->assertSame($root->traceId, $child->traceId);
        $this->assertNotSame($root->spanId, $child->spanId);
    }

    #[Test]
    public function for_trace_id_mints_a_fresh_span_for_a_known_trace(): void
    {
        $trace = TraceContext::forTraceId('4bf92f3577b34da6a3ce929d0e0e4736', false);

        $this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $trace->traceId);
        $this->assertFalse($trace->sampled);
    }

    #[Test]
    public function to_header_round_trips_through_from_header(): void
    {
        $original = TraceContext::start();
        $parsed = TraceContext::fromHeader($original->toHeader());

        $this->assertSame($original->traceId, $parsed->traceId);
    }
}
