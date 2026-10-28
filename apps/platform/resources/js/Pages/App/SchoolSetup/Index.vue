<script setup lang="ts">
interface Props {
    // SWC.2: the remaining structure screens, by the capability each page checks.
    can: { viewYears: boolean; viewStructure: boolean; viewSubjects: boolean };
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

// ORG.12B (ADR 0080 §7): setup progress lives in the Setup & Readiness
// Center, derived from each owning module -- not as flags on this page.
const steps = [
    { label: 'School profile', href: '/app/school-setup/profile' },
    { label: 'Campuses', href: '/app/school-setup/campuses' },
    { label: 'Academic years', href: '/app/school-setup/academic-years' },
    { label: 'Grade levels', href: '/app/school-setup/grade-levels' },
    { label: 'Subjects', href: '/app/school-setup/subjects' },
];
</script>

<template>
    <main class="mx-auto max-w-2xl p-8 font-sans text-slate-900">
        <h1 class="text-xl font-semibold">School setup</h1>
        <p class="mt-1 text-sm text-slate-600">
            Configure School → Campus → Academic Year → Grade → Subjects, then the Terms, Sections,
            Rooms and Subject Offerings each year needs.
        </p>

        <p class="mt-6 rounded border border-slate-200 bg-slate-50 px-4 py-3 text-sm">
            See what is configured, what needs attention and what to do next in
            <a class="font-medium underline" href="/app/school-setup/readiness"
                >Setup &amp; readiness</a
            >.
        </p>

        <ul class="mt-6 divide-y divide-slate-200 rounded border border-slate-200">
            <li v-for="step in steps" :key="step.href" class="px-4 py-3">
                <a class="text-sm underline" :href="step.href">{{ step.label }}</a>
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
