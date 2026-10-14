<script setup lang="ts">
// SWC.2 (ADR 0073 section 10): the Terms of one Academic Year -- list,
// create and rename over AcademicTermService. A Term's dates must fall
// inside its Year and not overlap another Term (the service's refusals,
// shown on the dates); dates and order cannot change after creation, and
// Terms have no deactivation -- the domain has none.
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';
import SelectField from '../../../Components/Form/SelectField.vue';
import TextField from '../../../Components/Form/TextField.vue';

interface Term {
    id: string;
    name: string;
    code: string;
    sequence: number;
    startsOn: string;
    endsOn: string;
}

interface Year {
    id: string;
    name: string;
    code: string;
    status: string;
    startsOn: string;
    endsOn: string;
}

const props = defineProps<{
    academicYears: Year[];
    selectedYearId: string | null;
    terms: Term[];
    canManage: boolean;
}>();

const yearOptions = computed(() =>
    props.academicYears.map((y) => ({ value: y.id, label: `${y.name} (${y.status})` })),
);
const selectedYear = ref(props.selectedYearId);
const year = computed(() => props.academicYears.find((y) => y.id === props.selectedYearId) ?? null);
function changeYear() {
    router.get(
        '/app/school-setup/terms',
        { academic_year_id: selectedYear.value },
        { preserveState: false },
    );
}

const create = useForm<{
    name: string;
    code: string;
    sequence: number | null;
    starts_on: string;
    ends_on: string;
}>({
    name: '',
    code: '',
    sequence: null,
    starts_on: '',
    ends_on: '',
});
function submitCreate() {
    if (!props.selectedYearId) return;
    create.post(`/app/school-setup/academic-years/${props.selectedYearId}/terms`, {
        preserveScroll: true,
        onSuccess: () => create.reset(),
    });
}

const editing = ref<Term | null>(null);
const edit = useForm({ name: '', code: '' });
function startEdit(term: Term) {
    editing.value = term;
    edit.clearErrors();
    edit.name = term.name;
    edit.code = term.code;
    nextTick(() => document.getElementById('edit-term-name')?.focus());
}
function submitEdit() {
    if (!editing.value) return;
    edit.put(`/app/school-setup/terms/${editing.value.id}`, {
        preserveScroll: true,
        onSuccess: () => (editing.value = null),
    });
}
</script>

<template>
    <Head title="Terms" />
    <main class="mx-auto max-w-4xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/school-setup">← School setup</a>
        <h1 class="mt-2 text-xl font-semibold">Terms</h1>

        <p v-if="props.academicYears.length === 0" class="mt-4 text-sm text-slate-600">
            Terms belong to an Academic Year. Create an Academic Year first.
        </p>
        <template v-else>
            <form class="mt-4 flex items-end gap-3" @submit.prevent="changeYear">
                <div class="w-72">
                    <SelectField
                        id="terms-year"
                        v-model="selectedYear"
                        label="Academic year"
                        :options="yearOptions"
                    />
                </div>
                <button type="submit" class="rounded border border-slate-300 px-3 py-2">
                    Show terms
                </button>
            </form>
            <p v-if="year" class="mt-2 text-sm text-slate-600">
                {{ year.name }} runs {{ year.startsOn }} – {{ year.endsOn }}.
            </p>

            <p v-if="props.terms.length === 0" class="mt-4 text-sm text-slate-600">
                This Academic Year has no Term yet.
            </p>
            <table v-else class="mt-4 w-full text-left text-sm">
                <caption class="sr-only">
                    Terms of
                    {{
                        year?.name
                    }}
                </caption>
                <thead class="border-b border-slate-300 text-slate-600">
                    <tr>
                        <th scope="col" class="py-2">Order</th>
                        <th scope="col" class="py-2">Name</th>
                        <th scope="col" class="py-2">Code</th>
                        <th scope="col" class="py-2">Dates</th>
                        <th v-if="props.canManage" scope="col" class="py-2">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    <tr v-for="term in props.terms" :key="term.id">
                        <td class="py-2">{{ term.sequence }}</td>
                        <td class="py-2 font-medium">{{ term.name }}</td>
                        <td class="py-2">{{ term.code }}</td>
                        <td class="py-2">{{ term.startsOn }} – {{ term.endsOn }}</td>
                        <td v-if="props.canManage" class="py-2 text-right">
                            <button type="button" class="underline" @click="startEdit(term)">
                                Rename<span class="sr-only"> {{ term.name }}</span>
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>

            <section
                v-if="editing"
                class="mt-6 rounded border border-slate-300 p-4"
                aria-labelledby="edit-term-heading"
            >
                <h2 id="edit-term-heading" class="font-semibold">Rename {{ editing.name }}</h2>
                <p class="mt-1 text-sm text-slate-600">
                    A Term's dates and order cannot change after it is created.
                </p>
                <form class="mt-3 grid gap-3 sm:grid-cols-2" @submit.prevent="submitEdit">
                    <TextField
                        id="edit-term-name"
                        v-model="edit.name"
                        label="Name"
                        required
                        :error="edit.errors.name"
                    />
                    <TextField
                        id="edit-term-code"
                        v-model="edit.code"
                        label="Code"
                        required
                        :maxlength="32"
                        :error="edit.errors.code"
                    />
                    <div class="flex gap-3 sm:col-span-2">
                        <button
                            type="submit"
                            :disabled="edit.processing"
                            class="rounded bg-slate-900 px-3 py-2 text-white disabled:opacity-50"
                        >
                            Save
                        </button>
                        <button
                            type="button"
                            class="rounded border border-slate-300 px-3 py-2"
                            @click="editing = null"
                        >
                            Cancel
                        </button>
                    </div>
                </form>
            </section>

            <section
                v-if="props.canManage && props.selectedYearId"
                class="mt-8"
                aria-labelledby="add-term-heading"
            >
                <h2 id="add-term-heading" class="font-semibold">Add a Term to {{ year?.name }}</h2>
                <form class="mt-3 grid gap-3 sm:grid-cols-2" @submit.prevent="submitCreate">
                    <TextField
                        id="term-name"
                        v-model="create.name"
                        label="Name"
                        required
                        :error="create.errors.name"
                    />
                    <TextField
                        id="term-code"
                        v-model="create.code"
                        label="Code"
                        required
                        :maxlength="32"
                        hint="Unique in this Academic Year."
                        :error="create.errors.code"
                    />
                    <TextField
                        id="term-sequence"
                        v-model="create.sequence"
                        label="Order"
                        type="number"
                        :min="1"
                        required
                        :error="create.errors.sequence"
                    />
                    <div></div>
                    <TextField
                        id="term-starts"
                        v-model="create.starts_on"
                        label="Starts on"
                        type="date"
                        required
                        hint="Inside the Academic Year, and not overlapping another Term."
                        :error="create.errors.starts_on"
                    />
                    <TextField
                        id="term-ends"
                        v-model="create.ends_on"
                        label="Ends on"
                        type="date"
                        required
                        :error="create.errors.ends_on"
                    />
                    <div class="sm:col-span-2">
                        <button
                            type="submit"
                            :disabled="create.processing"
                            class="rounded bg-slate-900 px-3 py-2 text-white disabled:opacity-50"
                        >
                            Add Term
                        </button>
                    </div>
                </form>
            </section>
        </template>
    </main>
</template>
