<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

interface SectionOption {
    // SIS 11.3 (OD-SIS-6): advisory occupancy, e.g. "28 / 30 · 2 seats available" (information only).
    occupancy?: string | null;
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
    <main class="mx-auto max-w-xl px-4 py-6 sm:p-8 font-sans text-ink">
        <a class="text-sm underline" :href="`/app/students/${student.id}`">← Back</a>
        <h1 class="mt-2 text-xl font-semibold">Enroll {{ studentName() }}</h1>
        <p class="mt-1 text-sm text-ink-muted">
            Academic Year, Campus, and Grade come from the Section you choose below.
        </p>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <div>
                <label class="block text-sm font-medium text-ink" for="section_id">Section</label>
                <select
                    id="section_id"
                    v-model="form.section_id"
                    required
                    :aria-invalid="!!form.errors.section_id"
                    aria-describedby="section_id-error"
                    class="input mt-1"
                >
                    <option value="" disabled>Select a Section…</option>
                    <option v-for="s in sections" :key="s.id" :value="s.id">
                        {{ s.occupancy ? `${s.label} — ${s.occupancy}` : s.label }}
                    </option>
                </select>
                <p id="section_id-error" class="mt-1 text-sm text-danger">
                    {{ form.errors.section_id }}
                </p>
            </div>

            <div>
                <label class="block text-sm font-medium text-ink" for="roll_number"
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
                    class="input mt-1 sm:w-40"
                />
                <p id="roll_number-error" class="mt-1 text-sm text-danger">
                    {{ form.errors.roll_number }}
                </p>
            </div>

            <div>
                <label class="block text-sm font-medium text-ink" for="starts_on">Start date</label>
                <input
                    id="starts_on"
                    v-model="form.starts_on"
                    type="date"
                    required
                    :aria-invalid="!!form.errors.starts_on"
                    aria-describedby="starts_on-error"
                    class="input mt-1 sm:w-56"
                />
                <p id="starts_on-error" class="mt-1 text-sm text-danger">
                    {{ form.errors.starts_on }}
                </p>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit" :disabled="form.processing" class="btn btn-primary">
                    {{ form.processing ? 'Enrolling…' : 'Enroll student' }}
                </button>
                <a class="text-sm underline" :href="`/app/students/${student.id}`">Cancel</a>
            </div>
        </form>
    </main>
</template>
