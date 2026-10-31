<script setup lang="ts">
// SIS 11.7 (OD-SIS-4): Houses -- the first Student community. A House is
// School-wide, deactivated rather than deleted, and never a permission. The
// member count is an operational count of the Students this viewer may see.
import { useForm } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';
import ConfirmDialog from '../../../Components/ConfirmDialog.vue';
import EmptyState from '../../../Components/EmptyState.vue';
import { useConfirmation } from '../../../useConfirmation';

interface House {
    id: string;
    name: string;
    code: string;
    description: string | null;
    status: 'active' | 'inactive';
    displayOrder: number;
    version: number;
    members: number;
    rosterUrl: string;
}

const props = defineProps<{
    houses: House[];
    countsScope: 'whole_school' | 'campus';
    canManage: boolean;
}>();

const create = useForm({ name: '', code: '', description: '', display_order: 0 });
function submitCreate(): void {
    create.post('/app/students/houses', { preserveScroll: true, onSuccess: () => create.reset() });
}

const editing = ref<House | null>(null);
const edit = useForm({ name: '', code: '', description: '', display_order: 0, version: 0 });
function startEdit(h: House): void {
    editing.value = h;
    edit.defaults({
        name: h.name,
        code: h.code,
        description: h.description ?? '',
        display_order: h.displayOrder,
        version: h.version,
    });
    edit.reset();
    edit.clearErrors();
    nextTick(() => document.getElementById(`edit-name-${h.id}`)?.focus());
}
function submitEdit(h: House): void {
    edit.put(`/app/students/houses/${h.id}`, {
        preserveScroll: true,
        onSuccess: () => (editing.value = null),
    });
}

const statusForm = useForm({ status: '', version: 0 });
const { confirmDialog, confirmation, confirmAction, onConfirm, onClose } = useConfirmation();
async function setStatus(h: House, status: 'active' | 'inactive'): Promise<void> {
    if (
        status === 'inactive' &&
        !(await confirmAction({
            title: `Deactivate ${h.name}?`,
            body: 'Its history stays on every Student record. A House with current members cannot be deactivated: move or end their memberships first.',
            confirmLabel: 'Deactivate House',
        }))
    ) {
        return;
    }
    statusForm.status = status;
    statusForm.version = h.version;
    statusForm.post(`/app/students/houses/${h.id}/status`, { preserveScroll: true });
}
</script>

