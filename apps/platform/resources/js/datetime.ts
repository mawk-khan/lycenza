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
