<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

interface SectionOption {
    id: string;
    label: string;
    academicYearId: string;
    campusId: string;
    gradeLevelId: string;
}

interface Props {
    student: {
        id: string;
        studentNumber: string;
        firstName: string;
        middleName: string | null;
        lastName: string | null;
    };
    sections: SectionOption[];
}

const props = defineProps<Props>();

function studentName(): string {
    return [props.student.firstName, props.student.middleName, props.student.lastName]
        .filter(Boolean)
        .join(' ');
}

const form = useForm({
    section_id: '',
    roll_number: '',
    starts_on: '',
});

function submit(): void {
    form.post(`/app/students/${props.student.id}/enrollments`);
}
</script>

<template>
    <main class="mx-auto max-w-xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" :href="`/app/students/${student.id}`">← Back</a>
        <h1 class="mt-2 text-xl font-semibold">Enroll {{ studentName() }}</h1>
        <p class="mt-1 text-sm text-slate-500">
            Academic Year, Campus, and Grade come from the Section you choose below.
        </p>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <div>
                <label class="block text-sm font-medium text-slate-700" for="section_id"
                    >Section</label
                >
                <select
                    id="section_id"
                    v-model="form.section_id"
                    required
                    :aria-invalid="!!form.errors.section_id"
                    aria-describedby="section_id-error"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                >
                    <option value="" disabled>Select a Section…</option>
                    <option v-for="s in sections" :key="s.id" :value="s.id">{{ s.label }}</option>
                </select>
                <p id="section_id-error" class="mt-1 text-sm text-red-600">
                    {{ form.errors.section_id }}
                </p>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700" for="roll_number"
                    >Roll number</label
                >
                <!--
                    type="text", never "number" -- a Roll Number like
                    "007" must round-trip exactly (this checkpoint's
                    brief, section 18); the server is authoritative for
                    trimming/validation, this input never coerces it.
                -->
                <input
                    id="roll_number"
                    v-model="form.roll_number"
                    type="text"
                    required
                    :aria-invalid="!!form.errors.roll_number"
                    aria-describedby="roll_number-error"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 sm:w-40"
                />
                <p id="roll_number-error" class="mt-1 text-sm text-red-600">
                    {{ form.errors.roll_number }}
                </p>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700" for="starts_on"
                    >Start date</label
                >
                <input
                    id="starts_on"
                    v-model="form.starts_on"
                    type="date"
                    required
                    :aria-invalid="!!form.errors.starts_on"
                    aria-describedby="starts_on-error"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 sm:w-56"
                />
                <p id="starts_on-error" class="mt-1 text-sm text-red-600">
                    {{ form.errors.starts_on }}
                </p>
            </div>

            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                >
                    {{ form.processing ? 'Enrolling…' : 'Enroll student' }}
                </button>
                <a class="text-sm underline" :href="`/app/students/${student.id}`">Cancel</a>
            </div>
        </form>
    </main>
</template>
