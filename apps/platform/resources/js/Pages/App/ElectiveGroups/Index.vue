<script setup lang="ts">
// SWC.2 (ADR 0073 sections 4.3, 16 X1): Elective Groups of one Academic
// Year -- list and create over ElectiveGroupService. A group belongs to one
// (Year, Campus, Grade level); its code is unique there. Students choose at
// most one Offering of a group in the elective-enrollment workspace.
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import SelectField from '../../../Components/Form/SelectField.vue';
import TextField from '../../../Components/Form/TextField.vue';

interface Group {
    id: string;
    name: string;
    code: string;
    campus: string | null;
    gradeLevel: string | null;
    offeringCount: number;
}

const props = defineProps<{
    academicYears: Array<{ id: string; name: string; code: string }>;
    selectedYearId: string | null;
    groups: Group[];
    campuses: Array<{ id: string; name: string }>;
    gradeLevels: Array<{ id: string; name: string }>;
    canManage: boolean;
}>();

const yearOptions = computed(() =>
    props.academicYears.map((y) => ({ value: y.id, label: y.name })),
);
const campusOptions = computed(() => props.campuses.map((c) => ({ value: c.id, label: c.name })));
const gradeOptions = computed(() => props.gradeLevels.map((g) => ({ value: g.id, label: g.name })));
const selectedYear = ref(props.selectedYearId);
const yearName = computed(
    () => props.academicYears.find((y) => y.id === props.selectedYearId)?.name ?? '',
);
function changeYear() {
    router.get(
        '/app/elective-groups',
        { academic_year_id: selectedYear.value },
        { preserveState: false },
    );
}

const form = useForm<{
    academic_year_id: string | null;
    campus_id: string | null;
    grade_level_id: string | null;
    name: string;
    code: string;
}>({
    academic_year_id: props.selectedYearId,
    campus_id: null,
    grade_level_id: null,
    name: '',
    code: '',
});
function submit() {
    form.academic_year_id = props.selectedYearId;
    form.post('/app/elective-groups', { preserveScroll: true });
}
</script>

<template>
    <Head title="Elective groups" />
    <main class="mx-auto max-w-4xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/subject-offerings">← Subject offerings</a>
        <h1 class="mt-2 text-xl font-semibold">Elective groups</h1>
        <p class="mt-1 text-sm text-slate-600">
            A group collects alternative elective Offerings of one Grade level on one Campus; a
            Student takes at most one of them.
        </p>

        <p v-if="props.academicYears.length === 0" class="mt-4 text-sm text-slate-600">
            Elective groups belong to an Academic Year. Create an Academic Year first.
        </p>
        <template v-else>
            <form class="mt-4 flex items-end gap-3" @submit.prevent="changeYear">
                <div class="w-72">
                    <SelectField
                        id="groups-year"
                        v-model="selectedYear"
                        label="Academic year"
                        :options="yearOptions"
                    />
                </div>
                <button type="submit" class="rounded border border-slate-300 px-3 py-2">
                    Show groups
                </button>
            </form>

            <p v-if="props.groups.length === 0" class="mt-4 text-sm text-slate-600">
                {{ yearName }} has no Elective group yet.
            </p>
            <table v-else class="mt-4 w-full text-left text-sm">
                <caption class="sr-only">
                    Elective groups of
                    {{
                        yearName
                    }}
                </caption>
                <thead class="border-b border-slate-300 text-slate-600">
                    <tr>
                        <th scope="col" class="py-2">Name</th>
                        <th scope="col" class="py-2">Code</th>
                        <th scope="col" class="py-2">Grade level</th>
                        <th scope="col" class="py-2">Campus</th>
                        <th scope="col" class="py-2">Offerings</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    <tr v-for="group in props.groups" :key="group.id">
                        <td class="py-2 font-medium">
                            <a class="underline" :href="`/app/elective-groups/${group.id}`">{{
                                group.name
                            }}</a>
                        </td>
                        <td class="py-2">{{ group.code }}</td>
                        <td class="py-2">{{ group.gradeLevel }}</td>
                        <td class="py-2">{{ group.campus }}</td>
                        <td class="py-2">{{ group.offeringCount }}</td>
                    </tr>
                </tbody>
            </table>

            <section
                v-if="props.canManage && props.selectedYearId"
                class="mt-8"
                aria-labelledby="add-group-heading"
            >
                <h2 id="add-group-heading" class="font-semibold">
                    Add an Elective group to {{ yearName }}
                </h2>
                <form class="mt-3 grid gap-3 sm:grid-cols-2" @submit.prevent="submit">
                    <SelectField
                        id="group-grade"
                        v-model="form.grade_level_id"
                        label="Grade level"
                        :options="gradeOptions"
                        placeholder="Select a Grade level"
                        required
                        :error="form.errors.grade_level_id"
                    />
                    <SelectField
                        id="group-campus"
                        v-model="form.campus_id"
                        label="Campus"
                        :options="campusOptions"
                        placeholder="Select a Campus"
                        required
                        :error="form.errors.campus_id"
                    />
                    <TextField
                        id="group-name"
                        v-model="form.name"
                        label="Name"
                        required
                        :error="form.errors.name"
                    />
                    <TextField
                        id="group-code"
                        v-model="form.code"
                        label="Code"
                        required
                        :maxlength="32"
                        hint="Unique for the Grade level and Campus in this Year."
                        :error="form.errors.code"
                    />
                    <div class="sm:col-span-2">
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="rounded bg-slate-900 px-3 py-2 text-white disabled:opacity-50"
                        >
                            Add Elective group
                        </button>
                    </div>
                </form>
            </section>
        </template>
    </main>
</template>
