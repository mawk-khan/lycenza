<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

const form = useForm({
    full_name: '',
    phone: '',
});

function submit(): void {
    form.post('/app/visitor/directory');
}
</script>

<template>
    <main class="mx-auto max-w-xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/visitor/directory">← Visitor directory</a>
        <h1 class="mt-2 text-xl font-semibold">Add Visitor</h1>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <div>
                <label class="block text-sm text-slate-600" for="full-name">Full name</label>
                <input
                    id="full-name"
                    v-model="form.full_name"
                    type="text"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <p v-if="form.errors.full_name" class="mt-1 text-sm text-red-600">
                    {{ form.errors.full_name }}
                </p>
            </div>

            <div>
                <label class="block text-sm text-slate-600" for="phone">Phone (optional)</label>
                <input
                    id="phone"
                    v-model="form.phone"
                    type="text"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <p v-if="form.errors.phone" class="mt-1 text-sm text-red-600">
                    {{ form.errors.phone }}
                </p>
            </div>

            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                >
                    {{ form.processing ? 'Saving…' : 'Add Visitor' }}
                </button>
                <a class="text-sm underline" href="/app/visitor/directory">Cancel</a>
            </div>
        </form>
    </main>
</template>
