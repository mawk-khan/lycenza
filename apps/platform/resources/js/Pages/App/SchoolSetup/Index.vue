<script setup lang="ts">
interface Props {
    progress: {
        profileConfigured: boolean;
        campusCreated: boolean;
        academicYearActivated: boolean;
        gradeLevelsConfigured: boolean;
        subjectsConfigured: boolean;
    };
}

defineProps<Props>();

const steps: Array<{ key: keyof Props['progress']; label: string; href: string }> = [
    {
        key: 'profileConfigured',
        label: 'School profile configured',
        href: '/app/school-setup/profile',
    },
    { key: 'campusCreated', label: 'Campus created', href: '/app/school-setup/campuses' },
    {
        key: 'academicYearActivated',
        label: 'Academic Year activated',
        href: '/app/school-setup/academic-years',
    },
    {
        key: 'gradeLevelsConfigured',
        label: 'Grade Levels configured',
        href: '/app/school-setup/grade-levels',
    },
    { key: 'subjectsConfigured', label: 'Subjects configured', href: '/app/school-setup/subjects' },
];
</script>

<template>
    <main class="mx-auto max-w-2xl p-8 font-sans text-slate-900">
        <h1 class="text-xl font-semibold">School Setup</h1>
        <p class="mt-1 text-sm text-slate-500">
            Configure School → Campus → Academic Year → Grade → Subjects. Students are not part of
            this checkpoint.
        </p>

        <ul class="mt-6 divide-y divide-slate-200 rounded border border-slate-200">
            <li
                v-for="step in steps"
                :key="step.key"
                class="flex items-center justify-between px-4 py-3"
            >
                <a class="text-sm underline" :href="step.href">{{ step.label }}</a>
                <span
                    class="text-xs font-medium"
                    :class="progress[step.key] ? 'text-emerald-600' : 'text-slate-400'"
                >
                    {{ progress[step.key] ? 'Done' : 'Not started' }}
                </span>
            </li>
        </ul>

        <nav class="mt-8 text-sm">
            <a class="underline" href="/app">← Back to dashboard</a>
        </nav>
    </main>
</template>
