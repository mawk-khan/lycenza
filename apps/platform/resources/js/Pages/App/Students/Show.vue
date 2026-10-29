<script setup lang="ts">
import { formatCalendarDate } from '../../../datetime';
import { useConfirmation } from '../../../useConfirmation';
import ConfirmDialog from '../../../Components/ConfirmDialog.vue';
import SearchCombobox from '../../../Components/Form/SearchCombobox.vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import EmptyState from '../../../Components/EmptyState.vue';
import MfaCodeField from '../../../Components/MfaCodeField.vue';
import RelationshipFlags from '../../../Components/RelationshipFlags.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';
import { RELATIONSHIP_TYPES } from '../../../relationshipTypes';
import StudentFamily from '../../../Components/Students/StudentFamily.vue';
import StudentProfileSummaries from '../../../Components/Students/StudentProfileSummaries.vue';
import type { StudentProfileSections } from '../../../Components/Students/studentProfileSections';

interface Relationship {
    id: string;
    relationshipType: string;
    isPrimary: boolean;
    isLegalGuardian: boolean;
    isEmergencyContact: boolean;
    isAuthorizedPickup: boolean;
    guardian: {
        id: string;
        firstName: string;
        middleName: string | null;
        lastName: string | null;
        status: 'active' | 'inactive';
    };
    contact: { type: string; value: string } | null;
}

interface AccountLink {
    schoolMembershipId: string;
    memberName: string;
    membershipActive: boolean;
}

interface MembershipCandidate {
    schoolMembershipId: string;
    name: string;
}

interface EnrollmentRef {
    id: string;
    name: string;
}

interface Enrollment {
    id: string;
    academicYear: EnrollmentRef & { status: 'draft' | 'active' | 'closed'; endsOn: string };
    campus: EnrollmentRef;
    gradeLevel: EnrollmentRef;
    section: EnrollmentRef;
    rollNumber: string;
    status: 'active' | 'completed' | 'withdrawn' | 'transferred' | 'cancelled';
    startsOn: string;
    endsOn: string | null;
    startsInFuture: boolean;
    cancelledBeforeStart: boolean;
}

interface Props {
    // SIS 6.1: the viewer reads Attendance (the records page re-checks).
    canViewAttendance?: boolean;
    student: {
        id: string;
        studentNumber: string;
        firstName: string;
        middleName: string | null;
        lastName: string | null;
        status: 'active' | 'inactive';
        dateOfBirth: string;
        createdAt: string;
    };
    relationships: Relationship[];
    canManageStudents: boolean;
    canManageGuardians: boolean;
    // ORG.7F-1 (F-1): a Guardian's contact value is sent only to a
    // guardians.view holder; without it `contact` is always null.
    canViewGuardianContacts: boolean;
    accountLink: AccountLink | null;
    // Phase 1B.6: present ONLY when the actor holds enrollments.view --
    // a Student page for a user with students.view but not
    // enrollments.view must never receive Enrollment data at all (this
    // checkpoint's brief, section 35), so every field below the flag is
    // optional.
    canViewEnrollments: boolean;
    canManageEnrollments?: boolean;
    // ORG.8E (ADR 0076 §63): the closed reasons per high-impact action and
    // whether the actor can give a fresh code.
    lifecycleReasons: Record<string, string[]>;
    hasMfaFactor: boolean;
    currentEnrollment?: Enrollment | null;
    enrollmentHistory?: Enrollment[];
    // SIS.EXP-1: the Student 360 sections the server authorized (absent = not shown).
    sections: StudentProfileSections;
}

const props = defineProps<Props>();

// SIS.EXP-1: whether any "At a glance" card is present (for the page navigation).
const hasGlance = computed(() =>
    (['subjects', 'attendance', 'fees', 'transport', 'hostel', 'library'] as const).some(
        (key) => props.sections[key] !== undefined,
    ),
);

// SIS 9.1: the accessible confirmation (replaces window.confirm).
const { confirmDialog, confirmation, confirmAction, onConfirm, onClose } = useConfirmation();

const canLinkGuardians = props.canManageStudents && props.canManageGuardians;

// --- Phase 5B.2: Lycenza account link ---------------------------------

const showLinkPicker = ref(false);
const linkQuery = ref('');
const linkCandidates = ref<MembershipCandidate[]>([]);
const linking = ref(false);
const unlinking = ref(false);
let linkSearchDebounce: ReturnType<typeof setTimeout> | undefined;

function searchLinkCandidates(): void {
    const q = linkQuery.value.trim();
    clearTimeout(linkSearchDebounce);
    linkSearchDebounce = setTimeout(async () => {
        const response = await fetch(
            `/app/students/${props.student.id}/account-link/search?q=${encodeURIComponent(q)}`,
            { headers: { Accept: 'application/json' } },
        );
        // UAT-C4: a refused or non-JSON answer is simply no results.
        const body = response.ok ? await response.json().catch(() => ({ data: [] })) : { data: [] };
        linkCandidates.value = body.candidates as MembershipCandidate[];
    }, 250);
}

function linkAccount(candidate: MembershipCandidate): void {
    linking.value = true;
    router.post(
        `/app/students/${props.student.id}/account-link`,
        { school_membership_id: candidate.schoolMembershipId },
        {
            preserveScroll: true,
            onFinish: () => {
                linking.value = false;
                showLinkPicker.value = false;
                linkQuery.value = '';
                linkCandidates.value = [];
            },
        },
    );
}

