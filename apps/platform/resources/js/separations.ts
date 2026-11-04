// HR.12: shared labels for the separation pages. Values come from the server;
// these only name them.

export type SeparationStatus =
    'requested' | 'approved' | 'rejected' | 'withdrawn' | 'cancelled' | 'finalized' | 'completed';

export const statusFilterLabels: Record<string, string> = {
    open: 'In progress',
    requested: 'Awaiting decision',
    approved: 'Notice period',
    finalized: 'Finalized, offboarding open',
    completed: 'Completed',
    closed: 'Rejected, withdrawn or cancelled',
};

export const eventLabels: Record<string, string> = {
    requested: 'Separation recorded',
    approved: 'Approved — notice period started',
    rejected: 'Rejected',
    withdrawn: 'Withdrawn',
    planned_day_changed: 'Planned last working day changed',
    cancelled: 'Cancelled — employment active again',
    finalized: 'Finalized — employment ended',
    clearance_cleared: 'Clearance item cleared',
    access_retained: 'School access recorded as kept',
    completed: 'Offboarding completed',
};

export const obligationStateLabels: Record<string, { label: string; glyph: string; tag: string }> =
    {
        open: { label: 'Outstanding', glyph: '!', tag: 'tag tag-attention' },
        clear: { label: 'Clear', glyph: '✓', tag: 'tag tag-positive' },
        attention: { label: 'Review', glyph: '●', tag: 'tag tag-info' },
        not_integrated: { label: 'Not checked here', glyph: '–', tag: 'tag tag-neutral' },
        retained: { label: 'Kept on purpose', glyph: '●', tag: 'tag tag-info' },
        unavailable: { label: 'Could not check', glyph: '!', tag: 'tag tag-attention' },
    };
