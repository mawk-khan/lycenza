<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import ArchiveRecordButton from '../../../../Components/HR/ArchiveRecordButton.vue';
import SearchCombobox from '../../../../Components/Form/SearchCombobox.vue';
import MfaCodeField from '../../../../Components/MfaCodeField.vue';
import StatusBadge from '../../../../Components/StatusBadge.vue';

interface Summary {
    employee_id: string;
    employee_number: string;
    display_name: string;
    employee_record_status: 'active' | 'archived';
    user_linked: boolean;
    current_employment_status: string | null;
    position_name: string | null;
    department_name: string | null;
    campus_name: string | null;
    manager_display_name: string | null;
}

interface PersonalDetails {
    date_of_birth: string | null;
    nationality: string | null;
    marital_status: string | null;
    preferred_language: string | null;
}

interface Contact {
    personal_email: string | null;
    personal_phone: string | null;
    alternate_phone: string | null;
}

interface Address {
    id: string;
    address_type: string;
    address_line1: string;
    address_line2: string | null;
    city: string | null;
    state_region: string | null;
    postal_code: string | null;
    country_code: string;
}

interface EmergencyContact {
    id: string;
    name: string;
    relationship: string | null;
    phone: string;
    alternate_phone: string | null;
    email: string | null;
    is_primary: boolean;
}

interface Employment {
    id: string;
    employment_type: string;
    starts_on: string;
    ends_on: string | null;
    status: string;
    is_current: boolean;
}

interface Assignment {
    id: string;
    employment_record_id: string;
    position_id: string | null;
    department_id: string | null;
    campus_id: string | null;
    is_primary: boolean;
    starts_on: string;
    ends_on: string | null;
    is_current: boolean;
    position_name: string | null;
    department_name: string | null;
    campus_name: string | null;
}

interface Qualification {
    id: string;
    qualification_type: string;
    qualification_name: string;
    institution: string;
    starts_on: string | null;
    completed_on: string | null;
    grade_or_result: string | null;
    verification_status: 'unverified' | 'verified' | 'rejected';
}

interface Experience {
    id: string;
    organization: string;
    job_title: string;
    starts_on: string;
    ends_on: string | null;
    description: string | null;
}

interface Certification {
    id: string;
    name: string;
    issuer: string;
    issued_on: string | null;
    expires_on: string | null;
    verification_status: 'unverified' | 'verified' | 'rejected';
}

interface DocumentEntry {
    id: string;
    category: string;
    classification_tier: string;
    issued_on: string | null;
    expires_on: string | null;
    status: string;
    // HR.2A: a governed file can be downloaded; a pre-HR.2A record is metadata only.
    has_file: boolean;
    category_label: string;
}

// HR.2A: reference codes, derived experience, stale-form tokens and the closed catalogues.
interface Duration {
    days: number;
    years: number;
    months: number;
}
interface StaffProfile {
    referenceCodes: {
        id: string;
        kind: string;
        kindLabel: string;
        value: string;
        retired: boolean;
        retireReasonLabel: string | null;
        recordedOn: string;
    }[];
    experience: { previous?: Duration & { openEnded: number }; service?: Duration };
    versions: { identity?: string; personal?: string };
    can: { manageReferenceCodes: boolean };
    options: {
        documentCategories: { value: string; label: string; requiredTier: string | null }[];
        archiveReasons: CodeOption[];
        referenceKinds: CodeOption[];
        retireReasons: CodeOption[];
    };
}

interface Note {
    id: string;
    body: string;
    classification_tier: string;
    author_display_name: string | null;
    created_at: string;
}

interface Workspace {
    summary: Summary;
    personal_details: PersonalDetails | null;
    contact: Contact | null;
    addresses: Address[];
    emergency_contacts: EmergencyContact[];
    employment_history: Employment[];
    assignments: Assignment[];
    qualifications: Qualification[];
    experience: Experience[];
    certifications: Certification[];
    documents: DocumentEntry[];
    notes: Note[];
}

interface RefOption {
    id: string;
    name: string;
}

interface CodeOption {
    value: string;
    label: string;
}

// HR.1A: an employment's dated terms and named placement changes.
interface WorkforceHistory {
    currentTerms: {
        employmentType: string;
        employmentTypeLabel: string;
        staffType: string | null;
        staffTypeLabel: string;
        categoryName: string | null;
    } | null;
    terms: {
        id: string;
        effectiveFrom: string;
        employmentTypeLabel: string;
        staffTypeLabel: string;
        categoryName: string | null;
        reasonLabel: string;
    }[];
    changes: {
        id: string;
        effectiveOn: string;
        kind: string;
        kindLabel: string;
        reasonLabel: string;
        from: string | null;
        to: string;
    }[];
    // HR.3A: the employment's reporting lines (voided corrections included, marked).
    reporting: {
        id: string;
        managerName: string | null;
        startsOn: string;
        endsOn: string | null;
        startedLabel: string;
        startReasonLabel: string | null;
        endedLabel: string | null;
        endReasonLabel: string | null;
        voided: boolean;
        voidReasonLabel: string | null;
    }[];
}

interface Props {
    workspace: Workspace;
    can: {
        manageEmployees: boolean;
        managePersonal: boolean;
        manageAssignments: boolean;
        recordSeparations: boolean;
        viewSeparations: boolean;
        manageQualifications: boolean;
        manageDocuments: boolean;
        manageNotes: boolean;
        viewTeachingAssignments: boolean;
    };
    positions: RefOption[];
    departments: RefOption[];
    employeeCategories: RefOption[];
    campuses: RefOption[];
    workforceHistory: Record<string, WorkforceHistory>;
    workforceOptions: {
        employmentTypes: CodeOption[];
        staffTypes: CodeOption[];
        termsReasons: CodeOption[];
        placementKinds: CodeOption[];
        placementReasons: CodeOption[];
        reportingReasons: CodeOption[];
    };
    hasMfaFactor: boolean;
    linkedAccountName: string | null;
    staffProfile: StaffProfile;
}

interface AccountCandidate {
    userId: string;
    name: string;
    // S-15 (HR.1A): staff / guardian / member -- shown, never a role name.
    accountKind: 'staff' | 'guardian' | 'member';
}

const accountKindLabels: Record<AccountCandidate['accountKind'], string> = {
    staff: 'staff account',
    guardian: 'Guardian-only account',
    member: 'no role yet',
};

interface ManagerCandidate {
    assignmentId: string;
    label: string;
}

const props = defineProps<Props>();
const base = `/app/hr/employees/${props.workspace.summary.employee_id}`;

function toggleArchive(): void {
    const action =
        props.workspace.summary.employee_record_status === 'active' ? 'archive' : 'restore';
    const verb = action === 'archive' ? 'Archive' : 'Restore';
    if (!window.confirm(`${verb} ${props.workspace.summary.display_name}?`)) return;
    router.post(`${base}/${action}`, {}, { preserveScroll: true });
}

// --- Personal details / contact (one combined form) --------------------

const editingPersonal = ref(false);
const personalForm = useForm({
    date_of_birth: props.workspace.personal_details?.date_of_birth ?? '',
    nationality: props.workspace.personal_details?.nationality ?? '',
    marital_status: props.workspace.personal_details?.marital_status ?? '',
    preferred_language: props.workspace.personal_details?.preferred_language ?? '',
    personal_email: props.workspace.contact?.personal_email ?? '',
    personal_phone: props.workspace.contact?.personal_phone ?? '',
    alternate_phone: props.workspace.contact?.alternate_phone ?? '',
    // HR.2A: the stale-form token (the save refuses a form opened before a newer change).
    version: props.staffProfile.versions.personal ?? '',
});
function submitPersonal(): void {
    personalForm.put(`${base}/personal-detail`, {
        onSuccess: () => (editingPersonal.value = false),
    });
}

// --- Addresses -----------------------------------------------------------

const showAddressForm = ref(false);
const addressForm = useForm({
    address_type: 'current',
    address_line1: '',
    address_line2: '',
    city: '',
    state_region: '',
    postal_code: '',
    country_code: 'IN',
});
function submitAddress(): void {
    addressForm.post(`${base}/addresses`, {
        preserveScroll: true,
        onSuccess: () => {
            showAddressForm.value = false;
            addressForm.reset();
        },
    });
}

// --- Emergency contacts ----------------------------------------------------

const showContactForm = ref(false);
const contactForm = useForm({
    name: '',
    relationship: '',
    phone: '',
    alternate_phone: '',
    email: '',
});
function submitContact(): void {
    contactForm.post(`${base}/emergency-contacts`, {
        preserveScroll: true,
        onSuccess: () => {
            showContactForm.value = false;
            contactForm.reset();
        },
    });
}
function makeContactPrimary(id: string): void {
    router.post(`${base}/emergency-contacts/${id}/primary`, {}, { preserveScroll: true });
}

// --- Employment / lifecycle ------------------------------------------------

const showEmploymentForm = ref(false);
const employmentForm = useForm({
    employment_type: 'permanent',
    staff_type: '',
    employee_category_id: '',
    starts_on: '',
});
function submitEmployment(): void {
    employmentForm.post(`${base}/employment-records`, {
        preserveScroll: true,
        onSuccess: () => {
            showEmploymentForm.value = false;
            employmentForm.reset();
        },
    });
}

const endingEmploymentId = ref<string | null>(null);
// HR.12: the only direct end is a death (fresh MFA + confirmation); every
// other separation is a case another person approves (HR-061).
const endEmploymentForm = useForm({
    ends_on: '',
    status: 'deceased',
    mfa_code: '',
    confirm: false as boolean,
});
function submitEndEmployment(id: string): void {
    endEmploymentForm.post(`${base}/employment-records/${id}/end`, {
        preserveScroll: true,
        onSuccess: () => (endingEmploymentId.value = null),
    });
}

const showRehireForm = ref(false);
const rehireForm = useForm({ employment_type: 'permanent', staff_type: '', starts_on: '' });
function submitRehire(): void {
    rehireForm.post(`${base}/rehire`, {
        preserveScroll: true,
        onSuccess: () => (showRehireForm.value = false),
    });
}

// HR.1A: a dated, reasoned terms change (appended; earlier terms stay).
const termsEmploymentId = ref<string | null>(null);
const termsForm = useForm({
    effective_from: '',
    employment_type: 'permanent',
    staff_type: '',
    employee_category_id: '',
    reason_code: '',
});
function openTerms(e: Employment): void {
    if (termsEmploymentId.value === e.id) {
        termsEmploymentId.value = null;
        return;
    }
    const current = props.workforceHistory[e.id]?.currentTerms;
    termsForm.reset();
    termsForm.clearErrors();
    termsForm.employment_type = current?.employmentType ?? e.employment_type;
    termsForm.staff_type = current?.staffType ?? '';
    termsEmploymentId.value = e.id;
}
function submitTerms(id: string): void {
    termsForm.post(`${base}/employment-records/${id}/terms`, {
        preserveScroll: true,
        onSuccess: () => (termsEmploymentId.value = null),
    });
}

