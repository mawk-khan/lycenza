// Phase 9.9: a stable `Idempotency-Key` per logical submission, for the
// five Payroll actions that carry idempotency semantics on the JSON API
// (create run, create correction, approve, post, reverse). The
// session-authenticated Inertia routes these keys are sent to do NOT
// currently enforce idempotency on them (see routes/web.php's Payroll
// section for the detailed reason: the existing `EnsureIdempotent`
// middleware's replay path was built exclusively for JSON API responses
// and would replay a broken, target-less redirect here) -- sending the
// header is forward-compatible groundwork, not a guarantee. The actual
// double-submit protection today is each form's own `processing`-
// disabled button, exactly like every other consequential action in
// this codebase (e.g. `Finance/Charges/Show.vue`'s `cancelling` guard).
//
// `idempotencyKey(scope)` returns the SAME key for repeated calls with
// the same `scope` string until `clearIdempotencyKey(scope)` is called
// (after a successful submission, or when the user changes what they're
// submitting) -- a retry of the identical logical action reuses its
// key; a new logical action gets a fresh one.

const keys = new Map<string, string>();

export function idempotencyKey(scope: string): string {
    const existing = keys.get(scope);
    if (existing) return existing;

    const generated = crypto.randomUUID();
    keys.set(scope, generated);
    return generated;
}

export function clearIdempotencyKey(scope: string): void {
    keys.delete(scope);
}
