<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

interface Props {
    components: Array<{ id: string; code: string; name: string; type: string }>;
}

defineProps<Props>();

const form = useForm({ code: '', name: '' });

function submit(): void {
    form.post('/app/payroll/structures');
}
</script>

<template>
    <main class="mx-auto max-w-xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/payroll/structures">← Salary Structures</a>

        <h1 class="mt-2 text-xl font-semibold">New structure / revision</h1>
        <p class="mt-1 text-sm text-slate-500">
            A new code creates a fresh structure at version 1. An EXISTING code creates the next
            revision of it instead (draft) -- the active revision, if any, is unaffected until you
            activate the new one.
        </p>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <div>
                <label class="block text-sm text-slate-600" for="code">Code</label>
                <input
                    id="code"
                    v-model="form.code"
                    type="text"
                    maxlength="64"
                    class="mt-1 w-full max-w-xs rounded border border-slate-300 px-3 py-2 text-sm"
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
                    maxlength="255"
                    class="mt-1 w-full max-w-md rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">
                    {{ form.errors.name }}
                </p>
            </div>

            <p v-if="components.length === 0" class="text-sm text-amber-700">
                No active Salary Components exist yet -- create one first, then add components to
                this structure from its detail page once it's created.
            </p>

            <button
                type="submit"
                :disabled="form.processing"
                class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white disabled:opacity-50"
            >
                {{ form.processing ? 'Creating…' : 'Create draft' }}
            </button>
        </form>
    </main>
</template>
