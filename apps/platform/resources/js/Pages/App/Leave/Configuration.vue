<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import {
    outcomeLabels,
    portionLabels,
    ruleText,
    timingText,
    typeName,
    units,
    type EligibilityResult,
    type LeavePolicyRow,
    type LeaveTypeRow,
    type Option,
    type ReferenceOption,
} from '../../../leave';
import { useLeaveError } from '../../../leave';
import { xsrfToken } from '../../../csrf';

/**
 * HRX.1/HRX.2 — leave configuration (administration).
 *
 * - The leave year starts in a School-chosen month (April by default — a
 *   product default, not a legal rule). Once a year is open the start month
 *   changes only prospectively, from a future first-of-month after every
 *   open year; opened years never change.
 * - Leave types and policies are School-configured; policies are immutable
 *   versions. The staff working week and holidays decide which days are
 *   charged.
 * - HR.9 (ADR 0065 §28): a version also carries its request-timing rules
 *   (notice and backdating in calendar days, a maximum per request in
 *   half-day units) and its eligibility rules (staff type, Employee
 *   Category, Department). Nothing is a draft on the server: "Preview"
 *   asks the server how the version would apply, writing nothing; a saved
 *   version is read-only, changed only by a new version that replaces it.
 */
interface Props {
    settings: {
        leaveYearStartMonth: number;
        baseStartMonth: number;
        startChanges: {
            id: string;
            previousStartMonth: number;
            startMonth: number;
            effectiveFrom: string;
        }[];
        yearsExist: boolean;
    };
    years: { id: string; label: string; startsOn: string; endsOn: string; isTransition: boolean }[];
    types: LeaveTypeRow[];
    policies: LeavePolicyRow[];
    calendar: {
        weeklyPattern: Record<string, string>;
        holidays: { id: string; date: string; portion: string; name: string }[];
    };
    portions: string[];
    canConfigure: boolean;
    staffTypes: Option[];
    employeeCategories: ReferenceOption[];
    departments: ReferenceOption[];
    today: string;
    entitlementMethods: Option[];
    accrualTimings: Option[];
}

const props = defineProps<Props>();

const months = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
];
const weekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

const settingsForm = useForm({ leave_year_start_month: props.settings.baseStartMonth });
const changeForm = useForm({ start_month: props.settings.leaveYearStartMonth, effective_from: '' });
const yearForm = useForm({ on: '' });
const typeForm = useForm({
    code: '',
    name: '',
    is_paid: true,
    tracks_balance: true,
    allows_half_day: true,
});
interface RuleInput {
    staff_type: string;
    employee_category_id: string;
    department_id: string;
}
const policyForm = useForm({
    leave_type_id: '',
    name: '',
    annual_allocation_units: 0,
    carry_forward_allowed: false,
    carry_forward_cap_units: null as number | null,
    carry_forward_expiry_days: null as number | null,
    supersedes_policy_id: '',
    min_notice_days: null as number | null,
    max_backdate_days: null as number | null,
    max_units_per_request: null as number | null,
    entitlement_method: 'annual',
    monthly_accrual_units: null as number | null,
    accrual_timing: '',
    eligibility_rules: [] as RuleInput[],
});
const weekForm = useForm({
    weekdays: Object.fromEntries(
        [1, 2, 3, 4, 5, 6, 7].map((d) => [d, props.calendar.weeklyPattern[String(d)] ?? 'off']),
    ) as Record<number, string>,
});
const holidayForm = useForm({ date: '', portion: 'full', name: '' });

function post(form: { post: (url: string, options: object) => void }, url: string): void {
    form.post(url, { preserveScroll: true });
}

const categoryNames = computed(() =>
    Object.fromEntries(props.employeeCategories.map((c) => [c.id, c.name])),
);
const departmentNames = computed(() =>
    Object.fromEntries(props.departments.map((d) => [d.id, d.name])),
);
const nullable = (v: string): string | null => (v === '' ? null : v);
const nullableNumber = (v: number | string | null): number | null =>
    v === null || v === '' ? null : Number(v);