async function unlinkAccount(): Promise<void> {
    if (
        !(await confirmAction({
            title: 'Unlink this account?',
            body: 'Unlink this Lycenza account? The Student record and any past communications are kept -- only future in-app reachability changes.',
            confirmLabel: 'Unlink account',
            tone: 'danger',
        }))
    )
        return;
    unlinking.value = true;
    router.delete(`/app/students/${props.student.id}/account-link`, {
        preserveScroll: true,
        onFinish: () => (unlinking.value = false),
    });
}

// --- ORG.8E: high-impact lifecycle assurance ----------------------------
// Deactivate/reactivate, withdraw and cancel need a closed reason, an
// explicit confirmation and a fresh authentication code; the server
// re-checks all of it. Completion stays an ordinary action.

const REASON_LABELS: Record<string, string> = {
    left_school: 'Left the School',
    transferred_out: 'Transferred to another school',
    completed_studies: 'Completed studies',
    duplicate_record: 'Duplicate record',
    entered_in_error: 'Entered in error',
    returned_to_school: 'Returned to the School',
    deactivated_in_error: 'Deactivated in error',
    guardian_request: 'Guardian request',
    did_not_join: 'Did not join',
    duplicate_placement: 'Duplicate placement',
};

function reasonsFor(action: string): string[] {
    return props.lifecycleReasons[action] ?? [];
}

const statusForm = useForm({ status: '', reason_code: '', mfa_code: '', confirm: false });
const statusOpen = ref(false);
const statusAction = computed(() =>
    props.student.status === 'active' ? 'student_deactivated' : 'student_reactivated',
);

function openStatusChange(): void {
    statusForm.reset();
    statusForm.clearErrors();
    statusForm.status = props.student.status === 'active' ? 'inactive' : 'active';
    statusOpen.value = true;
}

function submitStatusChange(): void {
    statusForm.post(`/app/students/${props.student.id}/status`, {
        preserveScroll: true,
        onError: () => statusForm.reset('mfa_code'),
        onSuccess: () => {
            statusOpen.value = false;
        },
    });
}

// --- Enrollment lifecycle (inline) -------------------------------------

type LifecycleAction = 'complete' | 'withdraw' | 'cancel';

const LIFECYCLE_REASON_ACTION: Record<string, string> = {
    withdraw: 'enrollment_withdrawn',
    cancel: 'enrollment_cancelled',
};

const activeLifecycleAction = ref<LifecycleAction | null>(null);
const lifecycleForm = useForm({ ends_on: '', reason_code: '', mfa_code: '', confirm: false });

const LIFECYCLE_LABELS: Record<LifecycleAction, string> = {
    complete: 'Complete enrollment',
    withdraw: 'Withdraw enrollment',
    cancel: 'Cancel enrollment',
};

function startLifecycleAction(action: LifecycleAction): void {
    lifecycleForm.reset();
    lifecycleForm.clearErrors();
    // ORG.8G: a placement completes on its academic year's last day.
    if (action === 'complete' && props.currentEnrollment) {
        lifecycleForm.ends_on = props.currentEnrollment.academicYear.endsOn;
    }
    activeLifecycleAction.value = action;
}

function submitLifecycleAction(enrollmentId: string): void {
    if (!activeLifecycleAction.value) return;
    lifecycleForm.post(`/app/enrollments/${enrollmentId}/${activeLifecycleAction.value}`, {
        onError: () => lifecycleForm.reset('mfa_code'),
        onSuccess: () => {
            activeLifecycleAction.value = null;
        },
    });
}

// --- V11.3 (ADR 0075 section 11): a placement left open in a CLOSED year ---
// The same Complete/Withdraw transitions and endpoints as the current
// placement (no new lifecycle semantics). Cancel is not offered: it treats a
// placement as one that should never have been operational, which a real
// past placement was not.

type HistoricalAction = 'complete' | 'withdraw';

const historical = ref<{ id: string; action: HistoricalAction } | null>(null);
const historicalForm = useForm({ ends_on: '', reason_code: '', mfa_code: '', confirm: false });
const historicalDone = ref<string | null>(null);

const HISTORICAL_HELP: Record<HistoricalAction, string> = {
    complete:
        'The placement ran to the end of its year (the usual case after moving to the next year).',
    withdraw: 'The Student left this placement before its year ended.',
};

function isOpenInClosedYear(e: Enrollment): boolean {
    return e.status === 'active' && e.academicYear.status === 'closed';
}

function startHistorical(e: Enrollment, action: HistoricalAction): void {
    historicalForm.reset();
    historicalForm.clearErrors();
    historicalForm.ends_on = action === 'complete' ? e.academicYear.endsOn : '';
    historicalDone.value = null;
    historical.value = { id: e.id, action };
}

function submitHistorical(e: Enrollment): void {
    if (!historical.value) return;
    const action = historical.value.action;
    historicalForm.post(`/app/enrollments/${e.id}/${action}`, {
        preserveScroll: true,
        onError: () => historicalForm.reset('mfa_code'),
        // Keep this local confirmation across the redirect back to this page
        // (it does not rely on flashed messages, X-01).
        preserveState: true,
        onSuccess: () => {
            historical.value = null;
            historicalDone.value = `${e.academicYear.name} placement ${action === 'complete' ? 'completed' : 'withdrawn'}.`;
        },
    });
}

