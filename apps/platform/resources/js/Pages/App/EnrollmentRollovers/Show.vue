<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import StatusBadge from '../../../Components/StatusBadge.vue';
import MappingsPanel from './MappingsPanel.vue';
import ItemsPanel from './ItemsPanel.vue';
import type {
    RolloverDecision,
    RolloverExecutionStatus,
    RolloverPlanStatus,
    RolloverRollNumberStrategy,
    RolloverValidationResult,
} from '../../../rolloverTypes';

interface YearRef {
    id: string;
    name: string;
    code: string;
}

interface Ref {
    id: string;
    name: string;
}

interface Mapping {
    id: string;
    sourceGradeLevel: Ref;
    sourceSection: Ref | null;
    targetGradeLevel: Ref;
    targetSection: Ref | null;
    isRepeat: boolean;
}

interface ValidationSummary {
    total: number;
    ready: number;
    excluded: number;
    already_enrolled: number;
    review: number;
    blocked: number;
    unvalidated: number;
}

interface ExecutionSummary {
    total: number;
    succeeded: number;
    reconciled: number;
    skipped: number;
    failed: number;
    pending: number;
}

interface Plan {
    id: string;
    sourceAcademicYear: YearRef;
    targetAcademicYear: YearRef;
    status: RolloverPlanStatus;
    configurationVersion: number;
    validatedConfigurationVersion: number | null;
    createdAt: string;
    validatedAt: string | null;
    executionStartedAt: string | null;
    completedAt: string | null;
    isValidatedForCurrentConfiguration: boolean;
    mappings: Mapping[];
    executionSummary: ExecutionSummary;
    validationSummary: ValidationSummary;
}

interface Item {
    id: string;
    student: {
        id: string;
        studentNumber: string;
        firstName: string;
        middleName: string | null;
        lastName: string | null;
    };
    sourceEnrollment: { id: string; section: Ref | null };
    decision: RolloverDecision;
    targetSection: Ref | null;
    rollNumberStrategy: RolloverRollNumberStrategy | null;
    targetRollNumber: string | null;
    validationResult: RolloverValidationResult | null;
    validationReason: string | null;
    executionStatus: RolloverExecutionStatus | null;
    targetEnrollmentId: string | null;
    executedAt: string | null;
}

interface PageLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Props {
    plan: Plan;
    items: { data: Item[]; links: PageLink[]; total: number };
    itemFilters: { validation_result: string; execution_status: string; decision: string };
    canManage: boolean;
    gradeLevels: Ref[];
    sourceSections: Array<Ref & { label: string; gradeLevelId: string }>;
    targetSections: Array<Ref & { label: string; gradeLevelId: string }>;
}

const props = defineProps<Props>();

const configurable = computed(() => ['draft', 'validated'].includes(props.plan.status));
const canStart = computed(
    () => props.plan.status === 'validated' && props.plan.isValidatedForCurrentConfiguration,
);
const canResume = computed(() => props.plan.status === 'executing');
const isTerminal = computed(() =>
    ['completed', 'completed_with_errors', 'cancelled'].includes(props.plan.status),
);
const hasExecutionHistory = computed(
    () =>
        props.plan.executionSummary.succeeded +
            props.plan.executionSummary.reconciled +
            props.plan.executionSummary.failed >
        0,
);
const needsRevalidation = computed(
    () => props.plan.status === 'draft' && hasExecutionHistory.value,
);

// These three actions submit no form fields at all -- a plain
// router.post() with local reactive state (rather than useForm({}),
// whose typed error bag cannot carry an arbitrary non-field error key)
// is the correct shape here, not a workaround.
const validateProcessing = ref(false);
const validateError = ref<string | null>(null);
function runValidation(): void {
    validateProcessing.value = true;
    validateError.value = null;
    router.post(
        `/app/enrollment-rollovers/${props.plan.id}/validate`,
        {},
        {
            onError: (errors) => {
                validateError.value = Object.values(errors)[0] ?? null;
            },
            onFinish: () => {
                validateProcessing.value = false;
            },
        },
    );
}

const startProcessing = ref(false);
const startError = ref<string | null>(null);
function confirmStart(): void {
    const confirmed = window.confirm(
        `Start executing this rollover plan?\n\n` +
            `- Target-year Enrollments will be created for READY and ALREADY-ENROLLED Students.\n` +
            `- Current-year (source) Enrollments will NOT be completed or otherwise changed.\n` +
            `- This request processes up to the server's per-request limit; if more Students remain, ` +
            `the plan will stay "Executing" and you will need to click Resume to continue.`,
    );
    if (!confirmed) return;

    startProcessing.value = true;
    startError.value = null;
    router.post(
        `/app/enrollment-rollovers/${props.plan.id}/start`,
        {},
        {
            onError: (errors) => {
                startError.value = Object.values(errors)[0] ?? null;
            },
            onFinish: () => {
                startProcessing.value = false;
            },
        },
    );
}

