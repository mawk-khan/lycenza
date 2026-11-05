<script setup lang="ts">
// HR.14: the HR Reports hub. The server lists only the reports this viewer
// may open (each checks its own capabilities again when opened); nothing
// here changes data, exports or prints anything.
interface ReportSummary {
    key: string;
    title: string;
    description: string;
    basis: string;
    url: string;
}

defineProps<{
    categories: { key: string; label: string; reports: ReportSummary[] }[];
}>();
</script>

<template>
    <main class="mx-auto max-w-4xl px-4 py-6 font-sans text-ink sm:p-8">
        <a class="text-sm underline" href="/app/hr">← HR</a>
        <h1 class="mt-2 text-xl font-semibold">HR reports</h1>
        <p class="mt-1 text-sm text-ink-muted">
            Read-only lists of staff, employment history, leave, staff attendance and staff tasks,
            read live from the records. You see only the reports for information you may view.
            Headcount, rates and trends are not available here.
        </p>

        <section
            v-for="category in categories"
            :key="category.key"
            class="mt-8"
            :aria-labelledby="`hr-reports-${category.key}`"
        >
            <h2 :id="`hr-reports-${category.key}`" class="text-sm font-semibold">
                {{ category.label }}
            </h2>
            <ul class="mt-3 divide-y divide-line border-y border-line">
                <li v-for="report in category.reports" :key="report.key" class="py-4">
                    <a class="font-medium underline" :href="report.url">{{ report.title }}</a>
                    <p class="mt-1 text-sm text-ink-muted">{{ report.description }}</p>
                    <p class="mt-1 text-xs text-ink-muted">{{ report.basis }}</p>
                </li>
            </ul>
        </section>
    </main>
</template>
