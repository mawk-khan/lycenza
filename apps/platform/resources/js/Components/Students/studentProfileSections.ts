// SIS.EXP-1: the server's Student 360 payload (App\Support\StudentProfile\
// StudentProfileSections). Every key is optional: a section is present only
// when the server authorized it.

export type SectionScope = 'school' | 'campus';

export interface StudentSibling {
    id: string;
    name: string;
    studentNumber: string;
    status: 'active' | 'inactive';
    currentPlacement: { gradeLevel: string; section: string; campus: string } | null;
    sharedGuardians: string[];
}

export interface StudentProfileSections {
    family?: { siblings: StudentSibling[]; truncated: boolean };
    subjects?: { items: { subjectName: string; subjectCode: string | null; required: boolean }[] };
    attendance?: {
        from: string;
        to: string;
        recent: {
            date: string | null;
            classLabel: string | null;
            subjectName: string | null;
            periodName: string | null;
            status: string;
            corrected: boolean;
        }[];
        link: string;
    };
    fees?: {
        scope: SectionScope;
        hasCharges: boolean;
        billed: string | null;
        concession: string | null;
        paid: string | null;
        outstanding: string | null;
        overdue: string | null;
        openCharges: number;
        nextDueDate: string | null;
        earliestOverdueDueDate: string | null;
        currency: string | null;
        statementLink: string | null;
    };
    transport?: {
        scope: SectionScope;
        assignments: {
            routeName: string;
            pickupStopName: string | null;
            dropoffStopName: string | null;
            startsOn: string;
        }[];
    };
    hostel?: {
        scope: SectionScope;
        residencies: { hostelName: string; roomCode: string; bedCode: string; startsOn: string }[];
    };
    library?: { scope: SectionScope; activeLoans: number; overdueLoans: number; link: string };
    // SIS.EXP-3: counts per category only (titles and files are on the
    // Documents page, behind current MFA).
    documents?: {
        active: number;
        archived: number;
        categories: { value: string; label: string; count: number }[];
        link: string;
    };
    // SIS 11.7: the current House (descriptive community membership, never authority).
    house?: {
        house: string | null;
        since: string | null;
        responsibilities: string[];
        link: string | null;
    };
    // SIS.EXP-4: certificate history (safe metadata).
    certificates?: {
        items: {
            id: string;
            typeLabel: string;
            number: string;
            issuedOn: string;
            status: 'issued' | 'voided';
            replacedByNumber: string | null;
            downloadUrl: string | null;
        }[];
        link: string;
    };
}
