<script setup lang="ts">
// SIS 11.7 / 11.8: the Student Communities directory -- Houses, Clubs or
// Activities (a closed type from the server, never chosen here). A group is
// School-wide, deactivated rather than deleted, and never a permission. The
// member count is an operational count of the Students this viewer may see.
import { useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';
import ConfirmDialog from '../../../Components/ConfirmDialog.vue';
import EmptyState from '../../../Components/EmptyState.vue';
import { useConfirmation } from '../../../useConfirmation';

interface Group {
    id: string;
    name: string;
    code: string;
    description: string | null;
    category: string | null;
    categoryLabel: string | null;
    status: 'active' | 'inactive';
    displayOrder: number;
    version: number;
    members: number;
    rosterUrl: string;
}

const props = defineProps<{
    type: { value: 'house' | 'club' | 'activity'; label: string; plural: string; baseUrl: string };
    groups: Group[];
    categories: { value: string; label: string }[];
    countsScope: 'whole_school' | 'campus';
    canManage: boolean;
}>();

const isActivity = computed(() => props.type.value === 'activity');
const membersLabel = computed(() =>
    isActivity.value ? 'Current participants' : 'Current members',
);
const intro = computed(
    () =>
        ({
            house: 'Houses are School-wide Student communities. A Student is in at most one House at a time.',
            club: 'Clubs are School-wide Student communities. A Student may belong to several Clubs at once.',
            activity:
                'Activities are School-wide extracurricular and co-curricular activities. A Student may take part in several at once.',
        })[props.type.value],
);

const create = useForm({
    name: '',
    code: '',
    description: '',
    display_order: 0,
    activity_category: '',
});
function submitCreate(): void {
    create.post(props.type.baseUrl, { preserveScroll: true, onSuccess: () => create.reset() });
}

const editing = ref<Group | null>(null);
const edit = useForm({
    name: '',
    code: '',
    description: '',
    display_order: 0,
    activity_category: '',
    version: 0,
});
function startEdit(g: Group): void {
    editing.value = g;
    edit.defaults({
        name: g.name,
        code: g.code,
        description: g.description ?? '',
        display_order: g.displayOrder,
        activity_category: g.category ?? '',
        version: g.version,
    });
    edit.reset();
    edit.clearErrors();
    nextTick(() => document.getElementById(`edit-name-${g.id}`)?.focus());
}
function submitEdit(g: Group): void {
    edit.put(`${props.type.baseUrl}/${g.id}`, {
        preserveScroll: true,
        onSuccess: () => (editing.value = null),
    });
}

const statusForm = useForm({ status: '', version: 0 });
const { confirmDialog, confirmation, confirmAction, onConfirm, onClose } = useConfirmation();
async function setStatus(g: Group, status: 'active' | 'inactive'): Promise<void> {
    if (
        status === 'inactive' &&
        !(await confirmAction({
            title: `Deactivate ${g.name}?`,
            body: `Its history stays on every Student record. A ${props.type.label} with current ${isActivity.value ? 'participants' : 'members'} cannot be deactivated: end them first.`,
            confirmLabel: `Deactivate ${props.type.label}`,
        }))
    ) {
        return;
    }
    statusForm.status = status;
    statusForm.version = g.version;
    statusForm.post(`${props.type.baseUrl}/${g.id}/status`, { preserveScroll: true });
}
</script>

<template>
    <main class="mx-auto max-w-5xl px-4 py-6 font-sans text-slate-900 sm:p-8">
        <a class="text-sm underline" href="/app/students/communities">← Student communities</a>
        <h1 class="mt-2 text-xl font-semibold">{{ type.plural }}</h1>
        <p class="mt-1 max-w-3xl text-sm text-slate-600">
            {{ intro }} Membership and responsibilities are descriptive: they never change what
            anyone can see or do in Lycenza.
            <template v-if="countsScope === 'campus'">
                Counts include only the Students on your Campuses.</template
            >
        </p>

        <EmptyState
            v-if="groups.length === 0"
            class="mt-6"
            :title="`No ${type.plural} yet`"
            :description="
                canManage
                    ? `Create the first ${type.label} below.`
                    : `${type.plural} appear here once they are created.`
            "
        />
        <div v-else class="relative mt-6 overflow-x-auto rounded border border-slate-200">
            <table class="w-full min-w-[40rem] divide-y divide-slate-200 text-sm">
                <caption class="sr-only">
                    {{
                        type.plural
                    }}
                </caption>
                <thead class="bg-slate-50 text-left text-slate-600">
                    <tr>
                        <th scope="col" class="px-3 py-2 font-medium">{{ type.label }}</th>
                        <th scope="col" class="px-3 py-2 font-medium">Code</th>
                        <th v-if="isActivity" scope="col" class="px-3 py-2 font-medium">
                            Category
                        </th>
                        <th scope="col" class="px-3 py-2 font-medium">{{ membersLabel }}</th>
                        <th scope="col" class="px-3 py-2 font-medium">Status</th>
                        <th scope="col" class="px-3 py-2 font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    <template v-for="g in groups" :key="g.id">
                        <tr>
                            <td class="px-3 py-2">
                                <span class="font-medium">{{ g.name }}</span>
                                <span v-if="g.description" class="block text-xs text-slate-500">{{
                                    g.description
                                }}</span>
                            </td>
                            <td class="px-3 py-2">{{ g.code }}</td>
                            <td v-if="isActivity" class="px-3 py-2">
                                {{ g.categoryLabel ?? '—' }}
                            </td>
                            <td class="px-3 py-2">{{ g.members }}</td>
                            <td class="px-3 py-2">
                                {{ g.status === 'active' ? 'Active' : 'Inactive' }}
                            </td>
                            <td class="whitespace-nowrap px-3 py-2">
                                <a class="underline" :href="g.rosterUrl"
                                    >Roster<span class="sr-only"> of {{ g.name }}</span></a
                                >
                                <template v-if="canManage">
                                    <button
                                        type="button"
                                        class="ml-3 underline"
                                        :aria-expanded="editing?.id === g.id"
                                        @click="startEdit(g)"
                                    >
                                        Edit<span class="sr-only"> {{ g.name }}</span>
                                    </button>
                                    <button
                                        v-if="g.status === 'active'"
                                        type="button"
                                        class="ml-3 text-red-700 underline"
                                        @click="setStatus(g, 'inactive')"
                                    >
                                        Deactivate<span class="sr-only"> {{ g.name }}</span>
                                    </button>
                                    <button
                                        v-else
                                        type="button"
                                        class="ml-3 underline"
                                        @click="setStatus(g, 'active')"
                                    >
                                        Reactivate<span class="sr-only"> {{ g.name }}</span>
                                    </button>
                                </template>
                            </td>
                        </tr>
                        <tr v-if="editing?.id === g.id">
                            <td :colspan="isActivity ? 6 : 5" class="bg-slate-50 px-3 py-3">
                                <form
                                    class="grid gap-3 sm:grid-cols-2"
                                    :aria-label="`Edit ${g.name}`"
                                    @submit.prevent="submitEdit(g)"
                                >
                                    <div>
                                        <label class="block text-sm" :for="`edit-name-${g.id}`"
                                            >Name</label
                                        >
                                        <input
                                            :id="`edit-name-${g.id}`"
                                            v-model="edit.name"
                                            maxlength="80"
                                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                                            :aria-invalid="!!edit.errors.name"
                                            :aria-describedby="
                                                edit.errors.name
                                                    ? `edit-name-error-${g.id}`
                                                    : undefined
                                            "
                                        />
                                        <p
                                            v-if="edit.errors.name"
                                            :id="`edit-name-error-${g.id}`"
                                            class="mt-1 text-sm text-red-700"
                                        >
                                            {{ edit.errors.name }}
                                        </p>
                                    </div>
                                    <div>
                                        <label class="block text-sm" :for="`edit-code-${g.id}`"
                                            >Code</label
                                        >
                                        <input
                                            :id="`edit-code-${g.id}`"
                                            v-model="edit.code"
                                            maxlength="20"
                                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                                            :aria-invalid="!!edit.errors.code"
                                            :aria-describedby="
                                                edit.errors.code
                                                    ? `edit-code-error-${g.id}`
                                                    : undefined
                                            "
                                        />
                                        <p
                                            v-if="edit.errors.code"
                                            :id="`edit-code-error-${g.id}`"
                                            class="mt-1 text-sm text-red-700"
                                        >
                                            {{ edit.errors.code }}
                                        </p>
                                    </div>
                                    <div>
                                        <label
                                            class="block text-sm"
                                            :for="`edit-description-${g.id}`"
                                            >Description (optional)</label
                                        >
                                        <input
                                            :id="`edit-description-${g.id}`"
                                            v-model="edit.description"
                                            maxlength="255"
                                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                                        />
                                    </div>
                                    <div v-if="isActivity">
                                        <label class="block text-sm" :for="`edit-category-${g.id}`"
                                            >Category (optional)</label
                                        >
                                        <select
                                            :id="`edit-category-${g.id}`"
                                            v-model="edit.activity_category"
                                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                                        >
                                            <option value="">No category</option>
                                            <option
                                                v-for="c in categories"
                                                :key="c.value"
                                                :value="c.value"
                                            >
                                                {{ c.label }}
                                            </option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm" :for="`edit-order-${g.id}`"
                                            >Display order</label
                                        >
                                        <input
                                            :id="`edit-order-${g.id}`"
                                            v-model.number="edit.display_order"
                                            type="number"
                                            min="0"
                                            max="999"
                                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                                        />
                                    </div>
                                    <div class="flex items-end gap-3 sm:col-span-2">
                                        <button
                                            type="submit"
                                            class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                                            :disabled="edit.processing"
                                        >
                                            Save {{ type.label }}
                                        </button>
                                        <button
                                            type="button"
                                            class="text-sm underline"
                                            @click="editing = null"
                                        >
                                            Cancel
                                        </button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <section v-if="canManage" class="mt-10" aria-labelledby="create-heading">
            <h2 id="create-heading" class="text-base font-semibold">Create a {{ type.label }}</h2>
            <form class="mt-4 grid max-w-3xl gap-4 sm:grid-cols-2" @submit.prevent="submitCreate">
                <div>
                    <label class="block text-sm text-slate-700" for="group-name">Name</label>
                    <input
                        id="group-name"
                        v-model="create.name"
                        maxlength="80"
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        :aria-invalid="!!create.errors.name"
                        :aria-describedby="create.errors.name ? 'group-name-error' : undefined"
                    />
                    <p
                        v-if="create.errors.name"
                        id="group-name-error"
                        class="mt-1 text-sm text-red-700"
                    >
                        {{ create.errors.name }}
                    </p>
                </div>
                <div>
                    <label class="block text-sm text-slate-700" for="group-code">Code</label>
                    <input
                        id="group-code"
                        v-model="create.code"
                        maxlength="20"
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        :aria-invalid="!!create.errors.code"
                        :aria-describedby="
                            create.errors.code ? 'group-code-error' : 'group-code-help'
                        "
                    />
                    <p id="group-code-help" class="mt-1 text-xs text-slate-500">
                        Letters, digits, - or _. Unique in your School.
                    </p>
                    <p
                        v-if="create.errors.code"
                        id="group-code-error"
                        class="mt-1 text-sm text-red-700"
                    >
                        {{ create.errors.code }}
                    </p>
                </div>
                <div>
                    <label class="block text-sm text-slate-700" for="group-description"
                        >Description (optional)</label
                    >
                    <input
                        id="group-description"
                        v-model="create.description"
                        maxlength="255"
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                    />
                </div>
                <div v-if="isActivity">
                    <label class="block text-sm text-slate-700" for="group-category"
                        >Category (optional)</label
                    >
                    <select
                        id="group-category"
                        v-model="create.activity_category"
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        :aria-invalid="!!create.errors.activity_category"
                    >
                        <option value="">No category</option>
                        <option v-for="c in categories" :key="c.value" :value="c.value">
                            {{ c.label }}
                        </option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm text-slate-700" for="group-order"
                        >Display order</label
                    >
                    <input
                        id="group-order"
                        v-model.number="create.display_order"
                        type="number"
                        min="0"
                        max="999"
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                    />
                </div>
                <div class="sm:col-span-2">
                    <button
                        type="submit"
                        class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                        :disabled="create.processing"
                    >
                        Create {{ type.label }}
                    </button>
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
