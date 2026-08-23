from app.core.trace import TraceContext


def test_start_produces_a_valid_new_trace() -> None:
    trace = TraceContext.start()
    assert len(trace.trace_id) == 32
    assert len(trace.span_id) == 16
    assert trace.sampled is True


def test_from_header_reuses_trace_id_but_mints_a_fresh_span_id() -> None:
    inbound = "00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01"
    trace = TraceContext.from_header(inbound)

    assert trace.trace_id == "4bf92f3577b34da6a3ce929d0e0e4736"
    assert trace.span_id != "00f067aa0ba902b7"
    assert len(trace.span_id) == 16
    assert trace.sampled is True


def test_from_header_respects_the_not_sampled_flag() -> None:
    inbound = "00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-00"
    trace = TraceContext.from_header(inbound)
    assert trace.sampled is False


def test_from_header_falls_back_to_a_new_trace_on_malformed_input() -> None:
    trace = TraceContext.from_header("not-a-real-traceparent")
    assert len(trace.trace_id) == 32


def test_from_header_falls_back_to_a_new_trace_on_all_zero_trace_id() -> None:
    inbound = "00-00000000000000000000000000000000-00f067aa0ba902b7-01"
    trace = TraceContext.from_header(inbound)
    assert trace.trace_id != "00000000000000000000000000000000"[:32]
    assert trace.trace_id != "0" * 32


def test_from_header_falls_back_to_a_new_trace_on_missing_header() -> None:
    trace = TraceContext.from_header(None)
    assert len(trace.trace_id) == 32


def test_child_span_keeps_trace_id_but_changes_span_id() -> None:
    parent = TraceContext.start()
    child = parent.child_span()

    assert child.trace_id == parent.trace_id
    assert child.span_id != parent.span_id


def test_to_header_round_trips_through_from_header() -> None:
    trace = TraceContext.start()
    header = trace.to_header()
    reparsed = TraceContext.from_header(header)

    assert reparsed.trace_id == trace.trace_id
