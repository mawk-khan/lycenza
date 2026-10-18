// ORG.3 (ADR 0076 §9.2-§9.3): labels for the Platform School Registry's
// derived values. Readiness is advisory: it never blocks activation.

export const readinessStyles: Record<string, { label: string; badge: string }> = {
    ready: { label: 'Ready', badge: 'bg-emerald-50 text-emerald-700' },
    partially_configured: { label: 'Partially configured', badge: 'bg-sky-50 text-sky-700' },
    needs_attention: { label: 'Needs attention', badge: 'bg-amber-50 text-amber-800' },
};

export const domainLabels: Record<string, string> = {
    none: 'None',
    pending: 'Being verified',
    active: 'Active',
    attention: 'Suspended',
};

export const administratorLabels: Record<string, string> = {
    ready: 'An activated School administrator exists',
    not_activated: 'An administrator exists but has not activated their account',
    missing: 'No qualifying School administrator',
};

export const checkLabels: Record<string, string> = {
    administrator: 'Activated School administrator',
    campus: 'At least one active Campus',
    academic_year: 'An active academic year',
    custom_domain: 'Custom domain active',
};

export const warningLabels: Record<string, string> = {
    awaiting_activation: 'The School is provisioning and not yet active.',
    school_closed: 'The School is closed. Nothing was deleted.',
    school_suspended: 'The School is suspended.',
    administrator_not_activated: 'Its School administrator has not activated their account.',
    administrator_missing: 'It has no qualifying School administrator.',
    no_active_campus: 'It has no active Campus.',
    no_active_academic_year: 'It has no active academic year.',
    custom_domain_suspended: 'A custom domain is suspended.',
    custom_domain_pending: 'A custom domain is still being verified.',
};

export function statusLabel(status: string, closed: boolean): string {
    if (closed) {
        return 'Closed';
    }
    return (
        { provisioning: 'Provisioning', active: 'Active', suspended: 'Suspended' }[status] ?? status
    );
}
