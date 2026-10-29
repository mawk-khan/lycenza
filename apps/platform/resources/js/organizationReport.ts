// ORG.12H (ADR 0080 §13.2): the School Control Plane report's shape. Every
// value comes from the server (SchoolControlPlaneReport); sections the viewer
// cannot read are null.
import type { IntegrationStatus } from './integrationReadiness';
import type { SetupCenter, SetupStatus } from './setupReadiness';

export interface ReportGap {
    key: string;
    title: string;
    category: string;
    owner: string;
    core: boolean;
    status: SetupStatus;
    explanation: string;
    prerequisites: string[];
    hiddenPrerequisite: boolean;
    action: { kind: 'configure' | 'view' | 'none'; href: string | null };
}

export interface OrganizationReport {
    school: { name: string };
    evaluatedAt: string;
    reports: string[];
    setup: {
        overview: SetupCenter['overview'];
        categories: Array<{
            key: string;
            label: string;
            applicable: number;
            configured: number;
            incomplete: number;
            attention: number;
            blocked: number;
        }>;
        notEnabled: string[];
    };
    gaps: ReportGap[];
    integrations:
        | { available: false }
        | {
              available: true;
              counts: Record<IntegrationStatus, number>;
              hiddenFamilies: number;
              verificationRequired: string[];
              href: string;
          }
        | null;
    presentation:
        | { available: false }
        | {
              available: true;
              brandSource: 'system' | 'school' | 'network' | 'mixed';
              networkName: string | null;
              attention: string | null;
              navigation: { staff_web: number; guardian_portal: number };
              mediaAvailable: boolean;
              href: string;
          }
        | null;
    access:
        | { available: false }
        | {
              available: true;
              roleTemplates: number;
              wholeSchoolAssignments: number;
              campusAssignments: number;
              sensitiveData: { families: number; status: string; configurable: boolean };
              href: string;
          }
        | null;
}

export const brandSourceLabels: Record<string, string> = {
    system: 'Lycenza default (no School brand)',
    school: "The School's own brand",
    network: 'Inherited from the Network',
    mixed: "The School's own values, with the Network brand for the rest",
};
