<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
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

interface Props {
    workspace: Workspace;
    can: {
        manageEmployees: boolean;
        managePersonal: boolean;
        manageAssignments: boolean;
        manageQualifications: boolean;
        manageDocuments: boolean;
        manageNotes: boolean;
    };
    positions: RefOption[];
    departments: RefOption[];
    employeeCategories: RefOption[];
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
function removeAddress(id: string): void {
    if (!window.confirm('Remove this address?')) return;
    router.delete(`${base}/addresses/${id}`, { preserveScroll: true });
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
function removeContact(id: string): void {
    if (!window.confirm('Remove this emergency contact?')) return;
    router.delete(`${base}/emergency-contacts/${id}`, { preserveScroll: true });
}
function makeContactPrimary(id: string): void {
    router.post(`${base}/emergency-contacts/${id}/primary`, {}, { preserveScroll: true });
}

// --- Employment / lifecycle ------------------------------------------------

const showEmploymentForm = ref(false);
const employmentForm = useForm({
    employment_type: 'full_time',
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
const endEmploymentForm = useForm({ ends_on: '', status: 'separated' });
function submitEndEmployment(id: string): void {
    endEmploymentForm.post(`${base}/employment-records/${id}/end`, {
        preserveScroll: true,
        onSuccess: () => (endingEmploymentId.value = null),
    });
}

const showRehireForm = ref(false);
const rehireForm = useForm({ employment_type: 'full_time', starts_on: '' });
function submitRehire(): void {
    rehireForm.post(`${base}/rehire`, {
        preserveScroll: true,
        onSuccess: () => (showRehireForm.value = false),
    });
}

// --- Assignments -----------------------------------------------------------

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

const managingAssignmentId = ref<string | null>(null);
const managerForm = useForm({ manager_assignment_id: '' });
function submitManager(a: Assignment): void {
    managerForm.post(
        `${base}/employment-records/${a.employment_record_id}/assignments/${a.id}/manager`,
        {
            preserveScroll: true,
            onSuccess: () => (managingAssignmentId.value = null),
        },
    );
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
function removeQualification(id: string): void {
    if (!window.confirm('Remove this qualification?')) return;
    router.delete(`${base}/qualifications/${id}`, { preserveScroll: true });
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
function removeExperience(id: string): void {
    if (!window.confirm('Remove this experience record?')) return;
    router.delete(`${base}/experience/${id}`, { preserveScroll: true });
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
function removeCertification(id: string): void {
    if (!window.confirm('Remove this certification?')) return;
    router.delete(`${base}/certifications/${id}`, { preserveScroll: true });
}
function verifyCertification(id: string): void {
    router.post(`${base}/certifications/${id}/verify`, {}, { preserveScroll: true });
}
function rejectCertification(id: string): void {
    router.post(`${base}/certifications/${id}/reject`, {}, { preserveScroll: true });
}

// --- Documents (metadata only) ----------------------------------------------

const showDocumentForm = ref(false);
const documentForm = useForm({
    category: '',
    classification_tier: 'restricted',
    storage_disk: 's3',
    storage_path: '',
    original_filename: '',
    mime_type: 'application/pdf',
    size_bytes: 0,
    issued_on: '',
    expires_on: '',
});
function submitDocument(): void {
    documentForm.post(`${base}/hr-document-records`, {
        preserveScroll: true,
        onSuccess: () => {
            showDocumentForm.value = false;
            documentForm.reset();
        },
    });
}
function archiveDocument(id: string): void {
    router.post(`${base}/hr-document-records/${id}/archive`, {}, { preserveScroll: true });
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
function removeNote(id: string): void {
    if (!window.confirm('Remove this note?')) return;
    router.delete(`${base}/notes/${id}`, { preserveScroll: true });
}
</script>

<template>
    <main class="mx-auto max-w-3xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/hr/employees">← Employees</a>

        <div class="mt-2 flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">{{ workspace.summary.display_name }}</h1>
                <p class="mt-1 text-sm text-slate-500">{{ workspace.summary.employee_number }}</p>
            </div>
            <div v-if="can.manageEmployees" class="flex items-center gap-2">
                <a
                    :href="`${base}/edit`"
                    class="rounded border border-slate-300 px-3 py-2 text-sm font-medium hover:bg-slate-50"
                >
                    Edit
                </a>
                <button
                    type="button"
                    class="rounded border border-slate-300 px-3 py-2 text-sm font-medium hover:bg-slate-50"
                    @click="toggleArchive"
                >
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
            <h2 class="text-sm font-medium text-slate-500">Summary</h2>
            <dl
                class="mt-2 grid grid-cols-1 gap-x-8 gap-y-3 rounded border border-slate-200 p-4 text-sm sm:grid-cols-2"
            >
                <div>
                    <dt class="text-slate-500">Status</dt>
                    <dd class="mt-0.5">
                        <StatusBadge :status="workspace.summary.employee_record_status" />
                    </dd>
                </div>
                <div>
                    <dt class="text-slate-500">Current employment status</dt>
                    <dd class="mt-0.5">{{ workspace.summary.current_employment_status ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Position</dt>
                    <dd class="mt-0.5">{{ workspace.summary.position_name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Department</dt>
                    <dd class="mt-0.5">{{ workspace.summary.department_name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Campus</dt>
                    <dd class="mt-0.5">{{ workspace.summary.campus_name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Reports to</dt>
                    <dd class="mt-0.5">{{ workspace.summary.manager_display_name ?? '—' }}</dd>
                </div>
            </dl>
        </section>

        <!-- Personal & contact -->
        <section class="mt-8">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-medium text-slate-500">
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
                class="mt-2 grid grid-cols-1 gap-x-8 gap-y-3 rounded border border-slate-200 p-4 text-sm sm:grid-cols-2"
            >
                <div>
                    <dt class="text-slate-500">Date of birth</dt>
                    <dd class="mt-0.5">{{ workspace.personal_details?.date_of_birth ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Nationality</dt>
                    <dd class="mt-0.5">{{ workspace.personal_details?.nationality ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Personal email</dt>
                    <dd class="mt-0.5">{{ workspace.contact?.personal_email ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Personal phone</dt>
                    <dd class="mt-0.5">{{ workspace.contact?.personal_phone ?? '—' }}</dd>
                </div>
            </dl>

            <form
                v-else
                class="mt-3 rounded border border-slate-200 p-4"
                @submit.prevent="submitPersonal"
            >
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm text-slate-600">Date of birth</label>
                        <input
                            v-model="personalForm.date_of_birth"
                            type="date"
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                    <div>
                        <label class="block text-sm text-slate-600">Nationality</label>
                        <input
                            v-model="personalForm.nationality"
                            type="text"
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                    <div>
                        <label class="block text-sm text-slate-600">Marital status</label>
                        <input
                            v-model="personalForm.marital_status"
                            type="text"
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                    <div>
                        <label class="block text-sm text-slate-600">Preferred language</label>
                        <input
                            v-model="personalForm.preferred_language"
                            type="text"
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                    <div>
                        <label class="block text-sm text-slate-600">Personal email</label>
                        <input
                            v-model="personalForm.personal_email"
                            type="email"
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                    <div>
                        <label class="block text-sm text-slate-600">Personal phone</label>
                        <input
                            v-model="personalForm.personal_phone"
                            type="text"
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                    <div>
                        <label class="block text-sm text-slate-600">Alternate phone</label>
                        <input
                            v-model="personalForm.alternate_phone"
                            type="text"
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                </div>
                <button
                    type="submit"
                    :disabled="personalForm.processing"
                    class="mt-3 rounded bg-slate-900 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50"
                >
                    Save
                </button>
            </form>
        </section>

        <!-- Addresses -->
        <section class="mt-8">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-medium text-slate-500">Addresses</h2>
                <button
                    v-if="can.managePersonal"
                    type="button"
                    class="text-sm font-medium underline"
                    @click="showAddressForm = !showAddressForm"
                >
                    {{ showAddressForm ? 'Cancel' : '+ Add address' }}
                </button>
            </div>

            <p v-if="workspace.addresses.length === 0" class="mt-3 text-sm text-slate-500">
                No addresses yet.
            </p>
            <ul v-else class="mt-3 space-y-2">
                <li
                    v-for="a in workspace.addresses"
                    :key="a.id"
                    class="flex items-start justify-between gap-3 rounded border border-slate-200 p-3 text-sm"
                >
                    <div>
                        <p class="font-medium capitalize">{{ a.address_type }}</p>
                        <p class="mt-0.5 text-slate-600">
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
                    <button
                        v-if="can.managePersonal"
                        type="button"
                        class="text-red-600 underline"
                        @click="removeAddress(a.id)"
                    >
                        Remove
                    </button>
                </li>
            </ul>

            <form
                v-if="showAddressForm"
                class="mt-3 rounded border border-slate-200 p-4"
                @submit.prevent="submitAddress"
            >
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm text-slate-600">Type</label>
                        <select
                            v-model="addressForm.address_type"
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        >
                            <option value="current">Current</option>
                            <option value="permanent">Permanent</option>
                            <option value="mailing">Mailing</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm text-slate-600">Address line 1</label>
                        <input
                            v-model="addressForm.address_line1"
                            type="text"
                            required
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                    <div>
                        <label class="block text-sm text-slate-600">City</label>
                        <input
                            v-model="addressForm.city"
                            type="text"
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                    <div>
                        <label class="block text-sm text-slate-600">State/region</label>
                        <input
                            v-model="addressForm.state_region"
                            type="text"
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                    <div>
                        <label class="block text-sm text-slate-600">Postal code</label>
                        <input
                            v-model="addressForm.postal_code"
                            type="text"
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                    <div>
                        <label class="block text-sm text-slate-600">Country code</label>
                        <input
                            v-model="addressForm.country_code"
                            type="text"
                            maxlength="2"
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                </div>
                <button
                    type="submit"
                    :disabled="addressForm.processing"
                    class="mt-3 rounded bg-slate-900 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50"
                >
                    Add address
                </button>
            </form>
        </section>

        <!-- Emergency contacts -->
        <section class="mt-8">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-medium text-slate-500">Emergency contacts</h2>
                <button
                    v-if="can.managePersonal"
                    type="button"
                    class="text-sm font-medium underline"
                    @click="showContactForm = !showContactForm"
                >
                    {{ showContactForm ? 'Cancel' : '+ Add contact' }}
                </button>
            </div>

            <p v-if="workspace.emergency_contacts.length === 0" class="mt-3 text-sm text-slate-500">
                No emergency contacts yet.
            </p>
            <ul v-else class="mt-3 space-y-2">
                <li
                    v-for="c in workspace.emergency_contacts"
                    :key="c.id"
                    class="flex items-start justify-between gap-3 rounded border border-slate-200 p-3 text-sm"
                >
                    <div>
                        <p class="font-medium">
                            {{ c.name }}
                            <span
                                v-if="c.is_primary"
                                class="ml-2 rounded-full bg-emerald-50 px-2 py-0.5 text-xs text-emerald-700"
                                >Primary</span
                            >
                        </p>
                        <p class="mt-0.5 text-slate-600">
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
                        <button
                            type="button"
                            class="text-red-600 underline"
                            @click="removeContact(c.id)"
                        >
                            Remove
                        </button>
                    </div>
                </li>
            </ul>

            <form
                v-if="showContactForm"
                class="mt-3 rounded border border-slate-200 p-4"
                @submit.prevent="submitContact"
            >
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm text-slate-600">Name</label>
                        <input
                            v-model="contactForm.name"
                            type="text"
                            required
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                    <div>
                        <label class="block text-sm text-slate-600">Relationship</label>
                        <input
                            v-model="contactForm.relationship"
                            type="text"
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                    <div>
                        <label class="block text-sm text-slate-600">Phone</label>
                        <input
                            v-model="contactForm.phone"
                            type="text"
                            required
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                    <div>
                        <label class="block text-sm text-slate-600">Email</label>
                        <input
                            v-model="contactForm.email"
                            type="email"
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                </div>
                <button
                    type="submit"
                    :disabled="contactForm.processing"
                    class="mt-3 rounded bg-slate-900 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50"
                >
                    Add contact
                </button>
            </form>
        </section>

        <!-- Employment history -->
        <section class="mt-8">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-medium text-slate-500">Employment history</h2>
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

            <p v-if="workspace.employment_history.length === 0" class="mt-3 text-sm text-slate-500">
                No employment history visible (either none exists yet, or it requires
                assignments-view access).
            </p>
            <ul v-else class="mt-3 space-y-2">
                <li
                    v-for="e in workspace.employment_history"
                    :key="e.id"
                    class="rounded border border-slate-200 p-3 text-sm"
                >
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="font-medium">
                                {{ e.employment_type }}
                                <span
                                    v-if="e.is_current"
                                    class="ml-2 rounded-full bg-emerald-50 px-2 py-0.5 text-xs text-emerald-700"
                                    >Current</span
                                >
                            </p>
                            <p class="mt-0.5 text-slate-500">
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
                                class="text-red-600 underline"
                                @click="
                                    endingEmploymentId = endingEmploymentId === e.id ? null : e.id
                                "
                            >
                                {{ endingEmploymentId === e.id ? 'Cancel' : 'End' }}
                            </button>
                        </div>
                    </div>

                    <form
                        v-if="endingEmploymentId === e.id"
                        class="mt-3 border-t border-slate-100 pt-3"
                        @submit.prevent="submitEndEmployment(e.id)"
                    >
                        <div class="flex flex-wrap items-end gap-3">
                            <div>
                                <label class="block text-sm text-slate-600">End date</label>
                                <input
                                    v-model="endEmploymentForm.ends_on"
                                    type="date"
                                    required
                                    class="mt-1 rounded border border-slate-300 px-3 py-2 text-sm"
                                />
                            </div>
                            <div>
                                <label class="block text-sm text-slate-600">Status</label>
                                <select
                                    v-model="endEmploymentForm.status"
                                    class="mt-1 rounded border border-slate-300 px-3 py-2 text-sm"
                                >
                                    <option value="separated">Separated</option>
                                    <option value="terminated">Terminated</option>
                                    <option value="retired">Retired</option>
                                    <option value="deceased">Deceased</option>
                                </select>
                            </div>
                            <button
                                type="submit"
                                :disabled="endEmploymentForm.processing"
                                class="rounded bg-slate-900 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50"
                            >
                                Confirm
                            </button>
                        </div>
                    </form>

                    <!-- Assignments under this employment -->
                    <ul
                        v-if="workspace.assignments.some((a) => a.employment_record_id === e.id)"
                        class="mt-3 space-y-2 border-t border-slate-100 pt-3"
                    >
                        <li
                            v-for="a in workspace.assignments.filter(
                                (x) => x.employment_record_id === e.id,
                            )"
                            :key="a.id"
                            class="rounded bg-slate-50 p-3"
                        >
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <p class="font-medium">
                                        {{ a.position_name ?? '—' }}
                                        <span
                                            v-if="a.is_primary"
                                            class="ml-2 rounded-full bg-emerald-50 px-2 py-0.5 text-xs text-emerald-700"
                                            >Primary</span
                                        >
                                        <span
                                            v-if="a.is_current"
                                            class="ml-2 rounded-full bg-sky-50 px-2 py-0.5 text-xs text-sky-700"
                                            >Current</span
                                        >
                                    </p>
                                    <p class="mt-0.5 text-slate-500">
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
                                        @click="
                                            managingAssignmentId =
                                                managingAssignmentId === a.id ? null : a.id
                                        "
                                    >
                                        {{
                                            managingAssignmentId === a.id ? 'Cancel' : 'Set manager'
                                        }}
                                    </button>
                                    <button
                                        v-if="a.ends_on === null"
                                        type="button"
                                        class="text-red-600 underline"
                                        @click="
                                            endingAssignmentId =
                                                endingAssignmentId === a.id ? null : a.id
                                        "
                                    >
                                        {{ endingAssignmentId === a.id ? 'Cancel' : 'End' }}
                                    </button>
                                </div>
                            </div>

                            <form
                                v-if="managingAssignmentId === a.id"
                                class="mt-3 border-t border-slate-200 pt-3"
                                @submit.prevent="submitManager(a)"
                            >
                                <label class="block text-sm text-slate-600"
                                    >Manager Assignment id (blank clears it)</label
                                >
                                <div class="mt-1 flex flex-wrap items-center gap-3">
                                    <input
                                        v-model="managerForm.manager_assignment_id"
                                        type="text"
                                        placeholder="Assignment UUID"
                                        class="w-72 rounded border border-slate-300 px-3 py-2 text-sm"
                                    />
                                    <button
                                        type="submit"
                                        :disabled="managerForm.processing"
                                        class="rounded bg-slate-900 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50"
                                    >
                                        Save
                                    </button>
                                </div>
                                <p class="mt-1 text-sm text-red-600">
                                    {{ managerForm.errors.manager_assignment_id }}
                                </p>
                            </form>

                            <form
                                v-if="endingAssignmentId === a.id"
                                class="mt-3 border-t border-slate-200 pt-3"
                                @submit.prevent="submitEndAssignment(a)"
                            >
                                <label class="block text-sm text-slate-600">End date</label>
                                <div class="mt-1 flex items-center gap-3">
                                    <input
                                        v-model="endAssignmentForm.ends_on"
                                        type="date"
                                        required
                                        class="rounded border border-slate-300 px-3 py-2 text-sm"
                                    />
                                    <button
                                        type="submit"
                                        :disabled="endAssignmentForm.processing"
                                        class="rounded bg-slate-900 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50"
                                    >
                                        Confirm
                                    </button>
                                </div>
                            </form>
                        </li>
                    </ul>

                    <form
                        v-if="assigningEmploymentId === e.id"
                        class="mt-3 space-y-3 border-t border-slate-100 pt-3"
                        @submit.prevent="submitAssignment(e.id)"
                    >
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <label class="block text-sm text-slate-600">Position</label>
                                <select
                                    v-model="assignmentForm.position_id"
                                    required
                                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                                >
                                    <option value="" disabled>Select a Position</option>
                                    <option v-for="p in positions" :key="p.id" :value="p.id">
                                        {{ p.name }}
                                    </option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm text-slate-600"
                                    >Department (optional)</label
                                >
                                <select
                                    v-model="assignmentForm.department_id"
                                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                                >
                                    <option value="">None</option>
                                    <option v-for="d in departments" :key="d.id" :value="d.id">
                                        {{ d.name }}
                                    </option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm text-slate-600">Start date</label>
                                <input
                                    v-model="assignmentForm.starts_on"
                                    type="date"
                                    required
                                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                                />
                            </div>
                        </div>
                        <p class="text-sm text-red-600">{{ assignmentForm.errors.position_id }}</p>
                        <button
                            type="submit"
                            :disabled="assignmentForm.processing"
                            class="rounded bg-slate-900 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50"
                        >
                            Add assignment
                        </button>
                    </form>
                </li>
            </ul>

            <form
                v-if="showRehireForm"
                class="mt-3 rounded border border-slate-200 p-4"
                @submit.prevent="submitRehire"
            >
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm text-slate-600">Employment type</label>
                        <input
                            v-model="rehireForm.employment_type"
                            type="text"
                            required
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                    <div>
                        <label class="block text-sm text-slate-600">Start date</label>
                        <input
                            v-model="rehireForm.starts_on"
                            type="date"
                            required
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                </div>
                <button
                    type="submit"
                    :disabled="rehireForm.processing"
                    class="mt-3 rounded bg-slate-900 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50"
                >
                    Rehire
                </button>
            </form>

            <form
                v-if="showEmploymentForm"
                class="mt-3 rounded border border-slate-200 p-4"
                @submit.prevent="submitEmployment"
            >
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm text-slate-600">Employment type</label>
                        <input
                            v-model="employmentForm.employment_type"
                            type="text"
                            required
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                    <div>
                        <label class="block text-sm text-slate-600">Category (optional)</label>
                        <select
                            v-model="employmentForm.employee_category_id"
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        >
                            <option value="">None</option>
                            <option v-for="c in employeeCategories" :key="c.id" :value="c.id">
                                {{ c.name }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm text-slate-600">Start date</label>
                        <input
                            v-model="employmentForm.starts_on"
                            type="date"
                            required
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                </div>
                <button
                    type="submit"
                    :disabled="employmentForm.processing"
                    class="mt-3 rounded bg-slate-900 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50"
                >
                    Add employment
                </button>
            </form>
        </section>

        <!-- Qualifications -->
        <section class="mt-8">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-medium text-slate-500">Qualifications</h2>
                <button
                    v-if="can.manageQualifications"
                    type="button"
                    class="text-sm font-medium underline"
                    @click="showQualificationForm = !showQualificationForm"
                >
                    {{ showQualificationForm ? 'Cancel' : '+ Add qualification' }}
                </button>
            </div>

            <p v-if="workspace.qualifications.length === 0" class="mt-3 text-sm text-slate-500">
                No qualifications visible.
            </p>
            <ul v-else class="mt-3 space-y-2">
                <li
                    v-for="q in workspace.qualifications"
                    :key="q.id"
                    class="flex items-start justify-between gap-3 rounded border border-slate-200 p-3 text-sm"
                >
                    <div>
                        <p class="font-medium">
                            {{ q.qualification_name }}
                            <span class="font-normal text-slate-500"
                                >({{ q.qualification_type }})</span
                            >
                        </p>
                        <p class="mt-0.5 text-slate-600">
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
                        <button
                            type="button"
                            class="text-red-600 underline"
                            @click="removeQualification(q.id)"
                        >
                            Remove
                        </button>
                    </div>
                </li>
            </ul>

            <form
                v-if="showQualificationForm"
                class="mt-3 rounded border border-slate-200 p-4"
                @submit.prevent="submitQualification"
            >
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm text-slate-600">Type</label>
                        <select
                            v-model="qualificationForm.qualification_type"
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        >
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
                        <label class="block text-sm text-slate-600">Name</label>
                        <input
                            v-model="qualificationForm.qualification_name"
                            type="text"
                            required
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                    <div>
                        <label class="block text-sm text-slate-600">Institution</label>
                        <input
                            v-model="qualificationForm.institution"
                            type="text"
                            required
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                    <div>
                        <label class="block text-sm text-slate-600">Completed on</label>
                        <input
                            v-model="qualificationForm.completed_on"
                            type="date"
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                </div>
                <button
                    type="submit"
                    :disabled="qualificationForm.processing"
                    class="mt-3 rounded bg-slate-900 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50"
                >
                    Add qualification
                </button>
            </form>
        </section>

        <!-- Experience -->
        <section class="mt-8">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-medium text-slate-500">Experience</h2>
                <button
                    v-if="can.manageQualifications"
                    type="button"
                    class="text-sm font-medium underline"
                    @click="showExperienceForm = !showExperienceForm"
                >
                    {{ showExperienceForm ? 'Cancel' : '+ Add experience' }}
                </button>
            </div>

            <p v-if="workspace.experience.length === 0" class="mt-3 text-sm text-slate-500">
                No experience records visible.
            </p>
            <ul v-else class="mt-3 space-y-2">
                <li
                    v-for="x in workspace.experience"
                    :key="x.id"
                    class="flex items-start justify-between gap-3 rounded border border-slate-200 p-3 text-sm"
                >
                    <div>
                        <p class="font-medium">{{ x.job_title }} · {{ x.organization }}</p>
                        <p class="mt-0.5 text-slate-600">
                            {{ x.starts_on }} – {{ x.ends_on ?? 'present' }}
                        </p>
                    </div>
                    <button
                        v-if="can.manageQualifications"
                        type="button"
                        class="text-red-600 underline"
                        @click="removeExperience(x.id)"
                    >
                        Remove
                    </button>
                </li>
            </ul>

            <form
                v-if="showExperienceForm"
                class="mt-3 rounded border border-slate-200 p-4"
                @submit.prevent="submitExperience"
            >
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm text-slate-600">Organization</label>
                        <input
                            v-model="experienceForm.organization"
                            type="text"
                            required
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                    <div>
                        <label class="block text-sm text-slate-600">Job title</label>
                        <input
                            v-model="experienceForm.job_title"
                            type="text"
                            required
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                    <div>
                        <label class="block text-sm text-slate-600">Start date</label>
                        <input
                            v-model="experienceForm.starts_on"
                            type="date"
                            required
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                    <div>
                        <label class="block text-sm text-slate-600">End date</label>
                        <input
                            v-model="experienceForm.ends_on"
                            type="date"
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                </div>
                <button
                    type="submit"
                    :disabled="experienceForm.processing"
                    class="mt-3 rounded bg-slate-900 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50"
                >
                    Add experience
                </button>
            </form>
        </section>

        <!-- Certifications -->
        <section class="mt-8">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-medium text-slate-500">Certifications</h2>
                <button
                    v-if="can.manageQualifications"
                    type="button"
                    class="text-sm font-medium underline"
                    @click="showCertificationForm = !showCertificationForm"
                >
                    {{ showCertificationForm ? 'Cancel' : '+ Add certification' }}
                </button>
            </div>

            <p v-if="workspace.certifications.length === 0" class="mt-3 text-sm text-slate-500">
                No certifications visible.
            </p>
            <ul v-else class="mt-3 space-y-2">
                <li
                    v-for="c in workspace.certifications"
                    :key="c.id"
                    class="flex items-start justify-between gap-3 rounded border border-slate-200 p-3 text-sm"
                >
                    <div>
                        <p class="font-medium">{{ c.name }}</p>
                        <p class="mt-0.5 text-slate-600">
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
                        <button
                            type="button"
                            class="text-red-600 underline"
                            @click="removeCertification(c.id)"
                        >
                            Remove
                        </button>
                    </div>
                </li>
            </ul>

            <form
                v-if="showCertificationForm"
                class="mt-3 rounded border border-slate-200 p-4"
                @submit.prevent="submitCertification"
            >
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm text-slate-600">Name</label>
                        <input
                            v-model="certificationForm.name"
                            type="text"
                            required
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                    <div>
                        <label class="block text-sm text-slate-600">Issuer</label>
                        <input
                            v-model="certificationForm.issuer"
                            type="text"
                            required
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                    <div>
                        <label class="block text-sm text-slate-600">Issued on</label>
                        <input
                            v-model="certificationForm.issued_on"
                            type="date"
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                    <div>
                        <label class="block text-sm text-slate-600">Expires on</label>
                        <input
                            v-model="certificationForm.expires_on"
                            type="date"
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                </div>
                <button
                    type="submit"
                    :disabled="certificationForm.processing"
                    class="mt-3 rounded bg-slate-900 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50"
                >
                    Add certification
                </button>
            </form>
        </section>

        <!-- Documents -->
        <section class="mt-8">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-medium text-slate-500">Documents (Restricted metadata)</h2>
                <button
                    v-if="can.manageDocuments"
                    type="button"
                    class="text-sm font-medium underline"
                    @click="showDocumentForm = !showDocumentForm"
                >
                    {{ showDocumentForm ? 'Cancel' : '+ Register document' }}
                </button>
            </div>
            <p class="mt-1 text-xs text-slate-400">
                Metadata only -- file upload is not available yet. Highly Sensitive documents never
                appear here.
            </p>

            <p v-if="workspace.documents.length === 0" class="mt-3 text-sm text-slate-500">
                No documents visible.
            </p>
            <ul v-else class="mt-3 space-y-2">
                <li
                    v-for="d in workspace.documents"
                    :key="d.id"
                    class="flex items-start justify-between gap-3 rounded border border-slate-200 p-3 text-sm"
                >
                    <div>
                        <p class="font-medium capitalize">{{ d.category.replace(/_/g, ' ') }}</p>
                        <p class="mt-0.5 text-slate-600">
                            {{ d.status }} · expires {{ d.expires_on ?? 'never' }}
                        </p>
                    </div>
                    <button
                        v-if="can.manageDocuments && d.status === 'active'"
                        type="button"
                        class="text-red-600 underline"
                        @click="archiveDocument(d.id)"
                    >
                        Archive
                    </button>
                </li>
            </ul>

            <form
                v-if="showDocumentForm"
                class="mt-3 rounded border border-slate-200 p-4"
                @submit.prevent="submitDocument"
            >
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm text-slate-600">Category</label>
                        <input
                            v-model="documentForm.category"
                            type="text"
                            placeholder="id_proof"
                            required
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                    <div>
                        <label class="block text-sm text-slate-600">Classification</label>
                        <select
                            v-model="documentForm.classification_tier"
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        >
                            <option value="restricted">Restricted</option>
                            <option value="highly_sensitive">Highly sensitive</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm text-slate-600">Storage path</label>
                        <input
                            v-model="documentForm.storage_path"
                            type="text"
                            required
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                    <div>
                        <label class="block text-sm text-slate-600">Original filename</label>
                        <input
                            v-model="documentForm.original_filename"
                            type="text"
                            required
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                    <div>
                        <label class="block text-sm text-slate-600">Size (bytes)</label>
                        <input
                            v-model.number="documentForm.size_bytes"
                            type="number"
                            min="0"
                            required
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                    <div>
                        <label class="block text-sm text-slate-600">Expires on</label>
                        <input
                            v-model="documentForm.expires_on"
                            type="date"
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                </div>
                <button
                    type="submit"
                    :disabled="documentForm.processing"
                    class="mt-3 rounded bg-slate-900 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50"
                >
                    Register document
                </button>
            </form>
        </section>

        <!-- Notes -->
        <section class="mt-8">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-medium text-slate-500">
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

            <p v-if="workspace.notes.length === 0" class="mt-3 text-sm text-slate-500">
                No notes visible.
            </p>
            <ul v-else class="mt-3 space-y-2">
                <li
                    v-for="n in workspace.notes"
                    :key="n.id"
                    class="flex items-start justify-between gap-3 rounded border border-slate-200 p-3 text-sm"
                >
                    <div>
                        <p>{{ n.body }}</p>
                        <p class="mt-1 text-xs text-slate-500">
                            {{ n.classification_tier }} ·
                            {{ n.author_display_name ?? 'Unknown author' }} · {{ n.created_at }}
                        </p>
                    </div>
                    <button
                        v-if="can.manageNotes"
                        type="button"
                        class="text-red-600 underline"
                        @click="removeNote(n.id)"
                    >
                        Remove
                    </button>
                </li>
            </ul>

            <form
                v-if="showNoteForm"
                class="mt-3 rounded border border-slate-200 p-4"
                @submit.prevent="submitNote"
            >
                <label class="block text-sm text-slate-600">Note</label>
                <textarea
                    v-model="noteForm.body"
                    required
                    rows="3"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                ></textarea>
                <div class="mt-3">
                    <label class="block text-sm text-slate-600">Classification</label>
                    <select
                        v-model="noteForm.classification_tier"
                        class="mt-1 w-48 rounded border border-slate-300 px-3 py-2 text-sm"
                    >
                        <option value="sensitive">Sensitive</option>
                        <option value="confidential">Confidential</option>
                    </select>
                </div>
                <button
                    type="submit"
                    :disabled="noteForm.processing"
                    class="mt-3 rounded bg-slate-900 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50"
                >
                    Add note
                </button>
            </form>
        </section>
    </main>
</template>
