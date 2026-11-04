<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import EmptyState from '../EmptyState.vue';
import StatusBadge from '../StatusBadge.vue';
import { sourceLabels, statusFilterLabels, type TaskPage } from '../../tasks';

/**
 * HR.13 — one server-filtered, server-paged list of staff tasks. The page
 * passes the URL it came from; filtering and paging reload it.
 */
const props = withDefaults(
    defineProps<{
        result: TaskPage;
        url: string;
        showAssignee?: boolean;
        showAttribution?: boolean;
        extraQuery?: Record<string, string | null | undefined>;
        caption: string;
    }>(),
    { showAssignee: true, showAttribution: false, extraQuery: () => ({}) },
);

const status = ref(props.result.status);
const pages = computed(() => Math.max(1, Math.ceil(props.result.total / props.result.perPage)));

function load(page = 1): void {
    router.get(
        props.url,
        { ...props.extraQuery, status: status.value, page: page > 1 ? page : undefined },
        { preserveState: true, replace: true },
    );
}
</script>

<template>
    <div>
        <form class="flex flex-wrap items-end gap-3" @submit.prevent="load()">
            <div>
                <label class="field-label" :for="`task-status-${caption}`">Show</label>
                <select :id="`task-status-${caption}`" v-model="status" class="input mt-1 w-auto">
                    <option v-for="(label, key) in statusFilterLabels" :key="key" :value="key">
                        {{ label }}
                    </option>
                </select>
            </div>
            <button type="submit" class="btn btn-secondary">Apply</button>
        </form>

        <EmptyState
            v-if="result.rows.length === 0"
            class="mt-6"
            title="No tasks here"
            description="Nothing matches this view."
        />
        <div v-else class="mt-6 overflow-x-auto">
            <table class="data-table w-full">
                <caption class="sr-only">
                    {{
                        caption
                    }}
                </caption>
                <thead>
                    <tr>
                        <th scope="col">Task</th>
                        <th v-if="showAssignee" scope="col">Assigned to</th>
                        <th scope="col">Due</th>
                        <th scope="col">Status</th>
                        <th scope="col">Source</th>
                        <th v-if="showAttribution" scope="col">Department · position</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in result.rows" :key="row.id">
                        <td>
                            <a class="underline" :href="`/app/hr/tasks/${row.id}`">{{
                                row.title
                            }}</a>
                            <span v-if="row.category" class="ml-1 text-ink-muted">{{
                                row.category
                            }}</span>
                            <span
                                v-if="row.section || row.subjectOffering"
                                class="block text-ink-muted"
                            >
                                {{ [row.section, row.subjectOffering].filter(Boolean).join(' · ') }}
                            </span>
                        </td>
                        <td v-if="showAssignee">{{ row.assignee ?? '—' }}</td>
                        <td>
                            {{ row.dueOn }}
                            <span v-if="row.overdue" class="tag tag-attention ml-1"
                                ><span aria-hidden="true">!</span> Overdue</span
                            >
                        </td>
                        <td><StatusBadge :status="row.status" /></td>
                        <td>{{ sourceLabels[row.source] ?? row.source }}</td>
                        <td v-if="showAttribution">
                            {{ [row.department, row.position].filter(Boolean).join(' · ') || '—' }}
                        </td>
                    </tr>
                </tbody>
            </table>
            <nav
                v-if="pages > 1"
                class="mt-4 flex items-center gap-3 text-sm"
                :aria-label="`${caption} pages`"
            >
                <button
                    type="button"
                    class="btn btn-ghost"
                    :disabled="result.page <= 1"
                    @click="load(result.page - 1)"
                >
                    Previous
                </button>
                <span aria-live="polite"
                    >Page {{ result.page }} of {{ pages }} · {{ result.total }} task(s)</span
                >
                <button
                    type="button"
                    class="btn btn-ghost"
                    :disabled="result.page >= pages"
                    @click="load(result.page + 1)"
                >
                    Next
                </button>
            </nav>
        </div>
    </div>
</template>
