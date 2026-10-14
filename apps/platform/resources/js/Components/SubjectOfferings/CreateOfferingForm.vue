<script setup lang="ts">
// SWC.2 (ADR 0073 section 10): create a Subject Offering for one Academic
// Year through SubjectOfferingService. An Offering is unique per (Year,
// Campus, Grade level, Subject) -- the server's rule, shown as a field error.
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import CheckboxField from '../Form/CheckboxField.vue';
import SelectField from '../Form/SelectField.vue';
import TextField from '../Form/TextField.vue';

const props = defineProps<{
    academicYearId: string;
    academicYearName: string;
    options: {
        campuses: Array<{ id: string; name: string }>;
        gradeLevels: Array<{ id: string; name: string }>;
        subjects: Array<{ id: string; name: string; code: string }>;
    };
}>();

const campusOptions = computed(() =>
    props.options.campuses.map((c) => ({ value: c.id, label: c.name })),
);
const gradeOptions = computed(() =>
    props.options.gradeLevels.map((g) => ({ value: g.id, label: g.name })),
);
const subjectOptions = computed(() =>
    props.options.subjects.map((s) => ({ value: s.id, label: `${s.name} (${s.code})` })),
);

const form = useForm<{
    academic_year_id: string;
    campus_id: string | null;
    grade_level_id: string | null;
    subject_id: string | null;
    is_required: boolean;
    sequence: number | null;
    weekly_periods_target: number | null;
}>({
    academic_year_id: props.academicYearId,
    campus_id: null,
    grade_level_id: null,
    subject_id: null,
    is_required: true,
    sequence: null,
    weekly_periods_target: null,
});

function submit() {
    form.academic_year_id = props.academicYearId;
    form.post('/app/subject-offerings', { preserveScroll: true });
}
</script>

<template>
    <section
        class="mt-8 rounded border border-slate-300 p-4"
        aria-labelledby="create-offering-heading"
    >
        <h2 id="create-offering-heading" class="font-semibold">
            Add a Subject Offering to {{ academicYearName }}
        </h2>
        <form class="mt-3 grid gap-3 sm:grid-cols-3" @submit.prevent="submit">
            <SelectField
                id="offering-grade"
                v-model="form.grade_level_id"
                label="Grade level"
                :options="gradeOptions"
                placeholder="Select a Grade level"
                required
                :error="form.errors.grade_level_id"
            />
            <SelectField
                id="offering-campus"
                v-model="form.campus_id"
                label="Campus"
                :options="campusOptions"
                placeholder="Select a Campus"
                required
                :error="form.errors.campus_id"
            />
            <SelectField
                id="offering-subject"
                v-model="form.subject_id"
                label="Subject"
                :options="subjectOptions"
                placeholder="Select a Subject"
                required
                :error="form.errors.subject_id"
            />
            <TextField
                id="offering-sequence"
                v-model="form.sequence"
                label="Display order"
                type="number"
                :min="0"
                :error="form.errors.sequence"
            />
            <TextField
                id="offering-periods"
                v-model="form.weekly_periods_target"
                label="Weekly periods target"
                type="number"
                :min="0"
                :error="form.errors.weekly_periods_target"
            />
            <CheckboxField
                id="offering-required"
                v-model="form.is_required"
                label="Required for every Student"
                hint="Clear it for an elective. Once the Offering has academic records this cannot change."
                :error="form.errors.is_required"
            />
            <div class="sm:col-span-3">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded bg-slate-900 px-3 py-2 text-white disabled:opacity-50"
                >
                    Add Subject Offering
                </button>
            </div>
        </form>
    </section>
</template>
