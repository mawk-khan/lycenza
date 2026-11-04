<script setup lang="ts">
import TaskTable from '../../../../Components/HR/TaskTable.vue';
import type { TaskPage } from '../../../../tasks';

/** HR.13 — Assigned by me (server-filtered and paged). */
defineProps<{ result: TaskPage & { reportCount?: number } }>();
</script>

<template>
    <main class="mx-auto max-w-5xl p-8 font-sans text-ink">
        <a class="text-sm underline" href="/app">← Dashboard</a>
        <h1 class="mt-2 text-xl font-semibold">Assigned by me</h1>
        <p class="mt-1 text-sm text-ink-muted">Tasks you assigned.</p>
        <p v-if="result.reportCount === 0" class="notice notice-attention mt-3">
            <span aria-hidden="true">!</span> Nobody reports to you today, so there are no team
            tasks.
        </p>
        <TaskTable
            class="mt-4"
            :result="result"
            url="/app/hr/tasks/assigned-by-me"
            :show-assignee="true"
            caption="Assigned by me"
        />
    </main>
</template>
