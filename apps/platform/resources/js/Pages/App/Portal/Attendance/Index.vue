<script setup lang="ts">
import EmptyState from '../../../../Components/EmptyState.vue';

/**
 * POR.2 — Guardian portal: choose which of your children's attendance to see.
 * Only the Students you are recorded as a legal guardian of, in this School.
 */
interface Props {
    schoolName: string;
    students: { id: string; name: string }[];
}

defineProps<Props>();
</script>

<template>
    <main class="mx-auto max-w-3xl px-6 py-10">
        <a class="text-sm underline" href="/app">Back</a>
        <h1 class="mt-4 text-xl font-semibold text-slate-900">Attendance · {{ schoolName }}</h1>

        <EmptyState
            v-if="students.length === 0"
            class="mt-6"
            title="No students"
            description="There is no student whose attendance you can see in this School."
        />

        <ul v-else class="mt-6 divide-y divide-slate-200 rounded border border-slate-200">
            <li v-for="student in students" :key="student.id" class="px-4 py-3">
                <a class="underline" :href="`/app/portal/attendance/students/${student.id}`">{{
                    student.name
                }}</a>
            </li>
        </ul>
    </main>
</template>
