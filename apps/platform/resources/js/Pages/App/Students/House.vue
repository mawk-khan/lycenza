<script setup lang="ts">
// SIS 11.7 (OD-SIS-4): one Student's House -- the current House, the full
// membership history and House responsibilities. Every change starts today
// (School-local) and closes the previous row the day before; history is
// never rewritten. House and responsibility are descriptive: they never
// change what anyone can see or do. Each write carries the version the page
// showed, so a change made meanwhile is refused instead of overwritten.
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import ConfirmDialog from '../../../Components/ConfirmDialog.vue';
import EmptyState from '../../../Components/EmptyState.vue';
import { formatCalendarDate } from '../../../datetime';
import { useConfirmation } from '../../../useConfirmation';

interface Option {
    value: string;
    label: string;
}

interface HistoryRow {
    key: string;
    house: string;
    from: string;
    to: string | null;
    sameDay: boolean;
    started: string;
    ended: string | null;
}

interface Responsibility {
    id: string;
    label: string;
    house: string;
    from: string;
    to: string | null;
    sameDay: boolean;
    current: boolean;
    version: number;
}

const props = defineProps<{
    student: { id: string; name: string; studentNumber: string; status: string };
    current: { house: string; houseId: string; since: string } | null;
    history: HistoryRow[];
    responsibilities: Responsibility[];
    version: string;
    options: {
        houses: Option[];
        changeReasons: Option[];
        endReasons: Option[];
        responsibilities: Option[];
    };
    canAssign: boolean;
}>();

const base = `/app/students/${props.student.id}/house`;
const active = computed(() => props.student.status === 'active');
const choices = computed(() =>
    props.options.houses.filter((h) => h.value !== props.current?.houseId),
);
const currentResponsibilities = computed(() => props.responsibilities.filter((r) => r.current));

const assign = useForm({ house_id: '', reason: '', version: props.version });
function submitAssign(): void {
    assign
        .transform((data) => ({
            ...data,
            reason: props.current ? data.reason : null,
            version: props.version,
        }))
        .post(base, { preserveScroll: true, onSuccess: () => assign.reset('house_id', 'reason') });
}

const { confirmDialog, confirmation, confirmAction, onConfirm, onClose } = useConfirmation();

const end = useForm({ reason: '', version: props.version });
async function submitEnd(): Promise<void> {
    if (
        !(await confirmAction({
            title: `End ${props.student.name}'s House membership?`,
            body: `${props.current?.house ?? 'The House'} membership ends yesterday and any current House responsibility ends with it. The history stays on record.`,
            confirmLabel: 'End membership',
        }))
    ) {
        return;
    }
    end.transform((data) => ({ ...data, version: props.version })).post(`${base}/end`, {
        preserveScroll: true,
        onSuccess: () => end.reset('reason'),
    });
}

const give = useForm({ responsibility: '', version: props.version });
function submitResponsibility(): void {
    give.transform((data) => ({ ...data, version: props.version })).post(
        `${base}/responsibilities`,
        {
            preserveScroll: true,
            onSuccess: () => give.reset('responsibility'),
        },
    );
}

const endOne = useForm({ version: 0 });
async function endResponsibility(r: Responsibility): Promise<void> {
    if (
        !(await confirmAction({
            title: `End ${r.label}?`,
            body: `${props.student.name} stops holding ${r.label} in ${r.house}. The history stays on record.`,
            confirmLabel: 'End responsibility',
        }))
    ) {
        return;
    }
    endOne.version = r.version;
    endOne.post(`${base}/responsibilities/${r.id}/end`, { preserveScroll: true });
}

function period(from: string, to: string | null, sameDay: boolean): string {
    if (sameDay) {
        return `${formatCalendarDate(from)} (changed the same day)`;
    }

    return to === null
        ? `Since ${formatCalendarDate(from)}`
        : `${formatCalendarDate(from)} – ${formatCalendarDate(to)}`;
}
</script>

