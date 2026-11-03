<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import {
    outcomeLabels,
    ruleText,
    staffTypeLabels,
    units,
    useLeaveError,
    type EligibilityResult,
    type LeaveTypeRow,
    type Option,
} from '../../../leave';

/**
 * HR.9 (ADR 0065 §28.4) — leave policy eligibility.
 *
 * For one leave type and one date, every employment spanning that date and
 * the server's answer: already assigned (and whether today's rules still
 * point at that version), eligible for exactly one current version,
 * ambiguous, matched by no rule, or not eligible (draft, separated or ended
 * employment; inactive Employee). Each row shows the facts it was judged on.
 * The run assigns every eligible employment from the chosen date; it never
 * ends, moves or replaces an existing assignment, and never assigns your own
 * employment. Nothing here is computed in the browser.
 */
interface Props {
    types: LeaveTypeRow[];
    leaveTypeId: string;
    date: string;
    today: string;
    outcome: string;
    result: EligibilityResult | null;
    error: string | null;
    outcomes: Option[];
    staffTypes: Option[];
    labels: { categories: Record<string, string>; departments: Record<string, string> };
    canManage: boolean;
}

const props = defineProps<Props>();
const leaveError = useLeaveError();

const filters = ref({ leave_type_id: props.leaveTypeId, date: props.date, outcome: props.outcome });
const runForm = useForm({ leave_type_id: props.leaveTypeId, as_of: props.date });
const confirming = ref(false);

function applyFilters(): void {
    router.get(
        '/app/leave/eligibility',
        { ...filters.value, outcome: filters.value.outcome || undefined },
        { preserveScroll: true },
    );
}

function executeRun(): void {
    runForm.post('/app/leave/eligibility-runs', {
        preserveScroll: true,
        onFinish: () => {
            confirming.value = false;
        },
    });
}

const versionNames = computed(() =>
    Object.fromEntries((props.result?.versions ?? []).map((v) => [v.policyId, v.name])),
);
// HR.10: the server filters by outcome and pages (100 rows); the counts cover every employment.
const rows = computed(() => props.result?.rows ?? []);
const pages = computed(() =>
    props.result?.total === undefined || props.result.perPage === undefined
        ? 1
        : Math.max(1, Math.ceil(props.result.total / props.result.perPage)),
);
function goToPage(page: number): void {
    router.get(
        '/app/leave/eligibility',
        { ...filters.value, outcome: filters.value.outcome || undefined, page },
        { preserveScroll: true },
    );
}
const eligibleCount = computed(() => props.result?.counts.eligible ?? 0);
const driftCount = computed(
    () => (props.result?.rows ?? []).filter((r) => r.assignedMatchesRules === false).length,
);

function factsText(r: {
    facts: {
        staffType: string | null;
        employeeCategoryId: string | null;
        departmentId: string | null;
    };
}): string {
    return [
        r.facts.staffType === null
            ? 'Staff type not classified'
            : staffTypeLabels[r.facts.staffType],
        r.facts.employeeCategoryId === null
            ? 'no Category'
            : (props.labels.categories[r.facts.employeeCategoryId] ?? 'Category'),
        r.facts.departmentId === null
            ? 'no primary Department'
            : (props.labels.departments[r.facts.departmentId] ?? 'Department'),
    ].join(' · ');
}
</script>

