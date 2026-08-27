<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import StatusBadge from '../../../../Components/StatusBadge.vue';

interface CopyRow {
    id: string;
    code: string;
    status: 'active' | 'inactive';
    available: boolean;
    activeLoan: { id: string; studentName: string; dueAt: string } | null;
}

interface Props {
    title: {
        id: string;
        title: string;
        author: string | null;
        isbn: string | null;
        status: 'active' | 'inactive';
    };
    copies: CopyRow[];
    canManage: boolean;
}

const props = defineProps<Props>();

const showAddCopy = ref(false);
const form = useForm({ code: '' });

function submitCopy(): void {
    form.post(`/app/library/titles/${props.title.id}/copies`, {
        onSuccess: () => {
            form.reset();
            showAddCopy.value = false;
        },
    });
}
</script>

<template>
    <main class="mx-auto max-w-3xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/library/titles">← Library catalogue</a>

        <div class="mt-2 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">{{ title.title }}</h1>
                <p class="mt-1 text-sm text-slate-500">{{ title.author ?? 'Author unknown' }}</p>
                <p v-if="title.isbn" class="mt-1 text-xs text-slate-400">ISBN {{ title.isbn }}</p>
            </div>
            <StatusBadge :status="title.status" />
        </div>

        <div class="mt-8 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-slate-900">Copies</h2>
            <button
                v-if="canManage"
                type="button"
                class="rounded bg-slate-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-800"
                @click="showAddCopy = !showAddCopy"
            >
                {{ showAddCopy ? 'Cancel' : 'Add copy' }}
            </button>
        </div>

        <form v-if="showAddCopy" class="mt-3 flex items-end gap-3" @submit.prevent="submitCopy">
            <div>
                <label class="block text-sm text-slate-600" for="copy-code">Accession code</label>
                <input
                    id="copy-code"
                    v-model="form.code"
                    type="text"
                    class="mt-1 w-48 rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <p v-if="form.errors.code" class="mt-1 text-sm text-red-600">
                    {{ form.errors.code }}
                </p>
            </div>
            <button
                type="submit"
                :disabled="form.processing"
                class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
            >
                {{ form.processing ? 'Saving…' : 'Register copy' }}
            </button>
        </form>

        <p v-if="copies.length === 0" class="mt-4 text-sm text-slate-500">
            No copies registered yet.
        </p>

        <table v-else class="mt-4 w-full text-left text-sm">
            <thead>
                <tr class="border-b border-slate-200 text-xs text-slate-500">
                    <th scope="col" class="py-2 font-medium">Code</th>
                    <th scope="col" class="py-2 font-medium">Status</th>
                    <th scope="col" class="py-2 font-medium">Availability</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <tr v-for="copy in copies" :key="copy.id">
                    <td class="py-3 font-medium">{{ copy.code }}</td>
                    <td class="py-3"><StatusBadge :status="copy.status" /></td>
                    <td class="py-3 text-slate-600">
                        <span v-if="copy.available" class="text-emerald-700">Available</span>
                        <span v-else-if="copy.activeLoan">
                            On loan to {{ copy.activeLoan.studentName }} (due
                            {{ copy.activeLoan.dueAt }})
                        </span>
                        <span v-else>Unavailable</span>
                    </td>
                </tr>
            </tbody>
        </table>
    </main>
</template>