<template>
    <main class="mx-auto max-w-5xl px-4 py-6 font-sans text-slate-900 sm:p-8">
        <a class="text-sm underline" href="/app/students">← Students</a>
        <h1 class="mt-2 text-xl font-semibold">Houses</h1>
        <p class="mt-1 max-w-3xl text-sm text-slate-600">
            Houses are School-wide Student communities. Membership and House responsibilities are
            descriptive: they never change what anyone can see or do in Lycenza.
            <template v-if="props.countsScope === 'campus'">
                Member counts include only the Students on your Campuses.</template
            >
        </p>

        <EmptyState
            v-if="houses.length === 0"
            class="mt-6"
            title="No Houses yet"
            :description="
                canManage
                    ? 'Create the first House below.'
                    : 'Houses appear here once they are created.'
            "
        />
        <div v-else class="relative mt-6 overflow-x-auto rounded border border-slate-200">
            <table class="w-full min-w-[40rem] divide-y divide-slate-200 text-sm">
                <caption class="sr-only">
                    Houses
                </caption>
                <thead class="bg-slate-50 text-left text-slate-600">
                    <tr>
                        <th scope="col" class="px-3 py-2 font-medium">House</th>
                        <th scope="col" class="px-3 py-2 font-medium">Code</th>
                        <th scope="col" class="px-3 py-2 font-medium">Current members</th>
                        <th scope="col" class="px-3 py-2 font-medium">Status</th>
                        <th scope="col" class="px-3 py-2 font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    <template v-for="h in houses" :key="h.id">
                        <tr>
                            <td class="px-3 py-2">
                                <span class="font-medium">{{ h.name }}</span>
                                <span v-if="h.description" class="block text-xs text-slate-500">{{
                                    h.description
                                }}</span>
                            </td>
                            <td class="px-3 py-2">{{ h.code }}</td>
                            <td class="px-3 py-2">{{ h.members }}</td>
                            <td class="px-3 py-2">
                                {{ h.status === 'active' ? 'Active' : 'Inactive' }}
                            </td>
                            <td class="whitespace-nowrap px-3 py-2">
                                <a class="underline" :href="h.rosterUrl"
                                    >Roster<span class="sr-only"> of {{ h.name }}</span></a
                                >
                                <template v-if="canManage">
                                    <button
                                        type="button"
                                        class="ml-3 underline"
                                        :aria-expanded="editing?.id === h.id"
                                        @click="startEdit(h)"
                                    >
                                        Edit<span class="sr-only"> {{ h.name }}</span>
                                    </button>
                                    <button
                                        v-if="h.status === 'active'"
                                        type="button"
                                        class="ml-3 text-red-700 underline"
                                        @click="setStatus(h, 'inactive')"
                                    >
                                        Deactivate<span class="sr-only"> {{ h.name }}</span>
                                    </button>
                                    <button
                                        v-else
                                        type="button"
                                        class="ml-3 underline"
                                        @click="setStatus(h, 'active')"
                                    >
                                        Reactivate<span class="sr-only"> {{ h.name }}</span>
                                    </button>
                                </template>
                            </td>
                        </tr>
                        <tr v-if="editing?.id === h.id">
                            <td colspan="5" class="bg-slate-50 px-3 py-3">
                                <form
                                    class="grid gap-3 sm:grid-cols-2"
                                    :aria-label="`Edit ${h.name}`"
                                    @submit.prevent="submitEdit(h)"
                                >
                                    <div>
                                        <label class="block text-sm" :for="`edit-name-${h.id}`"
                                            >Name</label
                                        >
                                        <input
                                            :id="`edit-name-${h.id}`"
                                            v-model="edit.name"
                                            maxlength="80"
                                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                                            :aria-invalid="!!edit.errors.name"
                                            :aria-describedby="
                                                edit.errors.name
                                                    ? `edit-name-error-${h.id}`
                                                    : undefined
                                            "
                                        />
                                        <p
                                            v-if="edit.errors.name"
                                            :id="`edit-name-error-${h.id}`"
                                            class="mt-1 text-sm text-red-700"
                                        >
                                            {{ edit.errors.name }}
                                        </p>
                                    </div>
                                    <div>
                                        <label class="block text-sm" :for="`edit-code-${h.id}`"
                                            >Code</label
                                        >
                                        <input
                                            :id="`edit-code-${h.id}`"
                                            v-model="edit.code"
                                            maxlength="20"
                                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                                            :aria-invalid="!!edit.errors.code"
                                            :aria-describedby="
                                                edit.errors.code
                                                    ? `edit-code-error-${h.id}`
                                                    : undefined
                                            "
                                        />
                                        <p
                                            v-if="edit.errors.code"
                                            :id="`edit-code-error-${h.id}`"
                                            class="mt-1 text-sm text-red-700"
                                        >
                                            {{ edit.errors.code }}
                                        </p>
                                    </div>
                                    <div>
                                        <label
                                            class="block text-sm"
                                            :for="`edit-description-${h.id}`"
                                            >Description (optional)</label
                                        >
                                        <input
                                            :id="`edit-description-${h.id}`"
                                            v-model="edit.description"
                                            maxlength="255"
                                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                                        />
                                    </div>
                                    <div>
                                        <label class="block text-sm" :for="`edit-order-${h.id}`"
                                            >Display order</label
                                        >
                                        <input
                                            :id="`edit-order-${h.id}`"
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
                                            Save House
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
            <h2 id="create-heading" class="text-base font-semibold">Create a House</h2>
            <form class="mt-4 grid max-w-3xl gap-4 sm:grid-cols-2" @submit.prevent="submitCreate">
                <div>
                    <label class="block text-sm text-slate-700" for="house-name">Name</label>
                    <input
                        id="house-name"
                        v-model="create.name"
                        maxlength="80"
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        :aria-invalid="!!create.errors.name"
                        :aria-describedby="create.errors.name ? 'house-name-error' : undefined"
                    />
                    <p
                        v-if="create.errors.name"
                        id="house-name-error"
                        class="mt-1 text-sm text-red-700"
                    >
                        {{ create.errors.name }}
                    </p>
                </div>
                <div>
                    <label class="block text-sm text-slate-700" for="house-code">Code</label>
                    <input
                        id="house-code"
                        v-model="create.code"
                        maxlength="20"
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                        :aria-invalid="!!create.errors.code"
                        :aria-describedby="
                            create.errors.code ? 'house-code-error' : 'house-code-help'
                        "
                    />
                    <p id="house-code-help" class="mt-1 text-xs text-slate-500">
                        Letters, digits, - or _. Unique in your School.
                    </p>
                    <p
                        v-if="create.errors.code"
                        id="house-code-error"
                        class="mt-1 text-sm text-red-700"
                    >
                        {{ create.errors.code }}
                    </p>
                </div>
                <div>
                    <label class="block text-sm text-slate-700" for="house-description"
                        >Description (optional)</label
                    >
                    <input
                        id="house-description"
                        v-model="create.description"
                        maxlength="255"
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                    />
                </div>
                <div>
                    <label class="block text-sm text-slate-700" for="house-order"
                        >Display order</label
                    >
                    <input
                        id="house-order"
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
                        Create House
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
