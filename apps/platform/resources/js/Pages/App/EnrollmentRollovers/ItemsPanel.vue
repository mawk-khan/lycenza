<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Pagination from '../../../Components/Pagination.vue';
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

interface SectionOption {
    id: string;
    label: string;
}

interface Props {
    rolloverId: string;
    items: { data: Item[]; links: PageLink[]; total: number };
    itemFilters: { validation_result: string; execution_status: string; decision: string };
    configurable: boolean;
    canManage: boolean;
    targetSections: SectionOption[];
}

const props = defineProps<Props>();

const validationResult = ref(props.itemFilters.validation_result);
const executionStatus = ref(props.itemFilters.execution_status);
const decisionFilter = ref(props.itemFilters.decision);

function applyFilters(): void {
    router.get(
        `/app/enrollment-rollovers/${props.rolloverId}`,
        {
            validation_result: validationResult.value || undefined,
            execution_status: executionStatus.value || undefined,
            decision: decisionFilter.value || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

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
        }))
        .patch(`/app/enrollment-rollovers/${props.rolloverId}/items/${itemId}`, {
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
        </form>

        <p v-if="items.data.length === 0" class="mt-4 text-sm text-slate-500">
            No Student Items match. Run Validation to populate them.
        </p>

        <template v-else>
            <!-- Desktop table -->
            <div class="mt-4 hidden overflow-x-auto md:block">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-xs text-slate-500">
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
                                <td class="py-3">
                                    {{ studentName(item.student) }}
                                    <span class="block text-xs text-slate-500">{{
                                        item.student.studentNumber
                                    }}</span>
                                </td>
                                <td class="py-3 text-slate-600">
                                    {{ item.sourceEnrollment.section?.name ?? '—' }}
                                </td>
                                <td class="py-3 text-slate-600">{{ item.decision }}</td>
                                <td class="py-3 text-slate-600">
                                    <span v-if="item.targetSection"
                                        >{{ item.targetSection.name }}
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
                                <td colspan="7" class="pb-4">
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
                                                        {{ s.label }}
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
                    <p class="font-medium">{{ studentName(item.student) }}</p>
                    <p class="text-xs text-slate-500">{{ item.student.studentNumber }}</p>
                    <p class="mt-2 text-sm text-slate-600">
                        {{ item.sourceEnrollment.section?.name ?? '—' }} → decision:
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
