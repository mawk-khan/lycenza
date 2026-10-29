// ORG.12G (ADR 0080 §12): how the Integration & Device Readiness Hub reads.
// Every family, section, status and link comes from the server
// (IntegrationHubReadService); this module only turns the closed status
// vocabulary into words and icons. It holds no catalogue.
import type { SetupStatus } from './setupReadiness';

export type IntegrationStatus =
    | 'not_available'
    | 'not_applicable'
    | 'not_configured'
    | 'configured'
    | 'healthy'
    | 'degraded'
    | 'attention_required'
    | 'error';

export interface IntegrationCard {
    key: string;
    title: string;
    description: string;
    section: string;
    owner: string;
    managedBy: 'school' | 'platform';
    status: IntegrationStatus;
    explanation: string;
    action: { kind: 'configure' | 'view' | 'none'; href: string | null };
}

export interface IntegrationHub {
    school: { name: string };
    evaluatedAt: string;
    overview: {
        counts: Record<IntegrationStatus, number>;
        hiddenFamilies: number;
        verificationRequired: Array<{ key: string; title: string; href: string | null }>;
        setupArea: {
            title: string;
            status: SetupStatus | null;
            explanation: string;
            href: string | null;
        } | null;
    };
    sections: Array<{ key: string; label: string; items: IntegrationCard[] }>;
}

// Never colour alone: each status has a word and a distinct icon.
export const integrationStatusPresentation: Record<
    IntegrationStatus,
    { label: string; icon: string; classes: string }
> = {
    healthy: {
        label: 'Healthy',
        icon: '✓',
        classes: 'bg-emerald-50 text-emerald-800 ring-emerald-200',
    },
    configured: {
        label: 'Configured',
        icon: '●',
        classes: 'bg-sky-50 text-sky-800 ring-sky-200',
    },
    not_configured: {
        label: 'Not configured',
        icon: '○',
        classes: 'bg-slate-50 text-slate-700 ring-slate-300',
    },
    degraded: {
        label: 'Degraded',
        icon: '◐',
        classes: 'bg-amber-50 text-amber-900 ring-amber-200',
    },
    attention_required: {
        label: 'Needs attention',
        icon: '⚠',
        classes: 'bg-amber-50 text-amber-900 ring-amber-200',
    },
    error: {
        label: 'Status unavailable',
        icon: '✕',
        classes: 'bg-rose-50 text-rose-800 ring-rose-200',
    },
    not_applicable: {
        label: 'Module not enabled',
        icon: '–',
        classes: 'bg-slate-50 text-slate-600 ring-slate-200',
    },
    not_available: {
        label: 'Not available',
        icon: '⊘',
        classes: 'bg-slate-100 text-slate-700 ring-slate-300',
    },
};

// Overview order: what needs looking at first.
export const overviewOrder: IntegrationStatus[] = [
    'error',
    'attention_required',
    'degraded',
    'not_configured',
    'configured',
    'healthy',
    'not_applicable',
    'not_available',
];
