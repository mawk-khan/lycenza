<script setup lang="ts">
// SWC.2 (ADR 0073 sections 4.3, 16 X1): one Elective group -- its Offerings,
// and assigning or removing an elective Offering of the same (Year, Campus,
// Grade level) through ElectiveGroupService. The service refuses a required
// Offering, another context, and any change to an Offering that already
// has Student participation; those refusals are shown here.
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ConfirmDialog from '../../../Components/ConfirmDialog.vue';
import ErrorSummary from '../../../Components/Form/ErrorSummary.vue';
import SelectField from '../../../Components/Form/SelectField.vue';

interface Offering {
    id: string;
    subject: { name: string; code: string } | null;
    status: string;
    otherGroup: string | null;
}

const props = defineProps<{
    group: {
        id: string;
        name: string;
        code: string;
        academicYear: string | null;
        campus: string | null;
        gradeLevel: string | null;
    };
    assigned: Offering[];
    candidates: Offering[];
    canManage: boolean;
}>();

const label = (o: Offering) =>
    `${o.subject?.name ?? 'Subject'} (${o.subject?.code ?? '—'})${o.otherGroup ? ` — now in ${o.otherGroup}` : ''}`;
const candidateOptions = computed(() =>
    props.candidates.map((o) => ({ value: o.id, label: label(o) })),
);

const assign = useForm<{ subject_offering_id: string | null }>({ subject_offering_id: null });
function submitAssign() {
    assign.post(`/app/elective-groups/${props.group.id}/offerings`, {
        preserveScroll: true,
        onSuccess: () => assign.reset(),
    });
}

const remove = useForm({});
const pending = ref<Offering | null>(null);
const dialog = ref<InstanceType<typeof ConfirmDialog> | null>(null);
function askRemove(offering: Offering) {
    pending.value = offering;
    dialog.value?.open();
}
function runRemove() {
    if (!pending.value) return;
    remove.delete(`/app/elective-groups/${props.group.id}/offerings/${pending.value.id}`, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head :title="props.group.name" />
    <main class="mx-auto max-w-4xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/elective-groups">← Elective groups</a>
        <h1 class="mt-2 text-xl font-semibold">{{ props.group.name }} ({{ props.group.code }})</h1>
        <p class="mt-1 text-sm text-slate-600">
            {{ props.group.academicYear }} · {{ props.group.gradeLevel }} · {{ props.group.campus }}
        </p>

        <ErrorSummary :errors="remove.errors" :fields="['subject_offering_id']" />

        <section class="mt-6" aria-labelledby="assigned-heading">
            <h2 id="assigned-heading" class="font-semibold">Offerings in this group</h2>
            <p v-if="props.assigned.length === 0" class="mt-2 text-sm text-slate-600">
                No Offering is in this group yet.
            </p>
            <div v-else class="relative overflow-x-auto mt-2">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">
                        Offerings in
                        {{
                            props.group.name
                        }}
                    </caption>
                    <thead class="border-b border-slate-300 text-slate-600">
                        <tr>
                            <th scope="col" class="pr-3 py-2">Subject</th>
                            <th scope="col" class="pr-3 py-2">Status</th>
                            <th v-if="props.canManage" scope="col" class="pr-3 py-2">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        <tr v-for="offering in props.assigned" :key="offering.id">
                            <td class="pr-3 py-2">
                                <a
                                    class="underline"
                                    :href="`/app/subject-offerings/${offering.id}`"
                                    >{{ label(offering) }}</a
                                >
                            </td>
                            <td class="pr-3 py-2">
                                {{ offering.status === 'active' ? 'Active' : 'Inactive' }}
                            </td>
                            <td v-if="props.canManage" class="pr-3 py-2 text-right">
                                <button
                                    type="button"
                                    class="text-red-700 underline"
                                    :disabled="remove.processing"
                                    @click="askRemove(offering)"
                                >
                                    Remove<span class="sr-only"> {{ label(offering) }}</span>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section v-if="props.canManage" class="mt-8" aria-labelledby="assign-heading">
            <h2 id="assign-heading" class="font-semibold">Assign an elective Offering</h2>
            <p v-if="props.candidates.length === 0" class="mt-2 text-sm text-slate-600">
                No other active elective Offering exists for this Grade level and Campus in this
                Year.
            </p>
            <form v-else class="mt-3 flex items-end gap-3" @submit.prevent="submitAssign">
                <div class="flex-1">
                    <SelectField
                        id="assign-offering"
                        v-model="assign.subject_offering_id"
                        label="Elective Offering"
                        :options="candidateOptions"
                        placeholder="Select an Offering"
                        required
                        :error="assign.errors.subject_offering_id"
                    />
                </div>
                <button
                    type="submit"
                    :disabled="assign.processing"
                    class="rounded bg-slate-900 px-3 py-2 text-white disabled:opacity-50"
                >
                    Assign
                </button>
            </form>
        </section>

        <ConfirmDialog
            ref="dialog"
            :title="`Remove ${pending ? label(pending) : 'Offering'}?`"
            confirm-label="Remove"
            @confirm="runRemove"
        >
            The Offering leaves this group and stays in the records. An Offering with Student
            participation cannot be removed.
        </ConfirmDialog>
    </main>
</template>