const rulesPayload = (rules: RuleInput[]) =>
    rules.map((r) => ({
        staff_type: nullable(r.staff_type),
        employee_category_id: nullable(r.employee_category_id),
        department_id: nullable(r.department_id),
    }));

function addRule(): void {
    policyForm.eligibility_rules.push({
        staff_type: '',
        employee_category_id: '',
        department_id: '',
    });
}

function removeRule(index: number): void {
    policyForm.eligibility_rules.splice(index, 1);
}

const preview = ref<EligibilityResult | null>(null);
const previewDate = ref(props.today);
const previewError = ref<string | null>(null);
const previewing = ref(false);

/** Pre-fills the editor from the version it will replace (every field stays editable before saving). */
function startReplacing(p: LeavePolicyRow): void {
    policyForm.leave_type_id = p.leaveTypeId;
    policyForm.name = p.name;
    policyForm.annual_allocation_units = p.annualAllocationUnits;
    policyForm.carry_forward_allowed = p.carryForwardAllowed;
    policyForm.carry_forward_cap_units = p.carryForwardCapUnits;
    policyForm.carry_forward_expiry_days = p.carryForwardExpiryDays;
    policyForm.supersedes_policy_id = p.id;
    policyForm.min_notice_days = p.minNoticeDays;
    policyForm.max_backdate_days = p.maxBackdateDays;
    policyForm.max_units_per_request = p.maxUnitsPerRequest;
    policyForm.entitlement_method = p.entitlementMethod;
    policyForm.monthly_accrual_units = p.monthlyAccrualUnits;
    policyForm.accrual_timing = p.accrualTiming ?? '';
    policyForm.eligibility_rules = p.eligibilityRules.map((r) => ({
        staff_type: r.staffType ?? '',
        employee_category_id: r.employeeCategoryId ?? '',
        department_id: r.departmentId ?? '',
    }));
    preview.value = null;
}

function submitPolicy(): void {
    policyForm
        .transform((d) => ({
            ...d,
            supersedes_policy_id: d.supersedes_policy_id || null,
            min_notice_days: nullableNumber(d.min_notice_days),
            max_backdate_days: nullableNumber(d.max_backdate_days),
            max_units_per_request: nullableNumber(d.max_units_per_request),
            annual_allocation_units:
                d.entitlement_method === 'monthly' ? 0 : d.annual_allocation_units,
            monthly_accrual_units:
                d.entitlement_method === 'monthly' ? nullableNumber(d.monthly_accrual_units) : null,
            accrual_timing: d.entitlement_method === 'monthly' ? d.accrual_timing || null : null,
            eligibility_rules: rulesPayload(d.eligibility_rules),
        }))
        .post('/app/leave/policies', {
            preserveScroll: true,
            onSuccess: () => {
                policyForm.reset();
                preview.value = null;
            },
        });
}

/** Asks the server how this version would apply on a date. Writes nothing. */
async function previewPolicy(): Promise<void> {
    previewing.value = true;
    previewError.value = null;
    try {
        const response = await fetch('/app/leave/policies/preview', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify({
                leave_type_id: policyForm.leave_type_id,
                date: previewDate.value,
                supersedes_policy_id: policyForm.supersedes_policy_id || null,
                name: policyForm.name || 'Draft',
                annual_allocation_units: policyForm.annual_allocation_units,
                eligibility_rules: rulesPayload(policyForm.eligibility_rules),
            }),
        });
        const payload = await response.json().catch(() => null);
        if (!response.ok) {
            preview.value = null;
            previewError.value =
                payload?.error?.message ??
                payload?.message ??
                `Preview failed (${response.status}).`;
            return;
        }
        preview.value = payload.data as EligibilityResult;
    } finally {
        previewing.value = false;
    }
}

