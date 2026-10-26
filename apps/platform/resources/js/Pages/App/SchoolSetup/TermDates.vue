<script setup lang="ts">
// SIS 5.1 (MC-3, ADR 0077): correct a Term's start and end dates while its
// Academic Year is a DRAFT. Name, code and order do not change here. The new
// dates must lie inside the year and not overlap another Term (shown on the
// start date). Once the year is active or closed this page is not offered.
import { Head, useForm } from '@inertiajs/vue3';
import TextField from '../../../Components/Form/TextField.vue';

interface Props {
    term: {
        id: string;
        name: string;
        code: string;
        sequence: number;
        startsOn: string;
        endsOn: string;
        updatedAt: string;
    };
    academicYear: { id: string; name: string; startsOn: string; endsOn: string };
}

const props = defineProps<Props>();

const back = `/app/school-setup/terms?academic_year_id=${props.academicYear.id}`;

const form = useForm({
    starts_on: props.term.startsOn,
    ends_on: props.term.endsOn,
    expected_updated_at: props.term.updatedAt,
});

function submit(): void {
    form.put(`/app/school-setup/terms/${props.term.id}/dates`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Correct term dates" />
    <main class="mx-auto max-w-xl px-4 py-6 font-sans text-slate-900 sm:p-8">
        <a class="text-sm underline" :href="back">← Terms</a>
        <h1 class="mt-2 text-xl font-semibold">Correct dates of {{ term.name }}</h1>
        <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
            <dt class="text-slate-500">Code</dt>
            <dd>{{ term.code }}</dd>
            <dt class="text-slate-500">Order</dt>
            <dd>{{ term.sequence }}</dd>
            <dt class="text-slate-500">Current dates</dt>
            <dd>{{ term.startsOn }} – {{ term.endsOn }}</dd>
            <dt class="text-slate-500">Academic year</dt>
            <dd>
                {{ academicYear.name }} (draft), {{ academicYear.startsOn }} –
                {{ academicYear.endsOn }}
            </dd>
        </dl>
        <p class="mt-3 text-sm text-slate-600">
            The dates can be corrected only while the academic year is a draft. The change is
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
                    hint="Inside the academic year, not overlapping another Term."
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
                <a class="text-sm underline" :href="back">Cancel</a>
            </div>
        </form>
    </main>
</template>
