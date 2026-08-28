<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';
import EmptyState from '../../../Components/EmptyState.vue';
import Pagination from '../../../Components/Pagination.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';

interface Ref {
    id: string;
    name: string;
}

interface StudentRef {
    id: string;
    studentNumber: string;
    firstName: string;
    middleName: string | null;
    lastName: string | null;
}

interface RosterRow extends StudentRef {
    studentSubjectEnrollmentId: string | null;
}

interface HistoryRow {
    id: string;
    student: StudentRef;
    status: 'active' | 'withdrawn' | 'cancelled' | 'transferred';
    startsOn: string;
    endsOn: string | null;
}

interface PageLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Props {
    offering: {
        id: string;
        subject: (Ref & { code: string }) | null;
        academicYear: (Ref & { code: string }) | null;
        campus: Ref | null;
        gradeLevel: Ref | null;
        status: 'active' | 'inactive';
        isRequired: boolean;
        electiveGroup: Ref | null;
    };
    roster: RosterRow[];
    history: { data: HistoryRow[]; links: PageLink[]; total: number } | null;
    canManage: boolean;
    canViewStudentDetail: boolean;
}

const props = defineProps<Props>();

function studentName(s: StudentRef): string {
    return [s.firstName, s.middleName, s.lastName].filter(Boolean).join(' ');
}

function todayIso(): string {
    return new Date().toISOString().slice(0, 10);
}

// --- Enroll -----------------------------------------------------------

interface Candidate {
    student: StudentRef;
    studentEnrollmentId: string;
    section: Ref | null;
    hasCurrentGroupConflict: boolean;
}

const showEnrollPanel = ref(false);
const enrollQuery = ref('');
const candidates = ref<Candidate[]>([]);
const searching = ref(false);
let searchDebounce: ReturnType<typeof setTimeout> | undefined;

const enrollForm = useForm({ student_id: '', starts_on: todayIso() });

async function searchCandidates(): Promise<void> {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(async () => {
        searching.value = true;
        try {
            const response = await fetch(
                `/app/subject-offerings/${props.offering.id}/eligible-students?q=${encodeURIComponent(enrollQuery.value)}`,
                { headers: { Accept: 'application/json' } },
            );
            const body = await response.json();
            candidates.value = body.data as Candidate[];
        } finally {
            searching.value = false;
        }
    }, 250);
}

function openEnrollPanel(): void {
    showEnrollPanel.value = true;
    enrollForm.reset();
    enrollForm.starts_on = todayIso();
    void searchCandidates();
}

function submitEnroll(candidate: Candidate): void {
    enrollForm.student_id = candidate.student.id;
    enrollForm.post(`/app/subject-offerings/${props.offering.id}/enrollments`, {
        preserveScroll: true,
        onSuccess: () => {
            showEnrollPanel.value = false;
            candidates.value = [];
            enrollQuery.value = '';
        },
    });
}

// --- Withdraw / Cancel --------------------------------------------------

const withdrawForm = useForm({ ends_on: todayIso() });
const cancelForm = useForm({ ends_on: todayIso() });
const openLifecycleAction = ref<{ enrollmentId: string; type: 'withdraw' | 'cancel' } | null>(null);

function openLifecycle(enrollmentId: string, type: 'withdraw' | 'cancel'): void {
    openLifecycleAction.value = { enrollmentId, type };
    const form = type === 'withdraw' ? withdrawForm : cancelForm;
    form.reset();
    form.ends_on = todayIso();
}

function submitLifecycle(): void {
    if (!openLifecycleAction.value) return;
    const { enrollmentId, type } = openLifecycleAction.value;
    const label =
        type === 'withdraw'
            ? 'withdraw this participation (the Student actually left it)'
            : 'cancel this enrollment (an administrative correction, kept for history)';
    const confirmed = window.confirm(`Are you sure you want to ${label}?`);
    if (!confirmed) return;

    const form = type === 'withdraw' ? withdrawForm : cancelForm;
    form.post(`/app/subject-enrollments/${enrollmentId}/${type}`, {
        preserveScroll: true,
        onSuccess: () => (openLifecycleAction.value = null),
    });
}

// --- Transfer -----------------------------------------------------------

interface TransferTarget {
    id: string;
    subject: (Ref & { code: string }) | null;
    campus: Ref | null;
    gradeLevel: Ref | null;
    status: 'active' | 'inactive';
    electiveGroup: Ref | null;
}

const transferState = reactive<{
    enrollmentId: string | null;
    targets: TransferTarget[];
    loading: boolean;
}>({
    enrollmentId: null,
    targets: [],
    loading: false,
});
const transferForm = useForm({ target_subject_offering_id: '', effective_date: todayIso() });

