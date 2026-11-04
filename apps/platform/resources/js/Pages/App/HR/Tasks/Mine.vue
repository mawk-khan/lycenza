<script setup lang="ts">
import TaskTable from '../../../../Components/HR/TaskTable.vue';
import type { TaskPage } from '../../../../tasks';

/** HR.13 — My tasks (server-filtered and paged). */
defineProps<{ result: TaskPage & { reportCount?: number } }>();
</script>

<template>
    <main class="mx-auto max-w-5xl p-8 font-sans text-ink">
        <a class="text-sm underline" href="/app">← Dashboard</a>
        <h1 class="mt-2 text-xl font-semibold">My tasks</h1>
        <p class="mt-1 text-sm text-ink-muted">
            Tasks assigned to you. Submit each when it's done; a reviewed task goes to your manager
            or HR.
        </p>
        <p v-if="result.reportCount === 0" class="notice notice-attention mt-3">
            <span aria-hidden="true">!</span> Nobody reports to you today, so there are no team
            tasks.
        </p>
        <TaskTable
            class="mt-4"
            :result="result"
            url="/app/my-tasks"
            :show-assignee="false"
            caption="My tasks"
        />
    </main>
</template>
