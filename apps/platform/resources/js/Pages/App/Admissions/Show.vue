<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import StatusBadge from '../../../Components/StatusBadge.vue';
import { RELATIONSHIP_TYPES } from '../../../relationshipTypes';

interface Ref {
    id: string;
    name: string;
    code: string;
}

interface ApplicantRef {
    id: string;
    firstName: string;
    middleName: string | null;
    lastName: string | null;
}

interface SectionOption {
    id: string;
    label: string;
}

interface GuardianCandidate {
    id: string;
    firstName: string;
    middleName: string | null;
    lastName: string | null;
    status: string;
}

interface Props {
    application: {
        id: string;
        status: 'draft' | 'submitted' | 'accepted' | 'rejected' | 'withdrawn' | 'converted';
        applicant: ApplicantRef;
        academicYear: Ref;
        campus: Ref;
        gradeLevel: Ref;
        decisionNote: string | null;
        convertedStudentId: string | null;
        convertedStudentEnrollmentId: string | null;
        createdAt: string;
        convertedAt: string | null;
        updatedAt: string;
    };
    canManage: boolean;
    canViewStudents: boolean;
    compatibleSections: SectionOption[];
}

const props = defineProps<Props>();

const page = usePage<{ errors: Record<string, string> }>();
const statusError = computed(() => page.props.errors?.status);

function applicantName(): string {
    const a = props.application.applicant;
    return [a.firstName, a.middleName, a.lastName].filter(Boolean).join(' ');
}

// --- Simple one-click transitions (submit / withdraw) -------------------

const transitioning = ref(false);

function submitApplication(): void {
    transitioning.value = true;
    router.post(
        `/app/admissions/${props.application.id}/submit`,
        {},
        {
            preserveScroll: true,
            onFinish: () => (transitioning.value = false),
        },
    );
}

function withdrawApplication(): void {
    const confirmed = window.confirm(
        `Withdraw this application for ${applicantName()}? This cannot be undone from this page.`,
    );
    if (!confirmed) return;
    transitioning.value = true;
    router.post(
        `/app/admissions/${props.application.id}/withdraw`,
        {},
        {
            preserveScroll: true,
            onFinish: () => (transitioning.value = false),
        },
    );
}

// --- Accept / Reject (optional decision note) ----------------------------

type DecisionAction = 'accept' | 'reject';
const activeDecision = ref<DecisionAction | null>(null);
const decisionForm = useForm({ decision_note: '' });

function startDecision(action: DecisionAction): void {
    decisionForm.clearErrors();
    decisionForm.decision_note = '';
    activeDecision.value = action;
}

function submitDecision(): void {
    if (!activeDecision.value) return;
    if (activeDecision.value === 'reject') {
        const confirmed = window.confirm(`Reject this application for ${applicantName()}?`);
        if (!confirmed) return;
    }
    decisionForm.post(`/app/admissions/${props.application.id}/${activeDecision.value}`, {
        preserveScroll: true,
        onSuccess: () => (activeDecision.value = null),
    });
}

// --- Conversion -----------------------------------------------------------

type GuardianMode = 'none' | 'create' | 'link_existing';

const convertForm = useForm({
    student_number: '',
    section_id: '',
    roll_number: '',
    starts_on: '',
    guardian_mode: 'none' as GuardianMode,
    guardian_relationship_type: '',
    guardian_is_legal_guardian: false,
    guardian_is_emergency_contact: false,
    guardian_is_authorized_pickup: false,
    guardian_first_name: '',
    guardian_middle_name: '',
    guardian_last_name: '',
    guardian_contact_type: 'email' as 'email' | 'mobile',
    guardian_contact_value: '',
    guardian_guardian_id: '',
});

// The server's error bag uses dotted keys matching its own nested
// `guardian.*` request shape (see AdmissionApplicationController::convert()'s
// ValidationException::withMessages() calls) -- this form's flat
// `guardian_*` fields (needed for v-model) don't match those keys, so
// convertForm.errors' generated type doesn't know about them either.
// This helper reads the same runtime object with the real server key.
function convertError(key: string): string | undefined {
    return (convertForm.errors as Record<string, string | undefined>)[key];
}

