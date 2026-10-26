<script setup lang="ts">
// SIS 5.1 (MC-3, ADR 0077): correct a DRAFT Academic Year's start and end
// dates. Name, code and status do not change here. The service refuses dates
// that would leave a Term, a placement or another dependent record outside
// the year (shown as a refusal), and an overlap with another year (on the
// start date). Once the year is active or closed this page is not offered.
import { Head, useForm } from '@inertiajs/vue3';
import TextField from '../../../Components/Form/TextField.vue';

interface Props {
    academicYear: {
        id: string;
        name: string;
        code: string;
        status: string;
        startsOn: string;
        endsOn: string;
        updatedAt: string;
    };
}

const props = defineProps<Props>();

const form = useForm({
    starts_on: props.academicYear.startsOn,
    ends_on: props.academicYear.endsOn,
    expected_updated_at: props.academicYear.updatedAt,
});

function submit(): void {
    form.put(`/app/school-setup/academic-years/${props.academicYear.id}/dates`, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Correct academic year dates" />
    <main class="mx-auto max-w-xl px-4 py-6 font-sans text-slate-900 sm:p-8">
        <a class="text-sm underline" href="/app/school-setup/academic-years">← Academic years</a>
        <h1 class="mt-2 text-xl font-semibold">Correct dates of {{ academicYear.name }}</h1>
        <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
            <dt class="text-slate-500">Code</dt>
            <dd>{{ academicYear.code }}</dd>
            <dt class="text-slate-500">Status</dt>
            <dd>Draft</dd>
            <dt class="text-slate-500">Current dates</dt>
            <dd>{{ academicYear.startsOn }} – {{ academicYear.endsOn }}</dd>
        </dl>
        <p class="mt-3 text-sm text-slate-600">
            For a mistake made while preparing the year. The dates can be corrected only while the
            year is a draft; once it is activated they are fixed. Its Terms, placements and other
            records must stay inside the new dates, so correct those first if needed. The change is
            recorded in the audit log.
        </p>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <TextField
                    id="starts_on"
                    v-model="form.starts_on"
                    type="date"
                    label="Starts on"
                    required
                    hint="Must not overlap another Academic Year."
                    :error="form.errors.starts_on"
                />
                <TextField
                    id="ends_on"
                    v-model="form.ends_on"
                    type="date"
                    label="Ends on"
                    required
                    hint="Must be after the start date."
                    :error="form.errors.ends_on"
                />
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                >
                    {{ form.processing ? 'Saving…' : 'Save dates' }}
                </button>
                <a class="text-sm underline" href="/app/school-setup/academic-years">Cancel</a>
            </div>
        </form>
    </main>
</template>
