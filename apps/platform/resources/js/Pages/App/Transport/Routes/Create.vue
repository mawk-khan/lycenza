<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

const form = useForm({
    code: '',
    name: '',
    description: '',
});

function submit(): void {
    form.post('/app/transport/routes');
}
</script>

<template>
    <main class="mx-auto max-w-xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/transport/routes">← Transport routes</a>
        <h1 class="mt-2 text-xl font-semibold">Add route</h1>
        <p class="mt-1 text-sm text-slate-500">
            Register a new Transport route. Add Stops afterward.
        </p>

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
                <label class="block text-sm text-slate-600" for="description">
                    Description (optional)
                </label>
                <textarea
                    id="description"
                    v-model="form.description"
                    rows="3"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                ></textarea>
                <p v-if="form.errors.description" class="mt-1 text-sm text-red-600">
                    {{ form.errors.description }}
                </p>
            </div>

            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                >
                    {{ form.processing ? 'Saving…' : 'Add route' }}
                </button>
                <a class="text-sm underline" href="/app/transport/routes">Cancel</a>
            </div>
        </form>
    </main>
</template>
