// Phase 0G.7 (FINANCE.md, rules 30-31): every monetary value is an
// exact decimal STRING end to end -- this file never converts one to a
// JavaScript `Number`/`parseFloat`, even for display-only formatting,
// so there is no ambiguity for a reviewer to check. Purely string-based
// thousands-grouping on the integer part.

/**
 * Formats an exact decimal amount string (e.g. "1000.00") for display,
 * with thousands separators and the currency code -- never touches the
 * value's precision, never parses it to a Number.
 */
export function formatMoney(amount: string, currency: string): string {
    const negative = amount.startsWith('-');
    const unsigned = negative ? amount.slice(1) : amount;
    const [integerPart, fractionPart] = unsigned.split('.');
    const grouped = integerPart.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const decimals = fractionPart ? `.${fractionPart}` : '';
    return `${negative ? '-' : ''}${grouped}${decimals} ${currency}`;
}

/**
 * Converts a "digits, optional '.' + 1-2 digits" decimal string into
 * whole cents as a `BigInt` -- exact integer arithmetic, never a
 * floating-point `Number`. Returns `null` for anything that doesn't
 * match that shape (an incomplete/invalid amount the user is still
 * typing); callers treat `null` as "not countable yet", never as zero.
 */
function toCents(amount: string): bigint | null {
    const match = /^(\d{1,12})(?:\.(\d{1,2}))?$/.exec(amount.trim());
    if (!match) return null;
    const [, integerPart, fractionPart = ''] = match;
    const paddedFraction = fractionPart.padEnd(2, '0');
    return BigInt(integerPart) * 100n + BigInt(paddedFraction);
}

function fromCents(cents: bigint): string {
    const negative = cents < 0n;
    const abs = negative ? -cents : cents;
    const integerPart = abs / 100n;
    const fractionPart = (abs % 100n).toString().padStart(2, '0');
    return `${negative ? '-' : ''}${integerPart}.${fractionPart}`;
}

/**
 * A client-side balance PREVIEW only (FINANCE.md 0G.7 rule 17) -- exact
 * `BigInt`-cents arithmetic, never a float. Amounts that don't yet
 * parse as a valid decimal string are simply excluded from the running
 * total (the user is still typing); this is presentation assistance,
 * never authoritative -- the server re-validates the real balance
 * regardless.
 */
export function sumAmounts(amounts: string[]): string {
    const totalCents = amounts.reduce((sum, amount) => {
        const cents = toCents(amount);
        return cents === null ? sum : sum + cents;
    }, 0n);
    return fromCents(totalCents);
}
