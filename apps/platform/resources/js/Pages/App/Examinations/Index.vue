<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import EmptyState from '../../../Components/EmptyState.vue';

/**
 * Phase 0H.4A — the administrative Examination surface.
 *
 * An Examination is a named assessment WINDOW a School holds within one
 * AcademicYear ("Mid-Term Examination 2026-27, 10–20 September") — it is
 * NOT one Subject's paper. There is deliberately no paper editor, no
 * scheduling grid, no marks entry, no grade-scale editor, no results
 * view, no Student roster, no teacher UI and no report cards on this
 * page by design.
 *
 * Future dates are expected here (examinations are scheduled ahead), and
 * overlapping windows are permitted.
 *
 * `status` is edited through the ordinary update, exactly like every
 * other Academic Structure reference entity; there is no
 * activate/deactivate control and no delete.
 */
interface AcademicYearRow {
    id: string;
    name: string;
    code: string;
}

interface ExaminationRow {
    id: string;
    code: string;
    name: string;
    startsOn: string;
    endsOn: string;
    status: string;
}

interface Props {
    academicYears: AcademicYearRow[];
    filters: { academicYearId: string };
    examinations: ExaminationRow[];
    statuses: string[];
    canManage: boolean;
}

const props = defineProps<Props>();

const academicYearId = ref(props.filters.academicYearId);

watch(academicYearId, () => {
    router.get(
        '/app/examinations',
        { academic_year_id: academicYearId.value || undefined },
        { preserveState: false, replace: true },
    );
});

const createForm = useForm({
    academic_year_id: '',
    code: '',
    name: '',
    starts_on: '',
    ends_on: '',
    status: 'active',
});

function submitCreate(): void {
    createForm.academic_year_id = academicYearId.value;
    createForm.post('/app/examinations', {
        onSuccess: () => createForm.reset('code', 'name', 'starts_on', 'ends_on'),
    });
}

const editingId = ref<string | null>(null);
const editForm = useForm({
    code: '',
    name: '',
    starts_on: '',
    ends_on: '',
    status: 'active',
});

function startEdit(examination: ExaminationRow): void {
    editingId.value = examination.id;
    editForm.clearErrors();
    editForm.code = examination.code;
    editForm.name = examination.name;
    editForm.starts_on = examination.startsOn;
    editForm.ends_on = examination.endsOn;
    editForm.status = examination.status;
}

function submitEdit(): void {
    if (editingId.value === null) {
        return;
    }
    editForm.patch(`/app/examinations/${editingId.value}`, {
        onSuccess: () => {
            editingId.value = null;
        },
    });
}
</script>

