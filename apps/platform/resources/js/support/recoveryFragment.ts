// Phase 0O.10A (ADR 0056 section 8.1): the account-recovery secret is the
// link's #fragment. Browsers never send a fragment, but Inertia copies
// `location.hash` into its own page URL on the initial visit and writes it
// back to the history entry. So the fragment is captured and removed from
// the address bar HERE, before Inertia starts (app.ts), and kept only in
// this module's memory, bound to the link's selector. It leaves the browser
// only in the reset POST (Pages/Auth/AccountRecovery/Reset.vue).

const RECOVERY_PATH = /^\/account-recovery\/([A-Za-z0-9_-]{22})$/;

let captured: { selector: string; secret: string } | null = null;

export function captureRecoveryFragment(): void {
    if (typeof window === 'undefined') {
        return;
    }

    const match = RECOVERY_PATH.exec(window.location.pathname);
    if (match === null || window.location.hash === '') {
        return;
    }

    captured = { selector: match[1], secret: window.location.hash.slice(1) };
    window.history.replaceState(
        window.history.state,
        '',
        window.location.pathname + window.location.search,
    );
}

/** The captured secret for this selector, or '' (the page then shows "invalid or expired"). */
export function recoverySecretFor(selector: string): string {
    return captured !== null && captured.selector === selector ? captured.secret : '';
}

export function forgetRecoverySecret(): void {
    captured = null;
}
