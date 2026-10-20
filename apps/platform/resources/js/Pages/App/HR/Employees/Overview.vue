<script setup lang="ts">
// ORG.7E-1 (ADR 0076 §43): the Campus-safe operational overview. Only the
// in-scope placement and current assignments the server sent; every action
// is re-authorized server-side. Never personal, document, note or pay data.
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

interface Assignment {
    id: string;
    employmentRecordId: string;
    positionName: string | null;
    departmentName: string | null;
    campusId: string | null;
    campusName: string | null;
    isPrimary: boolean;
    startsOn: string;
    endsOn: string | null;
    managerName: string | null;
}

interface Option {
    id: string;
    name: string;
}

interface DepartmentOption extends Option {
    campusId: string | null;
}

interface ManagerCandidate {
    assignmentId: string;
    label: string;
}

interface Props {
    overview: {
        employee: {
            id: string;
            employeeNumber: string;
            displayName: string;
            isArchived: boolean;
        };
        placement: Assignment | null;
        // null: the viewer may not see assignments at all.
        currentAssignments: Assignment[] | null;
    };
    can: {
        edit: boolean;
        manageAssignments: boolean;
        addAssignment: boolean;
        viewProfile: boolean;
    };
    currentEmploymentId: string | null;
    positions: Option[];
    departments: DepartmentOption[];
    campuses: Option[];
}

const props = defineProps<Props>();

const base = computed(() => `/app/hr/employees/${props.overview.employee.id}`);

const pageErrors = computed(() => usePage().props.errors as Record<string, string | undefined>);
const generalError = computed(
    () => pageErrors.value.employee ?? pageErrors.value.assignment ?? pageErrors.value.ends_on,
);

function assignmentUrl(a: Assignment): string {
    return `${base.value}/employment-records/${a.employmentRecordId}/assignments/${a.id}`;
}

// --- Add an assignment ------------------------------------------------------
const adding = ref(false);
const assignmentForm = useForm({
    starts_on: '',
    position_id: '',
    campus_id: '',
    department_id: '',
});
const departmentChoices = computed(() =>
    props.departments.filter((d) => d.campusId === null || d.campusId === assignmentForm.campus_id),
);
function submitAssignment(): void {
    if (props.currentEmploymentId === null) {
        return;
    }
    assignmentForm.post(
        `${base.value}/employment-records/${props.currentEmploymentId}/assignments`,
        {
            preserveScroll: true,
            onSuccess: () => {
                adding.value = false;
                assignmentForm.reset();
            },
        },
    );
}

// --- End / primary ----------------------------------------------------------
const endingId = ref<string | null>(null);
const endForm = useForm({ ends_on: '' });
function submitEnd(a: Assignment): void {
    endForm.post(`${assignmentUrl(a)}/end`, {
        preserveScroll: true,
        onSuccess: () => (endingId.value = null),
    });
}
function makePrimary(a: Assignment): void {
    router.post(`${assignmentUrl(a)}/primary`, {}, { preserveScroll: true });
}

