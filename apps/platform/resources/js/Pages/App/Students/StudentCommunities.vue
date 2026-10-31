<script setup lang="ts">
// SIS 11.8 (OD-SIS-5A, OD-SIS-5B): one Student's Clubs and Activities -- the
// current memberships, Club responsibilities and the full history, kept
// apart per type. Joining starts today; leaving ends yesterday (School-local),
// so every period stays on record. Each end carries the version the page
// showed, so a change made meanwhile is refused instead of overwritten.
// Nothing here is ever a permission.
import { useForm } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import ConfirmDialog from '../../../Components/ConfirmDialog.vue';
import { formatCalendarDate } from '../../../datetime';
import { useConfirmation } from '../../../useConfirmation';

interface Option {
    value: string;
    label: string;
}

interface Role {
    id: string;
    label: string;
    from: string;
    to: string | null;
    sameDay: boolean;
    current: boolean;
    version: number;
}

interface Current {
    membershipId: string;
    name: string;
    category: string | null;
    since: string;
    version: number;
    responsibilities: Role[];
}

interface HistoryRow {
    key: string;
    name: string;
    from: string;
    to: string | null;
    sameDay: boolean;
    started: string;
    ended: string | null;
    responsibilities: Role[];
}

interface Section {
    current: Current[];
    history: HistoryRow[];
}

const props = defineProps<{
    student: { id: string; name: string; studentNumber: string; status: string };
    house: { current: string | null; since: string | null; url: string } | null;
    clubs: Section;
    activities: Section;
    options: {
        clubs: Option[];
        activities: Option[];
        clubEndReasons: Option[];
        activityEndReasons: Option[];
        clubResponsibilities: Option[];
    };
    canAssign: boolean;
}>();

const base = `/app/students/${props.student.id}/communities`;
const active = computed(() => props.student.status === 'active');
const { confirmDialog, confirmation, confirmAction, onConfirm, onClose } = useConfirmation();

const joinClub = useForm({ group_id: '' });
const joinActivity = useForm({ group_id: '' });
function join(form: typeof joinClub): void {
    form.post(base, { preserveScroll: true, onSuccess: () => form.reset() });
}

// One end form per row: its reason and the version the page showed.
const reasons = reactive<Record<string, string>>({});
const endForm = useForm({ reason: '', version: 0 });
async function end(row: Current, kind: 'club' | 'activity'): Promise<void> {
    const reason = reasons[row.membershipId];
    if (!reason) {
        endForm.setError('reason', 'Choose why this ends.');
        return;
    }
    if (
        !(await confirmAction({
            title: kind === 'club' ? `Leave ${row.name}?` : `End participation in ${row.name}?`,
            body: `${props.student.name} ${kind === 'club' ? 'leaves the Club' : 'stops taking part'} from yesterday; any Club responsibility ends with it. The history stays on record.`,
            confirmLabel: kind === 'club' ? 'Leave Club' : 'End participation',
        }))
    ) {
        return;
    }
    endForm.reason = reason;
    endForm.version = row.version;
    endForm.post(`${base}/${row.membershipId}/end`, { preserveScroll: true });
}

const role = reactive<Record<string, string>>({});
const giveForm = useForm({ responsibility: '' });
function give(row: Current): void {
    giveForm.responsibility = role[row.membershipId] ?? '';
    giveForm.post(`${base}/${row.membershipId}/responsibilities`, { preserveScroll: true });
}

const endRole = useForm({ version: 0 });
async function endResponsibility(r: Role, club: string): Promise<void> {
    if (
        !(await confirmAction({
            title: `End ${r.label}?`,
            body: `${props.student.name} stops holding ${r.label} in ${club}. The history stays on record.`,
            confirmLabel: 'End responsibility',
        }))
    ) {
        return;
    }
    endRole.version = r.version;
    endRole.post(`${base}/responsibilities/${r.id}/end`, { preserveScroll: true });
}

