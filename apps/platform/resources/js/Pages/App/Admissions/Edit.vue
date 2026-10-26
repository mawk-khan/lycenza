<script setup lang="ts">
// SIS 3.1: state-aware correction of an Admission Application. While it is a
// draft or submitted, its year, Campus and grade; once decided, its decision
// note. Status changes only through the lifecycle actions, never here.
import { Head, useForm } from '@inertiajs/vue3';
import StatusBadge from '../../../Components/StatusBadge.vue';
import ErrorSummary from '../../../Components/Form/ErrorSummary.vue';
import SelectField from '../../../Components/Form/SelectField.vue';

interface Ref {
    id: string;
    name: string;
}

interface Props {
    application: {
        id: string;
        status: 'draft' | 'submitted' | 'accepted' | 'rejected' | 'withdrawn' | 'converted';
        applicant: { firstName: string; middleName: string | null; lastName: string | null };
        academicYear: Ref;
        campus: Ref;
        gradeLevel: Ref;
        decisionNote: string | null;
        updatedAt: string;
    };
    mode: 'context' | 'note';
    academicYears: Array<{ id: string; name: string; label: string }>;
    campuses: Ref[];
    gradeLevels: Ref[];
}

const props = defineProps<Props>();

const applicantName = [
    props.application.applicant.firstName,
    props.application.applicant.middleName,
    props.application.applicant.lastName,
]
    .filter(Boolean)
    .join(' ');

const form = useForm({
    expected_status: props.application.status,
    expected_updated_at: props.application.updatedAt,
    academic_year_id: props.application.academicYear.id,
    campus_id: props.application.campus.id,
    grade_level_id: props.application.gradeLevel.id,
    decision_note: props.application.decisionNote ?? '',
});

function submit(): void {
    form.transform((data) =>
        props.mode === 'context'
            ? {
                  expected_status: data.expected_status,
                  expected_updated_at: data.expected_updated_at,
                  academic_year_id: data.academic_year_id,
                  campus_id: data.campus_id,
                  grade_level_id: data.grade_level_id,
              }
            : {
                  expected_status: data.expected_status,
                  expected_updated_at: data.expected_updated_at,
                  decision_note: data.decision_note,
              },
    ).put(`/app/admissions/${props.application.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Correct application" />
    <main class="mx-auto max-w-xl px-4 py-6 font-sans text-slate-900 sm:p-8">
        <a class="text-sm underline" :href="`/app/admissions/${application.id}`">← Back</a>
        <div class="mt-2 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Correct application</h1>
                <p class="mt-1 text-sm text-slate-500">{{ applicantName }}</p>
            </div>
            <StatusBadge :status="application.status" />
        </div>
        <p class="mt-2 text-sm text-slate-600">
            <template v-if="mode === 'context'">
                For a data-entry mistake in what this application is for. The status stays as it is;
                names and date of birth are corrected on the applicant's page.
            </template>
            <template v-else>
                The decision itself cannot change here, and the year, Campus and grade are fixed
                once a decision is made. You can correct the decision note.
            </template>
        </p>

        <ErrorSummary
            :errors="form.errors"
            :fields="['correction', 'expected_status', 'expected_updated_at']"
        />

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <template v-if="mode === 'context'">
                <SelectField
                    id="academic_year_id"
                    v-model="form.academic_year_id"
                    label="Academic Year"
                    required
                    :options="academicYears.map((y) => ({ value: y.id, label: y.label }))"
                    :error="form.errors.academic_year_id"
                />
                <SelectField
                    id="campus_id"
                    v-model="form.campus_id"
                    label="Campus"
                    required
                    :options="campuses.map((c) => ({ value: c.id, label: c.name }))"
                    :error="form.errors.campus_id"
                />
                <SelectField
                    id="grade_level_id"
                    v-model="form.grade_level_id"
                    label="Grade"
                    required
                    :options="gradeLevels.map((g) => ({ value: g.id, label: g.name }))"
                    :error="form.errors.grade_level_id"
                />
            </template>
            <template v-else>
                <dl class="grid grid-cols-1 gap-3 rounded border border-slate-200 p-4 text-sm">
                    <div>
                        <dt class="text-slate-500">Applied for (fixed)</dt>
                        <dd class="mt-0.5">
                            {{ application.gradeLevel.name }} · {{ application.campus.name }} ·
                            {{ application.academicYear.name }}
                        </dd>
                    </div>
                </dl>
                <div>
                    <label class="block text-sm font-medium text-slate-700" for="decision_note"
                        >Decision note</label
                    >
                    <textarea
                        id="decision_note"
                        v-model="form.decision_note"
                        rows="4"
                        maxlength="1000"
                        :aria-invalid="form.errors.decision_note ? 'true' : undefined"
                        :aria-describedby="
                            form.errors.decision_note ? 'decision_note-error' : undefined
                        "
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                    />
                    <p
                        v-if="form.errors.decision_note"
                        id="decision_note-error"
                        class="mt-1 text-sm text-red-700"
                    >
                        {{ form.errors.decision_note }}
                    </p>
                </div>
            </template>

            <div class="flex flex-wrap items-center gap-3">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                >
                    {{ form.processing ? 'Saving…' : 'Save correction' }}
                </button>
                <a class="text-sm underline" :href="`/app/admissions/${application.id}`">Cancel</a>
            </div>
        </form>
    </main>
</template>