// --- Assignments -----------------------------------------------------------

// HR.1A: the named, dated placement change of the primary assignment.
const placementAssignmentId = ref<string | null>(null);
const placementForm = useForm({
    change_kind: '',
    effective_on: '',
    position_id: '',
    department_id: '',
    campus_id: '',
    reason_code: '',
});
function openPlacement(a: Assignment): void {
    if (placementAssignmentId.value === a.id) {
        placementAssignmentId.value = null;
        return;
    }
    placementForm.reset();
    placementForm.clearErrors();
    placementForm.position_id = a.position_id ?? '';
    placementForm.department_id = a.department_id ?? '';
    placementForm.campus_id = a.campus_id ?? '';
    placementAssignmentId.value = a.id;
}
function submitPlacement(a: Assignment): void {
    placementForm.post(
        `${base}/employment-records/${a.employment_record_id}/assignments/${a.id}/placement-change`,
        {
            preserveScroll: true,
            onSuccess: () => (placementAssignmentId.value = null),
        },
    );
}

const assigningEmploymentId = ref<string | null>(null);
const assignmentForm = useForm({
    starts_on: '',
    position_id: '',
    campus_id: '',
    department_id: '',
});
function submitAssignment(employmentId: string): void {
    assignmentForm.post(`${base}/employment-records/${employmentId}/assignments`, {
        preserveScroll: true,
        onSuccess: () => (assigningEmploymentId.value = null),
    });
}

const endingAssignmentId = ref<string | null>(null);
const endAssignmentForm = useForm({ ends_on: '' });
function submitEndAssignment(a: Assignment): void {
    endAssignmentForm.post(
        `${base}/employment-records/${a.employment_record_id}/assignments/${a.id}/end`,
        {
            preserveScroll: true,
            onSuccess: () => (endingAssignmentId.value = null),
        },
    );
}
function makeAssignmentPrimary(a: Assignment): void {
    router.post(
        `${base}/employment-records/${a.employment_record_id}/assignments/${a.id}/primary`,
        {},
        { preserveScroll: true },
    );
}

// UAT-C6 (UAT-HR-23): the manager is picked by name; the request still
// carries the chosen Assignment, and the server re-validates everything.
const managingAssignmentId = ref<string | null>(null);
// HR.3A: a dated, reasoned reporting line (effective date: blank = today).
const managerForm = useForm({ manager_assignment_id: '', reason_code: '', effective_on: '' });
const managerQuery = ref('');
const managerOptions = ref<ManagerCandidate[]>([]);
let managerDebounce: ReturnType<typeof setTimeout> | undefined;
function managerUrl(a: Assignment): string {
    return `${base}/employment-records/${a.employment_record_id}/assignments/${a.id}`;
}
function toggleManager(a: Assignment): void {
    managingAssignmentId.value = managingAssignmentId.value === a.id ? null : a.id;
    managerForm.reset();
    managerForm.clearErrors();
    managerQuery.value = '';
    managerOptions.value = [];
}
watch(managerQuery, (value) => {
    const a = props.workspace.assignments.find((x) => x.id === managingAssignmentId.value);
    clearTimeout(managerDebounce);
    if (!a || value.trim() === '') {
        managerOptions.value = [];
        return;
    }
    managerDebounce = setTimeout(async () => {
        const response = await fetch(
            `${managerUrl(a)}/manager-candidates?q=${encodeURIComponent(value)}`,
        );
        // A refused or non-JSON answer is simply no results.
        const body = response.ok
            ? await response.json().catch(() => ({ candidates: [] }))
            : { candidates: [] };
        managerOptions.value = body.candidates;
    }, 250);
});
function pickManager(c: ManagerCandidate): void {
    managerForm.manager_assignment_id = c.assignmentId;
    managerOptions.value = [];
    managerQuery.value = c.label;
}
function submitManager(a: Assignment): void {
    managerForm.post(`${managerUrl(a)}/manager`, {
        preserveScroll: true,
        onSuccess: () => (managingAssignmentId.value = null),
    });
}
function clearManager(a: Assignment): void {
    managerForm.manager_assignment_id = '';
    submitManager(a);
}

// HR.3A: correct a recorded reporting line -- void it (kept, marked) and,
// when a manager is chosen, record the corrected line over the same dates.
// The manager search reuses an assignment of the same employment.
const correctingLineId = ref<string | null>(null);
const correctionForm = useForm({ manager_assignment_id: '', reason_code: '' });
const correctionQuery = ref('');
const correctionOptions = ref<ManagerCandidate[]>([]);
let correctionDebounce: ReturnType<typeof setTimeout> | undefined;
let correctionEmploymentId: string | null = null;
function toggleCorrection(employmentId: string, lineId: string): void {
    correctingLineId.value = correctingLineId.value === lineId ? null : lineId;
    correctionEmploymentId = employmentId;
    correctionForm.reset();
    correctionForm.clearErrors();
    correctionQuery.value = '';
    correctionOptions.value = [];
}
watch(correctionQuery, (value) => {
    const a = props.workspace.assignments.find(
        (x) => x.employment_record_id === correctionEmploymentId,
    );
    clearTimeout(correctionDebounce);
    if (!a || value.trim() === '') {
        correctionOptions.value = [];
        return;
    }
    correctionDebounce = setTimeout(async () => {
        const response = await fetch(
            `${managerUrl(a)}/manager-candidates?q=${encodeURIComponent(value)}`,
        );
        const body = response.ok
            ? await response.json().catch(() => ({ candidates: [] }))
            : { candidates: [] };
        correctionOptions.value = body.candidates;
    }, 250);
});
function pickCorrection(c: ManagerCandidate): void {
    correctionForm.manager_assignment_id = c.assignmentId;
    correctionOptions.value = [];
    correctionQuery.value = c.label;
}
function submitCorrection(employmentId: string, lineId: string): void {
    correctionForm.post(
        `${base}/employment-records/${employmentId}/reporting-lines/${lineId}/correct`,
        { preserveScroll: true, onSuccess: () => (correctingLineId.value = null) },
    );
}

// --- Sign-in account link (UAT-C6, UAT-STF-05) ----------------------------
// The governed Employee <-> User link (EmployeeService::linkUser/unlinkUser).

// Refusals of a link or removal (including "never yourself", ADR 0071 §26.2).
const accountError = computed(() => {
    const errors = usePage().props.errors as Record<string, string | undefined>;
    return errors.self_administration ?? errors.account ?? null;
});
const accountQuery = ref('');
const accountOptions = ref<AccountCandidate[]>([]);
const accountForm = useForm({ account: '' });
let accountDebounce: ReturnType<typeof setTimeout> | undefined;
watch(accountQuery, (value) => {
    clearTimeout(accountDebounce);
    if (value.trim() === '') {
        accountOptions.value = [];
        return;
    }
    accountDebounce = setTimeout(async () => {
        const response = await fetch(`${base}/account-link/search?q=${encodeURIComponent(value)}`);
        const body = response.ok
            ? await response.json().catch(() => ({ candidates: [] }))
            : { candidates: [] };
        accountOptions.value = body.candidates;
    }, 250);
});
function pickAccount(c: AccountCandidate): void {
    accountForm.account = c.userId;
    accountOptions.value = [];
    accountQuery.value = c.name;
}
function submitAccountLink(): void {
    accountForm.post(`${base}/account-link`, {
        preserveScroll: true,
        onSuccess: () => {
            accountForm.reset();
            accountQuery.value = '';
        },
    });
}
function removeAccountLink(): void {
    if (
        !window.confirm(
            `Remove the link between ${props.workspace.summary.display_name} and the account ${props.linkedAccountName ?? ''}? Their own attendance, leave and payslip pages stop working until an account is linked again.`,
        )
    ) {
        return;
    }
    router.post(`${base}/account-link/remove`, {}, { preserveScroll: true });
}

// --- Qualifications ----------------------------------------------------------

const showQualificationForm = ref(false);
const qualificationForm = useForm({
    qualification_type: 'bachelors',
    qualification_name: '',
    institution: '',
    completed_on: '',
});
function submitQualification(): void {
    qualificationForm.post(`${base}/qualifications`, {
        preserveScroll: true,
        onSuccess: () => {
            showQualificationForm.value = false;
            qualificationForm.reset();
        },
    });
}
function verifyQualification(id: string): void {
    router.post(`${base}/qualifications/${id}/verify`, {}, { preserveScroll: true });
}
function rejectQualification(id: string): void {
    router.post(`${base}/qualifications/${id}/reject`, {}, { preserveScroll: true });
}

// --- Experience ----------------------------------------------------------

const showExperienceForm = ref(false);
const experienceForm = useForm({
    organization: '',
    job_title: '',
    starts_on: '',
    ends_on: '',
    description: '',
});
function submitExperience(): void {
    experienceForm.post(`${base}/experience`, {
        preserveScroll: true,
        onSuccess: () => {
            showExperienceForm.value = false;
            experienceForm.reset();
        },
    });
}

// --- Certifications ----------------------------------------------------------

const showCertificationForm = ref(false);
const certificationForm = useForm({ name: '', issuer: '', issued_on: '', expires_on: '' });
function submitCertification(): void {
    certificationForm.post(`${base}/certifications`, {
        preserveScroll: true,
        onSuccess: () => {
            showCertificationForm.value = false;
            certificationForm.reset();
        },
    });
}
function verifyCertification(id: string): void {
    router.post(`${base}/certifications/${id}/verify`, {}, { preserveScroll: true });
}
function rejectCertification(id: string): void {
    router.post(`${base}/certifications/${id}/reject`, {}, { preserveScroll: true });
}

// --- Documents (governed uploads, HR.2A) ------------------------------------

const showDocumentForm = ref(false);
// HR.2A (S-12, D1-26): an upload through the platform Documents repository,
// with a closed category -- never a typed storage path.
const documentForm = useForm<{
    file: File | null;
    category: string;
    classification_tier: string;
    issued_on: string;
    expires_on: string;
    mfa_code: string;
}>({
    file: null,
    category: '',
    classification_tier: 'restricted',
    issued_on: '',
    expires_on: '',
    // SR.4 (ADR 0071 §26.7): only a highly sensitive record needs a fresh code.
    mfa_code: '',
});
// An identity document is always Highly Sensitive (the server enforces it too).
watch(
    () => documentForm.category,
    (category) => {
        const required = props.staffProfile.options.documentCategories.find(
            (c) => c.value === category,
        )?.requiredTier;
        if (required) documentForm.classification_tier = required;
    },
);
function pickDocumentFile(event: Event): void {
    documentForm.file = (event.target as HTMLInputElement).files?.[0] ?? null;
}
function submitDocument(): void {
    documentForm.post(`${base}/hr-document-records`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            showDocumentForm.value = false;
            documentForm.reset();
        },
        onError: () => documentForm.reset('mfa_code'),
    });
}
function archiveDocument(id: string): void {
    router.post(`${base}/hr-document-records/${id}/archive`, {}, { preserveScroll: true });
}

