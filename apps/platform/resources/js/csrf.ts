// Phase 0H.4D-P1: the Account Security MFA endpoints (begin/confirm
// enrollment, regenerate recovery codes) return computed JSON payloads
// (a QR code, plaintext recovery codes) rather than an Inertia page
// response, so they're called via plain `fetch()` instead of Inertia's
// `useForm()`/`router` (which expects an Inertia-shaped response).
// Laravel's default 'web' middleware group already sets the
// `XSRF-TOKEN` cookie on every response (VerifyCsrfToken); this reads
// it back exactly like axios's own default X-XSRF-TOKEN header
// behavior, so no new CSRF mechanism is introduced.
export function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

export async function postJson<T>(
    url: string,
    body: Record<string, unknown> = {},
    method: 'POST' | 'DELETE' = 'POST',
): Promise<T> {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: JSON.stringify(body),
    });

    if (!response.ok) {
        const payload = await response.json().catch(() => null);
        throw new Error(payload?.error?.message ?? `Request failed (${response.status})`);
    }

    return response.json() as Promise<T>;
}
