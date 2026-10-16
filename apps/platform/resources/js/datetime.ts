// UAT-C4 (UAT-UX-02, ADR 0075 section 17): the one way a staff page shows a
// stored instant -- in the School's timezone (the shared `schoolTimezone`
// prop), never the viewer's browser zone and never raw UTC ISO text. One
// format everywhere ("16 Oct 2026, 1:06 am" / "16 Oct 2026"); storage is
// untouched. Values that are already School-local calendar dates (due dates,
// attendance dates) are not instants: show them as they are.
import { usePage } from '@inertiajs/vue3';

const LOCALE = 'en-IN';

function schoolTimezone(): string | undefined {
    try {
        const tz = (usePage().props as { schoolTimezone?: string | null }).schoolTimezone;
        return tz ?? undefined;
    } catch {
        return undefined;
    }
}

function format(
    iso: string | null | undefined,
    options: Intl.DateTimeFormatOptions,
    timeZone?: string,
): string {
    if (!iso) {
        return '—';
    }
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) {
        return iso;
    }
    try {
        return new Intl.DateTimeFormat(LOCALE, {
            ...options,
            timeZone: timeZone ?? schoolTimezone(),
        }).format(date);
    } catch {
        return new Intl.DateTimeFormat(LOCALE, options).format(date);
    }
}

/** An instant as School-local date and time: "16 Oct 2026, 1:06 am". */
export function formatDateTime(iso: string | null | undefined, timeZone?: string): string {
    return format(iso, { dateStyle: 'medium', timeStyle: 'short' }, timeZone);
}

/** An instant as its School-local calendar day: "16 Oct 2026". */
export function formatDate(iso: string | null | undefined, timeZone?: string): string {
    return format(iso, { dateStyle: 'medium' }, timeZone);
}

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/**
 * UAT-C6 (RA-LIB-DATES): a School-local calendar date ("2026-10-16", e.g. a
 * loan's due date) as "16 Oct 2026" -- the same day in every timezone. It is
 * a date, not an instant: it is never parsed into a Date and never shifted.
 * Anything that is not a plain YYYY-MM-DD value is shown as it is.
 */
export function formatCalendarDate(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
    if (!match) {
        return value;
    }
    const month = MONTHS[Number(match[2]) - 1];

    return month ? `${Number(match[3])} ${month} ${match[1]}` : value;
}
