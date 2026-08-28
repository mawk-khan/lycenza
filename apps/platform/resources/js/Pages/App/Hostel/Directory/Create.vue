<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

interface CampusOption {
    id: string;
    name: string;
}

interface Props {
    campuses: CampusOption[];
}

defineProps<Props>();

const form = useForm({
    code: '',
    name: '',
    campus_id: '',
});

function submit(): void {
    form.post('/app/hostels');
}
</script>

<template>
    <main class="mx-auto max-w-xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/hostels">← Hostels</a>
        <h1 class="mt-2 text-xl font-semibold">Add Hostel</h1>

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
                <label class="block text-sm text-slate-600" for="campus">Campus</label>
                <select
                    id="campus"
                    v-model="form.campus_id"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                >
                    <option value="">— Select —</option>
                    <option v-for="campus in campuses" :key="campus.id" :value="campus.id">
                        {{ campus.name }}
                    </option>
                </select>
                <p v-if="form.errors.campus_id" class="mt-1 text-sm text-red-600">
                    {{ form.errors.campus_id }}
                </p>
            </div>

            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                >
                    {{ form.processing ? 'Saving…' : 'Add Hostel' }}
                </button>
                <a class="text-sm underline" href="/app/hostels">Cancel</a>
            </div>
        </form>
    </main>
</template>
