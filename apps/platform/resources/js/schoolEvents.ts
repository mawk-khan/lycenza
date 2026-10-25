// ORG.9D-3 (ADR 0076 §75): how a School calendar Event's time reads. The
// server sends School-local values already -- all-day Events as calendar
// dates (`YYYY-MM-DD`), timed Events as School-local wall-clock times
// (`YYYY-MM-DDTHH:mm`) -- so they are formatted as they are, never passed
// through the viewer's browser timezone.

export interface SchoolEvent {
    id: string;
    title: string;
    body: string;
    location: string | null;
    dateMode: 'all_day' | 'timed';
    startsOn: string | null;
    endsOn: string | null;
    startsAt: string | null;
    endsAt: string | null;
    audienceType: string;
    gradeLevelId: string | null;
    sectionId: string | null;
    audienceLabel: string;
    status: string;
    cancelled: boolean;
    ended: boolean;
    createdByName: string | null;
}

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

function day(value: string): string {
    const [y, m, d] = value.slice(0, 10).split('-');
    return `${Number(d)} ${MONTHS[Number(m) - 1] ?? m} ${y}`;
}

function clock(value: string): string {
    return value.slice(11, 16);
}

export function eventWhen(event: SchoolEvent): string {
    if (event.dateMode === 'all_day' && event.startsOn) {
        return event.endsOn && event.endsOn !== event.startsOn
            ? `${day(event.startsOn)} – ${day(event.endsOn)} (all day)`
            : `${day(event.startsOn)} (all day)`;
    }
    if (event.startsAt && event.endsAt) {
        return event.startsAt.slice(0, 10) === event.endsAt.slice(0, 10)
            ? `${day(event.startsAt)}, ${clock(event.startsAt)} – ${clock(event.endsAt)}`
            : `${day(event.startsAt)}, ${clock(event.startsAt)} – ${day(event.endsAt)}, ${clock(event.endsAt)}`;
    }
    return '—';
}

export const EVENT_STATUS_LABELS: Record<string, string> = {
    draft: 'Draft',
    pending_approval: 'Waiting for approval',
    approved: 'Approved — not yet published',
    rejected: 'Rejected',
    published: 'Published',
    cancelled: 'Cancelled',
};