// --- Reference codes (HR.2A, D1-03) ------------------------------------------

const showReferenceForm = ref(false);
const referenceForm = useForm({ kind: '', value: '' });
// A refusal (code taken, kind taken, format) arrives on the form's error bag.
const referenceError = computed(() => {
    const errors = referenceForm.errors as Record<string, string | undefined>;
    return errors.value ?? errors.kind ?? errors.reference_code ?? null;
});
function submitReference(): void {
    referenceForm.post(`${base}/reference-codes`, {
        preserveScroll: true,
        onSuccess: () => {
            showReferenceForm.value = false;
            referenceForm.reset();
        },
    });
}
const retiringReferenceId = ref<string | null>(null);
const retireReferenceForm = useForm({ reason_code: '' });
function submitRetireReference(id: string): void {
    retireReferenceForm.post(`${base}/reference-codes/${id}/retire`, {
        preserveScroll: true,
        onSuccess: () => {
            retiringReferenceId.value = null;
            retireReferenceForm.reset();
        },
    });
}
function duration(d: Duration | undefined): string {
    if (!d) return '';
    if (d.days === 0) return 'None recorded';
    const parts = [];
    if (d.years > 0) parts.push(`${d.years} ${d.years === 1 ? 'year' : 'years'}`);
    if (d.months > 0 || d.years === 0)
        parts.push(`${d.months} ${d.months === 1 ? 'month' : 'months'}`);
    return parts.join(' ');
}

// --- Notes ----------------------------------------------------------------

const showNoteForm = ref(false);
const noteForm = useForm({ body: '', classification_tier: 'sensitive' });
function submitNote(): void {
    noteForm.post(`${base}/notes`, {
        preserveScroll: true,
        onSuccess: () => {
            showNoteForm.value = false;
            noteForm.reset();
        },
    });
}
</script>

