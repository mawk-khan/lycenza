<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

interface Ref {
    id: string;
    name: string;
}

interface Props {
    applicant: {
        id: string;
        firstName: string;
        middleName: string | null;
        lastName: string | null;
    };
    academicYears: Ref[];
    campuses: Ref[];
    gradeLevels: Ref[];
}

const props = defineProps<Props>();

function applicantName(): string {
    return [props.applicant.firstName, props.applicant.middleName, props.applicant.lastName]
        .filter(Boolean)
        .join(' ');
}

const form = useForm({
    academic_year_id: '',
    campus_id: '',
    grade_level_id: '',
});

function submit(): void {
    form.post(`/app/admissions/applicants/${props.applicant.id}/applications`);
}
</script>

<template>
    <main class="mx-auto max-w-xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" :href="`/app/admissions/applicants/${applicant.id}`">← Back</a>
        <h1 class="mt-2 text-xl font-semibold">New application for {{ applicantName() }}</h1>
        <p class="mt-1 text-sm text-slate-500">
            Starts in draft. Section and roll number are decided later, at conversion time.
        </p>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <div>
                <label class="block text-sm font-medium text-slate-700" for="academic_year_id"
                    >Academic Year</label
                >
                <select
                    id="academic_year_id"
                    v-model="form.academic_year_id"
                    required
                    :aria-invalid="!!form.errors.academic_year_id"
                    aria-describedby="academic_year_id-error"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                >
                    <option value="" disabled>Select an Academic Year…</option>
                    <option v-for="y in academicYears" :key="y.id" :value="y.id">
                        {{ y.name }}
                    </option>
                </select>
                <p id="academic_year_id-error" class="mt-1 text-sm text-red-600">
                    {{ form.errors.academic_year_id }}
                </p>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700" for="campus_id"
                    >Campus</label
                >
                <select
                    id="campus_id"
                    v-model="form.campus_id"
                    required
                    :aria-invalid="!!form.errors.campus_id"
                    aria-describedby="campus_id-error"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                >
                    <option value="" disabled>Select a Campus…</option>
                    <option v-for="c in campuses" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
                <p id="campus_id-error" class="mt-1 text-sm text-red-600">
                    {{ form.errors.campus_id }}
                </p>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700" for="grade_level_id"
                    >Grade</label
                >
                <select
                    id="grade_level_id"
                    v-model="form.grade_level_id"
                    required
                    :aria-invalid="!!form.errors.grade_level_id"
                    aria-describedby="grade_level_id-error"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                >
                    <option value="" disabled>Select a Grade…</option>
                    <option v-for="g in gradeLevels" :key="g.id" :value="g.id">{{ g.name }}</option>
                </select>
                <p id="grade_level_id-error" class="mt-1 text-sm text-red-600">
                    {{ form.errors.grade_level_id }}
                </p>
            </div>

            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                >
                    {{ form.processing ? 'Creating…' : 'Create application' }}
                </button>
                <a class="text-sm underline" :href="`/app/admissions/applicants/${applicant.id}`"
                    >Cancel</a
                >
            </div>
        </form>
    </main>
</template>