function period(from: string, to: string | null, sameDay: boolean): string {
    if (sameDay) {
        return `${formatCalendarDate(from)} (ended the same day)`;
    }

    return to === null
        ? `Since ${formatCalendarDate(from)}`
        : `${formatCalendarDate(from)} – ${formatCalendarDate(to)}`;
}
</script>

<template>
    <main class="mx-auto max-w-4xl px-4 py-6 font-sans text-slate-900 sm:p-8">
        <a class="text-sm underline" :href="`/app/students/${student.id}`">← {{ student.name }}</a>
        <h1 class="mt-2 text-xl font-semibold">Clubs and activities</h1>
        <p class="mt-1 text-sm text-slate-600">
            {{ student.name }} · {{ student.studentNumber }}
            <template v-if="!active">
                · {{ student.status === 'inactive' ? 'Inactive' : student.status }}</template
            >
        </p>
        <p class="mt-2 text-xs text-slate-500">
            Clubs, activities and their responsibilities are descriptive. They never grant access to
            anything.
        </p>
        <p v-if="canAssign && !active" class="mt-3 text-sm text-slate-700">
            Only an active Student can join a Club or Activity, or take a responsibility. Current
            memberships can still be ended, and the history stays visible.
        </p>

        <section
            v-if="house"
            class="mt-6 rounded border border-slate-200 p-4"
            aria-labelledby="house-heading"
        >
            <h2 id="house-heading" class="text-base font-semibold">House</h2>
            <p class="mt-2 text-sm">
                <template v-if="house.current">
                    {{ house.current }}
                    <span v-if="house.since" class="text-slate-600">
                        · since {{ formatCalendarDate(house.since) }}</span
                    >
                </template>
                <template v-else>No current House.</template>
                <a class="ml-2 underline" :href="house.url">House and history</a>
            </p>
        </section>

        <!-- Clubs -->
        <section class="mt-6 rounded border border-slate-200 p-4" aria-labelledby="clubs-heading">
            <h2 id="clubs-heading" class="text-base font-semibold">Clubs</h2>
            <p v-if="clubs.current.length === 0" class="mt-2 text-sm text-slate-600">
                Not currently in a Club.
            </p>
            <ul v-else class="mt-2 divide-y divide-slate-200">
                <li v-for="row in clubs.current" :key="row.membershipId" class="py-3">
                    <p class="text-sm">
                        <span class="font-medium">{{ row.name }}</span>
                        <span class="text-slate-600">
                            · since {{ formatCalendarDate(row.since) }}</span
                        >
                    </p>
                    <ul v-if="row.responsibilities.length > 0" class="mt-1 text-sm">
                        <li v-for="r in row.responsibilities" :key="r.id">
                            {{ r.label }}
                            <span class="text-slate-600"
                                >since {{ formatCalendarDate(r.from) }}</span
                            >
                            <button
                                v-if="canAssign"
                                type="button"
                                class="ml-2 underline"
                                @click="endResponsibility(r, row.name)"
                            >
                                End<span class="sr-only"> {{ r.label }} in {{ row.name }}</span>
                            </button>
                        </li>
                    </ul>
                    <div v-if="canAssign" class="mt-2 flex flex-wrap items-end gap-3">
                        <div v-if="active">
                            <label
                                class="block text-xs text-slate-700"
                                :for="`role-${row.membershipId}`"
                                >Responsibility in {{ row.name }}</label
                            >
                            <select
                                :id="`role-${row.membershipId}`"
                                v-model="role[row.membershipId]"
                                class="mt-1 rounded border border-slate-300 px-2 py-1.5 text-sm"
                            >
                                <option :value="undefined" disabled>Choose</option>
                                <option
                                    v-for="o in options.clubResponsibilities"
                                    :key="o.value"
                                    :value="o.value"
                                >
                                    {{ o.label }}
                                </option>
                            </select>
                        </div>
                        <button
                            v-if="active"
                            type="button"
                            class="rounded border border-slate-300 px-3 py-1.5 text-sm"
                            :disabled="giveForm.processing"
                            @click="give(row)"
                        >
                            Give responsibility<span class="sr-only"> in {{ row.name }}</span>
                        </button>
                        <div>
                            <label
                                class="block text-xs text-slate-700"
                                :for="`end-${row.membershipId}`"
                                >Reason for leaving {{ row.name }}</label
                            >
                            <select
                                :id="`end-${row.membershipId}`"
                                v-model="reasons[row.membershipId]"
                                class="mt-1 rounded border border-slate-300 px-2 py-1.5 text-sm"
                            >
                                <option :value="undefined" disabled>Choose a reason</option>
                                <option
                                    v-for="o in options.clubEndReasons"
                                    :key="o.value"
                                    :value="o.value"
                                >
                                    {{ o.label }}
                                </option>
                            </select>
                        </div>
                        <button
                            type="button"
                            class="rounded border border-red-700 px-3 py-1.5 text-sm text-red-800"
                            :disabled="endForm.processing"
                            @click="end(row, 'club')"
                        >
                            Leave Club<span class="sr-only"> {{ row.name }}</span>
                        </button>
                    </div>
                </li>
            </ul>
            <p v-if="giveForm.errors.responsibility" class="mt-1 text-sm text-red-700" role="alert">
                {{ giveForm.errors.responsibility }}
            </p>
            <form
                v-if="canAssign && active"
                class="mt-3 flex flex-wrap items-end gap-3"
                @submit.prevent="join(joinClub)"
            >
                <div>
                    <label class="block text-sm text-slate-700" for="join-club">Join a Club</label>
                    <select
                        id="join-club"
                        v-model="joinClub.group_id"
                        required
                        class="mt-1 rounded border border-slate-300 px-3 py-2 text-sm"
                    >
                        <option value="" disabled>Choose a Club</option>
                        <option v-for="o in options.clubs" :key="o.value" :value="o.value">
                            {{ o.label }}
                        </option>
                    </select>
                </div>
                <button
                    type="submit"
                    class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                    :disabled="joinClub.processing || options.clubs.length === 0"
                >
                    Join Club
                </button>
                <p v-if="options.clubs.length === 0" class="w-full text-xs text-slate-500">
                    No other active Club to join.
                </p>
            </form>
        </section>

        <!-- Activities -->
        <section
            class="mt-6 rounded border border-slate-200 p-4"
            aria-labelledby="activities-heading"
        >
            <h2 id="activities-heading" class="text-base font-semibold">Activities</h2>
            <p v-if="activities.current.length === 0" class="mt-2 text-sm text-slate-600">
                Not currently taking part in an Activity.
            </p>
            <ul v-else class="mt-2 divide-y divide-slate-200">
                <li v-for="row in activities.current" :key="row.membershipId" class="py-3">
                    <p class="text-sm">
                        <span class="font-medium">{{ row.name }}</span>
                        <span v-if="row.category" class="text-slate-600">
                            · {{ row.category }}</span
                        >
                        <span class="text-slate-600">
                            · since {{ formatCalendarDate(row.since) }}</span
                        >
                    </p>
                    <div v-if="canAssign" class="mt-2 flex flex-wrap items-end gap-3">
                        <div>
                            <label
                                class="block text-xs text-slate-700"
                                :for="`end-${row.membershipId}`"
                                >Reason for ending {{ row.name }}</label
                            >
                            <select
                                :id="`end-${row.membershipId}`"
                                v-model="reasons[row.membershipId]"
                                class="mt-1 rounded border border-slate-300 px-2 py-1.5 text-sm"
                            >
                                <option :value="undefined" disabled>Choose a reason</option>
                                <option
                                    v-for="o in options.activityEndReasons"
                                    :key="o.value"
                                    :value="o.value"
                                >
                                    {{ o.label }}
                                </option>
                            </select>
                        </div>
                        <button
                            type="button"
                            class="rounded border border-red-700 px-3 py-1.5 text-sm text-red-800"
                            :disabled="endForm.processing"
                            @click="end(row, 'activity')"
                        >
                            End participation<span class="sr-only"> in {{ row.name }}</span>
                        </button>
                    </div>
                </li>
            </ul>
            <p v-if="endForm.errors.reason" class="mt-1 text-sm text-red-700" role="alert">
                {{ endForm.errors.reason }}
            </p>
            <form
                v-if="canAssign && active"
                class="mt-3 flex flex-wrap items-end gap-3"
                @submit.prevent="join(joinActivity)"
            >
                <div>
                    <label class="block text-sm text-slate-700" for="join-activity"
                        >Add participation</label
                    >
                    <select
                        id="join-activity"
                        v-model="joinActivity.group_id"
                        required
                        class="mt-1 rounded border border-slate-300 px-3 py-2 text-sm"
                    >
                        <option value="" disabled>Choose an Activity</option>
                        <option v-for="o in options.activities" :key="o.value" :value="o.value">
                            {{ o.label }}
                        </option>
                    </select>
                </div>
                <button
                    type="submit"
                    class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                    :disabled="joinActivity.processing || options.activities.length === 0"
                >
                    Add participation
                </button>
                <p v-if="options.activities.length === 0" class="w-full text-xs text-slate-500">
                    No other active Activity.
                </p>
            </form>
        </section>

        <!-- History, one table per type -->
        <section
            v-for="part in [
                {
                    key: 'clubs',
                    title: 'Club history',
                    rows: clubs.history,
                    col: 'Club',
                    empty: 'Never in a Club.',
                },
                {
                    key: 'activities',
                    title: 'Activity history',
                    rows: activities.history,
                    col: 'Activity',
                    empty: 'Never in an Activity.',
                },
            ]"
            :key="part.key"
            class="mt-8"
            :aria-labelledby="`${part.key}-history-heading`"
        >
            <h2 :id="`${part.key}-history-heading`" class="text-base font-semibold">
                {{ part.title }}
            </h2>
            <p v-if="part.rows.length === 0" class="mt-2 text-sm text-slate-600">
                {{ part.empty }}
            </p>
            <div v-else class="relative mt-3 overflow-x-auto rounded border border-slate-200">
                <table class="w-full min-w-[34rem] divide-y divide-slate-200 text-sm">
                    <caption class="sr-only">
                        {{
                            part.title
                        }}, current first
                    </caption>
                    <thead class="bg-slate-50 text-left text-slate-600">
                        <tr>
                            <th scope="col" class="px-3 py-2 font-medium">{{ part.col }}</th>
                            <th scope="col" class="px-3 py-2 font-medium">Period</th>
                            <th scope="col" class="px-3 py-2 font-medium">Ended because</th>
                            <th
                                v-if="part.key === 'clubs'"
                                scope="col"
                                class="px-3 py-2 font-medium"
                            >
                                Responsibilities
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        <tr v-for="h in part.rows" :key="h.key">
                            <td class="px-3 py-2">{{ h.name }}</td>
                            <td class="px-3 py-2">{{ period(h.from, h.to, h.sameDay) }}</td>
                            <td class="px-3 py-2">
                                {{ h.ended ?? (h.to === null ? 'Current' : '—') }}
                            </td>
                            <td v-if="part.key === 'clubs'" class="px-3 py-2">
                                <template v-if="h.responsibilities.length === 0">—</template>
                                <span v-for="(r, i) in h.responsibilities" :key="r.id"
                                    >{{ i > 0 ? '; ' : '' }}{{ r.label }} ({{
                                        period(r.from, r.to, r.sameDay)
                                    }})</span
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
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
