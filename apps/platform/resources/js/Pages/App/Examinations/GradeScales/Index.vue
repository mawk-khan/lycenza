<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import EmptyState from '../../../../Components/EmptyState.vue';

/**
 * Phase 0H.4C — the administrative GradeScale surface.
 *
 * A GradeScale is a named, School-owned mapping that converts a
 * normalized percentage (0.00–100.00) into a discrete grade outcome
 * through ordered, lower-bound-only thresholds — School-only, wholly
 * independent of the Examination chain (no AcademicYear/Examination
 * context on this page, unlike every other Examinations admin surface).
 *
 * GradeBands may be added, edited, or removed only while the scale is
 * `draft`. A scale may only activate once it has a band at the 0.00
 * threshold (this is what guarantees the whole 0–100 domain is
 * covered — there is no separate gap check). Once a scale has ever
 * been `active`, its bands are frozen forever, including while later
 * `inactive` — a grading-policy change is represented by creating a
 * new scale, never by editing bands on one that has ever been active.
 */
interface GradeBandRow {
    id: string;
    minPercentage: string;
    label: string;
}

interface GradeScaleRow {
    id: string;
    code: string;
    name: string;
    status: string;
    bands: GradeBandRow[];
}

interface Props {
    gradeScales: GradeScaleRow[];
    statuses: string[];
    canManage: boolean;
}

defineProps<Props>();

function hasFloorBand(scale: GradeScaleRow): boolean {
    return scale.bands.some((b) => Number(b.minPercentage) === 0);
}

const createForm = useForm({
    code: '',
    name: '',
});

function submitCreate(): void {
    createForm.post('/app/examinations/grade-scales', {
        onSuccess: () => createForm.reset('code', 'name'),
    });
}

const editingBandsFor = ref<string | null>(null);

function toggleBandEditor(scale: GradeScaleRow): void {
    editingBandsFor.value = editingBandsFor.value === scale.id ? null : scale.id;
}

const bandForm = useForm({
    min_percentage: '',
    label: '',
});

function submitAddBand(scale: GradeScaleRow): void {
    bandForm.post(`/app/examinations/grade-scales/${scale.id}/bands`, {
        onSuccess: () => bandForm.reset('min_percentage', 'label'),
    });
}

function removeBand(scale: GradeScaleRow, band: GradeBandRow): void {
    useForm({}).delete(`/app/examinations/grade-scales/${scale.id}/bands/${band.id}`);
}

const nameForm = useForm({ name: '' });
const editingNameFor = ref<string | null>(null);

function startEditName(scale: GradeScaleRow): void {
    editingNameFor.value = scale.id;
    nameForm.name = scale.name;
}

function submitName(scale: GradeScaleRow): void {
    nameForm.patch(`/app/examinations/grade-scales/${scale.id}`, {
        onSuccess: () => {
            editingNameFor.value = null;
        },
    });
}

function transition(scale: GradeScaleRow, status: string): void {
    useForm({ status }).patch(`/app/examinations/grade-scales/${scale.id}`);
}
</script>

