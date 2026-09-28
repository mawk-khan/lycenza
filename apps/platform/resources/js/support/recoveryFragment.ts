// Phase 0O.10A (ADR 0056 section 8.1): the account-recovery secret is the
// link's #fragment. Browsers never send a fragment, but Inertia copies
// `location.hash` into its own page URL on the initial visit and writes it
// back to the history entry. So the fragment is captured and removed from
// the address bar HERE, before Inertia starts (app.ts), and kept only in
// this module's memory, bound to the link's selector. It leaves the browser
// only in the credential's POST (Pages/Auth/AccountRecovery/Reset.vue).
//
// Phase 0O.12B (ADR 0059 section 10): the same scheme -- and this same
// capture -- for the two other one-time credential links: a bootstrap
// account's activation link (/account-activation/{selector}) and a staff
// account invitation (/invitations/{school}/staff/{selector}).

const CREDENTIAL_PATHS = [
    /^\/account-recovery\/([A-Za-z0-9_-]{22})$/,
    /^\/account-activation\/([A-Za-z0-9_-]{22})$/,
    /^\/invitations\/[0-9a-fA-F-]{36}\/staff\/([A-Za-z0-9_-]{22})$/,
];

let captured: { selector: string; secret: string } | null = null;

export function captureRecoveryFragment(): void {
    if (typeof window === 'undefined' || window.location.hash === '') {
        return;
    }

    for (const path of CREDENTIAL_PATHS) {
        const match = path.exec(window.location.pathname);
        if (match === null) {
            continue;
        }

        captured = { selector: match[1], secret: window.location.hash.slice(1) };
        window.history.replaceState(
            window.history.state,
            '',
            window.location.pathname + window.location.search,
        );

        return;
    }
}

/** The captured secret for this selector, or '' (the page then shows "invalid or expired"). */
export function recoverySecretFor(selector: string): string {
    return captured !== null && captured.selector === selector ? captured.secret : '';
}

export function forgetRecoverySecret(): void {
    captured = null;
}

/** ADR 0059: the same captured secret, named for the activation and staff-invitation pages. */
export const credentialSecretFor = recoverySecretFor;
export const forgetCredentialSecret = forgetRecoverySecret;
