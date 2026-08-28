<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

const EXAMPLE = JSON.stringify(
    [{ full_name: 'Alexandra Fernandes', employment_type: 'full_time', starts_on: '2026-06-01' }],
    null,
    2,
);

const form = useForm({ rows_json: '' });

function submit(): void {
    form.post('/app/hr/employees/import');
}
</script>

<template>
    <main class="mx-auto max-w-2xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/hr">← HR</a>
        <h1 class="mt-2 text-xl font-semibold">Import employees</h1>
        <p class="mt-1 text-sm text-slate-500">
            Paste a JSON array of employee rows. Each row may include core identity fields
            (required:
            <code>full_name</code>), optional personal/contact fields, and an optional current
            Employment/Assignment block. Duplicates are detected and reported, never merged or
            overwritten.
        </p>

        <form class="mt-6" @submit.prevent="submit">
            <label class="block text-sm text-slate-600" for="rows-json">Rows (JSON array)</label>
            <textarea
                id="rows-json"
                v-model="form.rows_json"
                rows="14"
                :placeholder="EXAMPLE"
                required
                class="mt-1 w-full rounded border border-slate-300 px-3 py-2 font-mono text-sm"
            ></textarea>
            <p class="mt-1 text-sm text-red-600">{{ form.errors.rows_json }}</p>

            <div class="mt-4 flex items-center gap-3">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                >
                    {{ form.processing ? 'Importing…' : 'Import' }}
                </button>
                <a class="text-sm underline" href="/app/hr">Cancel</a>
            </div>
        </form>
    </main>
</template>