const resumeProcessing = ref(false);
const resumeError = ref<string | null>(null);
function confirmResume(): void {
    const confirmed = window.confirm(
        'Process the next batch of pending Students for this plan?\n\n' +
            'Already-completed Students are not re-processed. This may still leave the plan ' +
            '"Executing" if more Students remain -- Resume again to continue.',
    );
    if (!confirmed) return;

    resumeProcessing.value = true;
    resumeError.value = null;
    router.post(
        `/app/enrollment-rollovers/${props.plan.id}/resume`,
        {},
        {
            onError: (errors) => {
                resumeError.value = Object.values(errors)[0] ?? null;
            },
            onFinish: () => {
                resumeProcessing.value = false;
            },
        },
    );
}

function reload(): void {
    router.reload();
}
</script>

<template>
    <main class="mx-auto max-w-5xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/enrollment-rollovers">← Enrollment Rollovers</a>

        <div class="mt-2 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">
                    {{ plan.sourceAcademicYear.name }} → {{ plan.targetAcademicYear.name }}
                </h1>
                <p class="mt-1 text-sm text-slate-500">
                    Configuration version {{ plan.configurationVersion }}
                    <span v-if="plan.validatedConfigurationVersion !== null">
                        · Last validated at version {{ plan.validatedConfigurationVersion }}</span
                    >
                </p>
            </div>
            <StatusBadge :status="plan.status" />
        </div>

        <p
            v-if="needsRevalidation"
            class="mt-4 rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800"
        >
            <strong>Revalidation required.</strong> Execution stopped because the plan's data
            changed since it was last validated. Target Enrollments already created for successful
            Students remain valid and were NOT undone. Resolve the underlying issue (see
            blocked/review Students below), then run validation again before starting the plan
            again.
        </p>

        <!-- Validation summary -->
        <section class="mt-8">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold text-slate-700">Validation</h2>
                <button
                    v-if="canManage && configurable"
                    type="button"
                    :disabled="validateProcessing"
                    class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                    @click="runValidation"
                >
                    {{ validateProcessing ? 'Validating…' : 'Run Validation' }}
                </button>
            </div>
            <p class="mt-1 text-sm text-slate-500">
                Checks the rollover plan against current data. This does not create or change any
                Student Enrollment.
            </p>
            <p v-if="validateError" class="mt-2 text-sm text-red-600">
                {{ validateError }}
            </p>

            <dl class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                <div class="rounded border border-slate-200 p-3">
                    <dt class="text-xs text-slate-500">Ready</dt>
                    <dd class="text-lg font-semibold text-emerald-700">
                        {{ plan.validationSummary.ready }}
                    </dd>
                </div>
                <div class="rounded border border-slate-200 p-3">
                    <dt class="text-xs text-slate-500">Already enrolled</dt>
                    <dd class="text-lg font-semibold text-sky-700">
                        {{ plan.validationSummary.already_enrolled }}
                    </dd>
                </div>
                <div class="rounded border border-slate-200 p-3">
                    <dt class="text-xs text-slate-500">Excluded</dt>
                    <dd class="text-lg font-semibold text-slate-600">
                        {{ plan.validationSummary.excluded }}
                    </dd>
                </div>
                <div class="rounded border border-slate-200 p-3">
                    <dt class="text-xs text-slate-500">Needs review</dt>
                    <dd class="text-lg font-semibold text-amber-700">
                        {{ plan.validationSummary.review }}
                    </dd>
                </div>
                <div class="rounded border border-slate-200 p-3">
                    <dt class="text-xs text-slate-500">Blocked</dt>
                    <dd class="text-lg font-semibold text-red-700">
                        {{ plan.validationSummary.blocked }}
                    </dd>
                </div>
                <div class="rounded border border-slate-200 p-3">
                    <dt class="text-xs text-slate-500">Total Students</dt>
                    <dd class="text-lg font-semibold">{{ plan.validationSummary.total }}</dd>
                </div>
            </dl>
        </section>

        <MappingsPanel
            :rollover-id="plan.id"
            :mappings="plan.mappings"
            :configurable="configurable"
            :can-manage="canManage"
            :grade-levels="gradeLevels"
            :source-sections="sourceSections"
            :target-sections="targetSections"
        />

        <ItemsPanel
            :rollover-id="plan.id"
            :items="items"
            :item-filters="itemFilters"
            :configurable="configurable"
            :can-manage="canManage"
            :target-sections="targetSections"
        />

        <!-- Execution -->
        <section class="mt-8 border-t border-slate-200 pt-6">
            <h2 class="text-sm font-semibold text-slate-700">Execution</h2>

            <template v-if="isTerminal">
                <p class="mt-1 text-sm text-slate-500">
                    This plan has finished. Configuration is read-only.
                </p>
                <dl class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
                    <div class="rounded border border-slate-200 p-3">
                        <dt class="text-xs text-slate-500">Succeeded</dt>
                        <dd class="text-lg font-semibold text-emerald-700">
                            {{ plan.executionSummary.succeeded }}
                        </dd>
                    </div>
                    <div class="rounded border border-slate-200 p-3">
                        <dt class="text-xs text-slate-500">Reconciled</dt>
                        <dd class="text-lg font-semibold text-sky-700">
                            {{ plan.executionSummary.reconciled }}
                        </dd>
                    </div>
                    <div class="rounded border border-slate-200 p-3">
                        <dt class="text-xs text-slate-500">Skipped</dt>
                        <dd class="text-lg font-semibold text-slate-600">
                            {{ plan.executionSummary.skipped }}
                        </dd>
                    </div>
                    <div class="rounded border border-slate-200 p-3">
                        <dt class="text-xs text-slate-500">Failed</dt>
                        <dd class="text-lg font-semibold text-red-700">
                            {{ plan.executionSummary.failed }}
                        </dd>
                    </div>
                    <div class="rounded border border-slate-200 p-3">
                        <dt class="text-xs text-slate-500">Total</dt>
                        <dd class="text-lg font-semibold">{{ plan.executionSummary.total }}</dd>
                    </div>
                </dl>
                <p class="mt-3 text-xs text-slate-500">
                    Started
                    {{
                        plan.executionStartedAt
                            ? new Date(plan.executionStartedAt).toLocaleString()
                            : '—'
                    }}
                    · Completed
                    {{ plan.completedAt ? new Date(plan.completedAt).toLocaleString() : '—' }}
                </p>
            </template>

            <template v-else-if="canResume">
                <p class="mt-1 text-sm text-slate-500">
                    This plan is still executing -- {{ plan.executionSummary.pending }} Student(s)
                    remain pending. No background process is running; click Resume to process the
                    next batch.
                </p>
                <dl class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
                    <div class="rounded border border-slate-200 p-3">
                        <dt class="text-xs text-slate-500">Succeeded</dt>
                        <dd class="text-lg font-semibold text-emerald-700">
                            {{ plan.executionSummary.succeeded }}
                        </dd>
                    </div>
                    <div class="rounded border border-slate-200 p-3">
                        <dt class="text-xs text-slate-500">Reconciled</dt>
                        <dd class="text-lg font-semibold text-sky-700">
                            {{ plan.executionSummary.reconciled }}
                        </dd>
                    </div>
                    <div class="rounded border border-slate-200 p-3">
                        <dt class="text-xs text-slate-500">Pending</dt>
                        <dd class="text-lg font-semibold text-amber-700">
                            {{ plan.executionSummary.pending }}
                        </dd>
                    </div>
                    <div class="rounded border border-slate-200 p-3">
                        <dt class="text-xs text-slate-500">Failed</dt>
                        <dd class="text-lg font-semibold text-red-700">
                            {{ plan.executionSummary.failed }}
                        </dd>
                    </div>
                    <div class="rounded border border-slate-200 p-3">
                        <dt class="text-xs text-slate-500">Total</dt>
                        <dd class="text-lg font-semibold">{{ plan.executionSummary.total }}</dd>
                    </div>
                </dl>
                <p v-if="resumeError" class="mt-2 text-sm text-red-600">
                    {{ resumeError }}
                </p>
                <button
                    v-if="canManage"
                    type="button"
                    :disabled="resumeProcessing"
                    class="mt-4 rounded bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700 disabled:opacity-50"
                    @click="confirmResume"
                >
                    {{ resumeProcessing ? 'Resuming…' : 'Resume Rollover' }}
                </button>
                <button
                    v-if="canManage"
                    type="button"
                    class="ml-3 mt-4 text-sm underline"
                    @click="reload"
                >
                    Refresh
                </button>
            </template>

            <template v-else>
                <p class="mt-1 text-sm text-slate-500">
                    Start becomes available once this plan is fully validated with no Students
                    needing review and none blocked.
                </p>
                <p v-if="startError" class="mt-2 text-sm text-red-600">
                    {{ startError }}
                </p>
                <button
                    v-if="canManage"
                    type="button"
                    :disabled="!canStart || startProcessing"
                    class="mt-4 rounded bg-red-700 px-4 py-2 text-sm font-medium text-white hover:bg-red-800 disabled:opacity-50"
                    @click="confirmStart"
                >
                    {{ startProcessing ? 'Starting…' : 'Start Rollover' }}
                </button>
            </template>
        </section>
    </main>
</template>
