<script setup lang="ts">
// SIS 11.1: the SIS Reports hub. The server lists only the reports this
// viewer may open (each checks its own capability again when opened);
// nothing here changes data or exports anything.
interface ReportSummary {
    key: string;
    title: string;
    description: string;
    url: string;
}

defineProps<{
    categories: { key: string; label: string; reports: ReportSummary[] }[];
}>();
</script>

<template>
    <main class="mx-auto max-w-4xl px-4 py-6 font-sans text-slate-900 sm:p-8">
        <a class="text-sm underline" href="/app/students">← Students</a>
        <h1 class="mt-2 text-xl font-semibold">Student reports</h1>
        <p class="mt-1 text-sm text-slate-500">
            Read-only lists of Student records, placements and history. You see only the reports for
            information you are allowed to view. Changes happen on the Student and Enrollment pages.
        </p>

        <section
            v-for="category in categories"
            :key="category.key"
            class="mt-8"
            :aria-labelledby="`reports-${category.key}`"
        >
            <h2 :id="`reports-${category.key}`" class="text-sm font-semibold">
                {{ category.label }}
            </h2>
            <ul class="mt-3 divide-y divide-slate-100 border-y border-slate-100">
                <li v-for="report in category.reports" :key="report.key" class="py-4">
                    <a class="font-medium underline" :href="report.url">{{ report.title }}</a>
                    <p class="mt-1 text-sm text-slate-600">{{ report.description }}</p>
                </li>
            </ul>
        </section>
    </main>
</template>