async function openTransfer(enrollmentId: string): Promise<void> {
    transferState.enrollmentId = enrollmentId;
    transferState.loading = true;
    transferForm.reset();
    transferForm.effective_date = todayIso();
    try {
        const response = await fetch(`/app/subject-enrollments/${enrollmentId}/transfer-targets`, {
            headers: { Accept: 'application/json' },
        });
        const body = await response.json();
        transferState.targets = body.data as TransferTarget[];
    } finally {
        transferState.loading = false;
    }
}

function submitTransfer(): void {
    if (!transferState.enrollmentId) return;
    transferForm.post(`/app/subject-enrollments/${transferState.enrollmentId}/transfer`, {
        preserveScroll: true,
        onSuccess: () => {
            transferState.enrollmentId = null;
            transferState.targets = [];
        },
    });
}

function closeAllPanels(): void {
    showEnrollPanel.value = false;
    openLifecycleAction.value = null;
    transferState.enrollmentId = null;
}
</script>

<template>
    <main class="mx-auto max-w-4xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/subject-offerings">← Subject Offerings</a>

        <div class="mt-2 flex items-start justify-between">
            <div>
                <h1 class="text-xl font-semibold">
                    {{ offering.subject?.name ?? 'Unknown Subject' }}
                </h1>
                <p class="mt-1 text-sm text-slate-500">
                    {{ offering.academicYear?.name }} · {{ offering.campus?.name }} ·
                    {{ offering.gradeLevel?.name }}
                </p>
            </div>
            <div class="flex items-center gap-2">
                <StatusBadge :status="offering.status" />
            </div>
        </div>

        <dl class="mt-4 grid grid-cols-2 gap-4 text-sm sm:grid-cols-3">
            <div>
                <dt class="text-slate-500">Type</dt>
                <dd class="font-medium">{{ offering.isRequired ? 'Required' : 'Elective' }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Elective Group</dt>
                <dd class="font-medium">{{ offering.electiveGroup?.name ?? 'Ungrouped' }}</dd>
            </div>
        </dl>

        <!-- Required Offering: read-only roster, no management surface at all. -->
        <section v-if="offering.isRequired" class="mt-8">
            <h2 class="text-sm font-medium text-slate-500">Current roster (read-only)</h2>
            <p class="mt-1 text-sm text-slate-500">
                Membership is derived automatically from Student placement (enrollment, Grade,
                Campus). To change who is here, update the Student's enrollment instead.
            </p>

            <EmptyState
                v-if="roster.length === 0"
                class="mt-4"
                title="No Students currently placed"
                description="No active Student enrollment currently matches this Offering's Year, Grade, and Campus."
            />
            <ul v-else class="mt-4 divide-y divide-slate-100 text-sm">
                <li v-for="s in roster" :key="s.id" class="flex items-center justify-between py-2">
                    <span>
                        <a
                            v-if="canViewStudentDetail"
                            class="underline"
                            :href="`/app/students/${s.id}`"
                            >{{ studentName(s) }}</a
                        >
                        <span v-else>{{ studentName(s) }}</span>
                        <span class="ml-2 text-slate-500">{{ s.studentNumber }}</span>
                    </span>
                </li>
            </ul>
        </section>

        <!-- Elective Offering: roster + management actions. -->
        <template v-else>
            <section class="mt-8">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-medium text-slate-500">Current roster</h2>
                    <button
                        v-if="canManage && offering.status === 'active'"
                        type="button"
                        class="rounded bg-slate-900 px-3 py-1.5 text-sm font-medium text-white"
                        @click="openEnrollPanel"
                    >
                        Enroll Student
                    </button>
                </div>

                <EmptyState
                    v-if="roster.length === 0"
                    class="mt-4"
                    title="No Students currently enrolled"
                    :description="
                        offering.status === 'active'
                            ? 'Enroll a compatible Student to get started.'
                            : 'This Offering is not currently active -- new enrollment is unavailable.'
                    "
                />
                <ul v-else class="mt-4 divide-y divide-slate-100 text-sm">
                    <li v-for="s in roster" :key="s.id" class="py-3">
                        <div class="flex items-center justify-between">
                            <span>
                                <a
                                    v-if="canViewStudentDetail"
                                    class="underline"
                                    :href="`/app/students/${s.id}`"
                                    >{{ studentName(s) }}</a
                                >
                                <span v-else>{{ studentName(s) }}</span>
                                <span class="ml-2 text-slate-500">{{ s.studentNumber }}</span>
                            </span>
                            <div
                                v-if="canManage && s.studentSubjectEnrollmentId"
                                class="flex gap-3 text-sm"
                            >
                                <button
                                    type="button"
                                    class="underline"
                                    @click="openLifecycle(s.studentSubjectEnrollmentId, 'withdraw')"
                                >
                                    Withdraw
                                </button>
                                <button
                                    type="button"
                                    class="underline"
                                    @click="openLifecycle(s.studentSubjectEnrollmentId, 'cancel')"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="button"
                                    class="underline"
                                    @click="openTransfer(s.studentSubjectEnrollmentId)"
                                >
                                    Transfer
                                </button>
                            </div>
                        </div>

                        <!-- Withdraw / Cancel inline confirmation -->
                        <form
                            v-if="
                                openLifecycleAction?.enrollmentId === s.studentSubjectEnrollmentId
                            "
                            class="mt-2 flex items-end gap-3 rounded border border-slate-200 p-3"
                            @submit.prevent="submitLifecycle"
                        >
                            <div>
                                <label
                                    class="block text-xs text-slate-600"
                                    :for="`ends-on-${s.studentSubjectEnrollmentId}`"
                                    >{{
                                        openLifecycleAction.type === 'withdraw'
                                            ? 'Withdrawal'
                                            : 'Cancellation'
                                    }}
                                    effective date</label
                                >
                                <input
                                    :id="`ends-on-${s.studentSubjectEnrollmentId}`"
                                    v-model="
                                        (openLifecycleAction.type === 'withdraw'
                                            ? withdrawForm
                                            : cancelForm
                                        ).ends_on
                                    "
                                    type="date"
                                    class="mt-1 rounded border border-slate-300 px-2 py-1 text-sm"
                                />
                            </div>
                            <p class="text-xs text-slate-500">
                                {{
                                    openLifecycleAction.type === 'withdraw'
                                        ? 'The Student was actively taking this elective and has now left it.'
                                        : 'Use this to correct an enrollment made in error -- the record is kept for history, never deleted.'
                                }}
                            </p>
                            <button
                                type="submit"
                                class="rounded bg-slate-900 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50"
                                :disabled="
                                    (openLifecycleAction.type === 'withdraw'
                                        ? withdrawForm
                                        : cancelForm
                                    ).processing
                                "
                            >
                                Confirm
                            </button>
                            <button type="button" class="text-sm underline" @click="closeAllPanels">
                                Cancel
                            </button>
                            <p
                                v-if="
                                    (openLifecycleAction.type === 'withdraw'
                                        ? withdrawForm
                                        : cancelForm
                                    ).errors.ends_on
                                "
                                class="text-sm text-red-600"
                            >
                                {{
                                    (openLifecycleAction.type === 'withdraw'
                                        ? withdrawForm
                                        : cancelForm
                                    ).errors.ends_on
                                }}
                            </p>
                        </form>

                        <!-- Transfer panel -->
                        <div
                            v-if="transferState.enrollmentId === s.studentSubjectEnrollmentId"
                            class="mt-2 rounded border border-slate-200 p-3"
                        >
                            <p v-if="transferState.loading" class="text-sm text-slate-500">
                                Loading compatible Offerings…
                            </p>
                            <p
                                v-else-if="transferState.targets.length === 0"
                                class="text-sm text-slate-500"
                            >
                                No compatible target Offerings found for this Student's current
                                placement.
                            </p>
                            <form v-else class="space-y-3" @submit.prevent="submitTransfer">
                                <div>
                                    <label
                                        class="block text-xs text-slate-600"
                                        :for="`target-${s.studentSubjectEnrollmentId}`"
                                        >Transfer to</label
                                    >
                                    <select
                                        :id="`target-${s.studentSubjectEnrollmentId}`"
                                        v-model="transferForm.target_subject_offering_id"
                                        class="mt-1 w-full rounded border border-slate-300 px-2 py-1 text-sm"
                                        required
                                    >
                                        <option value="" disabled>Select a target Offering</option>
                                        <option
                                            v-for="t in transferState.targets"
                                            :key="t.id"
                                            :value="t.id"
                                        >
                                            {{ t.subject?.name }} · {{ t.gradeLevel?.name }} ·
                                            {{ t.campus?.name }}
                                            {{
                                                t.electiveGroup
                                                    ? `· Group: ${t.electiveGroup.name}`
                                                    : '· Ungrouped'
                                            }}
                                            {{ t.status !== 'active' ? '(inactive)' : '' }}
                                        </option>
                                    </select>
                                </div>
                                <div>
                                    <label
                                        class="block text-xs text-slate-600"
                                        :for="`effective-${s.studentSubjectEnrollmentId}`"
                                        >Effective date</label
                                    >
                                    <input
                                        :id="`effective-${s.studentSubjectEnrollmentId}`"
                                        v-model="transferForm.effective_date"
                                        type="date"
                                        class="mt-1 rounded border border-slate-300 px-2 py-1 text-sm"
                                    />
                                </div>
                                <div class="flex items-center gap-3">
                                    <button
                                        type="submit"
                                        class="rounded bg-slate-900 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50"
                                        :disabled="transferForm.processing"
                                    >
                                        Transfer
                                    </button>
                                    <button
                                        type="button"
                                        class="text-sm underline"
                                        @click="closeAllPanels"
                                    >
                                        Cancel
                                    </button>
                                </div>
                                <p
                                    v-if="transferForm.errors.target_subject_offering_id"
                                    class="text-sm text-red-600"
                                >
                                    {{ transferForm.errors.target_subject_offering_id }}
                                </p>
                                <p
                                    v-if="transferForm.errors.effective_date"
                                    class="text-sm text-red-600"
                                >
                                    {{ transferForm.errors.effective_date }}
                                </p>
                            </form>
                        </div>
                    </li>
                </ul>

                <!-- Enroll panel -->
                <div v-if="showEnrollPanel" class="mt-4 rounded border border-slate-200 p-4">
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-medium">Enroll a Student</h3>
                        <button type="button" class="text-sm underline" @click="closeAllPanels">
                            Close
                        </button>
                    </div>
                    <label class="mt-3 block text-xs text-slate-600" for="enroll-search"
                        >Search by name or Student number</label
                    >
                    <input
                        id="enroll-search"
                        v-model="enrollQuery"
                        type="text"
                        class="mt-1 w-full rounded border border-slate-300 px-2 py-1 text-sm"
                        @input="searchCandidates"
                    />
                    <p v-if="searching" class="mt-2 text-sm text-slate-500">Searching…</p>
                    <EmptyState
                        v-else-if="candidates.length === 0"
                        class="mt-3"
                        title="No compatible Students found"
                        description="Only Students with a current active enrollment matching this Offering's Year, Grade, and Campus appear here."
                    />
                    <ul
                        v-else
                        class="mt-3 max-h-72 divide-y divide-slate-100 overflow-y-auto text-sm"
                    >
                        <li
                            v-for="c in candidates"
                            :key="c.studentEnrollmentId"
                            class="flex items-center justify-between py-2"
                        >
                            <span>
                                {{ studentName(c.student) }}
                                <span class="text-slate-500">{{ c.student.studentNumber }}</span>
                                <span v-if="c.section" class="ml-2 text-slate-500"
                                    >Section {{ c.section.name }}</span
                                >
                                <span v-if="c.hasCurrentGroupConflict" class="ml-2 text-amber-700"
                                    >(already has an active choice in this elective group)</span
                                >
                            </span>
                            <button
                                type="button"
                                class="text-sm underline"
                                @click="submitEnroll(c)"
                            >
                                Enroll
                            </button>
                        </li>
                    </ul>
                    <div class="mt-3">
                        <label class="block text-xs text-slate-600" for="enroll-starts-on"
                            >Starts on</label
                        >
                        <input
                            id="enroll-starts-on"
                            v-model="enrollForm.starts_on"
                            type="date"
                            class="mt-1 rounded border border-slate-300 px-2 py-1 text-sm"
                        />
                    </div>
                    <p v-if="enrollForm.errors.student_id" class="mt-2 text-sm text-red-600">
                        {{ enrollForm.errors.student_id }}
                    </p>
                </div>
            </section>

            <section class="mt-8">
                <h2 class="text-sm font-medium text-slate-500">Participation history</h2>
                <p class="mt-1 text-sm text-slate-500">
                    Every past and present participation, including withdrawn, cancelled, and
                    transferred rows -- kept for history, never deleted.
                </p>

                <EmptyState
                    v-if="!history || history.data.length === 0"
                    class="mt-4"
                    title="No participation history yet"
                    description="Once a Student is enrolled, withdrawn, cancelled, or transferred, it will appear here."
                />
                <template v-else>
                    <ul class="mt-4 divide-y divide-slate-100 text-sm">
                        <li
                            v-for="h in history.data"
                            :key="h.id"
                            class="flex items-center justify-between py-2"
                        >
                            <span>
                                {{ studentName(h.student) }}
                                <span class="ml-2 text-slate-500"
                                    >{{ h.startsOn
                                    }}<span v-if="h.endsOn"> → {{ h.endsOn }}</span></span
                                >
                            </span>
                            <StatusBadge :status="h.status" />
                        </li>
                    </ul>
                    <Pagination :links="history.links" />
                </template>
            </section>
        </template>
    </main>
</template>
