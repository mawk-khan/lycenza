<script setup lang="ts">
// ORG.7F-3 (ADR 0076 §47): the Campus transfer of the Student's CURRENT
// placement to a class on another Campus, effective today. Destinations are
// only the server's in-scope options; the server re-checks both Campuses,
// the Student's currentness and any unresolved Hostel, Transport or elective
// arrangement, and moves nothing else.
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

interface Props {
    student: {
        id: string;
        studentNumber: string;
        firstName: string;
        middleName: string | null;
        lastName: string | null;
    };
    currentPlacement: {
        campus: string | null;
        gradeLevel: string | null;
        section: string | null;
        rollNumber: string | null;
    };
    destinations: Array<{ id: string; label: string }>;
}

const props = defineProps<Props>();

const fullName = computed(() =>
    [props.student.firstName, props.student.middleName, props.student.lastName]
        .filter(Boolean)
        .join(' '),
);

const form = useForm({ target_section_id: '', roll_number: '' });

function submit(): void {
    form.post(`/app/students/${props.student.id}/transfer`);
}
</script>

<template>
    <main class="mx-auto max-w-xl px-4 py-6 sm:p-8 font-sans text-slate-900">
        <a class="text-sm underline" :href="`/app/students/${student.id}/overview`">← Back</a>
        <h1 class="mt-2 text-xl font-semibold">Transfer {{ fullName }}</h1>
        <p class="mt-1 text-sm text-slate-500">
            {{ student.studentNumber }} · now {{ currentPlacement.gradeLevel }} · Section
            {{ currentPlacement.section }} · {{ currentPlacement.campus }}
        </p>
        <p class="mt-2 text-sm text-slate-600">
            The transfer takes effect today. Hostel, transport and elective arrangements are never
            moved: they must be resolved first.
        </p>

        <p v-if="destinations.length === 0" class="mt-6 text-sm text-slate-600">
            No class on another of your campuses is available for this student.
        </p>

        <form v-else class="mt-6 space-y-4" @submit.prevent="submit">
            <div>
                <label for="target_section_id" class="block text-sm font-medium">New class</label>
                <select
                    id="target_section_id"
                    v-model="form.target_section_id"
                    required
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                >
                    <option value="" disabled>Choose a class</option>
                    <option v-for="d in destinations" :key="d.id" :value="d.id">
                        {{ d.label }}
                    </option>
                </select>
                <p v-if="form.errors.target_section_id" class="mt-1 text-sm text-red-700">
                    {{ form.errors.target_section_id }}
                </p>
            </div>
            <div>
                <label for="roll_number" class="block text-sm font-medium">Roll number</label>
                <input
                    id="roll_number"
                    v-model="form.roll_number"
                    type="text"
                    required
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <p v-if="form.errors.roll_number" class="mt-1 text-sm text-red-700">
                    {{ form.errors.roll_number }}
                </p>
            </div>
            <button
                type="submit"
                :disabled="form.processing"
                class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
            >
                {{ form.processing ? 'Transferring…' : 'Transfer student' }}
            </button>
        </form>
    </main>
</template>