// --- Reporting manager (picked by name, re-validated by the server) ---------
const managingId = ref<string | null>(null);
const managerForm = useForm({ manager_assignment_id: '' });
const managerQuery = ref('');
const managerOptions = ref<ManagerCandidate[]>([]);
let managerDebounce: ReturnType<typeof setTimeout> | undefined;
function toggleManager(a: Assignment): void {
    managingId.value = managingId.value === a.id ? null : a.id;
    managerForm.reset();
    managerForm.clearErrors();
    managerQuery.value = '';
    managerOptions.value = [];
}
watch(managerQuery, (value) => {
    const a = props.overview.currentAssignments?.find((x) => x.id === managingId.value);
    clearTimeout(managerDebounce);
    if (!a || value.trim() === '') {
        managerOptions.value = [];
        return;
    }
    managerDebounce = setTimeout(async () => {
        const response = await fetch(
            `${assignmentUrl(a)}/manager-candidates?q=${encodeURIComponent(value)}`,
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
    managerForm.post(`${assignmentUrl(a)}/manager`, {
        preserveScroll: true,
        onSuccess: () => (managingId.value = null),
    });
}
function clearManager(a: Assignment): void {
    managerForm.manager_assignment_id = '';
    submitManager(a);
}

function placementLabel(a: Assignment): string {
    return [a.positionName, a.departmentName, a.campusName].filter((x) => x).join(' · ') || '—';
}
</script>

<template>
    <main class="mx-auto max-w-4xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/hr/employees">← Staff directory</a>

        <div class="mt-2 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">{{ overview.employee.displayName }}</h1>
                <p class="mt-1 text-sm text-slate-500">
                    {{ overview.employee.employeeNumber }}
                    <span v-if="overview.employee.isArchived"> · Archived</span>
                </p>
            </div>
            <div class="flex shrink-0 gap-3 text-sm">
                <a v-if="can.edit" class="underline" :href="`${base}/edit`">Edit details</a>
                <a v-if="can.viewProfile" class="underline" :href="base">Full profile</a>
            </div>
        </div>

        <p
            v-if="generalError"
            role="alert"
            class="mt-4 rounded border border-red-300 bg-red-50 p-3 text-sm text-red-800"
        >
            {{ generalError }}
        </p>

        <section class="mt-6">
            <h2 class="text-sm font-semibold text-slate-700">Current placement</h2>
            <p class="mt-1 text-sm text-slate-600">
                {{
                    overview.placement
                        ? placementLabel(overview.placement)
                        : 'No current placement you can see.'
                }}
            </p>
            <p v-if="overview.placement?.managerName" class="mt-1 text-sm text-slate-500">
                Reports to {{ overview.placement.managerName }}
            </p>
        </section>

        <section v-if="overview.currentAssignments !== null" class="mt-8">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold text-slate-700">Current assignments</h2>
                <button
                    v-if="can.addAssignment"
                    type="button"
                    class="text-sm underline"
                    @click="adding = !adding"
                >
                    {{ adding ? 'Cancel' : 'Add assignment' }}
                </button>
            </div>

            <form
                v-if="adding && can.addAssignment"
                class="mt-3 grid gap-3 rounded border border-slate-200 p-4 sm:grid-cols-2"
                @submit.prevent="submitAssignment"
            >
                <div>
                    <label class="block text-sm text-slate-600" for="assignment-campus"
                        >Campus</label
                    >
                    <select
                        id="assignment-campus"
                        v-model="assignmentForm.campus_id"
                        required
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                    >
                        <option value="" disabled>Choose a campus</option>
                        <option v-for="c in campuses" :key="c.id" :value="c.id">
                            {{ c.name }}
                        </option>
                    </select>
                    <p class="mt-1 text-sm text-red-600">{{ assignmentForm.errors.campus_id }}</p>
                </div>
                <div>
                    <label class="block text-sm text-slate-600" for="assignment-position"
                        >Position</label
                    >
                    <select
                        id="assignment-position"
                        v-model="assignmentForm.position_id"
                        required
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                    >
                        <option value="" disabled>Choose a position</option>
                        <option v-for="p in positions" :key="p.id" :value="p.id">
                            {{ p.name }}
                        </option>
                    </select>
                    <p class="mt-1 text-sm text-red-600">{{ assignmentForm.errors.position_id }}</p>
                </div>
                <div>
                    <label class="block text-sm text-slate-600" for="assignment-department"
                        >Department (optional)</label
                    >
                    <select
                        id="assignment-department"
                        v-model="assignmentForm.department_id"
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                    >
                        <option value="">None</option>
                        <option v-for="d in departmentChoices" :key="d.id" :value="d.id">
                            {{ d.name }}
                        </option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm text-slate-600" for="assignment-starts"
                        >Starts on</label
                    >
                    <input
                        id="assignment-starts"
                        v-model="assignmentForm.starts_on"
                        type="date"
                        required
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                    />
                    <p class="mt-1 text-sm text-red-600">{{ assignmentForm.errors.starts_on }}</p>
                </div>
                <div class="sm:col-span-2">
                    <button
                        type="submit"
                        :disabled="assignmentForm.processing"
                        class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                    >
                        Add assignment
                    </button>
                </div>
            </form>

            <p v-if="overview.currentAssignments.length === 0" class="mt-2 text-sm text-slate-500">
                No current assignments you can see.
            </p>
            <ul v-else class="mt-3 divide-y divide-slate-100 rounded border border-slate-200">
                <li v-for="a in overview.currentAssignments" :key="a.id" class="p-4 text-sm">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <p class="font-medium">
                                {{ placementLabel(a) }}
                                <span v-if="a.isPrimary" class="ml-1 text-xs text-slate-500"
                                    >Primary</span
                                >
                            </p>
                            <p class="mt-1 text-slate-500">
                                From {{ a.startsOn
                                }}<span v-if="a.endsOn"> until {{ a.endsOn }}</span>
                                <span v-if="a.managerName"> · Reports to {{ a.managerName }}</span>
                            </p>
                        </div>
                        <div v-if="can.manageAssignments" class="flex gap-3">
                            <button
                                v-if="!a.isPrimary"
                                type="button"
                                class="underline"
                                @click="makePrimary(a)"
                            >
                                Make primary
                            </button>
                            <button type="button" class="underline" @click="toggleManager(a)">
                                Manager
                            </button>
                            <button
                                type="button"
                                class="underline"
                                @click="endingId = endingId === a.id ? null : a.id"
                            >
                                End
                            </button>
                        </div>
                    </div>

                    <form
                        v-if="endingId === a.id"
                        class="mt-3 flex items-end gap-3"
                        @submit.prevent="submitEnd(a)"
                    >
                        <div>
                            <label class="block text-sm text-slate-600" :for="`end-${a.id}`"
                                >Last day</label
                            >
                            <input
                                :id="`end-${a.id}`"
                                v-model="endForm.ends_on"
                                type="date"
                                required
                                class="mt-1 rounded border border-slate-300 px-3 py-2 text-sm"
                            />
                        </div>
                        <button
                            type="submit"
                            :disabled="endForm.processing"
                            class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white disabled:opacity-50"
                        >
                            End assignment
                        </button>
                    </form>

                    <div v-if="managingId === a.id" class="mt-3">
                        <label class="block text-sm text-slate-600" :for="`manager-${a.id}`"
                            >Reports to</label
                        >
                        <input
                            :id="`manager-${a.id}`"
                            v-model="managerQuery"
                            type="text"
                            placeholder="Search by name…"
                            class="mt-1 w-64 rounded border border-slate-300 px-3 py-2 text-sm"
                        />
                        <ul
                            v-if="managerOptions.length > 0"
                            class="mt-1 w-96 rounded border border-slate-200 bg-white"
                        >
                            <li v-for="c in managerOptions" :key="c.assignmentId">
                                <button
                                    type="button"
                                    class="w-full px-3 py-2 text-left hover:bg-slate-50"
                                    @click="pickManager(c)"
                                >
                                    {{ c.label }}
                                </button>
                            </li>
                        </ul>
                        <p class="mt-1 text-sm text-red-600">
                            {{ managerForm.errors.manager_assignment_id }}
                        </p>
                        <div class="mt-2 flex gap-3">
                            <button
                                type="button"
                                :disabled="
                                    managerForm.processing ||
                                    managerForm.manager_assignment_id === ''
                                "
                                class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white disabled:opacity-50"
                                @click="submitManager(a)"
                            >
                                Save manager
                            </button>
                            <button
                                v-if="a.managerName"
                                type="button"
                                class="text-sm underline"
                                @click="clearManager(a)"
                            >
                                Remove manager
                            </button>
                        </div>
                    </div>
                </li>
            </ul>
        </section>
    </main>
</template>
