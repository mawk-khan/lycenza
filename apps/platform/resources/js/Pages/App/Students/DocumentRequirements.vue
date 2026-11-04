<script setup lang="ts">
// SIS 11.5: the School's document requirement rules. A rule names a closed
// document type, Required or Optional, one academic year and optionally one
// Campus and/or Grade. Rules are guidance only -- they never block an
// admission, conversion or placement. Terms never change: a correction is
// retire + a new rule (both recorded).
import { useConfirmation } from '../../../useConfirmation';
import { router, useForm } from '@inertiajs/vue3';
import ConfirmDialog from '../../../Components/ConfirmDialog.vue';
import EmptyState from '../../../Components/EmptyState.vue';
import { formatCalendarDate, formatDateTime } from '../../../datetime';

interface Option {
    value: string;
    label: string;
}

interface Requirement {
    id: string;
    subject: string;
    subjectLabel: string;
    categoryLabel: string;
    required: boolean;
    year: string;
    campus: string;
    grade: string;
    effectiveFrom: string;
    retiredAt: string | null;
}

const props = defineProps<{
    requirements: Requirement[];
    options: {
        subjects: Option[];
        categories: Option[];
        years: Option[];
        campuses: Option[];
        grades: Option[];
    };
}>();

const form = useForm({
    subject: 'student',
    category: '',
    required: true,
    academic_year_id: props.options.years[0]?.value ?? '',
    campus_id: '',
    grade_level_id: '',
    effective_from: '',
});

function submit(): void {
    form.transform((data) => ({
        ...data,
        campus_id: data.campus_id || null,
        grade_level_id: data.grade_level_id || null,
        effective_from: data.effective_from || null,
    })).post('/app/students/document-requirements', {
        preserveScroll: true,
        onSuccess: () => form.reset('category', 'campus_id', 'grade_level_id', 'effective_from'),
    });
}

const { confirmDialog, confirmation, confirmAction, onConfirm, onClose } = useConfirmation();

async function retire(r: Requirement): Promise<void> {
    if (
        !(await confirmAction({
            title: 'Retire this requirement?',
            body: `${r.categoryLabel} for ${r.year} stops applying at once. Documents already collected stay on record.`,
            confirmLabel: 'Retire requirement',
        }))
    ) {
        return;
    }
    router.post(`/app/students/document-requirements/${r.id}/retire`, {}, { preserveScroll: true });
}
</script>

