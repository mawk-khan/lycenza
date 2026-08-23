<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

interface GradeLevel {
    id: string;
    name: string;
    code: string;
    sequence: number;
    status: string;
}

interface Props {
    gradeLevels: GradeLevel[];
    canManage: boolean;
}

defineProps<Props>();

const form = useForm({ name: '', code: '', sequence: '' });

function submit() {
    form.post('/app/school-setup/grade-levels', { onSuccess: () => form.reset() });
}
</script>

<template>
    <main class="mx-auto max-w-2xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/school-setup">← School setup</a>
        <h1 class="mt-2 text-xl font-semibold">Grade Levels</h1>

        <p v-if="gradeLevels.length === 0" class="mt-4 text-sm text-slate-500">
            No Grade Level exists yet. Create the first one below (e.g. Nursery, LKG, Grade 1).
        </p>
        <ul v-else class="mt-4 divide-y divide-slate-200 rounded border border-slate-200">
            <li
                v-for="grade in gradeLevels"
                :key="grade.id"
                class="flex items-center justify-between px-4 py-3 text-sm"
            >
                <span
                    >{{ grade.name }} <span class="text-slate-400">({{ grade.code }})</span></span
                >
                <span class="text-xs text-slate-500"
                    >sequence {{ grade.sequence }} · {{ grade.status }}</span
                >
            </li>
        </ul>

        <form v-if="canManage" class="mt-6 flex items-end gap-3" @submit.prevent="submit">
            <div class="flex-1">
                <label class="block text-sm text-slate-600" for="name">Name</label>
                <input
                    id="name"
                    v-model="form.name"
                    required
                    placeholder="Grade 1"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                />
            </div>
            <div class="w-24">
                <label class="block text-sm text-slate-600" for="code">Code</label>
                <input
                    id="code"
                    v-model="form.code"
                    required
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                />
            </div>
            <div class="w-28">
                <label class="block text-sm text-slate-600" for="sequence">Sequence</label>
                <input
                    id="sequence"
                    v-model="form.sequence"
                    type="number"
                    required
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                />
            </div>
            <button
                type="submit"
                :disabled="form.processing"
                class="rounded bg-slate-900 px-3 py-2 text-white disabled:opacity-50"
            >
                Add
            </button>
        </form>
        <p v-if="form.errors.code" class="mt-2 text-sm text-red-600">{{ form.errors.code }}</p>
        <p v-if="form.errors.sequence" class="mt-2 text-sm text-red-600">
            {{ form.errors.sequence }}
        </p>
    </main>
</template>