convertForm.transform((data) => {
    const base = {
        student_number: data.student_number,
        section_id: data.section_id,
        roll_number: data.roll_number,
        starts_on: data.starts_on,
    };

    if (data.guardian_mode === 'none') {
        return base;
    }

    if (data.guardian_mode === 'create') {
        return {
            ...base,
            guardian: {
                mode: 'create',
                relationship_type: data.guardian_relationship_type,
                is_legal_guardian: data.guardian_is_legal_guardian,
                is_emergency_contact: data.guardian_is_emergency_contact,
                is_authorized_pickup: data.guardian_is_authorized_pickup,
                first_name: data.guardian_first_name,
                middle_name: data.guardian_middle_name || null,
                last_name: data.guardian_last_name || null,
                contact_type: data.guardian_contact_value ? data.guardian_contact_type : null,
                contact_value: data.guardian_contact_value || null,
            },
        };
    }

    return {
        ...base,
        guardian: {
            mode: 'link_existing',
            relationship_type: data.guardian_relationship_type,
            is_legal_guardian: data.guardian_is_legal_guardian,
            is_emergency_contact: data.guardian_is_emergency_contact,
            is_authorized_pickup: data.guardian_is_authorized_pickup,
            guardian_id: data.guardian_guardian_id,
        },
    };
});

function submitConversion(): void {
    const confirmed = window.confirm(
        `Convert this application into a Student record for ${applicantName()}? This creates a permanent Student and Enrollment.`,
    );
    if (!confirmed) return;
    convertForm.post(`/app/admissions/${props.application.id}/convert`, { preserveScroll: true });
}

// -- Guardian: link-existing name search ----------------------------------

const nameQuery = ref('');
const nameResults = ref<GuardianCandidate[]>([]);
const selectedGuardian = ref<GuardianCandidate | null>(null);
let nameDebounce: ReturnType<typeof setTimeout> | undefined;

function searchGuardianCandidates(value: string): void {
    clearTimeout(nameDebounce);
    if (value.trim().length < 2) {
        nameResults.value = [];
        return;
    }
    nameDebounce = setTimeout(async () => {
        const response = await fetch(
            `/app/admissions/guardians/search?q=${encodeURIComponent(value)}`,
            {
                headers: { Accept: 'application/json' },
            },
        );
        const body = await response.json();
        nameResults.value = body.data;
    }, 300);
}

function selectGuardian(g: GuardianCandidate): void {
    selectedGuardian.value = g;
    convertForm.guardian_guardian_id = g.id;
    nameResults.value = [];
    nameQuery.value = '';
}

function guardianCandidateName(g: GuardianCandidate): string {
    return [g.firstName, g.middleName, g.lastName].filter(Boolean).join(' ');
}

// -- Guardian: create-mode contact-conflict check --------------------------

const contactCandidates = ref<GuardianCandidate[]>([]);
const contactChecking = ref(false);
const contactChecked = ref(false);

async function checkContactCandidates(): Promise<void> {
    if (!convertForm.guardian_contact_value) return;
    contactChecking.value = true;
    try {
        const response = await fetch(
            '/app/admissions/guardians/candidates?' +
                new URLSearchParams({
                    type: convertForm.guardian_contact_type,
                    value: convertForm.guardian_contact_value,
                }).toString(),
            { headers: { Accept: 'application/json' } },
        );
        const body = await response.json();
        contactCandidates.value = response.ok ? body.data : [];
        contactChecked.value = true;
    } finally {
        contactChecking.value = false;
    }
}

function switchToLinkExisting(g: GuardianCandidate): void {
    convertForm.guardian_mode = 'link_existing';
    selectGuardian(g);
    contactCandidates.value = [];
    contactChecked.value = false;
}
</script>