<template>
    <main class="mx-auto max-w-6xl px-4 py-6 font-sans text-ink sm:p-8">
        <a class="text-sm underline" href="/app/students/document-compliance"
            >← Document checklist</a
        >
        <h1 class="mt-2 text-xl font-semibold">Document requirements</h1>
        <p class="mt-2 max-w-3xl text-sm text-ink-muted">
            Which documents the School expects from Students placed in a year, or from Applicants
            applying for it. Requirements are guidance for staff: they never block an admission, a
            conversion or a placement.
        </p>

        <EmptyState
            v-if="requirements.length === 0"
            class="mt-6"
            title="No requirements yet"
            description="Add the first requirement below."
        />
        <div v-else class="relative mt-6 overflow-x-auto rounded-xs border border-line">
            <table class="min-w-[48rem] w-full divide-y divide-line text-sm">
                <caption class="sr-only">
                    Document requirements
                </caption>
                <thead class="bg-graphite-100 text-left text-ink-muted">
                    <tr>
                        <th scope="col" class="px-3 py-2 font-medium">Document</th>
                        <th scope="col" class="px-3 py-2 font-medium">Applies to</th>
                        <th scope="col" class="px-3 py-2 font-medium">Year · Campus · Grade</th>
                        <th scope="col" class="px-3 py-2 font-medium">From</th>
                        <th scope="col" class="px-3 py-2 font-medium">Status</th>
                        <th scope="col" class="px-3 py-2 font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr
                        v-for="r in requirements"
                        :key="r.id"
                        :class="r.retiredAt ? 'text-ink-muted' : ''"
                    >
                        <td class="px-3 py-2">
                            <span class="font-medium">{{ r.categoryLabel }}</span>
                            <span class="block text-xs">{{
                                r.required ? 'Required' : 'Optional'
                            }}</span>
                        </td>
                        <td class="px-3 py-2">{{ r.subjectLabel }}</td>
                        <td class="px-3 py-2">{{ r.year }} · {{ r.campus }} · {{ r.grade }}</td>
                        <td class="whitespace-nowrap px-3 py-2">
                            {{ formatCalendarDate(r.effectiveFrom) }}
                        </td>
                        <td class="px-3 py-2">
                            {{ r.retiredAt ? `Retired ${formatDateTime(r.retiredAt)}` : 'Active' }}
                        </td>
                        <td class="px-3 py-2">
                            <button
                                v-if="!r.retiredAt"
                                type="button"
                                class="underline"
                                @click="retire(r)"
                            >
                                Retire<span class="sr-only">
                                    {{ r.categoryLabel }} {{ r.year }}</span
                                >
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <section class="mt-10" aria-labelledby="add-heading">
            <h2 id="add-heading" class="text-base font-semibold">Add a requirement</h2>
            <form class="mt-4 grid max-w-3xl gap-4 sm:grid-cols-2" @submit.prevent="submit">
                <div>
                    <label class="block text-sm text-ink" for="req-subject">Applies to</label>
                    <select id="req-subject" v-model="form.subject" class="input mt-1">
                        <option v-for="o in options.subjects" :key="o.value" :value="o.value">
                            {{ o.label }}
                        </option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm text-ink" for="req-category">Document type</label>
                    <select
                        id="req-category"
                        v-model="form.category"
                        class="input mt-1"
                        :aria-invalid="!!form.errors.category"
                        :aria-describedby="form.errors.category ? 'req-category-error' : undefined"
                    >
                        <option value="" disabled>Choose a type</option>
                        <option v-for="o in options.categories" :key="o.value" :value="o.value">
                            {{ o.label }}
                        </option>
                    </select>
                    <p
                        v-if="form.errors.category"
                        id="req-category-error"
                        class="mt-1 text-sm text-danger"
                    >
                        {{ form.errors.category }}
                    </p>
                </div>
                <div>
                    <label class="block text-sm text-ink" for="req-year">Academic year</label>
                    <select
                        id="req-year"
                        v-model="form.academic_year_id"
                        class="input mt-1"
                        :aria-invalid="!!form.errors.academic_year_id"
                        :aria-describedby="
                            form.errors.academic_year_id ? 'req-year-error' : undefined
                        "
                    >
                        <option v-for="o in options.years" :key="o.value" :value="o.value">
                            {{ o.label }}
                        </option>
                    </select>
                    <p
                        v-if="form.errors.academic_year_id"
                        id="req-year-error"
                        class="mt-1 text-sm text-danger"
                    >
                        {{ form.errors.academic_year_id }}
                    </p>
                </div>
                <div>
                    <label class="block text-sm text-ink" for="req-campus">Campus</label>
                    <select id="req-campus" v-model="form.campus_id" class="input mt-1">
                        <option value="">All Campuses</option>
                        <option v-for="o in options.campuses" :key="o.value" :value="o.value">
                            {{ o.label }}
                        </option>
                    </select>
                    <p v-if="form.errors.campus_id" class="mt-1 text-sm text-danger">
                        {{ form.errors.campus_id }}
                    </p>
                </div>
                <div>
                    <label class="block text-sm text-ink" for="req-grade">Grade</label>
                    <select id="req-grade" v-model="form.grade_level_id" class="input mt-1">
                        <option value="">All Grades</option>
                        <option v-for="o in options.grades" :key="o.value" :value="o.value">
                            {{ o.label }}
                        </option>
                    </select>
                    <p v-if="form.errors.grade_level_id" class="mt-1 text-sm text-danger">
                        {{ form.errors.grade_level_id }}
                    </p>
                </div>
                <div>
                    <label class="block text-sm text-ink" for="req-from"
                        >Applies from (optional; today if empty)</label
                    >
                    <input
                        id="req-from"
                        v-model="form.effective_from"
                        type="date"
                        class="input mt-1"
                    />
                    <p v-if="form.errors.effective_from" class="mt-1 text-sm text-danger">
                        {{ form.errors.effective_from }}
                    </p>
                </div>
                <fieldset class="sm:col-span-2">
                    <legend class="text-sm text-ink">Expectation</legend>
                    <label class="mt-1 mr-4 inline-flex items-center gap-2 text-sm">
                        <input v-model="form.required" type="radio" :value="true" /> Required
                    </label>
                    <label class="mt-1 inline-flex items-center gap-2 text-sm">
                        <input v-model="form.required" type="radio" :value="false" /> Optional
                    </label>
                </fieldset>
                <div class="sm:col-span-2">
                    <button
                        type="submit"
                        class="btn btn-primary"
                        :disabled="form.processing || options.years.length === 0"
                    >
                        Add requirement
                    </button>
                    <p v-if="options.years.length === 0" class="mt-2 text-sm text-ink-muted">
                        Create a draft or active academic year first.
                    </p>
                </div>
            </form>
        </section>
        <ConfirmDialog
            ref="confirmDialog"
            :title="confirmation?.title ?? ''"
            :confirm-label="confirmation?.confirmLabel"
            :tone="confirmation?.tone ?? 'danger'"
            @confirm="onConfirm"
            @close="onClose"
        >
            <p class="whitespace-pre-line">{{ confirmation?.body }}</p>
        </ConfirmDialog>
    </main>
</template>
