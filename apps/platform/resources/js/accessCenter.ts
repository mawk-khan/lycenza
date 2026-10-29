// ORG.12E (ADR 0080 §10.7): the shapes of the read-only School Access Center.
// Explanatory only -- the flags describe existing rules and never authorize.

export interface CapabilityInfo {
    key: string;
    label: string;
    read: boolean;
    sensitive: boolean;
    approval: boolean;
    assurance: boolean;
    campus: boolean;
    classes: string[];
}

export interface ModuleInfo {
    key: string;
    label: string;
    core: boolean;
    description: string;
    capabilities: CapabilityInfo[];
}

export interface RoleTemplate {
    key: string;
    name: string;
    purpose: string | null;
    group: string;
    addOn: boolean;
    sensitivity: string[];
    capabilityCount: number;
    modules: string[];
    campusScopable: boolean;
    campusAreas: string[];
    adminAuthority: boolean;
    sensitiveFamilies: string[];
    capabilities: Array<{ module: string; capabilities: CapabilityInfo[] }>;
}

export interface SensitiveFamily {
    key: string;
    label: string;
    domain: string;
    classification: string;
    fields: string[];
    baseline: string[];
    configurable: boolean;
    reason: string;
}

export interface AssignmentInfo {
    roleKey: string;
    roleName: string;
    scope: string;
    campusId: string | null;
    effective: boolean;
}

export const reasonLabels: Record<string, string> = {
    module_not_enabled: 'Module not enabled for this School',
    campus_scope_not_adopted: 'A Campus assignment does not carry this capability',
    campus_inactive: 'The Campus is inactive',
    account_disabled: 'Account disabled',
    membership_inactive: 'School access suspended',
};