<template>
    <main class="mx-auto max-w-3xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/admissions">← Admissions</a>

        <div class="mt-2 flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">
                    <a
                        class="underline"
                        :href="`/app/admissions/applicants/${application.applicant.id}`"
                        >{{ applicantName() }}</a
                    >
                </h1>
                <p class="mt-1 text-sm text-slate-500">
                    {{ application.gradeLevel.name }} · {{ application.campus.name }} ·
                    {{ application.academicYear.name }}
                </p>
            </div>
            <StatusBadge :status="application.status" />
        </div>

        <p
            v-if="statusError"
            class="mt-4 rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700"
        >
            {{ statusError }}
        </p>

        <section
            v-if="application.decisionNote"
            class="mt-6 rounded border border-slate-200 p-4 text-sm"
        >
            <h2 class="font-medium text-slate-500">Decision note</h2>
            <p class="mt-1 whitespace-pre-wrap">{{ application.decisionNote }}</p>
        </section>

        <section
            v-if="application.status === 'converted'"
            class="mt-6 rounded border border-slate-200 p-4 text-sm"
        >
            <h2 class="font-medium text-slate-500">Converted</h2>
            <p class="mt-1">Converted {{ (application.convertedAt ?? '').slice(0, 10) }}.</p>
            <a
                v-if="canViewStudents && application.convertedStudentId"
                class="mt-2 inline-block underline"
                :href="`/app/students/${application.convertedStudentId}`"
            >
                View converted Student
            </a>
        </section>

        <!-- Lifecycle actions -->
        <section v-if="canManage" class="mt-6 flex flex-wrap items-center gap-3 text-sm">
            <button
                v-if="application.status === 'draft'"
                type="button"
                :disabled="transitioning"
                class="rounded bg-slate-900 px-3 py-1.5 font-medium text-white disabled:opacity-50"
                @click="submitApplication"
            >
                Submit
            </button>
            <button
                v-if="application.status === 'submitted'"
                type="button"
                class="rounded bg-slate-900 px-3 py-1.5 font-medium text-white"
                @click="startDecision('accept')"
            >
                Accept
            </button>
            <button
                v-if="application.status === 'submitted'"
                type="button"
                class="rounded border border-slate-300 px-3 py-1.5 font-medium hover:bg-slate-50"
                @click="startDecision('reject')"
            >
                Reject
            </button>
            <button
                v-if="['submitted', 'accepted'].includes(application.status)"
                type="button"
                :disabled="transitioning"
                class="text-red-600 underline disabled:opacity-50"
                @click="withdrawApplication"
            >
                Withdraw
            </button>
        </section>

        <!-- Inline accept/reject decision-note form -->
        <form
            v-if="activeDecision"
            class="mt-4 rounded border border-slate-200 p-4"
            @submit.prevent="submitDecision"
        >
            <p class="text-sm font-medium text-slate-700">
                {{ activeDecision === 'accept' ? 'Accept application' : 'Reject application' }}
            </p>
            <div class="mt-2">
                <label class="block text-sm text-slate-600" for="decision_note">
                    Decision note (optional)
                </label>
                <textarea
                    id="decision_note"
                    v-model="decisionForm.decision_note"
                    rows="3"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <p class="mt-1 text-sm text-red-600">{{ decisionForm.errors.decision_note }}</p>
            </div>
            <div class="mt-3 flex items-center gap-3">
                <button
                    type="submit"
                    :disabled="decisionForm.processing"
                    class="rounded bg-slate-900 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50"
                >
                    Confirm {{ activeDecision === 'accept' ? 'accept' : 'reject' }}
                </button>
                <button type="button" class="text-sm underline" @click="activeDecision = null">
                    Cancel
                </button>
            </div>
        </form>

        <!-- Conversion -->
        <section
            v-if="canManage && application.status === 'accepted'"
            class="mt-8 rounded border border-slate-200 p-4"
        >
            <h2 class="text-sm font-medium text-slate-500">Convert to Student</h2>
            <p class="mt-1 text-sm text-slate-500">
                Creates a Student, enrolls them into the Section you choose below, and (optionally)
                links a Guardian -- all at once. This cannot be undone.
            </p>

            <form class="mt-4 space-y-4" @submit.prevent="submitConversion">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-slate-700" for="student_number"
                            >Student number</label
                        >
                        <input
                            id="student_number"
                            v-model="convertForm.student_number"
                            type="text"
                            required
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        />
                        <p class="mt-1 text-sm text-red-600">
                            {{ convertForm.errors.student_number }}
                        </p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700" for="roll_number"
                            >Roll number</label
                        >
                        <input
                            id="roll_number"
                            v-model="convertForm.roll_number"
                            type="text"
                            required
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        />
                        <p class="mt-1 text-sm text-red-600">
                            {{ convertForm.errors.roll_number }}
                        </p>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-slate-700" for="section_id"
                            >Section</label
                        >
                        <select
                            id="section_id"
                            v-model="convertForm.section_id"
                            required
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        >
                            <option value="" disabled>Select a Section…</option>
                            <option v-for="s in compatibleSections" :key="s.id" :value="s.id">
                                {{ s.label }}
                            </option>
                        </select>
                        <p class="mt-1 text-sm text-red-600">{{ convertForm.errors.section_id }}</p>
                        <p
                            v-if="compatibleSections.length === 0"
                            class="mt-1 text-sm text-amber-700"
                        >
                            No active Section matches this application's Academic Year/Campus/Grade
                            yet.
                        </p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700" for="starts_on"
                            >Start date</label
                        >
                        <input
                            id="starts_on"
                            v-model="convertForm.starts_on"
                            type="date"
                            required
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        />
                        <p class="mt-1 text-sm text-red-600">{{ convertForm.errors.starts_on }}</p>
                    </div>
                </div>

                <div class="border-t border-slate-100 pt-4">
                    <p class="text-sm font-medium text-slate-700">Guardian (optional)</p>
                    <div class="mt-2 flex gap-4 text-sm">
                        <label class="flex items-center gap-2">
                            <input v-model="convertForm.guardian_mode" type="radio" value="none" />
                            None
                        </label>
                        <label class="flex items-center gap-2">
                            <input
                                v-model="convertForm.guardian_mode"
                                type="radio"
                                value="create"
                            />
                            Create new
                        </label>
                        <label class="flex items-center gap-2">
                            <input
                                v-model="convertForm.guardian_mode"
                                type="radio"
                                value="link_existing"
                            />
                            Link existing
                        </label>
                    </div>

                    <div v-if="convertForm.guardian_mode !== 'none'" class="mt-3">
                        <label class="block text-sm text-slate-600" for="guardian_relationship_type"
                            >Relationship</label
                        >
                        <select
                            id="guardian_relationship_type"
                            v-model="convertForm.guardian_relationship_type"
                            required
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm sm:w-64"
                        >
                            <option value="" disabled>Select…</option>
                            <option v-for="t in RELATIONSHIP_TYPES" :key="t.value" :value="t.value">
                                {{ t.label }}
                            </option>
                        </select>
                        <p class="mt-1 text-sm text-red-600">
                            {{ convertError('guardian.relationship_type') }}
                        </p>

                        <fieldset class="mt-3 space-y-2 text-sm">
                            <legend class="sr-only">Authority</legend>
                            <label class="flex items-center gap-2">
                                <input
                                    v-model="convertForm.guardian_is_legal_guardian"
                                    type="checkbox"
                                />
                                Legal guardian
                            </label>
                            <label class="flex items-center gap-2">
                                <input
                                    v-model="convertForm.guardian_is_emergency_contact"
                                    type="checkbox"
                                />
                                Emergency contact
                            </label>
                            <label class="flex items-center gap-2">
                                <input
                                    v-model="convertForm.guardian_is_authorized_pickup"
                                    type="checkbox"
                                />
                                Authorized pickup
                            </label>
                        </fieldset>
                    </div>

                    <!-- Create mode -->
                    <div v-if="convertForm.guardian_mode === 'create'" class="mt-4 space-y-3">
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <input
                                v-model="convertForm.guardian_first_name"
                                type="text"
                                placeholder="First name"
                                class="rounded border border-slate-300 px-3 py-2 text-sm"
                            />
                            <input
                                v-model="convertForm.guardian_middle_name"
                                type="text"
                                placeholder="Middle name"
                                class="rounded border border-slate-300 px-3 py-2 text-sm"
                            />
                            <input
                                v-model="convertForm.guardian_last_name"
                                type="text"
                                placeholder="Last name"
                                class="rounded border border-slate-300 px-3 py-2 text-sm"
                            />
                        </div>
                        <p class="text-sm text-red-600">
                            {{ convertError('guardian.first_name') }}
                        </p>

                        <div class="flex flex-wrap items-end gap-3">
                            <div>
                                <label
                                    class="block text-sm text-slate-600"
                                    for="guardian_contact_type"
                                    >Contact type</label
                                >
                                <select
                                    id="guardian_contact_type"
                                    v-model="convertForm.guardian_contact_type"
                                    class="mt-1 rounded border border-slate-300 px-3 py-2 text-sm"
                                >
                                    <option value="email">Email</option>
                                    <option value="mobile">Mobile</option>
                                </select>
                            </div>
                            <div class="flex-1">
                                <label
                                    class="block text-sm text-slate-600"
                                    for="guardian_contact_value"
                                    >Contact value (optional)</label
                                >
                                <input
                                    id="guardian_contact_value"
                                    v-model="convertForm.guardian_contact_value"
                                    type="text"
                                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                                />
                            </div>
                            <button
                                type="button"
                                :disabled="contactChecking || !convertForm.guardian_contact_value"
                                class="rounded border border-slate-300 px-3 py-2 text-sm font-medium hover:bg-slate-50 disabled:opacity-50"
                                @click="checkContactCandidates"
                            >
                                Check for existing
                            </button>
                        </div>
                        <p class="text-sm text-red-600">
                            {{ convertError('guardian.contact_value') }}
                        </p>

                        <div
                            v-if="contactChecked && contactCandidates.length > 0"
                            class="rounded border border-amber-200 bg-amber-50 p-3"
                        >
                            <p class="text-sm text-amber-700">
                                Existing guardians already use this contact -- link one instead of
                                creating a new Guardian.
                            </p>
                            <ul class="mt-2 divide-y divide-amber-100">
                                <li
                                    v-for="g in contactCandidates"
                                    :key="g.id"
                                    class="flex items-center justify-between py-1.5 text-sm"
                                >
                                    <span>{{ guardianCandidateName(g) }}</span>
                                    <button
                                        type="button"
                                        class="underline"
                                        @click="switchToLinkExisting(g)"
                                    >
                                        Use this guardian
                                    </button>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <!-- Link-existing mode -->
                    <div v-if="convertForm.guardian_mode === 'link_existing'" class="mt-4">
                        <div v-if="selectedGuardian" class="text-sm">
                            Linking <strong>{{ guardianCandidateName(selectedGuardian) }}</strong> ·
                            <button
                                type="button"
                                class="underline"
                                @click="
                                    selectedGuardian = null;
                                    convertForm.guardian_guardian_id = '';
                                "
                            >
                                Change
                            </button>
                        </div>
                        <div v-else class="relative">
                            <input
                                v-model="nameQuery"
                                type="text"
                                placeholder="Search Guardians by name…"
                                class="w-full rounded border border-slate-300 px-3 py-2 text-sm"
                                @input="searchGuardianCandidates(nameQuery)"
                            />
                            <ul
                                v-if="nameResults.length > 0"
                                class="mt-1 divide-y divide-slate-100 rounded border border-slate-200"
                            >
                                <li
                                    v-for="g in nameResults"
                                    :key="g.id"
                                    class="flex items-center justify-between px-3 py-2 text-sm"
                                >
                                    <span>{{ guardianCandidateName(g) }}</span>
                                    <button
                                        type="button"
                                        class="underline"
                                        @click="selectGuardian(g)"
                                    >
                                        Select
                                    </button>
                                </li>
                            </ul>
                        </div>
                        <p class="mt-1 text-sm text-red-600">
                            {{ convertError('guardian.guardian_id') }}
                        </p>
                    </div>
                </div>

                <button
                    type="submit"
                    :disabled="convertForm.processing"
                    class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                >
                    {{ convertForm.processing ? 'Converting…' : 'Convert to Student' }}
                </button>
            </form>
        </section>
    </main>
</template>