<template>
    <main class="mx-auto max-w-5xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app">← Dashboard</a>

        <h1 class="mt-2 text-xl font-semibold">Examinations</h1>
        <p class="mt-1 text-sm text-slate-500">
            The named assessment windows this School holds in each Academic Year.
        </p>

        <div class="mt-6">
            <label class="block text-sm text-slate-600" for="filter-year">Academic Year</label>
            <select
                id="filter-year"
                v-model="academicYearId"
                class="mt-1 w-64 rounded border border-slate-300 px-3 py-2 text-sm"
            >
                <option value="">Select a year</option>
                <option v-for="year in academicYears" :key="year.id" :value="year.id">
                    {{ year.name }} ({{ year.code }})
                </option>
            </select>
        </div>

        <EmptyState
            v-if="!academicYearId"
            class="mt-8"
            title="Choose an Academic Year"
            description="Examinations belong to one Academic Year. Pick a year to view or define its examination windows."
        />

        <template v-else>
            <h2 class="mt-8 text-sm font-semibold text-slate-700">Examination windows</h2>

            <EmptyState
                v-if="examinations.length === 0"
                class="mt-2"
                title="No examinations yet"
                description="Define the first examination window for this Academic Year."
            />

            <table v-else class="mt-2 w-full border-collapse text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-left text-slate-500">
                        <th class="py-2">Code</th>
                        <th class="py-2">Name</th>
                        <th class="py-2">Starts</th>
                        <th class="py-2">Ends</th>
                        <th class="py-2">Status</th>
                        <th v-if="canManage" class="py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="examination in examinations"
                        :key="examination.id"
                        class="border-b border-slate-100"
                    >
                        <td class="py-2 font-medium">{{ examination.code }}</td>
                        <td class="py-2">{{ examination.name }}</td>
                        <td class="py-2">{{ examination.startsOn }}</td>
                        <td class="py-2">{{ examination.endsOn }}</td>
                        <td class="py-2 capitalize">{{ examination.status }}</td>
                        <td v-if="canManage" class="py-2 text-right">
                            <button type="button" class="underline" @click="startEdit(examination)">
                                Edit
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>

            <form
                v-if="canManage && editingId !== null"
                class="mt-6 rounded border border-slate-300 p-4"
                @submit.prevent="submitEdit"
            >
                <h3 class="text-sm font-semibold">Edit examination</h3>
                <div class="mt-3 flex flex-wrap gap-3">
                    <input
                        v-model="editForm.code"
                        type="text"
                        placeholder="Code"
                        class="w-32 rounded border border-slate-300 px-3 py-2 text-sm"
                    />
                    <input
                        v-model="editForm.name"
                        type="text"
                        placeholder="Name"
                        class="w-72 rounded border border-slate-300 px-3 py-2 text-sm"
                    />
                    <input
                        v-model="editForm.starts_on"
                        type="date"
                        class="rounded border border-slate-300 px-3 py-2 text-sm"
                    />
                    <input
                        v-model="editForm.ends_on"
                        type="date"
                        class="rounded border border-slate-300 px-3 py-2 text-sm"
                    />
                    <select
                        v-model="editForm.status"
                        class="w-32 rounded border border-slate-300 px-3 py-2 text-sm capitalize"
                    >
                        <option v-for="status in statuses" :key="status" :value="status">
                            {{ status }}
                        </option>
                    </select>
                    <button
                        type="submit"
                        class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
                        :disabled="editForm.processing"
                    >
                        Save
                    </button>
                    <button type="button" class="text-sm underline" @click="editingId = null">
                        Cancel
                    </button>
                </div>
                <p v-if="editForm.errors.starts_on" class="mt-2 text-sm text-red-700">
                    {{ editForm.errors.starts_on }}
                </p>
                <p v-if="editForm.errors.code" class="mt-2 text-sm text-red-700">
                    {{ editForm.errors.code }}
                </p>
            </form>

            <form
                v-if="canManage"
                class="mt-6 rounded border border-slate-300 p-4"
                @submit.prevent="submitCreate"
            >
                <h3 class="text-sm font-semibold">Add an examination</h3>
                <div class="mt-3 flex flex-wrap gap-3">
                    <input
                        v-model="createForm.code"
                        type="text"
                        placeholder="Code"
                        class="w-32 rounded border border-slate-300 px-3 py-2 text-sm"
                    />
                    <input
                        v-model="createForm.name"
                        type="text"
                        placeholder="Name"
                        class="w-72 rounded border border-slate-300 px-3 py-2 text-sm"
                    />
                    <input
                        v-model="createForm.starts_on"
                        type="date"
                        class="rounded border border-slate-300 px-3 py-2 text-sm"
                    />
                    <input
                        v-model="createForm.ends_on"
                        type="date"
                        class="rounded border border-slate-300 px-3 py-2 text-sm"
                    />
                    <button
                        type="submit"
                        class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
                        :disabled="createForm.processing"
                    >
                        Add examination
                    </button>
                </div>
                <p v-if="createForm.errors.code" class="mt-2 text-sm text-red-700">
                    {{ createForm.errors.code }}
                </p>
                <p v-if="createForm.errors.starts_on" class="mt-2 text-sm text-red-700">
                    {{ createForm.errors.starts_on }}
                </p>
            </form>
        </template>
    </main>
</template>
