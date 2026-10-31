<script setup lang="ts">
// SIS 11.4 (D-11): the print-ready view of a governed export -- the same
// authority, filters, sort and columns as the CSV, no navigation or
// controls. Browser printing only: this is not an official PDF document.
import { onMounted } from 'vue';
import { formatCalendarDate, formatDateTime } from '../../../../datetime';

const props = defineProps<{
    school: string;
    title: string;
    columns: string[];
    rows: Array<Array<string | number | null>>;
    filters: string[];
    generatedAt: string;
    backUrl: string;
}>();

function cell(value: string | number | null): string {
    if (value === null || value === '') {
        return '—';
    }
    const text = String(value);
    return /^\d{4}-\d{2}-\d{2}$/.test(text) ? formatCalendarDate(text) : text;
}

function printPage(): void {
    window.print();
}

onMounted(() => {
    document.title = `${props.title} — ${props.school}`;
});
</script>

<template>
    <main class="mx-auto max-w-6xl px-4 py-6 font-sans text-slate-900 print:max-w-none print:p-0">
        <div class="mb-4 flex flex-wrap gap-3 print:hidden">
            <a class="text-sm underline" :href="backUrl">← Back to the report</a>
            <button type="button" class="text-sm underline" @click="printPage">Print</button>
        </div>
        <header>
            <p class="text-sm text-slate-600">{{ school }}</p>
            <h1 class="text-xl font-semibold">{{ title }}</h1>
            <p class="mt-1 text-sm text-slate-600">
                Generated {{ formatDateTime(generatedAt) }} · {{ rows.length }}
                {{ rows.length === 1 ? 'row' : 'rows' }}
            </p>
            <ul v-if="filters.length" class="mt-1 text-sm text-slate-600">
                <li v-for="f in filters" :key="f">{{ f }}</li>
            </ul>
        </header>
        <p v-if="rows.length === 0" class="mt-6 text-sm">Nothing matches these filters.</p>
        <div v-else class="mt-4 overflow-x-auto print:overflow-visible">
            <table class="min-w-full border-collapse text-sm">
                <caption class="sr-only">
                    {{
                        title
                    }}
                </caption>
                <thead class="text-left">
                    <tr>
                        <th
                            v-for="c in columns"
                            :key="c"
                            scope="col"
                            class="border-b border-slate-400 px-2 py-1 font-medium"
                        >
                            {{ c }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, i) in rows" :key="i" class="break-inside-avoid">
                        <td
                            v-for="(value, j) in row"
                            :key="j"
                            class="border-b border-slate-200 px-2 py-1 align-top"
                        >
                            {{ cell(value) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </main>
</template>

<style>
@media print {
    thead {
        display: table-header-group;
    }
}
</style>