<template>
    <main class="mx-auto max-w-5xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app">← Dashboard</a>

        <h1 class="mt-2 text-xl font-semibold">Grade Scales</h1>
        <p class="mt-1 text-sm text-slate-500">
            School-owned mappings from a percentage to a grade outcome. Independent of any
            Examination.
        </p>

        <EmptyState
            v-if="gradeScales.length === 0"
            class="mt-8"
            title="No grade scales yet"
            description="Define the first grade scale for this School."
        />

        <div
            v-for="scale in gradeScales"
            :key="scale.id"
            class="mt-6 rounded border border-slate-300 p-4"
        >
            <div class="flex items-center justify-between">
                <div>
                    <span class="font-medium">{{ scale.code }}</span>
                    <template v-if="editingNameFor === scale.id">
                        <form class="mt-1 flex gap-2" @submit.prevent="submitName(scale)">
                            <input
                                v-model="nameForm.name"
                                type="text"
                                class="rounded border border-slate-300 px-2 py-1 text-sm"
                            />
                            <button
                                type="submit"
                                class="text-sm underline"
                                :disabled="nameForm.processing"
                            >
                                Save
                            </button>
                            <button
                                type="button"
                                class="text-sm underline"
                                @click="editingNameFor = null"
                            >
                                Cancel
                            </button>
                        </form>
                    </template>
                    <template v-else>
                        <span class="ml-2 text-slate-600">{{ scale.name }}</span>
                        <button
                            v-if="canManage"
                            type="button"
                            class="ml-2 text-xs underline"
                            @click="startEditName(scale)"
                        >
                            edit name
                        </button>
                    </template>
                </div>
                <span class="rounded bg-slate-100 px-2 py-1 text-xs font-medium capitalize">{{
                    scale.status
                }}</span>
            </div>

            <table class="mt-3 w-full border-collapse text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-left text-slate-500">
                        <th class="py-1">Min %</th>
                        <th class="py-1">Label</th>
                        <th v-if="canManage && scale.status === 'draft'" class="py-1"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="band in scale.bands"
                        :key="band.id"
                        class="border-b border-slate-100"
                    >
                        <td class="py-1">{{ band.minPercentage }}</td>
                        <td class="py-1">{{ band.label }}</td>
                        <td v-if="canManage && scale.status === 'draft'" class="py-1 text-right">
                            <button
                                type="button"
                                class="text-xs underline"
                                @click="removeBand(scale, band)"
                            >
                                remove
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p v-if="!hasFloorBand(scale)" class="mt-2 text-xs text-amber-700">
                Missing a 0.00 threshold band — this scale cannot be activated until one is added.
            </p>

            <div v-if="canManage" class="mt-3 flex flex-wrap items-center gap-3">
                <template v-if="scale.status === 'draft'">
                    <button
                        type="button"
                        class="text-sm underline"
                        @click="toggleBandEditor(scale)"
                    >
                        {{ editingBandsFor === scale.id ? 'close' : 'add band' }}
                    </button>
                    <button
                        type="button"
                        class="rounded bg-slate-900 px-3 py-1 text-sm font-medium text-white hover:bg-slate-800"
                        :disabled="!hasFloorBand(scale)"
                        @click="transition(scale, 'active')"
                    >
                        Activate
                    </button>
                </template>
                <button
                    v-else-if="scale.status === 'active'"
                    type="button"
                    class="rounded bg-slate-900 px-3 py-1 text-sm font-medium text-white hover:bg-slate-800"
                    @click="transition(scale, 'inactive')"
                >
                    Deactivate
                </button>
                <button
                    v-else-if="scale.status === 'inactive'"
                    type="button"
                    class="rounded bg-slate-900 px-3 py-1 text-sm font-medium text-white hover:bg-slate-800"
                    @click="transition(scale, 'active')"
                >
                    Reactivate
                </button>
            </div>

            <form
                v-if="canManage && editingBandsFor === scale.id"
                class="mt-3 flex flex-wrap items-center gap-2 rounded border border-slate-200 p-2"
                @submit.prevent="submitAddBand(scale)"
            >
                <input
                    v-model="bandForm.min_percentage"
                    type="number"
                    min="0"
                    max="100"
                    step="0.01"
                    placeholder="Min %"
                    class="w-24 rounded border border-slate-300 px-2 py-1 text-sm"
                />
                <input
                    v-model="bandForm.label"
                    type="text"
                    placeholder="Label"
                    class="w-32 rounded border border-slate-300 px-2 py-1 text-sm"
                />
                <button type="submit" class="text-sm underline" :disabled="bandForm.processing">
                    Add
                </button>
                <p v-if="bandForm.errors.min_percentage" class="w-full text-xs text-red-700">
                    {{ bandForm.errors.min_percentage }}
                </p>
            </form>
        </div>

        <form
            v-if="canManage"
            class="mt-6 rounded border border-slate-300 p-4"
            @submit.prevent="submitCreate"
        >
            <h3 class="text-sm font-semibold">Add a grade scale</h3>
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
                <button
                    type="submit"
                    class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
                    :disabled="createForm.processing"
                >
                    Add grade scale
                </button>
            </div>
            <p v-if="createForm.errors.code" class="mt-2 text-sm text-red-700">
                {{ createForm.errors.code }}
            </p>
        </form>
    </main>
</template>
