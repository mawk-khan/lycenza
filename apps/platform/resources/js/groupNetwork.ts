// ORG.12D (ADR 0080 §9): the shapes of a School Group's governance read model
// (SchoolGroupDirectoryReadService). Platform metadata only -- a member
// School's name, code and lifecycle and the Group's own classification of it.
// Regions and Zones are governance groupings, never access.

export interface StructureItem {
    id: string;
    code: string;
    name: string;
    description: string | null;
    sortOrder: number;
    status: 'active' | 'inactive';
    schools: number;
    regionId?: string | null;
}

export interface GroupOverview {
    schools: { total: number; active: number; notActive: number };
    relationships: Record<'owned' | 'franchise' | 'managed' | 'affiliate' | 'unclassified', number>;
    attention: { unclassified: number; inInactiveStructure: number; schoolsNotActive: number };
    regions: StructureItem[];
    zones: StructureItem[];
}

export interface DirectoryRow {
    id: string;
    name: string;
    code: string | null;
    status: string;
    relationship: string | null;
    relationshipLabel: string;
    networkReference: string | null;
    region: { id: string; code: string; name: string; status: string } | null;
    zone: { id: string; code: string; name: string; status: string } | null;
    canEnter?: boolean;
    isMember?: boolean;
}

export interface Paginated<T> {
    data: T[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    from: number | null;
    to: number | null;
    total: number;
}

export interface Option {
    code: string;
    label: string;
}

// ORG.12E (ADR 0080 §10): the viewer's Network-governance read scope.
export interface GovernanceScope {
    type: 'whole_group' | 'scoped';
    regionIds: string[];
    zoneIds: string[];
}
