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
        classes: 'bg-graphite-100 text-ink ring-graphite-300',
    },
    configured: {
        label: 'Configured',
        icon: '●',
        classes: 'bg-accent-100 text-accent-strong ring-accent-300',
    },
    not_configured: {
        label: 'Not configured',
        icon: '○',
        classes: 'bg-graphite-100 text-ink ring-control-line',
    },
    degraded: {
        label: 'Degraded',
        icon: '◐',
        classes: 'bg-accent-100 text-ink ring-accent-300',
    },
    attention_required: {
        label: 'Needs attention',
        icon: '⚠',
        classes: 'bg-accent-100 text-ink ring-accent-300',
    },
    error: {
        label: 'Status unavailable',
        icon: '✕',
        classes: 'bg-danger-tint text-danger ring-danger/40',
    },
    not_applicable: {
        label: 'Module not enabled',
        icon: '–',
        classes: 'bg-graphite-100 text-ink-muted ring-line',
    },
    not_available: {
        label: 'Not available',
        icon: '⊘',
        classes: 'bg-graphite-200 text-ink ring-control-line',
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
