// ORG.12B (ADR 0080 §7): how the Setup & Readiness Center reads. Every
// setup area, title, category and action comes from the server
// (OrganizationSetupCenter); this module only turns the closed status and
// overall-state vocabularies into words and icons. It holds no catalogue.

export type SetupStatus =
    | 'not_applicable'
    | 'not_configured'
    | 'partially_configured'
    | 'configured'
    | 'blocked'
    | 'attention_required';

export interface SetupCard {
    key: string;
    title: string;
    description: string;
    owner: string;
    category: string;
    scope: string;
    core: boolean;
    status: SetupStatus;
    explanation: string;
    completedChecks: number | null;
    totalChecks: number | null;
    prerequisites: Array<{ key: string; title: string }>;
    hiddenPrerequisite: boolean;
    action: { kind: 'configure' | 'view' | 'none'; href: string | null };
}

export interface SetupCenter {
    school: { name: string };
    evaluatedAt: string;
    overview: {
        state: 'getting_started' | 'core_in_progress' | 'core_complete' | 'complete';
        core: { configured: number; total: number };
        optional: { configured: number; total: number };
        attention: number;
        blocked: number;
        notEnabled: number;
        hiddenAreas: number;
    };
    nextActions: Array<{ key: string; title: string; reason: string; href: string }>;
    categories: Array<{ key: string; label: string; items: SetupCard[] }>;
    notEnabled: Array<{ key: string; title: string; owner: string; description: string }>;
}

// Never colour alone: each status has a word and a distinct icon.
export const statusPresentation: Record<
    SetupStatus,
    { label: string; icon: string; classes: string }
> = {
    configured: {
        label: 'Configured',
        icon: '✓',
        classes: 'bg-emerald-50 text-emerald-800 ring-emerald-200',
    },
    partially_configured: {
        label: 'Partially configured',
        icon: '◐',
        classes: 'bg-sky-50 text-sky-800 ring-sky-200',
    },
    not_configured: {
        label: 'Not configured',
        icon: '○',
        classes: 'bg-slate-50 text-slate-700 ring-slate-300',
    },
    blocked: {
        label: 'Blocked',
        icon: '⛔',
        classes: 'bg-rose-50 text-rose-800 ring-rose-200',
    },
    attention_required: {
        label: 'Needs attention',
        icon: '⚠',
        classes: 'bg-amber-50 text-amber-900 ring-amber-200',
    },
    not_applicable: {
        label: 'Not enabled',
        icon: '–',
        classes: 'bg-slate-50 text-slate-500 ring-slate-200',
    },
};

// Configuration readiness only -- never a production-readiness claim.
export const overallStateLabels: Record<SetupCenter['overview']['state'], string> = {
    getting_started: 'Getting started',
    core_in_progress: 'Core setup in progress',
    core_complete: 'Core setup complete — enabled modules still need setup',
    complete: 'All applicable setup areas are configured',
};