<template>
    <main class="mx-auto max-w-4xl px-4 py-6 font-sans text-slate-900 sm:p-8">
        <a class="text-sm underline" :href="`/app/students/${student.id}`">← {{ student.name }}</a>
        <h1 class="mt-2 text-xl font-semibold">House</h1>
        <p class="mt-1 text-sm text-slate-600">
            {{ student.name }} · {{ student.studentNumber }}
            <template v-if="!active">
                · {{ student.status === 'inactive' ? 'Inactive' : student.status }}</template
            >
        </p>

        <section class="mt-6 rounded border border-slate-200 p-4" aria-labelledby="current-heading">
            <h2 id="current-heading" class="text-base font-semibold">Current House</h2>
            <p v-if="current" class="mt-2 text-sm">
                <span class="font-medium">{{ current.house }}</span>
                <span class="text-slate-600"> · since {{ formatCalendarDate(current.since) }}</span>
            </p>
            <p v-else class="mt-2 text-sm text-slate-600">No current House.</p>
            <ul v-if="currentResponsibilities.length > 0" class="mt-2 text-sm">
                <li v-for="r in currentResponsibilities" :key="r.id">
                    {{ r.label }}
                    <span class="text-slate-600">since {{ formatCalendarDate(r.from) }}</span>
                    <button
                        v-if="canAssign"
                        type="button"
                        class="ml-2 underline"
                        @click="endResponsibility(r)"
                    >
                        End<span class="sr-only"> {{ r.label }}</span>
                    </button>
                </li>
            </ul>
            <p class="mt-3 text-xs text-slate-500">
                A House and its responsibilities are descriptive. They never grant access to
                anything.
            </p>
        </section>

        <template v-if="canAssign">
            <p v-if="!active" class="mt-6 text-sm text-slate-700">
                Only an active Student can be given a House or a responsibility. Their history stays
                visible below.
            </p>
            <section
                v-else
                class="mt-6 rounded border border-slate-200 p-4"
                aria-labelledby="assign-heading"
            >
                <h2 id="assign-heading" class="text-base font-semibold">
                    {{ current ? 'Change House' : 'Assign a House' }}
                </h2>
                <p v-if="choices.length === 0" class="mt-2 text-sm text-slate-600">
                    There is no other active House. Create one in
                    <a class="underline" href="/app/students/houses">Houses</a>.
                </p>
                <form v-else class="mt-3 grid gap-3 sm:grid-cols-2" @submit.prevent="submitAssign">
                    <div>
                        <label class="block text-sm text-slate-700" for="assign-house">House</label>
                        <select
                            id="assign-house"
                            v-model="assign.house_id"
                            required
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                            :aria-invalid="!!assign.errors.house_id"
                            :aria-describedby="
                                assign.errors.house_id ? 'assign-house-error' : undefined
                            "
                        >
                            <option value="" disabled>Choose a House</option>
                            <option v-for="h in choices" :key="h.value" :value="h.value">
                                {{ h.label }}
                            </option>
                        </select>
                        <p
                            v-if="assign.errors.house_id"
                            id="assign-house-error"
                            class="mt-1 text-sm text-red-700"
                        >
                            {{ assign.errors.house_id }}
                        </p>
                    </div>
                    <div v-if="current">
                        <label class="block text-sm text-slate-700" for="assign-reason"
                            >Reason for the change</label
                        >
                        <select
                            id="assign-reason"
                            v-model="assign.reason"
                            required
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                            :aria-invalid="!!assign.errors.reason"
                            :aria-describedby="
                                assign.errors.reason ? 'assign-reason-error' : undefined
                            "
                        >
                            <option value="" disabled>Choose a reason</option>
                            <option
                                v-for="r in options.changeReasons"
                                :key="r.value"
                                :value="r.value"
                            >
                                {{ r.label }}
                            </option>
                        </select>
                        <p
                            v-if="assign.errors.reason"
                            id="assign-reason-error"
                            class="mt-1 text-sm text-red-700"
                        >
                            {{ assign.errors.reason }}
                        </p>
                    </div>
                    <p class="text-xs text-slate-500 sm:col-span-2">
                        The new House starts today<template v-if="current"
                            >; {{ current.house }} ends yesterday, with any responsibility held
                            there</template
                        >.
                    </p>
                    <div class="sm:col-span-2">
                        <button
                            type="submit"
                            class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                            :disabled="assign.processing"
                        >
                            {{ current ? 'Change House' : 'Assign House' }}
                        </button>
                    </div>
                </form>
            </section>

            <section
                v-if="current && active"
                class="mt-6 rounded border border-slate-200 p-4"
                aria-labelledby="give-heading"
            >
                <h2 id="give-heading" class="text-base font-semibold">Give a responsibility</h2>
                <form
                    class="mt-3 flex flex-wrap items-end gap-3"
                    @submit.prevent="submitResponsibility"
                >
                    <div>
                        <label class="block text-sm text-slate-700" for="give-responsibility"
                            >Responsibility in {{ current.house }}</label
                        >
                        <select
                            id="give-responsibility"
                            v-model="give.responsibility"
                            required
                            class="mt-1 rounded border border-slate-300 px-3 py-2 text-sm"
                            :aria-invalid="!!give.errors.responsibility"
                            :aria-describedby="
                                give.errors.responsibility
                                    ? 'give-responsibility-error'
                                    : 'give-responsibility-help'
                            "
                        >
                            <option value="" disabled>Choose</option>
                            <option
                                v-for="o in options.responsibilities"
                                :key="o.value"
                                :value="o.value"
                            >
                                {{ o.label }}
                            </option>
                        </select>
                    </div>
                    <button
                        type="submit"
                        class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                        :disabled="give.processing"
                    >
                        Give responsibility
                    </button>
                    <p id="give-responsibility-help" class="w-full text-xs text-slate-500">
                        A House has one House Captain and one Vice Captain at a time; it can have
                        several Prefects.
                    </p>
                    <p
                        v-if="give.errors.responsibility"
                        id="give-responsibility-error"
                        class="w-full text-sm text-red-700"
                    >
                        {{ give.errors.responsibility }}
                    </p>
                </form>
            </section>

            <section
                v-if="current"
                class="mt-6 rounded border border-slate-200 p-4"
                aria-labelledby="end-heading"
            >
                <h2 id="end-heading" class="text-base font-semibold">End House membership</h2>
                <form class="mt-3 flex flex-wrap items-end gap-3" @submit.prevent="submitEnd">
                    <div>
                        <label class="block text-sm text-slate-700" for="end-reason">Reason</label>
                        <select
                            id="end-reason"
                            v-model="end.reason"
                            required
                            class="mt-1 rounded border border-slate-300 px-3 py-2 text-sm"
                            :aria-invalid="!!end.errors.reason"
                            :aria-describedby="end.errors.reason ? 'end-reason-error' : undefined"
                        >
                            <option value="" disabled>Choose a reason</option>
                            <option v-for="r in options.endReasons" :key="r.value" :value="r.value">
                                {{ r.label }}
                            </option>
                        </select>
                    </div>
                    <button
                        type="submit"
                        class="rounded border border-red-700 px-3 py-2 text-sm font-medium text-red-800 hover:bg-red-50 disabled:opacity-50"
                        :disabled="end.processing"
                    >
                        End membership
                    </button>
                    <p
                        v-if="end.errors.reason"
                        id="end-reason-error"
                        class="w-full text-sm text-red-700"
                    >
                        {{ end.errors.reason }}
                    </p>
                </form>
            </section>
        </template>

        <section class="mt-8" aria-labelledby="history-heading">
            <h2 id="history-heading" class="text-base font-semibold">House history</h2>
            <EmptyState
                v-if="history.length === 0"
                class="mt-3"
                title="No House history"
                description="This Student has never been in a House."
            />
            <div v-else class="relative mt-3 overflow-x-auto rounded border border-slate-200">
                <table class="w-full min-w-[32rem] divide-y divide-slate-200 text-sm">
                    <caption class="sr-only">
                        House membership history, newest first
                    </caption>
                    <thead class="bg-slate-50 text-left text-slate-600">
                        <tr>
                            <th scope="col" class="px-3 py-2 font-medium">House</th>
                            <th scope="col" class="px-3 py-2 font-medium">Period</th>
                            <th scope="col" class="px-3 py-2 font-medium">Started</th>
                            <th scope="col" class="px-3 py-2 font-medium">Ended</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        <tr v-for="h in history" :key="h.key">
                            <td class="px-3 py-2">{{ h.house }}</td>
                            <td class="px-3 py-2">{{ period(h.from, h.to, h.sameDay) }}</td>
                            <td class="px-3 py-2">{{ h.started }}</td>
                            <td class="px-3 py-2">
                                {{ h.ended ?? (h.to === null ? 'Current' : '—') }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section v-if="responsibilities.length > 0" class="mt-8" aria-labelledby="resp-heading">
            <h2 id="resp-heading" class="text-base font-semibold">Responsibility history</h2>
            <div class="relative mt-3 overflow-x-auto rounded border border-slate-200">
                <table class="w-full min-w-[28rem] divide-y divide-slate-200 text-sm">
                    <caption class="sr-only">
                        House responsibility history
                    </caption>
                    <thead class="bg-slate-50 text-left text-slate-600">
                        <tr>
                            <th scope="col" class="px-3 py-2 font-medium">Responsibility</th>
                            <th scope="col" class="px-3 py-2 font-medium">House</th>
                            <th scope="col" class="px-3 py-2 font-medium">Period</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        <tr v-for="r in responsibilities" :key="r.id">
                            <td class="px-3 py-2">{{ r.label }}</td>
                            <td class="px-3 py-2">{{ r.house }}</td>
                            <td class="px-3 py-2">{{ period(r.from, r.to, r.sameDay) }}</td>
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
