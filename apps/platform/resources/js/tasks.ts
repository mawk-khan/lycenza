// HR.13: shared types and labels for the staff task pages. Values come from the server.

export type TaskStatus = 'assigned' | 'submitted' | 'returned' | 'completed' | 'cancelled';

export interface TaskRow {
    id: string;
    title: string;
    category: string | null;
    assignee: string | null;
    employmentRecordId: string;
    dueOn: string;
    status: TaskStatus;
    overdue: boolean;
    source: 'generated' | 'manager' | 'administrative';
    completionMode: 'self' | 'reviewed';
    department: string | null;
    position: string | null;
    staffType: string | null;
    section: string | null;
    subjectOffering: string | null;
}

export interface TaskPage {
    rows: TaskRow[];
    total: number;
    page: number;
    perPage: number;
    status: string;
}

export const statusFilterLabels: Record<string, string> = {
    open: 'Open',
    overdue: 'Overdue',
    assigned: 'To do',
    submitted: 'Awaiting review',
    returned: 'Returned for revision',
    completed: 'Completed',
    cancelled: 'Cancelled',
    all: 'Everything',
};

export const sourceLabels: Record<string, string> = {
    generated: 'Recurring',
    manager: 'From manager',
    administrative: 'From HR',
};

export const eventLabels: Record<string, string> = {
    generated: 'Created from a recurring task',
    assigned: 'Assigned',
    submitted: 'Submitted for review',
    returned: 'Returned for revision',
    completed: 'Completed',
    cancelled: 'Cancelled',
    reassigned: 'Reassigned',
};

export const WEEKDAYS = [
    { bit: 1, label: 'Mon' },
    { bit: 2, label: 'Tue' },
    { bit: 4, label: 'Wed' },
    { bit: 8, label: 'Thu' },
    { bit: 16, label: 'Fri' },
    { bit: 32, label: 'Sat' },
    { bit: 64, label: 'Sun' },
];
