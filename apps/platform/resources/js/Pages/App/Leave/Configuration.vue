<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { portionLabels, typeName, type LeaveTypeRow } from '../../../leave';
import { useLeaveError } from '../../../leave';

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
    policies: {
        id: string;
        leaveTypeId: string;
        name: string;
        annualAllocationUnits: number;
        carryForwardAllowed: boolean;
        carryForwardCapUnits: number | null;
        carryForwardExpiryDays: number | null;
        status: string;
    }[];
    calendar: {
        weeklyPattern: Record<string, string>;
        holidays: { id: string; date: string; portion: string; name: string }[];
    };
    portions: string[];
    canConfigure: boolean;
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
const policyForm = useForm({
    leave_type_id: '',
    name: '',
    annual_allocation_units: 0,
    carry_forward_allowed: false,
    carry_forward_cap_units: null as number | null,
    carry_forward_expiry_days: null as number | null,
    supersedes_policy_id: '',
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

function submitPolicy(): void {
    policyForm
        .transform((d) => ({ ...d, supersedes_policy_id: d.supersedes_policy_id || null }))
        .post('/app/leave/policies', { preserveScroll: true, onSuccess: () => policyForm.reset() });
}

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
    <main class="mx-auto max-w-6xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/leave/requests">← Leave requests</a>
        <h1 class="mt-2 text-xl font-semibold">Leave configuration</h1>
        <p
            v-if="leaveError"
            role="alert"
            class="mt-3 rounded border border-red-300 bg-red-50 p-3 text-sm text-red-800"
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
                    class="rounded border border-slate-300 px-2 py-1 text-sm"
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
                <span class="text-sm text-slate-600">Change for future years:</span>
                <select
                    v-model.number="changeForm.start_month"
                    aria-label="New start month"
                    class="rounded border border-slate-300 px-2 py-1 text-sm"
                >
                    <option v-for="(m, i) in months" :key="m" :value="i + 1">{{ m }}</option>
                </select>
                <input
                    v-model="changeForm.effective_from"
                    type="date"
                    aria-label="Effective from"
                    class="rounded border border-slate-300 px-2 py-1 text-sm"
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
                    class="rounded border border-slate-300 px-2 py-1 text-sm"
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
                    class="w-24 rounded border border-slate-300 px-2 py-1 text-sm"
                />
                <input
                    v-model="typeForm.name"
                    aria-label="Name"
                    placeholder="Name"
                    class="w-48 rounded border border-slate-300 px-2 py-1 text-sm"
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
            <p v-if="typeForm.errors.code" class="mt-1 text-sm text-red-700">
                {{ typeForm.errors.code }}
            </p>
        </section>

        <section class="mt-8">
            <h2 class="text-sm font-semibold">Policies</h2>
            <ul class="mt-2 text-sm">
                <li v-for="p in policies" :key="p.id">
                    {{ typeName(types, p.leaveTypeId) }} · {{ p.name }} ·
                    {{ p.annualAllocationUnits }} units a year ·
                    {{
                        p.carryForwardAllowed
                            ? `carry up to ${p.carryForwardCapUnits}`
                            : 'no carry-forward'
                    }}
                    · {{ p.status }}
                    <button
                        v-if="canConfigure && p.status === 'active'"
                        type="button"
                        class="ml-2 underline"
                        @click="retirePolicy(p.id)"
                    >
                        Retire
                    </button>
                </li>
            </ul>
            <form
                v-if="canConfigure"
                class="mt-2 flex flex-wrap items-center gap-2"
                @submit.prevent="submitPolicy"
            >
                <select
                    v-model="policyForm.leave_type_id"
                    aria-label="Leave type"
                    class="rounded border border-slate-300 px-2 py-1 text-sm"
                >
                    <option value="">Leave type</option>
                    <option
                        v-for="t in types.filter((x) => x.tracksBalance)"
                        :key="t.id"
                        :value="t.id"
                    >
                        {{ t.name }}
                    </option>
                </select>
                <input
                    v-model="policyForm.name"
                    aria-label="Policy name"
                    placeholder="Policy name"
                    class="w-40 rounded border border-slate-300 px-2 py-1 text-sm"
                />
                <input
                    v-model.number="policyForm.annual_allocation_units"
                    type="number"
                    min="0"
                    aria-label="Annual units"
                    class="w-24 rounded border border-slate-300 px-2 py-1 text-sm"
                />
                <label class="text-sm"
                    ><input v-model="policyForm.carry_forward_allowed" type="checkbox" /> Carry
                    forward</label
                >
                <input
                    v-model.number="policyForm.carry_forward_cap_units"
                    type="number"
                    min="1"
                    aria-label="Carry cap (units)"
                    placeholder="Cap"
                    class="w-20 rounded border border-slate-300 px-2 py-1 text-sm"
                />
                <input
                    v-model.number="policyForm.carry_forward_expiry_days"
                    type="number"
                    min="1"
                    aria-label="Carried expiry (days)"
                    placeholder="Expiry days"
                    class="w-28 rounded border border-slate-300 px-2 py-1 text-sm"
                />
                <select
                    v-model="policyForm.supersedes_policy_id"
                    aria-label="Supersedes"
                    class="rounded border border-slate-300 px-2 py-1 text-sm"
                >
                    <option value="">New policy</option>
                    <option
                        v-for="p in policies.filter((x) => x.status === 'active')"
                        :key="p.id"
                        :value="p.id"
                    >
                        Replaces {{ p.name }}
                    </option>
                </select>
                <button type="submit" class="underline">Add policy</button>
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
                        class="ml-1 rounded border border-slate-300 px-2 py-1 text-sm"
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
                    class="rounded border border-slate-300 px-2 py-1 text-sm"
                />
                <select
                    v-model="holidayForm.portion"
                    aria-label="Holiday portion"
                    class="rounded border border-slate-300 px-2 py-1 text-sm"
                >
                    <option v-for="p in portions" :key="p" :value="p">
                        {{ portionLabels[p] }}
                    </option>
                </select>
                <input
                    v-model="holidayForm.name"
                    aria-label="Holiday name"
                    placeholder="Name"
                    class="w-48 rounded border border-slate-300 px-2 py-1 text-sm"
                />
                <button type="submit" class="underline">Add holiday</button>
            </form>
        </section>
    </main>
</template>
