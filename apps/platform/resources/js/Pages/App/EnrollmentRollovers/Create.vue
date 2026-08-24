<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

interface AcademicYearOption {
    id: string;
    name: string;
    code: string;
    startsOn: string;
    endsOn: string;
    status: string;
}

interface Props {
    academicYears: AcademicYearOption[];
}

const props = defineProps<Props>();

const form = useForm({
    source_academic_year_id: '',
    target_academic_year_id: '',
});

function yearLabel(y: AcademicYearOption): string {
    return `${y.name} (${y.startsOn} – ${y.endsOn})`;
}

// Presentation only -- the operator deliberately picks both years; no
// "next year" is calculated here (this checkpoint's brief, section 15).
const sourceYear = computed(
    () => props.academicYears.find((y) => y.id === form.source_academic_year_id) ?? null,
);
const targetYear = computed(
    () => props.academicYears.find((y) => y.id === form.target_academic_year_id) ?? null,
);

function submit(): void {
    form.post('/app/enrollment-rollovers');
}
</script>

<template>
    <main class="mx-auto max-w-xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/enrollment-rollovers">← Back</a>
        <h1 class="mt-2 text-xl font-semibold">Create Enrollment Rollover Plan</h1>
        <p class="mt-1 text-sm text-slate-500">
            Choose the source Academic Year Students are currently placed in, and the target
            Academic Year they will be moved into. You will configure Grade/Section mappings and
            review individual Students next.
        </p>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <div>
                <label
                    class="block text-sm font-medium text-slate-700"
                    for="source_academic_year_id"
                    >Source Academic Year</label
                >
                <select
                    id="source_academic_year_id"
                    v-model="form.source_academic_year_id"
                    required
                    :aria-invalid="!!form.errors.source_academic_year_id"
                    aria-describedby="source_academic_year_id-error"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                >
                    <option value="" disabled>Select an Academic Year…</option>
                    <option v-for="y in academicYears" :key="y.id" :value="y.id">
                        {{ yearLabel(y) }}
                    </option>
                </select>
                <p id="source_academic_year_id-error" class="mt-1 text-sm text-red-600">
                    {{ form.errors.source_academic_year_id }}
                </p>
            </div>

            <div>
                <label
                    class="block text-sm font-medium text-slate-700"
                    for="target_academic_year_id"
                    >Target Academic Year</label
                >
                <select
                    id="target_academic_year_id"
                    v-model="form.target_academic_year_id"
                    required
                    :aria-invalid="!!form.errors.target_academic_year_id"
                    aria-describedby="target_academic_year_id-error"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                >
                    <option value="" disabled>Select an Academic Year…</option>
                    <option v-for="y in academicYears" :key="y.id" :value="y.id">
                        {{ yearLabel(y) }}
                    </option>
                </select>
                <p id="target_academic_year_id-error" class="mt-1 text-sm text-red-600">
                    {{ form.errors.target_academic_year_id }}
                </p>
            </div>

            <p
                v-if="sourceYear && targetYear"
                class="rounded border border-slate-200 bg-slate-50 p-3 text-sm text-slate-600"
            >
                Students currently placed in <strong>{{ sourceYear.name }}</strong> will be proposed
                for placement in <strong>{{ targetYear.name }}</strong
                >. No Enrollment changes until you explicitly start execution later.
            </p>

            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                >
                    {{ form.processing ? 'Creating…' : 'Create Plan' }}
                </button>
                <a class="text-sm underline" href="/app/enrollment-rollovers">Cancel</a>
            </div>
        </form>
    </main>
</template>