// --- SIS 4.2: roll-number correction and cancel-before-start -------------
// A roll number is the one placement field that is not its identity; the
// service keeps it unique in the Section for the year. A placement that has
// not started can be cancelled before it starts (closed reason, confirmation,
// fresh code): it is kept in history as never effective.

const rollTarget = ref<string | null>(null);
const rollForm = useForm({ roll_number: '' });

function startRollCorrection(e: Enrollment): void {
    rollForm.clearErrors();
    rollForm.roll_number = e.rollNumber;
    rollTarget.value = e.id;
}

// SIS 10.2 (F-3): the current placement is also listed in the history; its
// roll-number editor lives in the Current placement block only, so a history
// row never opens a second copy of it (decided by placement id, not position).
function isCurrentPlacement(e: Enrollment): boolean {
    return props.currentEnrollment?.id === e.id;
}

function submitRollCorrection(e: Enrollment): void {
    rollForm.post(`/app/enrollments/${e.id}/roll-number`, {
        preserveScroll: true,
        onSuccess: () => (rollTarget.value = null),
    });
}

const futureCancelTarget = ref<string | null>(null);
const futureCancelForm = useForm({ reason_code: '', mfa_code: '', confirm: false });

function startFutureCancel(e: Enrollment): void {
    futureCancelForm.reset();
    futureCancelForm.clearErrors();
    futureCancelTarget.value = e.id;
}

function submitFutureCancel(e: Enrollment): void {
    futureCancelForm.post(`/app/enrollments/${e.id}/cancel-before-start`, {
        preserveScroll: true,
        onError: () => futureCancelForm.reset('mfa_code'),
        onSuccess: () => (futureCancelTarget.value = null),
    });
}

function enrollmentPlacement(e: Enrollment): string {
    return `${e.gradeLevel.name} · Section ${e.section.name} · ${e.campus.name} · ${e.academicYear.name}`;
}

function fullName(): string {
    return [props.student.firstName, props.student.middleName, props.student.lastName]
        .filter(Boolean)
        .join(' ');
}

function guardianName(g: Relationship['guardian']): string {
    return [g.firstName, g.middleName, g.lastName].filter(Boolean).join(' ');
}

// --- Edit relationship (inline) --------------------------------------

const editingRelationshipId = ref<string | null>(null);
const relationshipForm = useForm({
    relationship_type: '',
    is_legal_guardian: false,
    is_emergency_contact: false,
    is_authorized_pickup: false,
});

function startEditRelationship(r: Relationship): void {
    relationshipForm.clearErrors();
    relationshipForm.relationship_type = r.relationshipType;
    relationshipForm.is_legal_guardian = r.isLegalGuardian;
    relationshipForm.is_emergency_contact = r.isEmergencyContact;
    relationshipForm.is_authorized_pickup = r.isAuthorizedPickup;
    editingRelationshipId.value = r.id;
}

function submitRelationshipEdit(relationshipId: string): void {
    relationshipForm.put(`/app/relationships/${relationshipId}`, {
        onSuccess: () => {
            editingRelationshipId.value = null;
        },
    });
}

function makePrimary(relationshipId: string): void {
    router.post(`/app/relationships/${relationshipId}/primary`, {}, { preserveScroll: true });
}

// A refused unlink (relationship still referenced by retained authorization evidence) comes back as this error.
const page = usePage();
const relationshipError = computed(
    () => (page.props.errors as Record<string, string> | undefined)?.relationship ?? null,
);

async function unlink(r: Relationship): Promise<void> {
    if (
        !(await confirmAction({
            title: 'Remove this Guardian from the Student?',
            body:
                `Remove this Guardian from ${fullName()}?\n\n` +
                'This removes their relationship with this student. The Guardian record and links to other students will remain.',
            confirmLabel: 'Remove Guardian',
            tone: 'danger',
        }))
    )
        return;

    router.delete(`/app/relationships/${r.id}`, { preserveScroll: true });
}
</script>

