<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

const form = useForm({
    code: '',
    name: '',
    unit_of_measure: 'each',
});

const units = ['each', 'box', 'packet', 'kg', 'litre'];

function submit(): void {
    form.post('/app/inventory-items');
}
</script>

<template>
    <main class="mx-auto max-w-xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/inventory-items">← Inventory items</a>
        <h1 class="mt-2 text-xl font-semibold">Add Item</h1>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <div>
                <label class="block text-sm text-slate-600" for="code">Code</label>
                <input
                    id="code"
                    v-model="form.code"
                    type="text"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <p v-if="form.errors.code" class="mt-1 text-sm text-red-600">
                    {{ form.errors.code }}
                </p>
            </div>

            <div>
                <label class="block text-sm text-slate-600" for="name">Name</label>
                <input
                    id="name"
                    v-model="form.name"
                    type="text"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">
                    {{ form.errors.name }}
                </p>
            </div>

            <div>
                <label class="block text-sm text-slate-600" for="unit">Unit of measure</label>
                <select
                    id="unit"
                    v-model="form.unit_of_measure"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                >
                    <option v-for="unit in units" :key="unit" :value="unit">{{ unit }}</option>
                </select>
                <p v-if="form.errors.unit_of_measure" class="mt-1 text-sm text-red-600">
                    {{ form.errors.unit_of_measure }}
                </p>
                <p class="mt-1 text-xs text-slate-400">
                    "each"/"box"/"packet" only accept whole-number quantities; "kg"/"litre" accept
                    fractional quantities.
                </p>
            </div>

            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                >
                    {{ form.processing ? 'Saving…' : 'Add Item' }}
                </button>
                <a class="text-sm underline" href="/app/inventory-items">Cancel</a>
            </div>
        </form>
    </main>
</template>
