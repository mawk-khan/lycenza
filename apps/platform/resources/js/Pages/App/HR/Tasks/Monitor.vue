<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import TaskTable from '../../../../Components/HR/TaskTable.vue';
import type { TaskPage } from '../../../../tasks';

/**
 * HR.13 — the Staff Task Monitor. Server-filtered and paged; the counts are
 * counts of task occurrences in the filtered set (no rates: ADR 0040).
 * Department and position are each task's attribution when it was created.
 */
const props = defineProps<{
    result: TaskPage & { counts: Record<string, number> };
    filters: Record<string, string | null | undefined>;
    can: { configure: boolean; assign: boolean };
    frequencies: { value: string; label: string }[];
    staffTypes: { value: string; label: string }[];
}>();

const frequency = ref(props.filters.frequency ?? '');
const staffType = ref(props.filters.staffType ?? '');

function apply(): void {
    router.get(
        '/app/hr/tasks',
        {
            status: props.result.status,
            frequency: frequency.value || undefined,
            staffType: staffType.value || undefined,
        },
        { preserveState: true, replace: true },
    );
}

const countLabels: Record<string, string> = {
    assigned: 'To do',
    submitted: 'Awaiting review',
    returned: 'Returned',
    completed: 'Completed',
    cancelled: 'Cancelled',
    overdue: 'Overdue',
};
</script>

<template>
    <main class="mx-auto max-w-6xl p-8 font-sans text-ink">
        <a class="text-sm underline" href="/app/hr">← HR</a>
        <div class="mt-2 flex flex-wrap items-baseline justify-between gap-3">
            <h1 class="text-xl font-semibold">Staff tasks</h1>
            <div class="flex flex-wrap gap-4 text-sm">
                <a v-if="can.assign" class="underline" href="/app/hr/tasks/assign">Assign a task</a>
                <a v-if="can.assign" class="underline" href="/app/hr/tasks/assigned-by-me"
                    >Assigned by me</a
                >
                <a v-if="can.configure" class="underline" href="/app/hr/tasks/catalogue"
                    >Recurring tasks and categories</a
                >
            </div>
        </div>

        <dl class="mt-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-6">
            <div v-for="(label, key) in countLabels" :key="key" class="frame p-3">
                <dt class="text-ink-muted">{{ label }}</dt>
                <dd class="text-lg font-semibold">{{ result.counts[key] ?? 0 }}</dd>
            </div>
        </dl>

        <form class="mt-6 flex flex-wrap items-end gap-3" @submit.prevent="apply">
            <div>
                <label class="field-label" for="monitor-frequency">Recurrence</label>
                <select id="monitor-frequency" v-model="frequency" class="input mt-1 w-auto">
                    <option value="">Any (including one-off)</option>
                    <option v-for="f in frequencies" :key="f.value" :value="f.value">
                        {{ f.label }}
                    </option>
                </select>
            </div>
            <div>
                <label class="field-label" for="monitor-staff-type">Staff type</label>
                <select id="monitor-staff-type" v-model="staffType" class="input mt-1 w-auto">
                    <option value="">Any</option>
                    <option v-for="t in staffTypes" :key="t.value" :value="t.value">
                        {{ t.label }}
                    </option>
                </select>
            </div>
            <button type="submit" class="btn btn-secondary">Filter</button>
        </form>

        <TaskTable
            class="mt-6"
            :result="result"
            url="/app/hr/tasks"
            :extra-query="{ frequency: frequency || undefined, staffType: staffType || undefined }"
            show-attribution
            caption="Staff tasks"
        />
    </main>
</template>