<template>
    <main class="mx-auto max-w-3xl px-4 py-6 sm:p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/students">← Students</a>

        <div class="mt-2 flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">{{ fullName() }}</h1>
                <p class="mt-1 text-sm text-slate-500">{{ student.studentNumber }}</p>
                <p class="mt-1 text-sm">
                    <a class="underline" :href="`/app/students/${student.id}/history`">History</a>
                    <template v-if="canViewAttendance">
                        ·
                        <a class="underline" :href="`/app/attendance/students/${student.id}`"
                            >Attendance records</a
                        >
                    </template>
                </p>
            </div>
            <div v-if="canManageStudents" class="flex items-center gap-2">
                <a
                    :href="`/app/students/${student.id}/edit`"
                    class="rounded border border-slate-300 px-3 py-2 text-sm font-medium hover:bg-slate-50"
                >
                    Edit
                </a>
                <button
                    type="button"
                    class="rounded border border-slate-300 px-3 py-2 text-sm font-medium hover:bg-slate-50"
                    @click="openStatusChange"
                >
                    {{ student.status === 'active' ? 'Mark inactive…' : 'Mark active…' }}
                </button>
            </div>
        </div>

        <form
            v-if="statusOpen"
            class="mt-4 rounded border border-amber-300 bg-amber-50 p-4"
            @submit.prevent="submitStatusChange"
        >
            <p class="text-sm font-medium text-slate-800">
                {{
                    statusForm.status === 'inactive'
                        ? 'Mark this Student inactive'
                        : 'Mark this Student active'
                }}
            </p>
            <p class="mt-1 text-sm text-slate-600">
                This changes the Student's status for the whole School. Enrollments are not changed.
            </p>
            <div class="mt-2">
                <label class="block text-sm text-slate-600" for="status-change-reason"
                    >Reason</label
                >
                <select
                    id="status-change-reason"
                    v-model="statusForm.reason_code"
                    required
                    class="mt-1 rounded border border-slate-300 px-3 py-2 text-sm sm:w-72"
                >
                    <option value="" disabled>Choose a reason</option>
                    <option v-for="r in reasonsFor(statusAction)" :key="r" :value="r">
                        {{ REASON_LABELS[r] ?? r }}
                    </option>
                </select>
                <p class="mt-1 text-sm text-red-600">{{ statusForm.errors.reason_code }}</p>
            </div>
            <MfaCodeField
                id="status-change-mfa"
                v-model="statusForm.mfa_code"
                class="mt-2"
                :has-mfa-factor="hasMfaFactor"
                action="This change"
                :error="statusForm.errors.mfa_code"
            />
            <label class="mt-2 flex items-start gap-2 text-sm text-slate-700">
                <input v-model="statusForm.confirm" type="checkbox" class="mt-0.5" />
                <span>I confirm this change. It is recorded with my name and the reason.</span>
            </label>
            <p class="mt-1 text-sm text-red-600">{{ statusForm.errors.confirm }}</p>
            <p class="mt-1 text-sm text-red-600">{{ statusForm.errors.status }}</p>
            <div class="mt-3 flex items-center gap-3">
                <button
                    type="submit"
                    :disabled="statusForm.processing || !hasMfaFactor"
                    class="rounded bg-slate-900 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50"
                >
                    Confirm change
                </button>
                <button type="button" class="text-sm underline" @click="statusOpen = false">
                    Cancel
                </button>
            </div>
        </form>

        <!-- SIS.EXP-1: in-page navigation over the sections this page actually has. -->
        <nav aria-label="Profile sections" class="mt-6 border-y border-slate-200 py-2">
            <ul class="flex flex-wrap gap-x-4 gap-y-1 text-sm">
                <li><a class="underline" href="#identity">Identity</a></li>
                <li v-if="canViewEnrollments">
                    <a class="underline" href="#placement">Placement</a>
                </li>
                <li><a class="underline" href="#guardians">Guardians</a></li>
                <li v-if="sections.family"><a class="underline" href="#family">Family</a></li>
                <li v-if="hasGlance"><a class="underline" href="#at-a-glance">At a glance</a></li>
                <li><a class="underline" href="#account">Account</a></li>
            </ul>
        </nav>

        <!-- Identity -->
        <section id="identity" class="mt-8 scroll-mt-4">
            <h2 class="text-sm font-medium text-slate-500">Identity</h2>
            <dl
                class="mt-2 grid grid-cols-1 gap-x-8 gap-y-3 rounded border border-slate-200 p-4 text-sm sm:grid-cols-2"
            >
                <div>
                    <dt class="text-slate-500">Student number</dt>
                    <dd class="mt-0.5">{{ student.studentNumber }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Status</dt>
                    <dd class="mt-0.5"><StatusBadge :status="student.status" /></dd>
                </div>
                <div>
                    <dt class="text-slate-500">Name</dt>
                    <dd class="mt-0.5">{{ fullName() }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Date of birth</dt>
                    <dd class="mt-0.5">{{ formatCalendarDate(student.dateOfBirth) }}</dd>
                </div>
            </dl>
        </section>

        <!-- Academic placement (Phase 1B.6) -- absent entirely (not
             just hidden) unless the actor holds enrollments.view. -->
        <section v-if="canViewEnrollments" id="placement" class="mt-8 scroll-mt-4">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-medium text-slate-500">Current placement</h2>
                <a
                    v-if="canManageEnrollments && !currentEnrollment"
                    :href="`/app/students/${student.id}/enrollments/create`"
                    class="text-sm font-medium underline"
                >
                    Enroll student
                </a>
            </div>

            <EmptyState
                v-if="!currentEnrollment"
                class="mt-3"
                title="No current enrollment"
                description="This student has no active academic placement for the current Academic Year."
            />

            <div v-else class="mt-3 rounded border border-slate-200 p-4 text-sm">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="font-medium">{{ enrollmentPlacement(currentEnrollment) }}</p>
                        <p class="mt-1 text-slate-500">
                            Roll {{ currentEnrollment.rollNumber }} · Since
                            {{ formatCalendarDate(currentEnrollment.startsOn) }}
                        </p>
                        <div class="mt-2"><StatusBadge :status="currentEnrollment.status" /></div>
                    </div>

                    <div
                        v-if="canManageEnrollments"
                        class="flex flex-wrap items-center gap-3 text-sm"
                    >
                        <a
                            :href="`/app/enrollments/${currentEnrollment.id}/transfer`"
                            class="underline"
                        >
                            Transfer
                        </a>
                        <button
                            type="button"
                            class="underline"
                            @click="startRollCorrection(currentEnrollment)"
                        >
                            Correct roll number
                        </button>
                        <button
                            type="button"
                            class="underline"
                            @click="startLifecycleAction('complete')"
                        >
                            Complete
                        </button>
                        <button
                            type="button"
                            class="underline"
                            @click="startLifecycleAction('withdraw')"
                        >
                            Withdraw
                        </button>
                        <button
                            type="button"
                            class="text-red-600 underline"
                            @click="startLifecycleAction('cancel')"
                        >
                            Cancel
                        </button>
                    </div>
                </div>

                <form
                    v-if="rollTarget === currentEnrollment.id"
                    class="mt-4 flex flex-wrap items-end gap-3 border-t border-slate-100 pt-4"
                    @submit.prevent="submitRollCorrection(currentEnrollment)"
                >
                    <div>
                        <label class="block text-sm text-slate-600" for="roll-current"
                            >Roll number</label
                        >
                        <input
                            id="roll-current"
                            v-model="rollForm.roll_number"
                            type="text"
                            required
                            :aria-invalid="!!rollForm.errors.roll_number"
                            aria-describedby="roll-current-error"
                            class="mt-1 rounded border border-slate-300 px-3 py-2 sm:w-40"
                        />
                        <p id="roll-current-error" class="mt-1 text-sm text-red-600">
                            {{ rollForm.errors.roll_number }}
                        </p>
                    </div>
                    <button
                        type="submit"
                        :disabled="rollForm.processing"
                        class="rounded bg-slate-900 px-3 py-2 font-medium text-white disabled:opacity-50"
                    >
                        Save roll number
                    </button>
                    <button type="button" class="underline" @click="rollTarget = null">
                        Cancel
                    </button>
                </form>

                <!-- Inline lifecycle form: Complete/Withdraw/Cancel all
                     collect exactly one field (an end date) -- there is
                     no generic status editor (this checkpoint's brief,
                     section 40). Each button posts to its own explicit,
                     named backend action. -->
                <form
                    v-if="activeLifecycleAction"
                    class="mt-4 border-t border-slate-100 pt-4"
                    @submit.prevent="submitLifecycleAction(currentEnrollment.id)"
                >
                    <p class="text-sm font-medium text-slate-700">
                        {{ LIFECYCLE_LABELS[activeLifecycleAction] }}
                    </p>
                    <div class="mt-2">
                        <label
                            class="block text-sm text-slate-600"
                            :for="`ends-on-${activeLifecycleAction}`"
                        >
                            End date
                        </label>
                        <input
                            :id="`ends-on-${activeLifecycleAction}`"
                            v-model="lifecycleForm.ends_on"
                            type="date"
                            required
                            :aria-invalid="!!lifecycleForm.errors.ends_on"
                            aria-describedby="ends-on-error"
                            class="mt-1 rounded border border-slate-300 px-3 py-2 text-sm sm:w-56"
                        />
                        <p id="ends-on-error" class="mt-1 text-sm text-red-600">
                            {{ lifecycleForm.errors.ends_on }}
                        </p>
                    </div>
                    <template v-if="activeLifecycleAction !== 'complete'">
                        <div class="mt-2">
                            <label class="block text-sm text-slate-600" for="lifecycle-reason"
                                >Reason</label
                            >
                            <select
                                id="lifecycle-reason"
                                v-model="lifecycleForm.reason_code"
                                required
                                class="mt-1 rounded border border-slate-300 px-3 py-2 text-sm sm:w-72"
                            >
                                <option value="" disabled>Choose a reason</option>
                                <option
                                    v-for="r in reasonsFor(
                                        LIFECYCLE_REASON_ACTION[activeLifecycleAction],
                                    )"
                                    :key="r"
                                    :value="r"
                                >
                                    {{ REASON_LABELS[r] ?? r }}
                                </option>
                            </select>
                            <p class="mt-1 text-sm text-red-600">
                                {{ lifecycleForm.errors.reason_code }}
                            </p>
                        </div>
                        <MfaCodeField
                            id="lifecycle-mfa"
                            v-model="lifecycleForm.mfa_code"
                            class="mt-2"
                            :has-mfa-factor="hasMfaFactor"
                            action="This change"
                            :error="lifecycleForm.errors.mfa_code"
                        />
                        <label class="mt-2 flex items-start gap-2 text-sm text-slate-700">
                            <input v-model="lifecycleForm.confirm" type="checkbox" class="mt-0.5" />
                            <span
                                >I confirm this change. It is recorded with my name and the
                                reason.</span
                            >
                        </label>
                        <p class="mt-1 text-sm text-red-600">{{ lifecycleForm.errors.confirm }}</p>
                    </template>
                    <div class="mt-3 flex items-center gap-3">
                        <button
                            type="submit"
                            :disabled="lifecycleForm.processing"
                            class="rounded bg-slate-900 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50"
                        >
                            Confirm
                        </button>
                        <button
                            type="button"
                            class="text-sm underline"
                            @click="activeLifecycleAction = null"
                        >
                            Cancel
                        </button>
                    </div>
                </form>
            </div>

            <h2 id="enrollment-history" class="mt-6 text-sm font-medium text-slate-500">
                Enrollment history
            </h2>
            <p v-if="historicalDone" class="mt-2 text-sm text-emerald-700" role="status">
                {{ historicalDone }}
            </p>
            <p
                v-if="!enrollmentHistory || enrollmentHistory.length === 0"
                class="mt-3 text-sm text-slate-500"
            >
                No Enrollment history yet.
            </p>
            <ul v-else class="mt-3 space-y-2">
                <li
                    v-for="e in enrollmentHistory"
                    :key="e.id"
                    class="flex flex-wrap items-center justify-between gap-2 rounded border border-slate-200 p-3 text-sm"
                >
                    <div>
                        <p>{{ enrollmentPlacement(e) }}</p>
                        <p class="mt-0.5 text-slate-500">
                            Roll {{ e.rollNumber }} ·
                            <template v-if="e.cancelledBeforeStart"
                                >Cancelled before it started (was to start
                                {{ formatCalendarDate(e.startsOn) }})</template
                            >
                            <template v-else-if="e.startsInFuture"
                                >Starts {{ formatCalendarDate(e.startsOn) }}</template
                            >
                            <template v-else
                                >{{ formatCalendarDate(e.startsOn) }} –
                                {{ e.endsOn ? formatCalendarDate(e.endsOn) : 'present' }}</template
                            >
                        </p>
                        <p v-if="isOpenInClosedYear(e)" class="mt-1 text-amber-800">
                            Still open, but {{ e.academicYear.name }} has been closed.
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <template v-if="e.startsInFuture && canManageEnrollments">
                            <button type="button" class="underline" @click="startRollCorrection(e)">
                                Correct roll number
                            </button>
                            <button
                                type="button"
                                class="text-red-600 underline"
                                @click="startFutureCancel(e)"
                            >
                                Cancel before it starts
                            </button>
                        </template>
                        <template v-if="isOpenInClosedYear(e) && canManageEnrollments">
                            <button
                                type="button"
                                class="underline"
                                @click="startHistorical(e, 'complete')"
                            >
                                Complete
                            </button>
                            <button
                                type="button"
                                class="underline"
                                @click="startHistorical(e, 'withdraw')"
                            >
                                Withdraw
                            </button>
                        </template>
                        <StatusBadge :status="e.status" />
                    </div>
                    <form
                        v-if="rollTarget === e.id && !isCurrentPlacement(e)"
                        class="flex w-full flex-wrap items-end gap-3 border-t border-slate-100 pt-3"
                        @submit.prevent="submitRollCorrection(e)"
                    >
                        <div>
                            <label class="block text-slate-600" :for="`roll-${e.id}`"
                                >Roll number</label
                            >
                            <input
                                :id="`roll-${e.id}`"
                                v-model="rollForm.roll_number"
                                type="text"
                                required
                                :aria-invalid="!!rollForm.errors.roll_number"
                                :aria-describedby="`roll-error-${e.id}`"
                                class="mt-1 rounded border border-slate-300 px-3 py-2 sm:w-40"
                            />
                            <p :id="`roll-error-${e.id}`" class="mt-1 text-red-600">
                                {{ rollForm.errors.roll_number }}
                            </p>
                        </div>
                        <button
                            type="submit"
                            :disabled="rollForm.processing"
                            class="rounded bg-slate-900 px-3 py-1.5 font-medium text-white disabled:opacity-50"
                        >
                            Save roll number
                        </button>
                        <button type="button" class="underline" @click="rollTarget = null">
                            Cancel
                        </button>
                    </form>
                    <form
                        v-if="futureCancelTarget === e.id"
                        class="w-full border-t border-slate-100 pt-3"
                        @submit.prevent="submitFutureCancel(e)"
                    >
                        <p class="font-medium text-slate-700">
                            Cancel the {{ e.academicYear.name }} placement in
                            {{ e.gradeLevel.name }} · Section {{ e.section.name }} before it starts
                        </p>
                        <p class="mt-0.5 text-slate-500">
                            It starts on {{ formatCalendarDate(e.startsOn) }} and has not started
                            yet. It stays in the history as cancelled before it started and never
                            becomes the Student's placement.
                        </p>
                        <div class="mt-2">
                            <label
                                class="block text-sm text-slate-600"
                                :for="`future-reason-${e.id}`"
                                >Reason</label
                            >
                            <select
                                :id="`future-reason-${e.id}`"
                                v-model="futureCancelForm.reason_code"
                                required
                                :aria-invalid="!!futureCancelForm.errors.reason_code"
                                :aria-describedby="`future-reason-error-${e.id}`"
                                class="mt-1 rounded border border-slate-300 px-3 py-2 text-sm sm:w-72"
                            >
                                <option value="" disabled>Choose a reason</option>
                                <option
                                    v-for="r in reasonsFor('enrollment_cancelled')"
                                    :key="r"
                                    :value="r"
                                >
                                    {{ REASON_LABELS[r] ?? r }}
                                </option>
                            </select>
                            <p
                                :id="`future-reason-error-${e.id}`"
                                class="mt-1 text-sm text-red-600"
                            >
                                {{ futureCancelForm.errors.reason_code }}
                            </p>
                        </div>
                        <MfaCodeField
                            :id="`future-mfa-${e.id}`"
                            v-model="futureCancelForm.mfa_code"
                            class="mt-2"
                            :has-mfa-factor="hasMfaFactor"
                            action="This change"
                            :error="futureCancelForm.errors.mfa_code"
                        />
                        <label class="mt-2 flex items-start gap-2 text-sm text-slate-700">
                            <input
                                v-model="futureCancelForm.confirm"
                                type="checkbox"
                                class="mt-0.5"
                            />
                            <span
                                >I confirm this placement is cancelled before it starts. It is
                                recorded with my name and the reason.</span
                            >
                        </label>
                        <p class="mt-1 text-sm text-red-600">
                            {{ futureCancelForm.errors.confirm }}
                        </p>
                        <div class="mt-2 flex items-center gap-3">
                            <button
                                type="submit"
                                :disabled="futureCancelForm.processing"
                                class="rounded bg-red-700 px-3 py-1.5 font-medium text-white disabled:opacity-50"
                            >
                                Cancel placement
                            </button>
                            <button
                                type="button"
                                class="underline"
                                @click="futureCancelTarget = null"
                            >
                                Keep it
                            </button>
                        </div>
                    </form>
                    <form
                        v-if="historical && historical.id === e.id"
                        class="w-full border-t border-slate-100 pt-3"
                        @submit.prevent="submitHistorical(e)"
                    >
                        <p class="font-medium text-slate-700">
                            {{ historical.action === 'complete' ? 'Complete' : 'Withdraw' }} the
                            {{ e.academicYear.name }} placement in {{ e.gradeLevel.name }} · Section
                            {{ e.section.name }}
                        </p>
                        <p class="mt-0.5 text-slate-500">
                            {{ HISTORICAL_HELP[historical.action] }}
                        </p>
                        <div class="mt-2">
                            <label class="block text-slate-600" :for="`historical-ends-on-${e.id}`"
                                >End date</label
                            >
                            <input
                                :id="`historical-ends-on-${e.id}`"
                                v-model="historicalForm.ends_on"
                                type="date"
                                required
                                :min="e.startsOn"
                                :aria-invalid="!!historicalForm.errors.ends_on"
                                :aria-describedby="`historical-ends-on-error-${e.id}`"
                                class="mt-1 rounded border border-slate-300 px-3 py-2 sm:w-56"
                            />
                            <p :id="`historical-ends-on-error-${e.id}`" class="mt-1 text-red-600">
                                {{ historicalForm.errors.ends_on }}
                            </p>
                        </div>
                        <template v-if="historical.action === 'withdraw'">
                            <div class="mt-2">
                                <label class="block text-sm text-slate-600" for="historical-reason"
                                    >Reason</label
                                >
                                <select
                                    id="historical-reason"
                                    v-model="historicalForm.reason_code"
                                    required
                                    class="mt-1 rounded border border-slate-300 px-3 py-2 text-sm sm:w-72"
                                >
                                    <option value="" disabled>Choose a reason</option>
                                    <option
                                        v-for="r in reasonsFor('enrollment_withdrawn')"
                                        :key="r"
                                        :value="r"
                                    >
                                        {{ REASON_LABELS[r] ?? r }}
                                    </option>
                                </select>
                                <p class="mt-1 text-sm text-red-600">
                                    {{ historicalForm.errors.reason_code }}
                                </p>
                            </div>
                            <MfaCodeField
                                id="historical-mfa"
                                v-model="historicalForm.mfa_code"
                                class="mt-2"
                                :has-mfa-factor="hasMfaFactor"
                                action="This change"
                                :error="historicalForm.errors.mfa_code"
                            />
                            <label class="mt-2 flex items-start gap-2 text-sm text-slate-700">
                                <input
                                    v-model="historicalForm.confirm"
                                    type="checkbox"
                                    class="mt-0.5"
                                />
                                <span
                                    >I confirm this change. It is recorded with my name and the
                                    reason.</span
                                >
                            </label>
                            <p class="mt-1 text-sm text-red-600">
                                {{ historicalForm.errors.confirm }}
                            </p>
                        </template>
                        <div class="mt-2 flex items-center gap-3">
                            <button
                                type="submit"
                                :disabled="historicalForm.processing"
                                class="rounded bg-slate-900 px-3 py-1.5 font-medium text-white disabled:opacity-50"
                            >
                                Confirm
                            </button>
                            <button type="button" class="underline" @click="historical = null">
                                Cancel
                            </button>
                        </div>
                    </form>
                </li>
            </ul>
        </section>

        <!-- Guardians -->
        <section id="guardians" class="mt-8 scroll-mt-4">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-medium text-slate-500">Guardians</h2>
                <a
                    v-if="canLinkGuardians"
                    :href="`/app/students/${student.id}/guardians/add`"
                    class="text-sm font-medium underline"
                >
                    Add guardian
                </a>
            </div>

            <p v-if="relationshipError" class="mt-3 text-sm text-red-700" role="alert">
                {{ relationshipError }}
            </p>

            <p v-if="relationships.length === 0" class="mt-3 text-sm text-slate-500">
                No Guardians linked yet.
            </p>

            <ul v-else class="mt-3 space-y-3">
                <li
                    v-for="r in relationships"
                    :key="r.id"
                    class="rounded border border-slate-200 p-4"
                >
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <a
                                class="font-medium underline"
                                :href="`/app/guardians/${r.guardian.id}`"
                                >{{ guardianName(r.guardian) }}</a
                            >
                            <RelationshipFlags
                                :relationship-type="r.relationshipType"
                                :is-primary="r.isPrimary"
                                :is-legal-guardian="r.isLegalGuardian"
                                :is-emergency-contact="r.isEmergencyContact"
                                :is-authorized-pickup="r.isAuthorizedPickup"
                            />
                            <p v-if="r.contact" class="mt-1 text-sm text-slate-500">
                                {{ r.contact.value }}
                            </p>
                        </div>

                        <div
                            v-if="canLinkGuardians"
                            class="flex flex-wrap items-center gap-3 text-sm"
                        >
                            <button
                                v-if="!r.isPrimary"
                                type="button"
                                class="underline"
                                @click="makePrimary(r.id)"
                            >
                                Make primary
                            </button>
                            <button
                                type="button"
                                class="underline"
                                @click="startEditRelationship(r)"
                            >
                                Edit
                            </button>
                            <button type="button" class="text-red-600 underline" @click="unlink(r)">
                                Remove from student
                            </button>
                        </div>
                    </div>

                    <!-- Inline edit-relationship form -->
                    <form
                        v-if="editingRelationshipId === r.id"
                        class="mt-4 border-t border-slate-100 pt-4"
                        @submit.prevent="submitRelationshipEdit(r.id)"
                    >
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <label
                                    class="block text-sm text-slate-600"
                                    :for="`relationship-type-${r.id}`"
                                    >Relationship</label
                                >
                                <select
                                    :id="`relationship-type-${r.id}`"
                                    v-model="relationshipForm.relationship_type"
                                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                                >
                                    <option
                                        v-for="t in RELATIONSHIP_TYPES"
                                        :key="t.value"
                                        :value="t.value"
                                    >
                                        {{ t.label }}
                                    </option>
                                </select>
                            </div>
                        </div>
                        <fieldset class="mt-3 space-y-2 text-sm">
                            <legend class="sr-only">Authority</legend>
                            <label class="flex items-center gap-2">
                                <input
                                    v-model="relationshipForm.is_legal_guardian"
                                    type="checkbox"
                                />
                                Legal guardian
                            </label>
                            <label class="flex items-center gap-2">
                                <input
                                    v-model="relationshipForm.is_emergency_contact"
                                    type="checkbox"
                                />
                                Emergency contact
                            </label>
                            <label class="flex items-center gap-2">
                                <input
                                    v-model="relationshipForm.is_authorized_pickup"
                                    type="checkbox"
                                />
                                Authorized pickup
                            </label>
                        </fieldset>
                        <div class="mt-3 flex items-center gap-3">
                            <button
                                type="submit"
                                :disabled="relationshipForm.processing"
                                class="rounded bg-slate-900 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50"
                            >
                                Save
                            </button>
                            <button
                                type="button"
                                class="text-sm underline"
                                @click="editingRelationshipId = null"
                            >
                                Cancel
                            </button>
                        </div>
                    </form>
                </li>
            </ul>
        </section>

        <!-- SIS.EXP-1: family (siblings through a shared active Guardian) and the
             read-only summaries of the owning modules. -->
        <StudentFamily
            v-if="sections.family"
            :siblings="sections.family.siblings"
            :truncated="sections.family.truncated"
        />
        <StudentProfileSummaries :sections="sections" />

        <!-- Lycenza account link -->
        <section id="account" class="mt-8 scroll-mt-4">
            <h2 class="text-sm font-medium text-slate-500">Lycenza account</h2>
            <p class="mt-1 text-xs text-slate-400">
                Optional. Linking an existing Lycenza account lets this Student receive in-app
                communications when they sign in. It does not create a new account.
            </p>
            <p class="mt-1 text-xs text-slate-400">
                Inviting a brand-new account (as is available for Guardians) is not available for
                Students yet -- only linking an account that already exists is supported.
            </p>

            <div v-if="accountLink" class="mt-3 rounded border border-slate-200 p-4 text-sm">
                <p>
                    Linked to <span class="font-medium">{{ accountLink.memberName }}</span>
                    <span v-if="!accountLink.membershipActive" class="ml-2 text-amber-600"
                        >(membership inactive -- in-app currently unavailable)</span
                    >
                </p>
                <button
                    v-if="canManageStudents"
                    type="button"
                    class="mt-2 text-red-600 underline"
                    :disabled="unlinking"
                    @click="unlinkAccount"
                >
                    Unlink
                </button>
            </div>

            <div v-else-if="canManageStudents">
                <button
                    v-if="!showLinkPicker"
                    type="button"
                    class="mt-3 text-sm font-medium underline"
                    @click="showLinkPicker = true"
                >
                    Link account
                </button>
                <div v-else class="relative mt-3">
                    <SearchCombobox
                        id="student-link-search"
                        v-model="linkQuery"
                        aria-label="Search Lycenza accounts to link"
                        placeholder="Search Lycenza accounts by name…"
                        class="w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        :options="linkCandidates"
                        :option-key="(o) => o.schoolMembershipId"
                        @input="searchLinkCandidates"
                        @select="linkAccount"
                    >
                        <template #option="{ option: candidate }">
                            {{ candidate.name }}
                        </template>
                    </SearchCombobox>
                    <button
                        type="button"
                        class="mt-2 text-xs text-slate-400 underline"
                        @click="showLinkPicker = false"
                    >
                        Cancel
                    </button>
                </div>
            </div>
            <p v-else class="mt-3 text-sm text-slate-500">Not linked.</p>
        </section>

        <!-- Communication preferences (Phase 5D.2) -->
        <section class="mt-8">
            <h2 class="text-sm font-medium text-slate-500">Communication preferences</h2>
            <div class="mt-3 rounded border border-slate-200 p-4 text-sm text-slate-600">
                <p>
                    <span class="font-medium">In-app:</span> managed through this Student's linked
                    Lycenza account preference above, once linked -- no separate setting here.
                </p>
                <p class="mt-2">
                    <span class="font-medium">Email:</span> not available -- Students have no
                    canonical email endpoint in Lycenza today.
                </p>
            </div>
        </section>
        <ConfirmDialog
            ref="confirmDialog"
            :title="confirmation?.title ?? ''"
            :confirm-label="confirmation?.confirmLabel"
            :tone="confirmation?.tone ?? 'danger'"
            @confirm="onConfirm"
            @close="onClose"
        >
            <p class="whitespace-pre-line">{{ confirmation?.body }}</p>
        </ConfirmDialog>
    </main>
</template>
