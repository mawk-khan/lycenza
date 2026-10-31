<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Pagination from '../../../Components/Pagination.vue';
import { postJson } from '../../../csrf';
import StatusBadge from '../../../Components/StatusBadge.vue';
import { rolloverReasonLabel } from '../../../rolloverReasons';
import {
    ROLLOVER_EXECUTION_STATUSES,
    ROLLOVER_ITEM_DECISIONS,
    ROLLOVER_ROLL_NUMBER_STRATEGIES,
    ROLLOVER_VALIDATION_RESULTS,
} from '../../../rolloverStatuses';
import type {
    RolloverDecision,
    RolloverExecutionStatus,
    RolloverRollNumberStrategy,
    RolloverValidationResult,
} from '../../../rolloverTypes';

interface Ref {
    id: string;
    name: string;
}

// UAT-C2: Sections carry their Grade so 6 A, 7 A and 8 A are told apart.
interface SectionRef extends Ref {
    gradeLevelName?: string | null;
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
    sourceEnrollment: { id: string; section: SectionRef | null; rollNumber?: string | null };
    decision: RolloverDecision;
    targetSection: SectionRef | null;
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

interface SectionOption {
    // SIS 11.3 (OD-SIS-6): advisory occupancy, e.g. "28 / 30 · 2 seats available" (information only).
    occupancy?: string | null;
    id: string;
    label: string;
}

type BulkOutcome =
    | 'ready'
    | 'applied'
    | 'already_decided'
    | 'not_in_plan'
    | 'already_executed'
    | 'no_mapping'
    | 'invalid_mapping'
    | 'missing_target_section'
    | 'roll_number_conflict'
    | 'target_placement_conflict'
    | 'source_needs_review';

interface BulkRow {
    itemId: string;
    outcome: BulkOutcome;
    target: string | null;
    student?: string | null;
}

interface BulkResult {
    decision: 'promote' | 'exclude';
    applied: number;
    total: number;
    unresolved: BulkRow[];
}

interface Props {
    rolloverId: string;
    items: { data: Item[]; links: PageLink[]; total: number; current_page?: number };
    itemFilters: { validation_result: string; execution_status: string; decision: string };
    configurable: boolean;
    canManage: boolean;
    targetSections: SectionOption[];
    configurationVersion: number;
    perPage: number;
    bulkMax: number;
    bulkResult: BulkResult | null;
}

const props = defineProps<Props>();

const validationResult = ref(props.itemFilters.validation_result);
const executionStatus = ref(props.itemFilters.execution_status);
const decisionFilter = ref(props.itemFilters.decision);
const perPage = ref(String(props.perPage));

function applyFilters(): void {
    router.get(
        `/app/enrollment-rollovers/${props.rolloverId}`,
        {
            validation_result: validationResult.value || undefined,
            execution_status: executionStatus.value || undefined,
            decision: decisionFilter.value || undefined,
            per_page: perPage.value === '20' ? undefined : perPage.value,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

// UAT-C2: every save returns to this exact view (page, page size, filters).
function context(): Record<string, string | number> {
    const c: Record<string, string | number> = { page: props.items.current_page ?? 1 };
    if (props.perPage !== 20) c.per_page = props.perPage;
    if (props.itemFilters.validation_result)
        c.validation_result = props.itemFilters.validation_result;
    if (props.itemFilters.execution_status) c.execution_status = props.itemFilters.execution_status;
    if (props.itemFilters.decision) c.decision = props.itemFilters.decision;
    return c;
}

function sectionLabel(section: SectionRef | null): string {
    if (!section) return '—';
    return section.gradeLevelName ? `${section.gradeLevelName} · ${section.name}` : section.name;
}

// --- UAT-C2 bulk Promote / Exclude ---------------------------------------
// Selection is this page's rows only, never unseen pages. Preview first
// (writes nothing), then save; the server re-checks everything and refuses
// the whole request if the plan changed since this page loaded.
const OUTCOME_LABELS: Record<BulkOutcome, string> = {
    ready: 'Ready',
    applied: 'Saved',
    already_decided: 'Already has this decision -- unchanged',
    not_in_plan: 'Not a Student of this plan -- unchanged',
    already_executed: 'Already rolled over -- unchanged',
    no_mapping: 'No mapping for this Grade -- set it individually (or Exclude)',
    invalid_mapping: 'The mapping does not fit this decision -- check the mapping',
    missing_target_section: 'No target Section -- choose one for this Student',
    roll_number_conflict:
        'Roll Number missing or already taken in the target Section -- set it for this Student',
    target_placement_conflict: 'Already placed differently in the target year -- review',
    source_needs_review: 'Source placement needs review -- decide individually',
};

const selected = ref<string[]>([]);
const selectable = computed(() => props.items.data.filter((i) => !isExecuted(i)).map((i) => i.id));
const allSelected = computed(
    () =>
        selectable.value.length > 0 && selectable.value.every((id) => selected.value.includes(id)),
);
function toggleAll(): void {
    selected.value = allSelected.value ? [] : [...selectable.value];
}

const preview = ref<{ decision: 'promote' | 'exclude'; rows: BulkRow[] } | null>(null);
const previewError = ref<string | null>(null);
const previewing = ref(false);
const bulkForm = useForm<{
    decision: string;
    item_ids: string[];
    configuration_version: number;
    context: Record<string, string | number>;
}>({ decision: '', item_ids: [], configuration_version: 0, context: {} });

async function startBulk(decision: 'promote' | 'exclude'): Promise<void> {
    previewError.value = null;
    previewing.value = true;
    try {
        const response = await postJson<{ rows: BulkRow[] }>(
            `/app/enrollment-rollovers/${props.rolloverId}/bulk-decisions/preview`,
            { decision, item_ids: selected.value },
        );
        preview.value = { decision, rows: response.rows };
    } catch (e) {
        previewError.value = e instanceof Error ? e.message : 'The preview could not be loaded.';
    } finally {
        previewing.value = false;
    }
}

const readyCount = computed(
    () => preview.value?.rows.filter((r) => r.outcome === 'ready').length ?? 0,
);
const outcomeCounts = computed(() => {
    const counts: Partial<Record<BulkOutcome, number>> = {};
    for (const r of preview.value?.rows ?? []) counts[r.outcome] = (counts[r.outcome] ?? 0) + 1;
    return counts;
});
// "Grade 6 · A → Grade 7 · Section A" with how many ready Students move that way.
const moves = computed(() => {
    const byMove = new Map<string, number>();
    if (preview.value?.decision !== 'promote') return [];
    for (const r of preview.value?.rows ?? []) {
        if (r.outcome !== 'ready') continue;
        const item = props.items.data.find((i) => i.id === r.itemId);
        const key = `${sectionLabel(item?.sourceEnrollment.section ?? null)} → ${r.target ?? '—'}`;
        byMove.set(key, (byMove.get(key) ?? 0) + 1);
    }
    return [...byMove.entries()];
});
function rowName(itemId: string): string {
    const item = props.items.data.find((i) => i.id === itemId);
    return item ? `${studentName(item.student)} (${item.student.studentNumber})` : itemId;
}

function confirmBulk(): void {
    if (!preview.value) return;
    bulkForm.decision = preview.value.decision;
    bulkForm.item_ids = [...selected.value];
    bulkForm.configuration_version = props.configurationVersion;
    bulkForm.context = context();
    bulkForm.post(`/app/enrollment-rollovers/${props.rolloverId}/bulk-decisions`, {
        preserveScroll: true,
        onSuccess: () => {
            preview.value = null;
            selected.value = [];
        },
    });
}
function cancelBulk(): void {
    preview.value = null;
    bulkForm.clearErrors();
}
const bulkError = computed(() => (bulkForm.errors as Record<string, string | undefined>).bulk);

function studentName(student: Item['student']): string {
    return [student.firstName, student.middleName, student.lastName].filter(Boolean).join(' ');
}

const editingItemId = ref<string | null>(null);

const editForm = useForm({
    decision: '',
    target_section_id: '',
    roll_number_strategy: '',
    target_roll_number: '',
});

function startEdit(item: Item): void {
    editingItemId.value = item.id;
    editForm.decision = item.decision;
    editForm.target_section_id = item.targetSection?.id ?? '';
    editForm.roll_number_strategy = item.rollNumberStrategy ?? '';
    editForm.target_roll_number = item.targetRollNumber ?? '';
}

function cancelEdit(): void {
    editingItemId.value = null;
    editForm.clearErrors();
}

function submitEdit(itemId: string): void {
    editForm
        .transform((data) => ({
            ...data,
            target_section_id: data.target_section_id || null,
            roll_number_strategy: data.roll_number_strategy || null,
            context: context(),
        }))
        .patch(`/app/enrollment-rollovers/${props.rolloverId}/items/${itemId}`, {
            preserveScroll: true,
            onSuccess: () => {
                editingItemId.value = null;
            },
        });
}

// An executed Item's real target Enrollment is never editable through
// this panel -- server remains authoritative regardless (this
// checkpoint's brief, section 35).
function isExecuted(item: Item): boolean {
    return item.executionStatus === 'succeeded' || item.executionStatus === 'reconciled';
}
</script>

<template>
    <section class="mt-8">
        <h2 class="text-sm font-semibold text-slate-700">Student Items</h2>
        <p class="mt-1 text-sm text-slate-500">
            One row per Student under review for this Plan. Items appear here after Run Validation
            is used at least once.
        </p>

        <form class="mt-3 flex flex-wrap items-end gap-3" @submit.prevent="applyFilters">
            <div>
                <label class="block text-xs text-slate-600" for="filter-validation-result"
                    >Validation result</label
                >
                <select
                    id="filter-validation-result"
                    v-model="validationResult"
                    class="mt-1 w-40 rounded border border-slate-300 px-2 py-1.5 text-sm"
                    @change="applyFilters"
                >
                    <option value="">All</option>
                    <option
                        v-for="r in ROLLOVER_VALIDATION_RESULTS"
                        :key="r.value"
                        :value="r.value"
                    >
                        {{ r.label }}
                    </option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-slate-600" for="filter-execution-status"
                    >Execution status</label
                >
                <select
                    id="filter-execution-status"
                    v-model="executionStatus"
                    class="mt-1 w-40 rounded border border-slate-300 px-2 py-1.5 text-sm"
                    @change="applyFilters"
                >
                    <option value="">All</option>
                    <option
                        v-for="r in ROLLOVER_EXECUTION_STATUSES"
                        :key="r.value"
                        :value="r.value"
                    >
                        {{ r.label }}
                    </option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-slate-600" for="filter-decision">Decision</label>
                <select
                    id="filter-decision"
                    v-model="decisionFilter"
                    class="mt-1 w-40 rounded border border-slate-300 px-2 py-1.5 text-sm"
                    @change="applyFilters"
                >
                    <option value="">All</option>
                    <option v-for="d in ROLLOVER_ITEM_DECISIONS" :key="d.value" :value="d.value">
                        {{ d.label }}
                    </option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-slate-600" for="filter-per-page"
                    >Students per page</label
                >
                <select
                    id="filter-per-page"
                    v-model="perPage"
                    class="mt-1 w-28 rounded border border-slate-300 px-2 py-1.5 text-sm"
                    @change="applyFilters"
                >
                    <option value="20">20</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>
        </form>

        <!-- UAT-C2: what the last bulk decision did, Student by Student. -->
        <section
            v-if="bulkResult"
            class="mt-4 rounded border px-4 py-3 text-sm"
            :class="
                bulkResult.unresolved.length === 0
                    ? 'border-emerald-200 bg-emerald-50'
                    : 'border-amber-200 bg-amber-50'
            "
            role="status"
        >
            <p class="font-medium">
                {{ bulkResult.decision === 'promote' ? 'Promote' : 'Exclude' }} saved for
                {{ bulkResult.applied }} of {{ bulkResult.total }} selected Student{{
                    bulkResult.total === 1 ? '' : 's'
                }}.
            </p>
            <template v-if="bulkResult.unresolved.length > 0">
                <p class="mt-1">These were left unchanged and need individual attention:</p>
                <ul class="mt-1 list-disc pl-5">
                    <li v-for="r in bulkResult.unresolved" :key="r.itemId">
                        {{ r.student ?? 'A Student' }}: {{ OUTCOME_LABELS[r.outcome] }}
                    </li>
                </ul>
            </template>
        </section>

        <!-- UAT-C2: bulk Promote / Exclude for the rows selected on this page. -->
        <div
            v-if="canManage && configurable && items.data.length > 0"
            class="mt-4 flex flex-wrap items-center gap-3 rounded border border-slate-200 bg-slate-50 px-3 py-2 text-sm"
        >
            <span>{{ selected.length }} selected on this page</span>
            <button
                type="button"
                class="underline disabled:opacity-50"
                :disabled="preview !== null"
                @click="toggleAll"
            >
                {{ allSelected ? 'Clear selection' : 'Select all on this page' }}
            </button>
            <button
                type="button"
                class="rounded border border-slate-300 bg-white px-3 py-1.5 disabled:opacity-50"
                :disabled="selected.length === 0 || previewing || preview !== null"
                @click="startBulk('promote')"
            >
                Promote selected…
            </button>
            <button
                type="button"
                class="rounded border border-slate-300 bg-white px-3 py-1.5 disabled:opacity-50"
                :disabled="selected.length === 0 || previewing || preview !== null"
                @click="startBulk('exclude')"
            >
                Exclude selected…
            </button>
            <span class="text-xs text-slate-500"
                >Up to {{ bulkMax }} at a time; only this page's rows.</span
            >
        </div>
        <p v-if="previewError" role="alert" class="mt-2 text-sm text-red-700">
            {{ previewError }}
        </p>

        <section
            v-if="preview"
            class="mt-3 rounded border border-slate-300 p-4 text-sm"
            aria-labelledby="bulk-preview-heading"
        >
            <h3 id="bulk-preview-heading" class="font-semibold">
                {{ preview.decision === 'promote' ? 'Promote' : 'Exclude' }}
                {{ preview.rows.length }} selected Student{{
                    preview.rows.length === 1 ? '' : 's'
                }}?
            </h3>
            <ul v-if="moves.length > 0" class="mt-2 space-y-1">
                <li v-for="[move, count] in moves" :key="move">{{ move }}: {{ count }}</li>
            </ul>
            <p class="mt-2">
                <strong>{{ readyCount }}</strong> ready.
                <template v-if="preview.decision === 'promote'">
                    Each keeps their current Roll Number.
                </template>
                Nothing else about these Students changes; the plan needs Run Validation again
                before it can be executed.
            </p>
            <ul class="mt-2 space-y-0.5 text-slate-600">
                <template v-for="(count, outcome) in outcomeCounts" :key="outcome">
                    <li v-if="outcome !== 'ready'">{{ count }} · {{ OUTCOME_LABELS[outcome] }}</li>
                </template>
            </ul>
            <details
                v-if="preview.rows.some((r) => r.outcome !== 'ready')"
                class="mt-2 text-slate-600"
            >
                <summary class="cursor-pointer">Students who will be left unchanged</summary>
                <ul class="mt-1 list-disc pl-5">
                    <template v-for="r in preview.rows" :key="r.itemId">
                        <li v-if="r.outcome !== 'ready'">
                            {{ rowName(r.itemId) }}: {{ OUTCOME_LABELS[r.outcome] }}
                        </li>
                    </template>
                </ul>
            </details>
            <p v-if="bulkError" role="alert" class="mt-2 text-red-700">{{ bulkError }}</p>
            <div class="mt-3 flex flex-wrap gap-3">
                <button
                    type="button"
                    class="rounded bg-slate-900 px-3 py-1.5 font-medium text-white disabled:opacity-50"
                    :disabled="readyCount === 0 || bulkForm.processing"
                    @click="confirmBulk"
                >
                    Save {{ preview.decision === 'promote' ? 'Promote' : 'Exclude' }} for
                    {{ readyCount }} Student{{ readyCount === 1 ? '' : 's' }}
                </button>
                <button type="button" class="underline" @click="cancelBulk">Cancel</button>
            </div>
        </section>

        <p v-if="items.data.length === 0" class="mt-4 text-sm text-slate-500">
            No Student Items match. Run Validation to populate them.
        </p>

        <template v-else>
            <!-- Desktop table -->
            <div class="mt-4 hidden overflow-x-auto md:block">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-xs text-slate-500">
                            <th v-if="canManage && configurable" scope="col" class="w-8 py-2">
                                <input
                                    type="checkbox"
                                    :checked="allSelected"
                                    :disabled="preview !== null"
                                    aria-label="Select every Student on this page"
                                    @change="toggleAll"
                                />
                            </th>
                            <th scope="col" class="py-2 font-medium">Student</th>
                            <th scope="col" class="py-2 font-medium">Source placement</th>
                            <th scope="col" class="py-2 font-medium">Decision</th>
                            <th scope="col" class="py-2 font-medium">Target proposal</th>
                            <th scope="col" class="py-2 font-medium">Result</th>
                            <th scope="col" class="py-2 font-medium">Execution</th>
                            <th scope="col" class="py-2 font-medium">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <template v-for="item in items.data" :key="item.id">
                            <tr>
                                <td v-if="canManage && configurable" class="py-3">
                                    <input
                                        v-if="!isExecuted(item)"
                                        v-model="selected"
                                        type="checkbox"
                                        :value="item.id"
                                        :disabled="preview !== null"
                                        :aria-label="`Select ${studentName(item.student)}`"
                                    />
                                </td>
                                <td class="py-3">
                                    {{ studentName(item.student) }}
                                    <span class="block text-xs text-slate-500">{{
                                        item.student.studentNumber
                                    }}</span>
                                </td>
                                <td class="py-3 text-slate-600">
                                    {{ sectionLabel(item.sourceEnrollment.section) }}
                                    <span
                                        v-if="item.sourceEnrollment.rollNumber"
                                        class="block text-xs text-slate-500"
                                        >Roll {{ item.sourceEnrollment.rollNumber }}</span
                                    >
                                </td>
                                <td class="py-3 text-slate-600">{{ item.decision }}</td>
                                <td class="py-3 text-slate-600">
                                    <span v-if="item.targetSection"
                                        >{{ sectionLabel(item.targetSection) }}
                                        <span v-if="item.targetRollNumber"
                                            >· Roll {{ item.targetRollNumber }}</span
                                        ></span
                                    >
                                    <span v-else>—</span>
                                </td>
                                <td class="py-3">
                                    <StatusBadge
                                        v-if="item.validationResult"
                                        :status="item.validationResult"
                                    />
                                    <span v-else class="text-slate-400">Not validated</span>
                                    <p
                                        v-if="item.validationReason"
                                        class="mt-1 text-xs text-slate-500"
                                    >
                                        {{ rolloverReasonLabel(item.validationReason) }}
                                    </p>
                                </td>
                                <td class="py-3">
                                    <StatusBadge
                                        v-if="item.executionStatus"
                                        :status="item.executionStatus"
                                    />
                                    <span v-else class="text-slate-400">—</span>
                                </td>
                                <td class="py-3 text-right">
                                    <button
                                        v-if="
                                            canManage &&
                                            configurable &&
                                            !isExecuted(item) &&
                                            editingItemId !== item.id
                                        "
                                        type="button"
                                        class="text-sm underline"
                                        @click="startEdit(item)"
                                    >
                                        Edit
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="editingItemId === item.id">
                                <td :colspan="canManage && configurable ? 8 : 7" class="pb-4">
                                    <form
                                        class="rounded border border-slate-200 p-4"
                                        @submit.prevent="submitEdit(item.id)"
                                    >
                                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                            <div>
                                                <label
                                                    class="block text-xs font-medium text-slate-700"
                                                    :for="`decision-${item.id}`"
                                                    >Decision</label
                                                >
                                                <select
                                                    :id="`decision-${item.id}`"
                                                    v-model="editForm.decision"
                                                    required
                                                    class="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
                                                >
                                                    <option
                                                        v-for="d in ROLLOVER_ITEM_DECISIONS"
                                                        :key="d.value"
                                                        :value="d.value"
                                                    >
                                                        {{ d.label }}
                                                    </option>
                                                </select>
                                            </div>
                                            <div>
                                                <label
                                                    class="block text-xs font-medium text-slate-700"
                                                    :for="`target-section-${item.id}`"
                                                    >Target Section override</label
                                                >
                                                <select
                                                    :id="`target-section-${item.id}`"
                                                    v-model="editForm.target_section_id"
                                                    class="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
                                                >
                                                    <option value="">Use mapping default</option>
                                                    <option
                                                        v-for="s in targetSections"
                                                        :key="s.id"
                                                        :value="s.id"
                                                    >
                                                        {{
                                                            s.occupancy
                                                                ? `${s.label} — ${s.occupancy}`
                                                                : s.label
                                                        }}
                                                    </option>
                                                </select>
                                            </div>
                                            <div>
                                                <label
                                                    class="block text-xs font-medium text-slate-700"
                                                    :for="`roll-strategy-${item.id}`"
                                                    >Roll Number strategy</label
                                                >
                                                <select
                                                    :id="`roll-strategy-${item.id}`"
                                                    v-model="editForm.roll_number_strategy"
                                                    class="mt-1 w-full rounded border border-slate-300 px-2 py-1.5"
                                                >
                                                    <option value="">Not set</option>
                                                    <option
                                                        v-for="s in ROLLOVER_ROLL_NUMBER_STRATEGIES"
                                                        :key="s.value"
                                                        :value="s.value"
                                                    >
                                                        {{ s.label }}
                                                    </option>
                                                </select>
                                            </div>
                                            <div>
                                                <label
                                                    class="block text-xs font-medium text-slate-700"
                                                    :for="`roll-number-${item.id}`"
                                                    >Explicit Roll Number</label
                                                >
                                                <!-- type="text", never "number" -- "007" must
                                                     survive (this checkpoint's brief, section 33). -->
                                                <input
                                                    :id="`roll-number-${item.id}`"
                                                    v-model="editForm.target_roll_number"
                                                    type="text"
                                                    :disabled="
                                                        editForm.roll_number_strategy !== 'explicit'
                                                    "
                                                    class="mt-1 w-full rounded border border-slate-300 px-2 py-1.5 disabled:bg-slate-100"
                                                />
                                            </div>
                                        </div>
                                        <p
                                            v-if="editForm.errors.decision"
                                            class="mt-2 text-sm text-red-600"
                                        >
                                            {{ editForm.errors.decision }}
                                        </p>
                                        <p class="mt-2 text-xs text-slate-500">
                                            Saving changes the Plan's configuration -- validation
                                            will be required again before execution.
                                        </p>
                                        <div class="mt-3 flex items-center gap-3">
                                            <button
                                                type="submit"
                                                :disabled="editForm.processing"
                                                class="rounded bg-slate-900 px-3 py-1.5 text-xs font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                                            >
                                                Save
                                            </button>
                                            <button
                                                type="button"
                                                class="text-xs underline"
                                                @click="cancelEdit"
                                            >
                                                Cancel
                                            </button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <!-- Mobile/tablet card list -->
            <ul class="mt-4 space-y-3 md:hidden">
                <li
                    v-for="item in items.data"
                    :key="item.id"
                    class="rounded border border-slate-200 p-4"
                >
                    <label class="flex items-start gap-2">
                        <input
                            v-if="canManage && configurable && !isExecuted(item)"
                            v-model="selected"
                            type="checkbox"
                            class="mt-1"
                            :value="item.id"
                            :disabled="preview !== null"
                        />
                        <span>
                            <span class="block font-medium">{{ studentName(item.student) }}</span>
                            <span class="block text-xs text-slate-500">{{
                                item.student.studentNumber
                            }}</span>
                        </span>
                    </label>
                    <p class="mt-2 text-sm text-slate-600">
                        {{ sectionLabel(item.sourceEnrollment.section) }} → decision:
                        {{ item.decision }}
                    </p>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <StatusBadge v-if="item.validationResult" :status="item.validationResult" />
                        <StatusBadge v-if="item.executionStatus" :status="item.executionStatus" />
                    </div>
                </li>
            </ul>

            <Pagination :links="items.links" />
        </template>
    </section>
</template>