<template>
    <main class="mx-auto max-w-3xl p-8 font-sans text-ink">
        <a class="text-sm underline" href="/app/hr/employees">← Employees</a>

        <div class="mt-2 flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">{{ workspace.summary.display_name }}</h1>
                <p class="mt-1 text-sm text-ink-muted">{{ workspace.summary.employee_number }}</p>
                <p v-if="can.viewTeachingAssignments" class="mt-1 text-sm">
                    <a
                        class="underline"
                        :href="`/app/teaching-assignments?employee_id=${workspace.summary.employee_id}`"
                        >Teaching assignments</a
                    >
                </p>
            </div>
            <div v-if="can.manageEmployees" class="flex items-center gap-2">
                <a :href="`${base}/edit`" class="btn btn-secondary"> Edit </a>
                <button type="button" class="btn btn-secondary" @click="toggleArchive">
                    {{
                        workspace.summary.employee_record_status === 'active'
                            ? 'Archive'
                            : 'Restore'
                    }}
                </button>
            </div>
        </div>

        <!-- Summary -->
        <section class="mt-8">
            <h2 class="text-sm font-medium text-ink-muted">Summary</h2>
            <dl
                class="mt-2 grid grid-cols-1 gap-x-8 gap-y-3 rounded-xs border border-line p-4 text-sm sm:grid-cols-2"
            >
                <div>
                    <dt class="text-ink-muted">Status</dt>
                    <dd class="mt-0.5">
                        <StatusBadge :status="workspace.summary.employee_record_status" />
                    </dd>
                </div>
                <div>
                    <dt class="text-ink-muted">Current employment status</dt>
                    <dd class="mt-0.5">{{ workspace.summary.current_employment_status ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-ink-muted">Position</dt>
                    <dd class="mt-0.5">{{ workspace.summary.position_name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-ink-muted">Department</dt>
                    <dd class="mt-0.5">{{ workspace.summary.department_name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-ink-muted">Campus</dt>
                    <dd class="mt-0.5">{{ workspace.summary.campus_name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-ink-muted">Reports to</dt>
                    <dd class="mt-0.5">{{ workspace.summary.manager_display_name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-ink-muted">Sign-in account</dt>
                    <dd class="mt-0.5">
                        <template v-if="workspace.summary.user_linked">
                            {{ linkedAccountName ?? 'Linked' }}
                        </template>
                        <template v-else>Not linked</template>
                    </dd>
                </div>
                <!-- HR.2A: derived from the dated records, never a stored number. -->
                <div v-if="staffProfile.experience.previous">
                    <dt class="text-ink-muted">Previous experience</dt>
                    <dd class="mt-0.5">
                        {{ duration(staffProfile.experience.previous) }}
                        <span
                            v-if="staffProfile.experience.previous.openEnded > 0"
                            class="text-ink-muted"
                        >
                            ({{ staffProfile.experience.previous.openEnded }} without an end date,
                            not counted)
                        </span>
                    </dd>
                </div>
                <div v-if="staffProfile.experience.service">
                    <dt class="text-ink-muted">Service in this School</dt>
                    <dd class="mt-0.5">{{ duration(staffProfile.experience.service) }}</dd>
                </div>
            </dl>

            <div
                v-if="can.manageEmployees"
                class="mt-3 rounded-xs border border-line p-4 text-sm"
                aria-labelledby="account-link-heading"
            >
                <h3 id="account-link-heading" class="font-medium text-ink">Sign-in account</h3>
                <template v-if="workspace.summary.user_linked">
                    <p class="mt-1 text-ink-muted">
                        Linked to <strong>{{ linkedAccountName }}</strong
                        >. This person's own attendance, leave and payslip pages use this Employee
                        record. To link a different account, remove this link first.
                    </p>
                    <button
                        type="button"
                        class="mt-2 rounded-xs border border-danger/40 px-3 py-1.5 font-medium text-danger hover:bg-danger-tint"
                        @click="removeAccountLink"
                    >
                        Remove link
                    </button>
                    <p v-if="accountError" class="mt-1 text-danger" role="alert">
                        {{ accountError }}
                    </p>
                </template>
                <form
                    v-else-if="workspace.summary.employee_record_status === 'active'"
                    class="mt-1"
                    @submit.prevent="submitAccountLink"
                >
                    <p class="text-ink-muted">
                        Link the Lycenza account this person signs in with (for example after they
                        accept a staff invitation), so their own attendance, leave and payslip pages
                        work.
                    </p>
                    <label class="mt-2 block text-ink-muted" for="account-search"
                        >Account (an active member of this School)</label
                    >
                    <div class="relative mt-1 flex flex-wrap items-start gap-3">
                        <div class="relative w-full max-w-md">
                            <SearchCombobox
                                id="account-search"
                                v-model="accountQuery"
                                placeholder="Search by name"
                                class="w-full rounded-xs border border-control-line px-3 py-2 text-sm"
                                :options="accountOptions"
                                :option-key="(o) => o.userId"
                                :error="accountForm.errors.account"
                                @select="pickAccount"
                            >
                                <template #option="{ option }"
                                    >{{ option.name }}
                                    <span class="text-ink-muted"
                                        >({{ accountKindLabels[option.accountKind] }})</span
                                    ></template
                                >
                            </SearchCombobox>
                        </div>
                        <button
                            type="submit"
                            :disabled="accountForm.processing || accountForm.account === ''"
                            class="btn btn-primary"
                        >
                            Link account
                        </button>
                    </div>
                    <p
                        v-if="accountError && !accountForm.errors.account"
                        class="mt-1 text-danger"
                        role="alert"
                    >
                        {{ accountError }}
                    </p>
                </form>
                <p v-else class="mt-1 text-ink-muted">
                    An archived Employee cannot be linked to an account.
                </p>
            </div>
        </section>

        <!-- HR.2A: the Profile's sections. -->
        <nav aria-label="Profile sections" class="mt-6 flex flex-wrap gap-x-4 gap-y-1 text-sm">
            <a class="underline" href="#profile-reference-codes">Reference codes</a>
            <a class="underline" href="#profile-personal">Personal &amp; contact</a>
            <a class="underline" href="#profile-addresses">Addresses</a>
            <a class="underline" href="#profile-employment">Employment &amp; reporting</a>
            <a
                v-if="workspace.qualifications.length > 0 || can.manageQualifications"
                class="underline"
                href="#profile-qualifications"
                >Qualifications &amp; experience</a
            >
            <a class="underline" href="#profile-documents">Documents</a>
        </nav>

        <!-- HR.2A (D1-03): external reference codes -- never the identity, never reused. -->
        <section id="profile-reference-codes" class="mt-8">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-medium text-ink-muted">Reference codes</h2>
                <button
                    v-if="staffProfile.can.manageReferenceCodes"
                    type="button"
                    class="text-sm font-medium underline"
                    @click="showReferenceForm = !showReferenceForm"
                >
                    {{ showReferenceForm ? 'Cancel' : '+ Add reference code' }}
                </button>
            </div>
            <p v-if="staffProfile.referenceCodes.length === 0" class="mt-3 text-sm text-ink-muted">
                No reference codes recorded.
            </p>
            <ul v-else class="mt-3 space-y-2">
                <li
                    v-for="r in staffProfile.referenceCodes"
                    :key="r.id"
                    class="rounded-xs border border-line p-3 text-sm"
                    :class="r.retired ? 'text-ink-muted' : ''"
                >
                    <div class="flex items-start justify-between gap-3">
                        <p>
                            <span class="font-medium">{{ r.kindLabel }}:</span> {{ r.value }}
                            <span v-if="r.retired">
                                (retired<template v-if="r.retireReasonLabel"
                                    >: {{ r.retireReasonLabel }}</template
                                >)</span
                            >
                        </p>
                        <button
                            v-if="staffProfile.can.manageReferenceCodes && !r.retired"
                            type="button"
                            class="text-danger underline"
                            @click="
                                retiringReferenceId = retiringReferenceId === r.id ? null : r.id
                            "
                        >
                            Retire
                        </button>
                    </div>
                    <form
                        v-if="retiringReferenceId === r.id"
                        class="mt-2 flex flex-wrap items-end gap-3"
                        @submit.prevent="submitRetireReference(r.id)"
                    >
                        <div>
                            <label
                                class="block text-sm text-ink-muted"
                                :for="`retire-reference-${r.id}`"
                                >Reason</label
                            >
                            <select
                                :id="`retire-reference-${r.id}`"
                                v-model="retireReferenceForm.reason_code"
                                required
                                class="input mt-1 w-auto"
                            >
                                <option value="" disabled>Select a reason</option>
                                <option
                                    v-for="o in staffProfile.options.retireReasons"
                                    :key="o.value"
                                    :value="o.value"
                                >
                                    {{ o.label }}
                                </option>
                            </select>
                        </div>
                        <button
                            type="submit"
                            :disabled="
                                retireReferenceForm.processing ||
                                retireReferenceForm.reason_code === ''
                            "
                            class="btn btn-primary"
                        >
                            Retire code
                        </button>
                        <p class="w-full text-sm text-danger">
                            {{ retireReferenceForm.errors.reason_code }}
                        </p>
                    </form>
                </li>
            </ul>
            <form
                v-if="showReferenceForm"
                class="mt-3 grid grid-cols-1 gap-3 rounded-xs border border-line p-4 sm:grid-cols-2"
                @submit.prevent="submitReference"
            >
                <div>
                    <label class="block text-sm text-ink-muted" for="reference-kind">Kind</label>
                    <select
                        id="reference-kind"
                        v-model="referenceForm.kind"
                        required
                        class="input mt-1"
                    >
                        <option value="" disabled>Select a kind</option>
                        <option
                            v-for="o in staffProfile.options.referenceKinds"
                            :key="o.value"
                            :value="o.value"
                        >
                            {{ o.label }}
                        </option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm text-ink-muted" for="reference-value">Code</label>
                    <input
                        id="reference-value"
                        v-model="referenceForm.value"
                        type="text"
                        maxlength="64"
                        required
                        class="input mt-1"
                    />
                </div>
                <p class="text-sm text-danger sm:col-span-2">
                    {{ referenceError }}
                </p>
                <button
                    type="submit"
                    :disabled="referenceForm.processing"
                    class="btn btn-primary sm:col-span-2 sm:justify-self-start"
                >
                    Save reference code
                </button>
            </form>
        </section>

        <!-- Personal & contact -->
        <section id="profile-personal" class="mt-8">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-medium text-ink-muted">
                    Personal &amp; contact (Restricted)
                </h2>
                <button
                    v-if="can.managePersonal"
                    type="button"
                    class="text-sm font-medium underline"
                    @click="editingPersonal = !editingPersonal"
                >
                    {{ editingPersonal ? 'Cancel' : 'Edit' }}
                </button>
            </div>

            <dl
                v-if="!editingPersonal"
                class="mt-2 grid grid-cols-1 gap-x-8 gap-y-3 rounded-xs border border-line p-4 text-sm sm:grid-cols-2"
            >
                <div>
                    <dt class="text-ink-muted">Date of birth</dt>
                    <dd class="mt-0.5">{{ workspace.personal_details?.date_of_birth ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-ink-muted">Nationality</dt>
                    <dd class="mt-0.5">{{ workspace.personal_details?.nationality ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-ink-muted">Personal email</dt>
                    <dd class="mt-0.5">{{ workspace.contact?.personal_email ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-ink-muted">Personal phone</dt>
                    <dd class="mt-0.5">{{ workspace.contact?.personal_phone ?? '—' }}</dd>
                </div>
            </dl>

            <form
                v-else
                class="mt-3 rounded-xs border border-line p-4"
                @submit.prevent="submitPersonal"
            >
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm text-ink-muted">Date of birth</label>
                        <input
                            v-model="personalForm.date_of_birth"
                            type="date"
                            class="input mt-1"
                        />
                    </div>
                    <div>
                        <label class="block text-sm text-ink-muted">Nationality</label>
                        <input v-model="personalForm.nationality" type="text" class="input mt-1" />
                    </div>
                    <div>
                        <label class="block text-sm text-ink-muted">Marital status</label>
                        <input
                            v-model="personalForm.marital_status"
                            type="text"
                            class="input mt-1"
                        />
                    </div>
                    <div>
                        <label class="block text-sm text-ink-muted">Preferred language</label>
                        <input
                            v-model="personalForm.preferred_language"
                            type="text"
                            class="input mt-1"
                        />
                    </div>
                    <div>
                        <label class="block text-sm text-ink-muted">Personal email</label>
                        <input
                            v-model="personalForm.personal_email"
                            type="email"
                            class="input mt-1"
                        />
                    </div>
                    <div>
                        <label class="block text-sm text-ink-muted">Personal phone</label>
                        <input
                            v-model="personalForm.personal_phone"
                            type="text"
                            class="input mt-1"
                        />
                    </div>
                    <div>
                        <label class="block text-sm text-ink-muted">Alternate phone</label>
                        <input
                            v-model="personalForm.alternate_phone"
                            type="text"
                            class="input mt-1"
                        />
                    </div>
                </div>
                <button
                    type="submit"
                    :disabled="personalForm.processing"
                    class="btn btn-primary mt-3"
                >
                    Save
                </button>
            </form>
        </section>

        <!-- Addresses -->
        <section id="profile-addresses" class="mt-8">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-medium text-ink-muted">Addresses</h2>
                <button
                    v-if="can.managePersonal"
                    type="button"
                    class="text-sm font-medium underline"
                    @click="showAddressForm = !showAddressForm"
                >
                    {{ showAddressForm ? 'Cancel' : '+ Add address' }}
                </button>
            </div>

            <p v-if="workspace.addresses.length === 0" class="mt-3 text-sm text-ink-muted">
                No addresses yet.
            </p>
            <ul v-else class="mt-3 space-y-2">
                <li
                    v-for="a in workspace.addresses"
                    :key="a.id"
                    class="flex items-start justify-between gap-3 rounded-xs border border-line p-3 text-sm"
                >
                    <div>
                        <p class="font-medium capitalize">{{ a.address_type }}</p>
                        <p class="mt-0.5 text-ink-muted">
                            {{
                                [
                                    a.address_line1,
                                    a.address_line2,
                                    a.city,
                                    a.state_region,
                                    a.postal_code,
                                    a.country_code,
                                ]
                                    .filter(Boolean)
                                    .join(', ')
                            }}
                        </p>
                    </div>
                    <ArchiveRecordButton
                        v-if="can.managePersonal"
                        :url="`${base}/addresses/${a.id}`"
                        subject="this address"
                        :reasons="staffProfile.options.archiveReasons"
                        :control-id="`archive-address-${a.id}`"
                    />
                </li>
            </ul>

            <form
                v-if="showAddressForm"
                class="mt-3 rounded-xs border border-line p-4"
                @submit.prevent="submitAddress"
            >
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm text-ink-muted">Type</label>
                        <select v-model="addressForm.address_type" class="input mt-1">
                            <option value="current">Current</option>
                            <option value="permanent">Permanent</option>
                            <option value="mailing">Mailing</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm text-ink-muted">Address line 1</label>
                        <input
                            v-model="addressForm.address_line1"
                            type="text"
                            required
                            class="input mt-1"
                        />
                    </div>
                    <div>
                        <label class="block text-sm text-ink-muted">City</label>
                        <input v-model="addressForm.city" type="text" class="input mt-1" />
                    </div>
                    <div>
                        <label class="block text-sm text-ink-muted">State/region</label>
                        <input v-model="addressForm.state_region" type="text" class="input mt-1" />
                    </div>
                    <div>
                        <label class="block text-sm text-ink-muted">Postal code</label>
                        <input v-model="addressForm.postal_code" type="text" class="input mt-1" />
                    </div>
                    <div>
                        <label class="block text-sm text-ink-muted">Country code</label>
                        <input
                            v-model="addressForm.country_code"
                            type="text"
                            maxlength="2"
                            class="input mt-1"
                        />
                    </div>
                </div>
                <button
                    type="submit"
                    :disabled="addressForm.processing"
                    class="btn btn-primary mt-3"
                >
                    Add address
                </button>
            </form>
        </section>

        <!-- Emergency contacts -->
        <section class="mt-8">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-medium text-ink-muted">Emergency contacts</h2>
                <button
                    v-if="can.managePersonal"
                    type="button"
                    class="text-sm font-medium underline"
                    @click="showContactForm = !showContactForm"
                >
                    {{ showContactForm ? 'Cancel' : '+ Add contact' }}
                </button>
            </div>

            <p v-if="workspace.emergency_contacts.length === 0" class="mt-3 text-sm text-ink-muted">
                No emergency contacts yet.
            </p>
            <ul v-else class="mt-3 space-y-2">
                <li
                    v-for="c in workspace.emergency_contacts"
                    :key="c.id"
                    class="flex items-start justify-between gap-3 rounded-xs border border-line p-3 text-sm"
                >
                    <div>
                        <p class="font-medium">
                            {{ c.name }}
                            <span
                                v-if="c.is_primary"
                                class="ml-2 rounded-xs bg-graphite-100 px-2 py-0.5 text-xs text-ink"
                                >Primary</span
                            >
                        </p>
                        <p class="mt-0.5 text-ink-muted">
                            {{ [c.relationship, c.phone, c.email].filter(Boolean).join(' · ') }}
                        </p>
                    </div>
                    <div v-if="can.managePersonal" class="flex shrink-0 items-center gap-3">
                        <button
                            v-if="!c.is_primary"
                            type="button"
                            class="underline"
                            @click="makeContactPrimary(c.id)"
                        >
                            Make primary
                        </button>
                        <ArchiveRecordButton
                            :url="`${base}/emergency-contacts/${c.id}`"
                            subject="this emergency contact"
                            :reasons="staffProfile.options.archiveReasons"
                            :control-id="`archive-contact-${c.id}`"
                        />
                    </div>
                </li>
            </ul>

            <form
                v-if="showContactForm"
                class="mt-3 rounded-xs border border-line p-4"
                @submit.prevent="submitContact"
            >
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm text-ink-muted">Name</label>
                        <input v-model="contactForm.name" type="text" required class="input mt-1" />
                    </div>
                    <div>
                        <label class="block text-sm text-ink-muted">Relationship</label>
                        <input v-model="contactForm.relationship" type="text" class="input mt-1" />
                    </div>
                    <div>
                        <label class="block text-sm text-ink-muted">Phone</label>
                        <input
                            v-model="contactForm.phone"
                            type="text"
                            required
                            class="input mt-1"
                        />
                    </div>
                    <div>
                        <label class="block text-sm text-ink-muted">Email</label>
                        <input v-model="contactForm.email" type="email" class="input mt-1" />
                    </div>
                </div>
                <button
                    type="submit"
                    :disabled="contactForm.processing"
                    class="btn btn-primary mt-3"
                >
                    Add contact
                </button>
            </form>
        </section>

        <!-- Employment history -->
        <section id="profile-employment" class="mt-8">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-medium text-ink-muted">Employment history</h2>
                <div v-if="can.manageAssignments" class="flex items-center gap-3">
                    <button
                        type="button"
                        class="text-sm font-medium underline"
                        @click="showRehireForm = !showRehireForm"
                    >
                        {{ showRehireForm ? 'Cancel' : 'Rehire' }}
                    </button>
                    <button
                        type="button"
                        class="text-sm font-medium underline"
                        @click="showEmploymentForm = !showEmploymentForm"
                    >
                        {{ showEmploymentForm ? 'Cancel' : '+ Add employment' }}
                    </button>
                </div>
            </div>

            <p v-if="workspace.employment_history.length === 0" class="mt-3 text-sm text-ink-muted">
                No employment history visible (either none exists yet, or it requires
                assignments-view access).
            </p>
            <ul v-else class="mt-3 space-y-2">
                <li
                    v-for="e in workspace.employment_history"
                    :key="e.id"
                    class="rounded-xs border border-line p-3 text-sm"
                >
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="font-medium">
                                {{
                                    workforceHistory[e.id]?.currentTerms?.employmentTypeLabel ??
                                    e.employment_type
                                }}
                                <span
                                    v-if="workforceHistory[e.id]?.currentTerms"
                                    class="ml-1 text-ink-muted"
                                    >·
                                    {{ workforceHistory[e.id]?.currentTerms?.staffTypeLabel }}</span
                                >
                                <span
                                    v-if="e.is_current"
                                    class="ml-2 rounded-xs bg-graphite-100 px-2 py-0.5 text-xs text-ink"
                                    >Current</span
                                >
                            </p>
                            <p class="mt-0.5 text-ink-muted">
                                {{ e.starts_on }} – {{ e.ends_on ?? 'present' }} · {{ e.status }}
                            </p>
                        </div>
                        <div v-if="can.manageAssignments" class="flex items-center gap-3">
                            <button
                                type="button"
                                class="underline"
                                @click="
                                    assigningEmploymentId =
                                        assigningEmploymentId === e.id ? null : e.id
                                "
                            >
                                {{ assigningEmploymentId === e.id ? 'Cancel' : '+ Assignment' }}
                            </button>
                            <button
                                v-if="e.ends_on === null"
                                type="button"
                                class="underline"
                                @click="openTerms(e)"
                            >
                                {{ termsEmploymentId === e.id ? 'Cancel' : 'Change terms' }}
                            </button>
                            <a
                                v-if="
                                    e.ends_on === null &&
                                    e.status === 'active' &&
                                    can.recordSeparations
                                "
                                class="underline"
                                :href="`/app/hr/separations/create?employment=${e.id}`"
                                >Record separation</a
                            >
                            <a
                                v-if="e.status === 'notice_period' && can.viewSeparations"
                                class="underline"
                                href="/app/hr/separations?status=approved"
                                >Separation in progress</a
                            >
                            <button
                                v-if="e.ends_on === null"
                                type="button"
                                class="text-danger underline"
                                @click="
                                    endingEmploymentId = endingEmploymentId === e.id ? null : e.id
                                "
                            >
                                {{ endingEmploymentId === e.id ? 'Cancel' : 'Record death' }}
                            </button>
                        </div>
                    </div>

                    <form
                        v-if="endingEmploymentId === e.id"
                        class="mt-3 border-t border-line pt-3"
                        @submit.prevent="submitEndEmployment(e.id)"
                    >
                        <div class="flex flex-wrap items-end gap-3">
                            <div>
                                <label class="block text-sm text-ink-muted">End date</label>
                                <input
                                    v-model="endEmploymentForm.ends_on"
                                    type="date"
                                    required
                                    class="input mt-1 w-auto"
                                />
                            </div>
                            <MfaCodeField
                                :id="`end-employment-mfa-${e.id}`"
                                v-model="endEmploymentForm.mfa_code"
                                :has-mfa-factor="hasMfaFactor"
                                action="Recording a death"
                                :error="endEmploymentForm.errors.mfa_code"
                            />
                        </div>
                        <label class="mt-3 flex items-start gap-2 text-sm">
                            <input
                                v-model="endEmploymentForm.confirm"
                                type="checkbox"
                                class="mt-1"
                            />
                            <span
                                >I confirm this employee has died. The employment ends on this date
                                as deceased; this is not how a resignation, retirement or contract
                                end is recorded.</span
                            >
                        </label>
                        <p
                            v-if="endEmploymentForm.errors.confirm"
                            class="mt-1 text-sm text-danger"
                            role="alert"
                        >
                            {{ endEmploymentForm.errors.confirm }}
                        </p>
                        <button
                            type="submit"
                            :disabled="
                                endEmploymentForm.processing ||
                                !endEmploymentForm.confirm ||
                                !hasMfaFactor
                            "
                            class="btn btn-danger mt-3"
                        >
                            Record death
                        </button>
                    </form>

                    <!-- HR.1A: dated terms change -->
                    <form
                        v-if="termsEmploymentId === e.id"
                        class="mt-3 space-y-3 border-t border-line pt-3"
                        @submit.prevent="submitTerms(e.id)"
                    >
                        <p class="text-ink-muted">
                            New terms take effect on the date below; earlier terms stay in the
                            history.
                        </p>
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <label
                                    class="block text-sm text-ink-muted"
                                    :for="`terms-from-${e.id}`"
                                    >Effective from</label
                                >
                                <input
                                    :id="`terms-from-${e.id}`"
                                    v-model="termsForm.effective_from"
                                    type="date"
                                    required
                                    class="input mt-1"
                                />
                                <p class="mt-1 text-sm text-danger">
                                    {{ termsForm.errors.effective_from }}
                                </p>
                            </div>
                            <div>
                                <label
                                    class="block text-sm text-ink-muted"
                                    :for="`terms-type-${e.id}`"
                                    >Employment type</label
                                >
                                <select
                                    :id="`terms-type-${e.id}`"
                                    v-model="termsForm.employment_type"
                                    required
                                    class="input mt-1"
                                >
                                    <option
                                        v-for="t in workforceOptions.employmentTypes"
                                        :key="t.value"
                                        :value="t.value"
                                    >
                                        {{ t.label }}
                                    </option>
                                </select>
                            </div>
                            <div>
                                <label
                                    class="block text-sm text-ink-muted"
                                    :for="`terms-staff-${e.id}`"
                                    >Staff type</label
                                >
                                <select
                                    :id="`terms-staff-${e.id}`"
                                    v-model="termsForm.staff_type"
                                    required
                                    class="input mt-1"
                                >
                                    <option value="" disabled>Select a staff type</option>
                                    <option
                                        v-for="t in workforceOptions.staffTypes"
                                        :key="t.value"
                                        :value="t.value"
                                    >
                                        {{ t.label }}
                                    </option>
                                </select>
                                <p class="mt-1 text-sm text-danger">
                                    {{ termsForm.errors.staff_type }}
                                </p>
                            </div>
                            <div>
                                <label
                                    class="block text-sm text-ink-muted"
                                    :for="`terms-cat-${e.id}`"
                                    >Category (optional)</label
                                >
                                <select
                                    :id="`terms-cat-${e.id}`"
                                    v-model="termsForm.employee_category_id"
                                    class="input mt-1"
                                >
                                    <option value="">None</option>
                                    <option
                                        v-for="c in employeeCategories"
                                        :key="c.id"
                                        :value="c.id"
                                    >
                                        {{ c.name }}
                                    </option>
                                </select>
                            </div>
                            <div>
                                <label
                                    class="block text-sm text-ink-muted"
                                    :for="`terms-reason-${e.id}`"
                                    >Reason</label
                                >
                                <select
                                    :id="`terms-reason-${e.id}`"
                                    v-model="termsForm.reason_code"
                                    required
                                    class="input mt-1"
                                >
                                    <option value="" disabled>Select a reason</option>
                                    <option
                                        v-for="r in workforceOptions.termsReasons"
                                        :key="r.value"
                                        :value="r.value"
                                    >
                                        {{ r.label }}
                                    </option>
                                </select>
                            </div>
                        </div>
                        <p class="text-sm text-danger">
                            {{ termsForm.errors.employment_type ?? termsForm.errors.reason_code }}
                        </p>
                        <button
                            type="submit"
                            :disabled="termsForm.processing"
                            class="btn btn-primary"
                        >
                            Record new terms
                        </button>
                    </form>

                    <!-- HR.1A: terms history and named placement changes -->
                    <details
                        v-if="workforceHistory[e.id]"
                        class="mt-3 border-t border-line pt-3 text-ink-muted"
                    >
                        <summary class="cursor-pointer text-sm font-medium text-ink">
                            Terms and placement history
                        </summary>
                        <h4 class="mt-2 text-xs font-medium uppercase tracking-wide text-ink-muted">
                            Terms
                        </h4>
                        <ul class="mt-1 space-y-1">
                            <li v-for="t in workforceHistory[e.id]?.terms ?? []" :key="t.id">
                                From {{ t.effectiveFrom }}: {{ t.employmentTypeLabel }} ·
                                {{ t.staffTypeLabel
                                }}<template v-if="t.categoryName"> · {{ t.categoryName }}</template>
                                <span class="text-ink-muted"> ({{ t.reasonLabel }})</span>
                            </li>
                        </ul>
                        <h4 class="mt-3 text-xs font-medium uppercase tracking-wide text-ink-muted">
                            Placement changes
                        </h4>
                        <p v-if="(workforceHistory[e.id]?.changes ?? []).length === 0" class="mt-1">
                            No named placement change recorded.
                        </p>
                        <ul v-else class="mt-1 space-y-1">
                            <li v-for="c in workforceHistory[e.id]?.changes ?? []" :key="c.id">
                                {{ c.effectiveOn }} · {{ c.kindLabel }}:
                                <template v-if="c.from">{{ c.from }} → </template>{{ c.to }}
                                <span class="text-ink-muted"> ({{ c.reasonLabel }})</span>
                            </li>
                        </ul>
                        <h4 class="mt-3 text-xs font-medium uppercase tracking-wide text-ink-muted">
                            Reporting lines
                        </h4>
                        <p
                            v-if="(workforceHistory[e.id]?.reporting ?? []).length === 0"
                            class="mt-1"
                        >
                            No reporting line recorded.
                        </p>
                        <ul v-else class="mt-1 space-y-2">
                            <li
                                v-for="r in workforceHistory[e.id]?.reporting ?? []"
                                :key="r.id"
                                :class="r.voided ? 'text-ink-muted line-through' : ''"
                            >
                                {{ r.startsOn }} – {{ r.endsOn ?? 'open' }}: reports to
                                {{ r.managerName ?? '—' }}
                                <span class="text-ink-muted">
                                    ({{ r.startedLabel
                                    }}<template v-if="r.startReasonLabel"
                                        >, {{ r.startReasonLabel }}</template
                                    ><template v-if="r.endedLabel"
                                        >; ended: {{ r.endedLabel }}</template
                                    ><template v-if="r.voided"
                                        >; voided: {{ r.voidReasonLabel }}</template
                                    >)
                                </span>
                                <button
                                    v-if="can.manageAssignments && !r.voided"
                                    type="button"
                                    class="ml-2 text-sm underline"
                                    @click="toggleCorrection(e.id, r.id)"
                                >
                                    Correct
                                </button>
                                <form
                                    v-if="correctingLineId === r.id"
                                    class="mt-2 space-y-2 rounded-xs bg-graphite-100 p-3 no-underline"
                                    @submit.prevent="submitCorrection(e.id, r.id)"
                                >
                                    <label
                                        class="block text-sm text-ink-muted"
                                        for="correction-manager-search"
                                        >Correct manager (leave blank to void the line)</label
                                    >
                                    <SearchCombobox
                                        id="correction-manager-search"
                                        v-model="correctionQuery"
                                        placeholder="Search for the manager"
                                        class="w-full max-w-md rounded-xs border border-control-line px-3 py-2 text-sm"
                                        :options="correctionOptions"
                                        :option-key="(o) => o.assignmentId"
                                        :error="correctionForm.errors.manager_assignment_id"
                                        @select="pickCorrection"
                                    >
                                        <template #option="{ option }">{{ option.label }}</template>
                                    </SearchCombobox>
                                    <label
                                        class="block text-sm text-ink-muted"
                                        :for="`correct-reason-${r.id}`"
                                        >Reason</label
                                    >
                                    <select
                                        :id="`correct-reason-${r.id}`"
                                        v-model="correctionForm.reason_code"
                                        required
                                        class="input w-auto"
                                    >
                                        <option value="" disabled>Select a reason</option>
                                        <option
                                            v-for="o in workforceOptions.reportingReasons"
                                            :key="o.value"
                                            :value="o.value"
                                        >
                                            {{ o.label }}
                                        </option>
                                    </select>
                                    <p class="text-sm text-danger">
                                        {{ correctionForm.errors.reason_code }}
                                    </p>
                                    <button
                                        type="submit"
                                        :disabled="
                                            correctionForm.processing ||
                                            correctionForm.reason_code === ''
                                        "
                                        class="btn btn-primary"
                                    >
                                        Record correction
                                    </button>
                                </form>
                            </li>
                        </ul>
                    </details>

                    <!-- Assignments under this employment -->
                    <ul
                        v-if="workspace.assignments.some((a) => a.employment_record_id === e.id)"
                        class="mt-3 space-y-2 border-t border-line pt-3"
                    >
                        <li
                            v-for="a in workspace.assignments.filter(
                                (x) => x.employment_record_id === e.id,
                            )"
                            :key="a.id"
                            class="rounded-xs bg-graphite-100 p-3"
                        >
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <p class="font-medium">
                                        {{ a.position_name ?? '—' }}
                                        <span
                                            v-if="a.is_primary"
                                            class="ml-2 rounded-xs bg-graphite-100 px-2 py-0.5 text-xs text-ink"
                                            >Primary</span
                                        >
                                        <span
                                            v-if="a.is_current"
                                            class="ml-2 rounded-xs bg-accent-100 px-2 py-0.5 text-xs text-accent-strong"
                                            >Current</span
                                        >
                                    </p>
                                    <p class="mt-0.5 text-ink-muted">
                                        {{
                                            [a.department_name, a.campus_name]
                                                .filter(Boolean)
                                                .join(' · ')
                                        }}
                                        · {{ a.starts_on }} – {{ a.ends_on ?? 'present' }}
                                    </p>
                                </div>
                                <div
                                    v-if="can.manageAssignments"
                                    class="flex flex-wrap items-center gap-3 text-sm"
                                >
                                    <button
                                        v-if="a.is_primary && a.ends_on === null"
                                        type="button"
                                        class="underline"
                                        @click="openPlacement(a)"
                                    >
                                        {{
                                            placementAssignmentId === a.id
                                                ? 'Cancel'
                                                : 'Change placement'
                                        }}
                                    </button>
                                    <button
                                        v-if="!a.is_primary && a.ends_on === null"
                                        type="button"
                                        class="underline"
                                        @click="makeAssignmentPrimary(a)"
                                    >
                                        Make primary
                                    </button>
                                    <button
                                        type="button"
                                        class="underline"
                                        @click="toggleManager(a)"
                                    >
                                        {{
                                            managingAssignmentId === a.id ? 'Cancel' : 'Set manager'
                                        }}
                                    </button>
                                    <button
                                        v-if="a.ends_on === null"
                                        type="button"
                                        class="text-danger underline"
                                        @click="
                                            endingAssignmentId =
                                                endingAssignmentId === a.id ? null : a.id
                                        "
                                    >
                                        {{ endingAssignmentId === a.id ? 'Cancel' : 'End' }}
                                    </button>
                                </div>
                            </div>

                            <!-- HR.1A: transfer / promotion / designation / department change -->
                            <form
                                v-if="placementAssignmentId === a.id"
                                class="mt-3 space-y-3 border-t border-line pt-3"
                                @submit.prevent="submitPlacement(a)"
                            >
                                <p class="text-ink-muted">
                                    This placement ends the day before the effective date and a new
                                    primary placement starts on it; both stay in the history.
                                </p>
                                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    <div>
                                        <label
                                            class="block text-sm text-ink-muted"
                                            :for="`placement-kind-${a.id}`"
                                            >Change</label
                                        >
                                        <select
                                            :id="`placement-kind-${a.id}`"
                                            v-model="placementForm.change_kind"
                                            required
                                            class="input mt-1"
                                        >
                                            <option value="" disabled>Select the change</option>
                                            <option
                                                v-for="k in workforceOptions.placementKinds"
                                                :key="k.value"
                                                :value="k.value"
                                            >
                                                {{ k.label }}
                                            </option>
                                        </select>
                                    </div>
                                    <div>
                                        <label
                                            class="block text-sm text-ink-muted"
                                            :for="`placement-from-${a.id}`"
                                            >Effective on</label
                                        >
                                        <input
                                            :id="`placement-from-${a.id}`"
                                            v-model="placementForm.effective_on"
                                            type="date"
                                            required
                                            class="input mt-1"
                                        />
                                    </div>
                                    <div>
                                        <label
                                            class="block text-sm text-ink-muted"
                                            :for="`placement-campus-${a.id}`"
                                            >Campus</label
                                        >
                                        <select
                                            :id="`placement-campus-${a.id}`"
                                            v-model="placementForm.campus_id"
                                            class="input mt-1"
                                        >
                                            <option value="">School-wide (no Campus)</option>
                                            <option v-for="c in campuses" :key="c.id" :value="c.id">
                                                {{ c.name }}
                                            </option>
                                        </select>
                                    </div>
                                    <div>
                                        <label
                                            class="block text-sm text-ink-muted"
                                            :for="`placement-dept-${a.id}`"
                                            >Department</label
                                        >
                                        <select
                                            :id="`placement-dept-${a.id}`"
                                            v-model="placementForm.department_id"
                                            class="input mt-1"
                                        >
                                            <option value="">None</option>
                                            <option
                                                v-for="d in departments"
                                                :key="d.id"
                                                :value="d.id"
                                            >
                                                {{ d.name }}
                                            </option>
                                        </select>
                                    </div>
                                    <div>
                                        <label
                                            class="block text-sm text-ink-muted"
                                            :for="`placement-position-${a.id}`"
                                            >Designation</label
                                        >
                                        <select
                                            :id="`placement-position-${a.id}`"
                                            v-model="placementForm.position_id"
                                            required
                                            class="input mt-1"
                                        >
                                            <option
                                                v-for="p in positions"
                                                :key="p.id"
                                                :value="p.id"
                                            >
                                                {{ p.name }}
                                            </option>
                                        </select>
                                    </div>
                                    <div>
                                        <label
                                            class="block text-sm text-ink-muted"
                                            :for="`placement-reason-${a.id}`"
                                            >Reason</label
                                        >
                                        <select
                                            :id="`placement-reason-${a.id}`"
                                            v-model="placementForm.reason_code"
                                            required
                                            class="input mt-1"
                                        >
                                            <option value="" disabled>Select a reason</option>
                                            <option
                                                v-for="r in workforceOptions.placementReasons"
                                                :key="r.value"
                                                :value="r.value"
                                            >
                                                {{ r.label }}
                                            </option>
                                        </select>
                                    </div>
                                </div>
                                <p class="text-sm text-danger">
                                    {{
                                        placementForm.errors.change_kind ??
                                        placementForm.errors.position_id ??
                                        placementForm.errors.effective_on
                                    }}
                                </p>
                                <button
                                    type="submit"
                                    :disabled="placementForm.processing"
                                    class="btn btn-primary"
                                >
                                    Record change
                                </button>
                            </form>

                            <form
                                v-if="managingAssignmentId === a.id"
                                class="mt-3 border-t border-line pt-3"
                                @submit.prevent="submitManager(a)"
                            >
                                <label class="block text-sm text-ink-muted" for="manager-search"
                                    >Reports to (search by name or employee number)</label
                                >
                                <div class="mt-1 flex flex-wrap items-start gap-3">
                                    <div class="relative w-full max-w-md">
                                        <SearchCombobox
                                            id="manager-search"
                                            v-model="managerQuery"
                                            placeholder="Search for the manager"
                                            class="w-full rounded-xs border border-control-line px-3 py-2 text-sm"
                                            :options="managerOptions"
                                            :option-key="(o) => o.assignmentId"
                                            :error="managerForm.errors.manager_assignment_id"
                                            @select="pickManager"
                                        >
                                            <template #option="{ option }">{{
                                                option.label
                                            }}</template>
                                        </SearchCombobox>
                                    </div>
                                    <div>
                                        <label
                                            class="block text-sm text-ink-muted"
                                            :for="`manager-effective-${a.id}`"
                                            >Effective from (blank = today)</label
                                        >
                                        <input
                                            :id="`manager-effective-${a.id}`"
                                            v-model="managerForm.effective_on"
                                            type="date"
                                            class="input mt-1 w-auto"
                                        />
                                    </div>
                                    <div>
                                        <label
                                            class="block text-sm text-ink-muted"
                                            :for="`manager-reason-${a.id}`"
                                            >Reason</label
                                        >
                                        <select
                                            :id="`manager-reason-${a.id}`"
                                            v-model="managerForm.reason_code"
                                            required
                                            class="input mt-1 w-auto"
                                        >
                                            <option value="" disabled>Select a reason</option>
                                            <option
                                                v-for="r in workforceOptions.reportingReasons"
                                                :key="r.value"
                                                :value="r.value"
                                            >
                                                {{ r.label }}
                                            </option>
                                        </select>
                                        <p class="mt-1 text-sm text-danger">
                                            {{
                                                managerForm.errors.reason_code ??
                                                managerForm.errors.effective_on
                                            }}
                                        </p>
                                    </div>
                                    <button
                                        type="submit"
                                        :disabled="
                                            managerForm.processing ||
                                            managerForm.manager_assignment_id === '' ||
                                            managerForm.reason_code === ''
                                        "
                                        class="btn btn-primary"
                                    >
                                        Save
                                    </button>
                                    <button
                                        type="button"
                                        :disabled="
                                            managerForm.processing || managerForm.reason_code === ''
                                        "
                                        class="btn btn-secondary"
                                        @click="clearManager(a)"
                                    >
                                        Remove manager
                                    </button>
                                </div>
                            </form>

                            <form
                                v-if="endingAssignmentId === a.id"
                                class="mt-3 border-t border-line pt-3"
                                @submit.prevent="submitEndAssignment(a)"
                            >
                                <label class="block text-sm text-ink-muted">End date</label>
                                <div class="mt-1 flex items-center gap-3">
                                    <input
                                        v-model="endAssignmentForm.ends_on"
                                        type="date"
                                        required
                                        class="input w-auto"
                                    />
                                    <button
                                        type="submit"
                                        :disabled="endAssignmentForm.processing"
                                        class="btn btn-primary"
                                    >
                                        Confirm
                                    </button>
                                </div>
                            </form>
                        </li>
                    </ul>

                    <form
                        v-if="assigningEmploymentId === e.id"
                        class="mt-3 space-y-3 border-t border-line pt-3"
                        @submit.prevent="submitAssignment(e.id)"
                    >
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <label class="block text-sm text-ink-muted">Position</label>
                                <select
                                    v-model="assignmentForm.position_id"
                                    required
                                    class="input mt-1"
                                >
                                    <option value="" disabled>Select a Position</option>
                                    <option v-for="p in positions" :key="p.id" :value="p.id">
                                        {{ p.name }}
                                    </option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm text-ink-muted"
                                    >Department (optional)</label
                                >
                                <select v-model="assignmentForm.department_id" class="input mt-1">
                                    <option value="">None</option>
                                    <option v-for="d in departments" :key="d.id" :value="d.id">
                                        {{ d.name }}
                                    </option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm text-ink-muted">Start date</label>
                                <input
                                    v-model="assignmentForm.starts_on"
                                    type="date"
                                    required
                                    class="input mt-1"
                                />
                            </div>
                        </div>
                        <p class="text-sm text-danger">{{ assignmentForm.errors.position_id }}</p>
                        <button
                            type="submit"
                            :disabled="assignmentForm.processing"
                            class="btn btn-primary"
                        >
                            Add assignment
                        </button>
                    </form>
                </li>
            </ul>

            <form
                v-if="showRehireForm"
                class="mt-3 rounded-xs border border-line p-4"
                @submit.prevent="submitRehire"
            >
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm text-ink-muted" for="rehire-type"
                            >Employment type</label
                        >
                        <select
                            id="rehire-type"
                            v-model="rehireForm.employment_type"
                            required
                            class="input mt-1"
                        >
                            <option
                                v-for="t in workforceOptions.employmentTypes"
                                :key="t.value"
                                :value="t.value"
                            >
                                {{ t.label }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm text-ink-muted" for="rehire-staff"
                            >Staff type</label
                        >
                        <select
                            id="rehire-staff"
                            v-model="rehireForm.staff_type"
                            required
                            class="input mt-1"
                        >
                            <option value="" disabled>Select a staff type</option>
                            <option
                                v-for="t in workforceOptions.staffTypes"
                                :key="t.value"
                                :value="t.value"
                            >
                                {{ t.label }}
                            </option>
                        </select>
                        <p class="mt-1 text-sm text-danger">{{ rehireForm.errors.staff_type }}</p>
                    </div>
                    <div>
                        <label class="block text-sm text-ink-muted">Start date</label>
                        <input
                            v-model="rehireForm.starts_on"
                            type="date"
                            required
                            class="input mt-1"
                        />
                    </div>
                </div>
                <button
                    type="submit"
                    :disabled="rehireForm.processing"
                    class="btn btn-primary mt-3"
                >
                    Rehire
                </button>
            </form>

            <form
                v-if="showEmploymentForm"
                class="mt-3 rounded-xs border border-line p-4"
                @submit.prevent="submitEmployment"
            >
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm text-ink-muted" for="employment-type"
                            >Employment type</label
                        >
                        <select
                            id="employment-type"
                            v-model="employmentForm.employment_type"
                            required
                            class="input mt-1"
                        >
                            <option
                                v-for="t in workforceOptions.employmentTypes"
                                :key="t.value"
                                :value="t.value"
                            >
                                {{ t.label }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm text-ink-muted" for="employment-staff"
                            >Staff type</label
                        >
                        <select
                            id="employment-staff"
                            v-model="employmentForm.staff_type"
                            required
                            class="input mt-1"
                        >
                            <option value="" disabled>Select a staff type</option>
                            <option
                                v-for="t in workforceOptions.staffTypes"
                                :key="t.value"
                                :value="t.value"
                            >
                                {{ t.label }}
                            </option>
                        </select>
                        <p class="mt-1 text-sm text-danger">
                            {{ employmentForm.errors.staff_type }}
                        </p>
                    </div>
                    <div>
                        <label class="block text-sm text-ink-muted">Category (optional)</label>
                        <select v-model="employmentForm.employee_category_id" class="input mt-1">
                            <option value="">None</option>
                            <option v-for="c in employeeCategories" :key="c.id" :value="c.id">
                                {{ c.name }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm text-ink-muted">Start date</label>
                        <input
                            v-model="employmentForm.starts_on"
                            type="date"
                            required
                            class="input mt-1"
                        />
                    </div>
                </div>
                <button
                    type="submit"
                    :disabled="employmentForm.processing"
                    class="btn btn-primary mt-3"
                >
                    Add employment
                </button>
            </form>
        </section>

        <!-- Qualifications -->
        <section id="profile-qualifications" class="mt-8">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-medium text-ink-muted">Qualifications</h2>
                <button
                    v-if="can.manageQualifications"
                    type="button"
                    class="text-sm font-medium underline"
                    @click="showQualificationForm = !showQualificationForm"
                >
                    {{ showQualificationForm ? 'Cancel' : '+ Add qualification' }}
                </button>
            </div>

            <p v-if="workspace.qualifications.length === 0" class="mt-3 text-sm text-ink-muted">
                No qualifications visible.
            </p>
            <ul v-else class="mt-3 space-y-2">
                <li
                    v-for="q in workspace.qualifications"
                    :key="q.id"
                    class="flex items-start justify-between gap-3 rounded-xs border border-line p-3 text-sm"
                >
                    <div>
                        <p class="font-medium">
                            {{ q.qualification_name }}
                            <span class="font-normal text-ink-muted"
                                >({{ q.qualification_type }})</span
                            >
                        </p>
                        <p class="mt-0.5 text-ink-muted">
                            {{ q.institution }} · {{ q.completed_on ?? 'ongoing' }} ·
                            {{ q.verification_status }}
                        </p>
                    </div>
                    <div v-if="can.manageQualifications" class="flex shrink-0 items-center gap-3">
                        <button
                            v-if="q.verification_status !== 'verified'"
                            type="button"
                            class="underline"
                            @click="verifyQualification(q.id)"
                        >
                            Verify
                        </button>
                        <button
                            v-if="q.verification_status !== 'rejected'"
                            type="button"
                            class="underline"
                            @click="rejectQualification(q.id)"
                        >
                            Reject
                        </button>
                        <ArchiveRecordButton
                            :url="`${base}/qualifications/${q.id}`"
                            subject="this qualification"
                            :reasons="staffProfile.options.archiveReasons"
                            :control-id="`archive-qualification-${q.id}`"
                        />
                    </div>
                </li>
            </ul>

            <form
                v-if="showQualificationForm"
                class="mt-3 rounded-xs border border-line p-4"
                @submit.prevent="submitQualification"
            >
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm text-ink-muted">Type</label>
                        <select v-model="qualificationForm.qualification_type" class="input mt-1">
                            <option value="secondary">Secondary</option>
                            <option value="higher_secondary">Higher secondary</option>
                            <option value="diploma">Diploma</option>
                            <option value="bachelors">Bachelors</option>
                            <option value="masters">Masters</option>
                            <option value="doctorate">Doctorate</option>
                            <option value="professional">Professional</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm text-ink-muted">Name</label>
                        <input
                            v-model="qualificationForm.qualification_name"
                            type="text"
                            required
                            class="input mt-1"
                        />
                    </div>
                    <div>
                        <label class="block text-sm text-ink-muted">Institution</label>
                        <input
                            v-model="qualificationForm.institution"
                            type="text"
                            required
                            class="input mt-1"
                        />
                    </div>
                    <div>
                        <label class="block text-sm text-ink-muted">Completed on</label>
                        <input
                            v-model="qualificationForm.completed_on"
                            type="date"
                            class="input mt-1"
                        />
                    </div>
                </div>
                <button
                    type="submit"
                    :disabled="qualificationForm.processing"
                    class="btn btn-primary mt-3"
                >
                    Add qualification
                </button>
            </form>
        </section>

        <!-- Experience -->
        <section class="mt-8">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-medium text-ink-muted">Experience</h2>
                <button
                    v-if="can.manageQualifications"
                    type="button"
                    class="text-sm font-medium underline"
                    @click="showExperienceForm = !showExperienceForm"
                >
                    {{ showExperienceForm ? 'Cancel' : '+ Add experience' }}
                </button>
            </div>

            <p v-if="workspace.experience.length === 0" class="mt-3 text-sm text-ink-muted">
                No experience records visible.
            </p>
            <ul v-else class="mt-3 space-y-2">
                <li
                    v-for="x in workspace.experience"
                    :key="x.id"
                    class="flex items-start justify-between gap-3 rounded-xs border border-line p-3 text-sm"
                >
                    <div>
                        <p class="font-medium">{{ x.job_title }} · {{ x.organization }}</p>
                        <p class="mt-0.5 text-ink-muted">
                            {{ x.starts_on }} – {{ x.ends_on ?? 'present' }}
                        </p>
                    </div>
                    <ArchiveRecordButton
                        v-if="can.manageQualifications"
                        :url="`${base}/experience/${x.id}`"
                        subject="this experience record"
                        :reasons="staffProfile.options.archiveReasons"
                        :control-id="`archive-experience-${x.id}`"
                    />
                </li>
            </ul>

            <form
                v-if="showExperienceForm"
                class="mt-3 rounded-xs border border-line p-4"
                @submit.prevent="submitExperience"
            >
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm text-ink-muted">Organization</label>
                        <input
                            v-model="experienceForm.organization"
                            type="text"
                            required
                            class="input mt-1"
                        />
                    </div>
                    <div>
                        <label class="block text-sm text-ink-muted">Job title</label>
                        <input
                            v-model="experienceForm.job_title"
                            type="text"
                            required
                            class="input mt-1"
                        />
                    </div>
                    <div>
                        <label class="block text-sm text-ink-muted">Start date</label>
                        <input
                            v-model="experienceForm.starts_on"
                            type="date"
                            required
                            class="input mt-1"
                        />
                    </div>
                    <div>
                        <label class="block text-sm text-ink-muted">End date</label>
                        <input v-model="experienceForm.ends_on" type="date" class="input mt-1" />
                    </div>
                </div>
                <button
                    type="submit"
                    :disabled="experienceForm.processing"
                    class="btn btn-primary mt-3"
                >
                    Add experience
                </button>
            </form>
        </section>

        <!-- Certifications -->
        <section class="mt-8">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-medium text-ink-muted">Certifications</h2>
                <button
                    v-if="can.manageQualifications"
                    type="button"
                    class="text-sm font-medium underline"
                    @click="showCertificationForm = !showCertificationForm"
                >
                    {{ showCertificationForm ? 'Cancel' : '+ Add certification' }}
                </button>
            </div>

            <p v-if="workspace.certifications.length === 0" class="mt-3 text-sm text-ink-muted">
                No certifications visible.
            </p>
            <ul v-else class="mt-3 space-y-2">
                <li
                    v-for="c in workspace.certifications"
                    :key="c.id"
                    class="flex items-start justify-between gap-3 rounded-xs border border-line p-3 text-sm"
                >
                    <div>
                        <p class="font-medium">{{ c.name }}</p>
                        <p class="mt-0.5 text-ink-muted">
                            {{ c.issuer }} · expires {{ c.expires_on ?? 'never' }} ·
                            {{ c.verification_status }}
                        </p>
                    </div>
                    <div v-if="can.manageQualifications" class="flex shrink-0 items-center gap-3">
                        <button
                            v-if="c.verification_status !== 'verified'"
                            type="button"
                            class="underline"
                            @click="verifyCertification(c.id)"
                        >
                            Verify
                        </button>
                        <button
                            v-if="c.verification_status !== 'rejected'"
                            type="button"
                            class="underline"
                            @click="rejectCertification(c.id)"
                        >
                            Reject
                        </button>
                        <ArchiveRecordButton
                            :url="`${base}/certifications/${c.id}`"
                            subject="this certification"
                            :reasons="staffProfile.options.archiveReasons"
                            :control-id="`archive-certification-${c.id}`"
                        />
                    </div>
                </li>
            </ul>

            <form
                v-if="showCertificationForm"
                class="mt-3 rounded-xs border border-line p-4"
                @submit.prevent="submitCertification"
            >
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm text-ink-muted">Name</label>
                        <input
                            v-model="certificationForm.name"
                            type="text"
                            required
                            class="input mt-1"
                        />
                    </div>
                    <div>
                        <label class="block text-sm text-ink-muted">Issuer</label>
                        <input
                            v-model="certificationForm.issuer"
                            type="text"
                            required
                            class="input mt-1"
                        />
                    </div>
                    <div>
                        <label class="block text-sm text-ink-muted">Issued on</label>
                        <input
                            v-model="certificationForm.issued_on"
                            type="date"
                            class="input mt-1"
                        />
                    </div>
                    <div>
                        <label class="block text-sm text-ink-muted">Expires on</label>
                        <input
                            v-model="certificationForm.expires_on"
                            type="date"
                            class="input mt-1"
                        />
                    </div>
                </div>
                <button
                    type="submit"
                    :disabled="certificationForm.processing"
                    class="btn btn-primary mt-3"
                >
                    Add certification
                </button>
            </form>
        </section>

        <!-- Documents -->
        <section id="profile-documents" class="mt-8">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-medium text-ink-muted">Documents</h2>
                <button
                    v-if="can.manageDocuments"
                    type="button"
                    class="text-sm font-medium underline"
                    @click="showDocumentForm = !showDocumentForm"
                >
                    {{ showDocumentForm ? 'Cancel' : '+ Upload document' }}
                </button>
            </div>
            <p class="mt-1 text-xs text-ink-muted">
                PDF, JPEG or PNG. Highly Sensitive documents are listed separately and need MFA.
            </p>

            <p v-if="workspace.documents.length === 0" class="mt-3 text-sm text-ink-muted">
                No documents recorded.
            </p>
            <ul v-else class="mt-3 space-y-2">
                <li
                    v-for="d in workspace.documents"
                    :key="d.id"
                    class="flex items-start justify-between gap-3 rounded-xs border border-line p-3 text-sm"
                >
                    <div>
                        <p class="font-medium">{{ d.category_label }}</p>
                        <p class="mt-0.5 text-ink-muted">
                            {{ d.status }} · expires {{ d.expires_on ?? 'never' }}
                            <template v-if="!d.has_file">
                                · recorded before uploads (no file)</template
                            >
                        </p>
                        <a
                            v-if="d.has_file"
                            class="mt-1 inline-block underline"
                            :href="`${base}/hr-document-records/${d.id}/download`"
                            >Download</a
                        >
                    </div>
                    <button
                        v-if="can.manageDocuments && d.status === 'active'"
                        type="button"
                        class="text-danger underline"
                        @click="archiveDocument(d.id)"
                    >
                        Archive
                    </button>
                </li>
            </ul>

            <form
                v-if="showDocumentForm"
                class="mt-3 rounded-xs border border-line p-4"
                @submit.prevent="submitDocument"
            >
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label class="block text-sm text-ink-muted" for="hr-document-file"
                            >File</label
                        >
                        <input
                            id="hr-document-file"
                            type="file"
                            accept="application/pdf,image/jpeg,image/png"
                            required
                            class="mt-1 w-full text-sm"
                            @change="pickDocumentFile"
                        />
                        <p class="mt-1 text-sm text-danger">{{ documentForm.errors.file }}</p>
                    </div>
                    <div>
                        <label class="block text-sm text-ink-muted" for="hr-document-category"
                            >Category</label
                        >
                        <select
                            id="hr-document-category"
                            v-model="documentForm.category"
                            required
                            class="input mt-1"
                        >
                            <option value="" disabled>Select a category</option>
                            <option
                                v-for="c in staffProfile.options.documentCategories"
                                :key="c.value"
                                :value="c.value"
                            >
                                {{ c.label }}
                            </option>
                        </select>
                        <p class="mt-1 text-sm text-danger">{{ documentForm.errors.category }}</p>
                    </div>
                    <div>
                        <label class="block text-sm text-ink-muted" for="hr-document-tier"
                            >Classification</label
                        >
                        <select
                            id="hr-document-tier"
                            v-model="documentForm.classification_tier"
                            class="input mt-1"
                        >
                            <option value="restricted">Restricted</option>
                            <option value="highly_sensitive">Highly sensitive</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm text-ink-muted" for="hr-document-issued"
                            >Issued on</label
                        >
                        <input
                            id="hr-document-issued"
                            v-model="documentForm.issued_on"
                            type="date"
                            class="input mt-1"
                        />
                    </div>
                    <div>
                        <label class="block text-sm text-ink-muted" for="hr-document-expires"
                            >Expires on</label
                        >
                        <input
                            id="hr-document-expires"
                            v-model="documentForm.expires_on"
                            type="date"
                            class="input mt-1"
                        />
                        <p class="mt-1 text-sm text-danger">
                            {{ documentForm.errors.expires_on }}
                        </p>
                    </div>
                </div>
                <div v-if="documentForm.classification_tier === 'highly_sensitive'" class="mt-3">
                    <MfaCodeField
                        id="hr-document-mfa"
                        v-model="documentForm.mfa_code"
                        :has-mfa-factor="hasMfaFactor"
                        action="Uploading a highly sensitive document"
                        :error="documentForm.errors.mfa_code"
                    />
                </div>
                <button
                    type="submit"
                    :disabled="
                        documentForm.processing ||
                        documentForm.file === null ||
                        (documentForm.classification_tier === 'highly_sensitive' && !hasMfaFactor)
                    "
                    class="btn btn-primary mt-3"
                >
                    Upload document
                </button>
            </form>
        </section>

        <!-- Notes -->
        <section class="mt-8">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-medium text-ink-muted">
                    HR notes (Restricted/Confidential)
                </h2>
                <button
                    v-if="can.manageNotes"
                    type="button"
                    class="text-sm font-medium underline"
                    @click="showNoteForm = !showNoteForm"
                >
                    {{ showNoteForm ? 'Cancel' : '+ Add note' }}
                </button>
            </div>

            <p v-if="workspace.notes.length === 0" class="mt-3 text-sm text-ink-muted">
                No notes visible.
            </p>
            <ul v-else class="mt-3 space-y-2">
                <li
                    v-for="n in workspace.notes"
                    :key="n.id"
                    class="flex items-start justify-between gap-3 rounded-xs border border-line p-3 text-sm"
                >
                    <div>
                        <p>{{ n.body }}</p>
                        <p class="mt-1 text-xs text-ink-muted">
                            {{ n.classification_tier }} ·
                            {{ n.author_display_name ?? 'Unknown author' }} · {{ n.created_at }}
                        </p>
                    </div>
                    <ArchiveRecordButton
                        v-if="can.manageNotes"
                        :url="`${base}/notes/${n.id}`"
                        subject="this note"
                        :reasons="staffProfile.options.archiveReasons"
                        :control-id="`archive-note-${n.id}`"
                    />
                </li>
            </ul>

            <form
                v-if="showNoteForm"
                class="mt-3 rounded-xs border border-line p-4"
                @submit.prevent="submitNote"
            >
                <label class="block text-sm text-ink-muted">Note</label>
                <textarea v-model="noteForm.body" required rows="3" class="input mt-1"></textarea>
                <div class="mt-3">
                    <label class="block text-sm text-ink-muted">Classification</label>
                    <select v-model="noteForm.classification_tier" class="input mt-1 w-48">
                        <option value="sensitive">Sensitive</option>
                        <option value="confidential">Confidential</option>
                    </select>
                </div>
                <button type="submit" :disabled="noteForm.processing" class="btn btn-primary mt-3">
                    Add note
                </button>
            </form>
        </section>
    </main>
</template>