<template>
    <main class="mx-auto max-w-6xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/leave/configuration">← Leave configuration</a>
        <h1 class="mt-2 text-xl font-semibold">Leave eligibility</h1>
        <p class="mt-1 text-sm text-slate-600">
            Which current policy version each employment resolves to, from its staff type, Employee
            Category and primary Department on the date. Units are half-days.
        </p>
        <p
            v-if="leaveError || error"
            role="alert"
            class="mt-3 rounded border border-red-300 bg-red-50 p-3 text-sm text-red-800"
        >
            {{ leaveError ?? error }}
        </p>

        <form class="mt-4 flex flex-wrap items-end gap-3" @submit.prevent="applyFilters">
            <label class="text-sm"
                >Leave type
                <select
                    v-model="filters.leave_type_id"
                    class="ml-1 rounded border border-slate-300 px-2 py-1 text-sm"
                >
                    <option v-for="t in types" :key="t.id" :value="t.id">{{ t.name }}</option>
                </select>
            </label>
            <label class="text-sm"
                >On
                <input
                    v-model="filters.date"
                    type="date"
                    class="ml-1 rounded border border-slate-300 px-2 py-1 text-sm"
                />
            </label>
            <label class="text-sm"
                >Show
                <select
                    v-model="filters.outcome"
                    class="ml-1 rounded border border-slate-300 px-2 py-1 text-sm"
                >
                    <option value="">Every outcome</option>
                    <option v-for="o in outcomes" :key="o.value" :value="o.value">
                        {{ o.label }}
                    </option>
                </select>
            </label>
            <button type="submit" class="underline">Show</button>
        </form>

        <p v-if="types.length === 0" class="mt-6 text-sm text-slate-600">
            No leave type yet. Create one in Leave configuration.
        </p>

        <template v-if="result">
            <section class="mt-6" aria-labelledby="versions-heading">
                <h2 id="versions-heading" class="text-sm font-semibold">Current versions</h2>
                <p v-if="result.versions.length === 0" class="mt-1 text-sm text-slate-600">
                    This leave type has no current policy version.
                </p>
                <ul class="mt-1 space-y-1 text-sm">
                    <li v-for="v in result.versions" :key="v.policyId">
                        <span class="font-medium">{{ v.name }}</span> ·
                        {{ units(v.annualAllocationUnits) }} a year ·
                        <template v-if="v.rules.length === 0"
                            >no eligibility rule (assigned by hand only)</template
                        >
                        <template v-else>
                            {{
                                v.rules
                                    .map((r) => ruleText(r, labels.categories, labels.departments))
                                    .join(' | ')
                            }}
                        </template>
                    </li>
                </ul>
            </section>

            <section class="mt-6" aria-labelledby="summary-heading">
                <h2 id="summary-heading" class="text-sm font-semibold">On {{ result.date }}</h2>
                <ul class="mt-1 flex flex-wrap gap-3 text-sm">
                    <li v-for="o in outcomes" :key="o.value">
                        {{ o.label }}: {{ result.counts[o.value] ?? 0 }}
                    </li>
                </ul>
                <p v-if="driftCount > 0" class="mt-1 text-sm text-amber-900">
                    On this page, {{ driftCount }} assigned employment(s) no longer match the
                    current rules. An assignment is never moved automatically: end it and assign
                    again if needed.
                </p>

                <div v-if="canManage" class="mt-3">
                    <button
                        v-if="!confirming"
                        type="button"
                        class="underline"
                        :disabled="eligibleCount === 0"
                        @click="confirming = true"
                    >
                        Assign the {{ eligibleCount }} eligible employment(s) from {{ result.date }}
                    </button>
                    <div
                        v-else
                        role="alertdialog"
                        aria-labelledby="run-confirm"
                        class="rounded border border-slate-300 p-3 text-sm"
                    >
                        <p id="run-confirm">
                            Assign each eligible employment the version it resolves to, effective
                            {{ result.date }} until its employment ends (or the day before a later
                            assignment)? Assigned, ambiguous and unmatched employments, and your
                            own, are left unchanged.
                        </p>
                        <div class="mt-2 flex gap-3">
                            <button
                                type="button"
                                class="underline"
                                :disabled="runForm.processing"
                                @click="executeRun"
                            >
                                Confirm and assign
                            </button>
                            <button type="button" class="underline" @click="confirming = false">
                                Cancel
                            </button>
                        </div>
                    </div>
                </div>
            </section>

            <section class="mt-6" aria-labelledby="rows-heading">
                <h2 id="rows-heading" class="text-sm font-semibold">Employments</h2>
                <p v-if="rows.length === 0" class="mt-1 text-sm text-slate-600">
                    No employment matches this view.
                </p>
                <table v-else class="mt-2 w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-slate-300">
                            <th scope="col" class="py-1 pr-2">Employee</th>
                            <th scope="col" class="py-1 pr-2">Facts on the date</th>
                            <th scope="col" class="py-1 pr-2">Outcome</th>
                            <th scope="col" class="py-1">Version</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="r in rows"
                            :key="r.employmentRecordId"
                            class="border-b border-slate-100 align-top"
                        >
                            <td class="py-1 pr-2">
                                {{ r.fullName ?? 'Unnamed' }} ({{ r.employeeNumber ?? '—' }})
                                <div class="text-xs text-slate-600">{{ r.employmentStatus }}</div>
                            </td>
                            <td class="py-1 pr-2">{{ factsText(r) }}</td>
                            <td class="py-1 pr-2">
                                {{ outcomeLabels[r.outcome] ?? r.outcome }}
                                <div
                                    v-if="r.assignedMatchesRules === false"
                                    class="text-xs text-amber-900"
                                >
                                    differs from the current rules
                                </div>
                            </td>
                            <td class="py-1">
                                <template v-if="r.assignedPolicyId">
                                    {{ versionNames[r.assignedPolicyId] ?? 'An earlier version' }}
                                </template>
                                <template v-else-if="r.policyId">
                                    {{ versionNames[r.policyId] ?? '—' }}
                                    <div
                                        v-if="r.outcome === 'eligible'"
                                        class="text-xs text-slate-600"
                                    >
                                        until {{ r.assignmentEndsOn ?? 'the employment ends' }}
                                    </div>
                                </template>
                                <template v-else-if="r.matchingPolicyIds.length > 1">
                                    {{
                                        r.matchingPolicyIds
                                            .map((id) => versionNames[id] ?? '—')
                                            .join(', ')
                                    }}
                                </template>
                                <template v-else>—</template>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <nav
                    v-if="pages > 1"
                    aria-label="Employment pages"
                    class="mt-3 flex items-center gap-3 text-sm"
                >
                    <button
                        type="button"
                        class="underline"
                        :disabled="(result.page ?? 1) <= 1"
                        @click="goToPage((result.page ?? 1) - 1)"
                    >
                        Previous
                    </button>
                    <span
                        >Page {{ result.page ?? 1 }} of {{ pages }} ({{ result.total }} rows)</span
                    >
                    <button
                        type="button"
                        class="underline"
                        :disabled="(result.page ?? 1) >= pages"
                        @click="goToPage((result.page ?? 1) + 1)"
                    >
                        Next
                    </button>
                </nav>
            </section>
        </template>
    </main>
</template>