const previewOutcomes = computed(() =>
    preview.value === null
        ? []
        : Object.entries(preview.value.counts).filter(([, count]) => count > 0),
);
const draftRows = computed(() =>
    preview.value === null ? [] : preview.value.rows.filter((r) => r.policyId === 'draft'),
);

/** Versions grouped by leave type: the current (active) ones first, then history. */
const policiesByType = computed(() =>
    props.types
        .map((t) => ({
            type: t,
            active: props.policies.filter((p) => p.leaveTypeId === t.id && p.status === 'active'),
            history: props.policies.filter((p) => p.leaveTypeId === t.id && p.status !== 'active'),
        }))
        .filter((g) => g.active.length + g.history.length > 0),
);

function setTypeStatus(id: string, status: string): void {
    router.post(`/app/leave/types/${id}/status`, { status }, { preserveScroll: true });
}

function retirePolicy(id: string): void {
    router.post(`/app/leave/policies/${id}/retire`, {}, { preserveScroll: true });
}

function removeHoliday(id: string): void {
    router.post(`/app/leave/calendar/holidays/${id}/remove`, {}, { preserveScroll: true });
}

const leaveError = useLeaveError();
</script>

<template>
    <main class="mx-auto max-w-6xl p-8 font-sans text-ink">
        <a class="text-sm underline" href="/app/leave/requests">← Leave requests</a>
        <h1 class="mt-2 text-xl font-semibold">Leave configuration</h1>
        <p
            v-if="leaveError"
            role="alert"
            class="mt-3 rounded-xs border border-danger/40 bg-danger-tint p-3 text-sm text-danger"
        >
            {{ leaveError }}
        </p>

        <section class="mt-6">
            <h2 class="text-sm font-semibold">Leave year</h2>
            <p class="mt-1 text-sm">
                Starts in {{ months[settings.baseStartMonth - 1]
                }}<template v-if="settings.startChanges.length > 0">
                    — changes:
                    <span v-for="c in settings.startChanges" :key="c.id">
                        {{ months[c.startMonth - 1] }} from {{ c.effectiveFrom }};
                    </span>
                </template>
            </p>
            <form
                v-if="canConfigure && !settings.yearsExist"
                class="mt-2 flex gap-2"
                @submit.prevent="post(settingsForm, '/app/leave/settings')"
            >
                <select
                    v-model.number="settingsForm.leave_year_start_month"
                    aria-label="Start month"
                    class="input w-auto"
                >
                    <option v-for="(m, i) in months" :key="m" :value="i + 1">{{ m }}</option>
                </select>
                <button type="submit" class="underline">Save</button>
            </form>
            <form
                v-if="canConfigure && settings.yearsExist"
                class="mt-2 flex flex-wrap gap-2"
                @submit.prevent="post(changeForm, '/app/leave/year-start-changes')"
            >
                <span class="text-sm text-ink-muted">Change for future years:</span>
                <select
                    v-model.number="changeForm.start_month"
                    aria-label="New start month"
                    class="input w-auto"
                >
                    <option v-for="(m, i) in months" :key="m" :value="i + 1">{{ m }}</option>
                </select>
                <input
                    v-model="changeForm.effective_from"
                    type="date"
                    aria-label="Effective from"
                    class="input w-auto"
                />
                <button type="submit" class="underline">Schedule</button>
            </form>
            <ul class="mt-3 text-sm">
                <li v-for="y in years" :key="y.id">
                    {{ y.label }}: {{ y.startsOn }} → {{ y.endsOn
                    }}{{ y.isTransition ? ' (transition)' : '' }}
                </li>
            </ul>
            <form
                v-if="canConfigure"
                class="mt-2 flex gap-2"
                @submit.prevent="post(yearForm, '/app/leave/years')"
            >
                <input
                    v-model="yearForm.on"
                    type="date"
                    aria-label="A date in the year to open"
                    class="input w-auto"
                />
                <button type="submit" class="underline">
                    Open the leave year containing this date
                </button>
            </form>
        </section>

        <section class="mt-8">
            <h2 class="text-sm font-semibold">Leave types</h2>
            <ul class="mt-2 text-sm">
                <li v-for="t in types" :key="t.id">
                    {{ t.name }} ({{ t.code }}) · {{ t.isPaid ? 'paid' : 'unpaid' }} ·
                    {{ t.tracksBalance ? 'tracks a balance' : 'no balance' }} ·
                    {{ t.allowsHalfDay ? 'half-days allowed' : 'whole days' }} · {{ t.status }}
                    <button
                        v-if="canConfigure"
                        type="button"
                        class="ml-2 underline"
                        @click="setTypeStatus(t.id, t.status === 'active' ? 'inactive' : 'active')"
                    >
                        {{ t.status === 'active' ? 'Deactivate' : 'Activate' }}
                    </button>
                </li>
            </ul>
            <form
                v-if="canConfigure"
                class="mt-2 flex flex-wrap items-center gap-2"
                @submit.prevent="post(typeForm, '/app/leave/types')"
            >
                <input
                    v-model="typeForm.code"
                    aria-label="Code"
                    placeholder="Code"
                    class="input w-24"
                />
                <input
                    v-model="typeForm.name"
                    aria-label="Name"
                    placeholder="Name"
                    class="input w-48"
                />
                <label class="text-sm"
                    ><input v-model="typeForm.is_paid" type="checkbox" /> Paid</label
                >
                <label class="text-sm"
                    ><input v-model="typeForm.tracks_balance" type="checkbox" /> Tracks a
                    balance</label
                >
                <label class="text-sm"
                    ><input v-model="typeForm.allows_half_day" type="checkbox" /> Half-days</label
                >
                <button type="submit" class="underline">Add type</button>
            </form>
            <p v-if="typeForm.errors.code" class="mt-1 text-sm text-danger">
                {{ typeForm.errors.code }}
            </p>
        </section>

        <section class="mt-8" aria-labelledby="policies-heading">
            <h2 id="policies-heading" class="text-sm font-semibold">Policies</h2>
            <p class="mt-1 text-sm text-ink-muted">
                A policy version is read-only once saved. To change it, replace it with a new
                version: existing assignments keep the version they were made under.
                <a class="underline" href="/app/leave/eligibility">Eligibility and assignment →</a>
            </p>
            <p v-if="policiesByType.length === 0" class="mt-2 text-sm text-ink-muted">
                No leave policy yet.
            </p>
            <div v-for="group in policiesByType" :key="group.type.id" class="mt-3">
                <h3 class="text-sm font-medium">{{ group.type.name }} ({{ group.type.code }})</h3>
                <ul class="mt-1 space-y-2 text-sm">
                    <li
                        v-for="p in group.active"
                        :key="p.id"
                        class="rounded-xs border border-line p-2"
                    >
                        <span class="font-medium">{{ p.name }}</span>
                        <span class="ml-2 rounded-xs bg-graphite-100 px-1 text-xs text-ink"
                            >Current</span
                        >
                        <div>
                            <template v-if="p.entitlementMethod === 'monthly'"
                                >{{ units(p.monthlyAccrualUnits ?? 0) }} a month ({{
                                    p.accrualTiming === 'period_start'
                                        ? 'in advance'
                                        : 'in arrears'
                                }}) ·</template
                            >
                            <template v-else
                                >{{ units(p.annualAllocationUnits) }} a year ·</template
                            >
                            {{
                                p.carryForwardAllowed
                                    ? `carry up to ${units(p.carryForwardCapUnits ?? 0)}`
                                    : 'no carry-forward'
                            }}
                        </div>
                        <div>Application rules: {{ timingText(p) }}</div>
                        <div>
                            Eligibility:
                            <template v-if="p.eligibilityRules.length === 0"
                                >assigned by hand only (no rule)</template
                            >
                            <ul v-else class="ml-4 list-disc">
                                <li v-for="r in p.eligibilityRules" :key="r.id ?? ''">
                                    {{ ruleText(r, categoryNames, departmentNames) }}
                                </li>
                            </ul>
                        </div>
                        <div v-if="canConfigure" class="mt-1 flex gap-3">
                            <button type="button" class="underline" @click="startReplacing(p)">
                                Replace with a new version
                            </button>
                            <button type="button" class="underline" @click="retirePolicy(p.id)">
                                Retire
                            </button>
                        </div>
                    </li>
                </ul>
                <details v-if="group.history.length > 0" class="mt-1 text-sm">
                    <summary>Earlier versions ({{ group.history.length }})</summary>
                    <ul class="mt-1 space-y-1">
                        <li v-for="p in group.history" :key="p.id" class="text-ink">
                            {{ p.name }} · {{ units(p.annualAllocationUnits) }} a year ·
                            {{ timingText(p) }} · retired {{ p.retiredAt?.slice(0, 10) }}
                        </li>
                    </ul>
                </details>
            </div>

            <form
                v-if="canConfigure"
                class="mt-4 space-y-3 rounded-xs border border-control-line p-3"
                aria-labelledby="policy-editor-heading"
                @submit.prevent="submitPolicy"
            >
                <h3 id="policy-editor-heading" class="text-sm font-semibold">
                    {{
                        policyForm.supersedes_policy_id
                            ? 'New version (replaces the current one)'
                            : 'New policy'
                    }}
                </h3>
                <fieldset class="flex flex-wrap items-end gap-2">
                    <legend class="text-sm font-medium">Policy identity</legend>
                    <label class="text-sm"
                        >Leave type
                        <select v-model="policyForm.leave_type_id" class="input ml-1 w-auto">
                            <option value="">Choose…</option>
                            <option
                                v-for="t in types.filter((x) => x.status === 'active')"
                                :key="t.id"
                                :value="t.id"
                            >
                                {{ t.name }}{{ t.tracksBalance ? '' : ' (no balance)' }}
                            </option>
                        </select>
                    </label>
                    <label class="text-sm"
                        >Name
                        <input v-model="policyForm.name" class="input ml-1 w-48" />
                    </label>
                    <label class="text-sm"
                        >Replaces
                        <select v-model="policyForm.supersedes_policy_id" class="input ml-1 w-auto">
                            <option value="">Nothing (a new policy)</option>
                            <option
                                v-for="p in policies.filter(
                                    (x) =>
                                        x.status === 'active' &&
                                        (policyForm.leave_type_id === '' ||
                                            x.leaveTypeId === policyForm.leave_type_id),
                                )"
                                :key="p.id"
                                :value="p.id"
                            >
                                {{ p.name }} ({{ typeName(types, p.leaveTypeId) }})
                            </option>
                        </select>
                    </label>
                </fieldset>
                <fieldset class="flex flex-wrap items-end gap-2">
                    <legend class="text-sm font-medium">Entitlement and carry-forward</legend>
                    <label class="text-sm"
                        >Funded by
                        <select v-model="policyForm.entitlement_method" class="input ml-1 w-auto">
                            <option v-for="m in entitlementMethods" :key="m.value" :value="m.value">
                                {{ m.label }}
                            </option>
                        </select>
                    </label>
                    <template v-if="policyForm.entitlement_method === 'monthly'">
                        <label class="text-sm"
                            >Units each whole month (half-days)
                            <input
                                v-model.number="policyForm.monthly_accrual_units"
                                type="number"
                                min="1"
                                max="62"
                                class="input ml-1 w-20"
                            />
                        </label>
                        <label class="text-sm"
                            >Credited
                            <select v-model="policyForm.accrual_timing" class="input ml-1 w-auto">
                                <option value="">Choose…</option>
                                <option v-for="t in accrualTimings" :key="t.value" :value="t.value">
                                    {{ t.label }}
                                </option>
                            </select>
                        </label>
                    </template>
                    <label v-if="policyForm.entitlement_method === 'annual'" class="text-sm"
                        >Annual allocation (half-day units)
                        <input
                            v-model.number="policyForm.annual_allocation_units"
                            type="number"
                            min="0"
                            class="input ml-1 w-24"
                        />
                    </label>
                    <label class="text-sm"
                        ><input v-model="policyForm.carry_forward_allowed" type="checkbox" /> Carry
                        forward at year close</label
                    >
                    <label class="text-sm"
                        >Carry cap (units)
                        <input
                            v-model.number="policyForm.carry_forward_cap_units"
                            type="number"
                            min="1"
                            :disabled="!policyForm.carry_forward_allowed"
                            class="input ml-1 w-20"
                        />
                    </label>
                    <label class="text-sm"
                        >Carried units expire after (days)
                        <input
                            v-model.number="policyForm.carry_forward_expiry_days"
                            type="number"
                            min="1"
                            :disabled="!policyForm.carry_forward_allowed"
                            class="input ml-1 w-20"
                        />
                    </label>
                </fieldset>
                <fieldset class="flex flex-wrap items-end gap-2">
                    <legend class="text-sm font-medium">
                        Application rules (leave blank for none)
                    </legend>
                    <label class="text-sm"
                        >Minimum notice (calendar days)
                        <input
                            v-model.number="policyForm.min_notice_days"
                            type="number"
                            min="1"
                            max="366"
                            class="input ml-1 w-20"
                        />
                    </label>
                    <label class="text-sm"
                        >Past dates allowed up to (calendar days back; 0 = none)
                        <input
                            v-model.number="policyForm.max_backdate_days"
                            type="number"
                            min="0"
                            max="366"
                            class="input ml-1 w-20"
                        />
                    </label>
                    <label class="text-sm"
                        >Maximum per request (half-day units)
                        <input
                            v-model.number="policyForm.max_units_per_request"
                            type="number"
                            min="1"
                            max="734"
                            class="input ml-1 w-20"
                        />
                    </label>
                </fieldset>
                <fieldset>
                    <legend class="text-sm font-medium">Eligibility rules</legend>
                    <p class="text-sm text-ink-muted">
                        An employment matches a rule when every chosen value matches its staff type,
                        Employee Category and primary Department on the date. "Any" also matches an
                        employment with no value. No rule: the version is assigned by hand only.
                    </p>
                    <div
                        v-for="(rule, i) in policyForm.eligibility_rules"
                        :key="i"
                        class="mt-2 flex flex-wrap items-end gap-2"
                    >
                        <label class="text-sm"
                            >Staff type
                            <select v-model="rule.staff_type" class="input ml-1 w-auto">
                                <option value="">Any</option>
                                <option v-for="o in staffTypes" :key="o.value" :value="o.value">
                                    {{ o.label }}
                                </option>
                            </select>
                        </label>
                        <label class="text-sm"
                            >Employee Category
                            <select v-model="rule.employee_category_id" class="input ml-1 w-auto">
                                <option value="">Any</option>
                                <option v-for="c in employeeCategories" :key="c.id" :value="c.id">
                                    {{ c.name }}
                                </option>
                            </select>
                        </label>
                        <label class="text-sm"
                            >Department
                            <select v-model="rule.department_id" class="input ml-1 w-auto">
                                <option value="">Any</option>
                                <option v-for="d in departments" :key="d.id" :value="d.id">
                                    {{ d.name }}
                                </option>
                            </select>
                        </label>
                        <button type="button" class="underline" @click="removeRule(i)">
                            Remove rule {{ i + 1 }}
                        </button>
                    </div>
                    <button type="button" class="mt-2 underline" @click="addRule">
                        Add an eligibility rule
                    </button>
                </fieldset>
                <div class="flex flex-wrap items-end gap-3 border-t border-line pt-2">
                    <label class="text-sm"
                        >Preview on
                        <input v-model="previewDate" type="date" class="input ml-1 w-auto" />
                    </label>
                    <button
                        type="button"
                        class="underline"
                        :disabled="previewing || policyForm.leave_type_id === ''"
                        @click="previewPolicy"
                    >
                        {{ previewing ? 'Previewing…' : 'Preview who this version applies to' }}
                    </button>
                    <button type="submit" class="underline" :disabled="policyForm.processing">
                        Save version
                    </button>
                </div>
                <p v-if="previewError" role="alert" class="text-sm text-danger">
                    {{ previewError }}
                </p>
                <div v-if="preview" class="text-sm" aria-live="polite">
                    <p
                        v-if="preview.overlappingPolicyId"
                        role="alert"
                        class="rounded-xs border border-accent-300 bg-accent-100 p-2 text-ink"
                    >
                        These rules overlap another current version of this leave type; saving will
                        be refused. Replace that version, or narrow the rules.
                    </p>
                    <p class="mt-1">
                        On {{ preview.date }}:
                        <span v-for="[outcome, count] in previewOutcomes" :key="outcome">
                            {{ outcomeLabels[outcome] ?? outcome }}: {{ count }};
                        </span>
                        <template v-if="preview.rows.length === 0"
                            >no employment spans that date.</template
                        >
                    </p>
                    <p class="mt-1">
                        {{ draftRows.length }} employment(s) would resolve to this version (each
                        later granted {{ units(policyForm.annual_allocation_units) }} by the annual
                        allocation run).
                    </p>
                    <ul v-if="draftRows.length > 0" class="ml-4 list-disc">
                        <li v-for="r in draftRows.slice(0, 50)" :key="r.employmentRecordId">
                            {{ r.fullName ?? 'Unnamed' }} ({{ r.employeeNumber ?? '—' }}) ·
                            {{ outcomeLabels[r.outcome] }}
                        </li>
                    </ul>
                </div>
            </form>
        </section>

        <section class="mt-8">
            <h2 class="text-sm font-semibold">Staff working week</h2>
            <form
                class="mt-2 flex flex-wrap gap-3"
                @submit.prevent="post(weekForm, '/app/leave/calendar/weekdays')"
            >
                <label v-for="(day, i) in weekdays" :key="day" class="text-sm">
                    {{ day }}
                    <select
                        v-model="weekForm.weekdays[i + 1]"
                        :disabled="!canConfigure"
                        class="input ml-1 w-auto"
                    >
                        <option value="full">Full day</option>
                        <option value="first_half">Morning only</option>
                        <option value="off">Off</option>
                    </select>
                </label>
                <button v-if="canConfigure" type="submit" class="underline">Save week</button>
            </form>

            <h3 class="mt-4 text-sm font-semibold">Staff holidays</h3>
            <ul class="mt-2 text-sm">
                <li v-for="h in calendar.holidays" :key="h.id">
                    {{ h.date }} · {{ portionLabels[h.portion] }} · {{ h.name }}
                    <button
                        v-if="canConfigure"
                        type="button"
                        class="ml-2 underline"
                        @click="removeHoliday(h.id)"
                    >
                        Remove
                    </button>
                </li>
            </ul>
            <form
                v-if="canConfigure"
                class="mt-2 flex flex-wrap gap-2"
                @submit.prevent="post(holidayForm, '/app/leave/calendar/holidays')"
            >
                <input
                    v-model="holidayForm.date"
                    type="date"
                    aria-label="Holiday date"
                    class="input w-auto"
                />
                <select
                    v-model="holidayForm.portion"
                    aria-label="Holiday portion"
                    class="input w-auto"
                >
                    <option v-for="p in portions" :key="p" :value="p">
                        {{ portionLabels[p] }}
                    </option>
                </select>
                <input
                    v-model="holidayForm.name"
                    aria-label="Holiday name"
                    placeholder="Name"
                    class="input w-48"
                />
                <button type="submit" class="underline">Add holiday</button>
            </form>
        </section>
    </main>
</template>
