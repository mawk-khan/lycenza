<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

interface Ref {
    id: string;
    name: string;
}

interface SectionOption {
    id: string;
    label: string;
    academicYearId: string;
    campusId: string;
    gradeLevelId: string;
}

interface Props {
    enrollment: {
        id: string;
        student: {
            id: string;
            studentNumber: string;
            firstName: string;
            middleName: string | null;
            lastName: string | null;
        };
        academicYear: Ref;
        campus: Ref;
        gradeLevel: Ref;
        section: Ref;
        rollNumber: string;
        startsOn: string;
    };
    sections: SectionOption[];
}

const props = defineProps<Props>();

function studentName(): string {
    const s = props.enrollment.student;
    return [s.firstName, s.middleName, s.lastName].filter(Boolean).join(' ');
}

const form = useForm({
    target_section_id: '',
    roll_number: '',
    effective_date: '',
});

// Presentation only -- shows the target Section's own Academic
// Year/Campus/Grade for staff context (this checkpoint's brief,
// section 26). The server independently derives and enforces the
// actual placement; this is never submitted as authoritative.
const targetSection = computed(
    () => props.sections.find((s) => s.id === form.target_section_id) ?? null,
);

function submit(): void {
    const confirmed = window.confirm(
        `Transfer ${studentName()} out of ${props.enrollment.gradeLevel.name} · Section ${props.enrollment.section.name}?\n\n` +
            'The current placement will be closed (as of the day before the effective date) and a new active placement will be created in the target Section.',
    );
    if (!confirmed) return;

    form.post(`/app/enrollments/${props.enrollment.id}/transfer`);
}
</script>

<template>
    <main class="mx-auto max-w-xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" :href="`/app/students/${enrollment.student.id}`">← Back</a>
        <h1 class="mt-2 text-xl font-semibold">Transfer {{ studentName() }}</h1>

        <section class="mt-4 rounded border border-slate-200 p-4 text-sm">
            <h2 class="font-medium text-slate-700">Current placement</h2>
            <p class="mt-1 text-slate-600">
                {{ enrollment.gradeLevel.name }} · Section {{ enrollment.section.name }} ·
                {{ enrollment.campus.name }} · {{ enrollment.academicYear.name }} · Roll
                {{ enrollment.rollNumber }}
            </p>
        </section>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <div>
                <label class="block text-sm font-medium text-slate-700" for="target_section_id"
                    >Target Section</label
                >
                <select
                    id="target_section_id"
                    v-model="form.target_section_id"
                    required
                    :aria-invalid="!!form.errors.target_section_id"
                    aria-describedby="target_section_id-error"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                >
                    <option value="" disabled>Select a Section…</option>
                    <option v-for="s in sections" :key="s.id" :value="s.id">{{ s.label }}</option>
                </select>
                <p id="target_section_id-error" class="mt-1 text-sm text-red-600">
                    {{ form.errors.target_section_id }}
                </p>
                <!--
                    Same School/Academic Year/Grade is required; a
                    different Campus is allowed (Phase 1B.3) -- the
                    server remains authoritative regardless of what this
                    label implies (this checkpoint's brief, section 26).
                -->
                <p v-if="targetSection" class="mt-1 text-sm text-slate-500">
                    Moves to {{ targetSection.label }}.
                </p>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700" for="roll_number"
                    >New roll number</label
                >
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
                <label class="block text-sm font-medium text-slate-700" for="effective_date"
                    >Effective date</label
                >
                <input
                    id="effective_date"
                    v-model="form.effective_date"
                    type="date"
                    required
                    :aria-invalid="!!form.errors.effective_date"
                    aria-describedby="effective_date-error"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 sm:w-56"
                />
                <p id="effective_date-error" class="mt-1 text-sm text-red-600">
                    {{ form.errors.effective_date }}
                </p>
            </div>

            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                >
                    {{ form.processing ? 'Transferring…' : 'Transfer enrollment' }}
                </button>
                <a class="text-sm underline" :href="`/app/students/${enrollment.student.id}`"
                    >Cancel</a
                >
            </div>
        </form>
    </main>
</template>
