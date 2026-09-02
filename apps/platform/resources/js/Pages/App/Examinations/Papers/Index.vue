<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import EmptyState from '../../../../Components/EmptyState.vue';

/**
 * Phase 0H.4B — the administrative ExaminationPaper surface, a
 * drill-down from one Examination.
 *
 * An ExaminationPaper is one SubjectOffering assessed within one
 * Examination — its scheduled sitting (date and time range) and maximum
 * obtainable marks. Offering-wide, never Section-specific. There is
 * deliberately no marks entry, no grade-scale editor, no results view, no
 * Student roster and no teacher/invigilator/room UI on this page.
 *
 * `status` is edited through the ordinary update, exactly like the
 * Examination page — there is no activate/deactivate control and no
 * delete. Inactive Papers remain visible and editable here; reactivation
 * is only rejected server-side when a parent is currently inactive.
 */
interface ExaminationInfo {
    id: string;
    code: string;
    name: string;
    startsOn: string;
    endsOn: string;
    status: string;
}

interface SubjectOfferingOption {
    id: string;
    label: string;
    isRequired: boolean;
}

interface PaperRow {
    id: string;
    subjectOfferingId: string;
    scheduledOn: string;
    startsAt: string;
    endsAt: string;
    maxMarks: string;
    status: string;
}

interface Props {
    examination: ExaminationInfo;
    subjectOfferings: SubjectOfferingOption[];
    papers: PaperRow[];
    statuses: string[];
    canManage: boolean;
}

const props = defineProps<Props>();

function offeringLabel(id: string): string {
    return props.subjectOfferings.find((o) => o.id === id)?.label ?? id;
}

const createForm = useForm({
    subject_offering_id: '',
    scheduled_on: '',
    starts_at: '',
    ends_at: '',
    max_marks: '',
    status: 'active',
});

function submitCreate(): void {
    createForm.post(`/app/examinations/${props.examination.id}/papers`, {
        onSuccess: () =>
            createForm.reset(
                'subject_offering_id',
                'scheduled_on',
                'starts_at',
                'ends_at',
                'max_marks',
            ),
    });
}

const editingId = ref<string | null>(null);
const editForm = useForm({
    scheduled_on: '',
    starts_at: '',
    ends_at: '',
    max_marks: '',
    status: 'active',
});

function startEdit(paper: PaperRow): void {
    editingId.value = paper.id;
    editForm.clearErrors();
    editForm.scheduled_on = paper.scheduledOn;
    editForm.starts_at = paper.startsAt;
    editForm.ends_at = paper.endsAt;
    editForm.max_marks = paper.maxMarks;
    editForm.status = paper.status;
}

function submitEdit(): void {
    if (editingId.value === null) {
        return;
    }
    editForm.patch(`/app/examinations/${props.examination.id}/papers/${editingId.value}`, {
        onSuccess: () => {
            editingId.value = null;
        },
    });
}
</script>

<template>
    <main class="mx-auto max-w-5xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/examinations">← Examinations</a>

        <h1 class="mt-2 text-xl font-semibold">{{ examination.name }} — Papers</h1>
        <p class="mt-1 text-sm text-slate-500">
            {{ examination.code }} · {{ examination.startsOn }} to {{ examination.endsOn }} ·
            <span class="capitalize">{{ examination.status }}</span>
        </p>

        <EmptyState
            v-if="papers.length === 0"
            class="mt-8"
            title="No papers yet"
            description="Schedule the first SubjectOffering's paper for this Examination."
        />

        <table v-else class="mt-8 w-full border-collapse text-sm">
            <thead>
                <tr class="border-b border-slate-200 text-left text-slate-500">
                    <th class="py-2">Subject Offering</th>
                    <th class="py-2">Date</th>
                    <th class="py-2">Start</th>
                    <th class="py-2">End</th>
                    <th class="py-2">Max Marks</th>
                    <th class="py-2">Status</th>
                    <th v-if="canManage" class="py-2"></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="paper in papers" :key="paper.id" class="border-b border-slate-100">
                    <td class="py-2 font-medium">{{ offeringLabel(paper.subjectOfferingId) }}</td>
                    <td class="py-2">{{ paper.scheduledOn }}</td>
                    <td class="py-2">{{ paper.startsAt }}</td>
                    <td class="py-2">{{ paper.endsAt }}</td>
                    <td class="py-2">{{ paper.maxMarks }}</td>
                    <td class="py-2 capitalize">{{ paper.status }}</td>
                    <td v-if="canManage" class="py-2 text-right">
                        <button type="button" class="underline" @click="startEdit(paper)">
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
            <h3 class="text-sm font-semibold">Edit paper</h3>
            <div class="mt-3 flex flex-wrap gap-3">
                <input
                    v-model="editForm.scheduled_on"
                    type="date"
                    class="rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <input
                    v-model="editForm.starts_at"
                    type="time"
                    step="1"
                    class="rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <input
                    v-model="editForm.ends_at"
                    type="time"
                    step="1"
                    class="rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <input
                    v-model="editForm.max_marks"
                    type="number"
                    min="0.01"
                    step="0.01"
                    placeholder="Max marks"
                    class="w-32 rounded border border-slate-300 px-3 py-2 text-sm"
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
            <p v-if="editForm.errors.scheduled_on" class="mt-2 text-sm text-red-700">
                {{ editForm.errors.scheduled_on }}
            </p>
            <p v-if="editForm.errors.status" class="mt-2 text-sm text-red-700">
                {{ editForm.errors.status }}
            </p>
        </form>

        <form
            v-if="canManage"
            class="mt-6 rounded border border-slate-300 p-4"
            @submit.prevent="submitCreate"
        >
            <h3 class="text-sm font-semibold">Schedule a paper</h3>
            <div class="mt-3 flex flex-wrap gap-3">
                <select
                    v-model="createForm.subject_offering_id"
                    class="w-64 rounded border border-slate-300 px-3 py-2 text-sm"
                >
                    <option value="">Select a Subject Offering</option>
                    <option
                        v-for="offering in subjectOfferings"
                        :key="offering.id"
                        :value="offering.id"
                    >
                        {{ offering.label }} ({{ offering.isRequired ? 'Required' : 'Elective' }})
                    </option>
                </select>
                <input
                    v-model="createForm.scheduled_on"
                    type="date"
                    class="rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <input
                    v-model="createForm.starts_at"
                    type="time"
                    step="1"
                    class="rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <input
                    v-model="createForm.ends_at"
                    type="time"
                    step="1"
                    class="rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <input
                    v-model="createForm.max_marks"
                    type="number"
                    min="0.01"
                    step="0.01"
                    placeholder="Max marks"
                    class="w-32 rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <button
                    type="submit"
                    class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
                    :disabled="createForm.processing"
                >
                    Schedule paper
                </button>
            </div>
            <p v-if="createForm.errors.subject_offering_id" class="mt-2 text-sm text-red-700">
                {{ createForm.errors.subject_offering_id }}
            </p>
            <p v-if="createForm.errors.scheduled_on" class="mt-2 text-sm text-red-700">
                {{ createForm.errors.scheduled_on }}
            </p>
        </form>
    </main>
</template>
