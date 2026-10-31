<script setup lang="ts">
// SIS 11.8: the Student Communities hub -- Houses, Clubs and Activities, as
// far as this viewer may see them. Navigation only: each area authorizes its
// own page, and a community is never a permission.
defineProps<{
    areas: { type: 'house' | 'club' | 'activity'; label: string; url: string; campus: boolean }[];
}>();

const DESCRIPTIONS: Record<string, string> = {
    house: 'One House per Student, kept with its history.',
    club: 'Student Clubs. A Student may belong to several.',
    activity: 'Extracurricular and co-curricular activities. A Student may take part in several.',
};
</script>

<template>
    <main class="mx-auto max-w-5xl px-4 py-6 font-sans text-slate-900 sm:p-8">
        <a class="text-sm underline" href="/app/students">← Students</a>
        <h1 class="mt-2 text-xl font-semibold">Student communities</h1>
        <p class="mt-1 max-w-3xl text-sm text-slate-600">
            Houses, Clubs and Activities describe how Students take part in School life. They never
            change what anyone can see or do in Lycenza.
        </p>
        <ul class="mt-6 grid gap-4 sm:grid-cols-3">
            <li v-for="area in areas" :key="area.type" class="rounded border border-slate-200 p-4">
                <h2 class="text-base font-semibold">
                    <a class="underline" :href="area.url">{{ area.label }}</a>
                </h2>
                <p class="mt-1 text-sm text-slate-600">{{ DESCRIPTIONS[area.type] }}</p>
                <p v-if="area.campus" class="mt-1 text-xs text-slate-500">
                    Counts show your Campuses only.
                </p>
            </li>
        </ul>
    </main>
</template>
