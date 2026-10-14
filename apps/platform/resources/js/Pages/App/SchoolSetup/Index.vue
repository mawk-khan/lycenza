<script setup lang="ts">
interface Props {
    // SWC.2: the remaining structure screens, by the capability each page checks.
    can: { viewYears: boolean; viewStructure: boolean; viewSubjects: boolean };
    progress: {
        profileConfigured: boolean;
        campusCreated: boolean;
        academicYearActivated: boolean;
        gradeLevelsConfigured: boolean;
        subjectsConfigured: boolean;
    };
}

const props = defineProps<Props>();

const more = [
    { label: 'Terms', href: '/app/school-setup/terms', show: () => props.can.viewYears },
    { label: 'Sections', href: '/app/school-setup/sections', show: () => props.can.viewStructure },
    {
        label: 'Academic departments',
        href: '/app/school-setup/departments',
        show: () => props.can.viewStructure,
    },
    { label: 'Rooms', href: '/app/school-setup/rooms', show: () => props.can.viewStructure },
    {
        label: 'Subject offerings',
        href: '/app/subject-offerings',
        show: () => props.can.viewSubjects,
    },
    { label: 'Elective groups', href: '/app/elective-groups', show: () => props.can.viewSubjects },
];

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
        <h1 class="text-xl font-semibold">School setup</h1>
        <p class="mt-1 text-sm text-slate-600">
            Configure School → Campus → Academic Year → Grade → Subjects, then the Terms, Sections,
            Rooms and Subject Offerings each year needs.
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

        <section v-if="more.some((m) => m.show())" class="mt-8" aria-labelledby="structure-heading">
            <h2 id="structure-heading" class="text-sm font-semibold text-slate-700">
                Academic structure
            </h2>
            <ul class="mt-2 space-y-1 text-sm">
                <template v-for="item in more" :key="item.href">
                    <li v-if="item.show()">
                        <a class="underline" :href="item.href">{{ item.label }}</a>
                    </li>
                </template>
            </ul>
        </section>

        <p class="mt-8 text-sm">
            <a class="underline" href="/app">← Back to the dashboard</a>
        </p>
    </main>
</template>
