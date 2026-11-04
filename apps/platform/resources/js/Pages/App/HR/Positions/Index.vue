<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import EmptyState from '../../../../Components/EmptyState.vue';
import StatusBadge from '../../../../Components/StatusBadge.vue';

interface PositionRow {
    id: string;
    name: string;
    code: string;
    status: 'active' | 'inactive';
}

interface Props {
    positions: PositionRow[];
    canManage: boolean;
}

defineProps<Props>();

const showForm = ref(false);
const form = useForm({ name: '', code: '' });

function submit(): void {
    form.post('/app/hr/positions', {
        onSuccess: () => {
            showForm.value = false;
            form.reset();
        },
    });
}

function toggleStatus(position: PositionRow): void {
    const action = position.status === 'active' ? 'archive' : 'reactivate';
    router.post(`/app/hr/positions/${position.id}/${action}`, {}, { preserveScroll: true });
}
</script>

<template>
    <main class="mx-auto max-w-2xl p-8 font-sans text-ink">
        <a class="text-sm underline" href="/app/hr">← HR</a>

        <div class="mt-2 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Positions</h1>
                <p class="mt-1 text-sm text-ink-muted">Job title reference data.</p>
            </div>
            <button
                v-if="canManage"
                type="button"
                class="btn btn-primary shrink-0"
                @click="showForm = !showForm"
            >
                {{ showForm ? 'Cancel' : 'Add position' }}
            </button>
        </div>

        <form
            v-if="showForm"
            class="mt-6 rounded-xs border border-line p-4"
            @submit.prevent="submit"
        >
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div>
                    <label class="block text-sm text-ink-muted">Name</label>
                    <input v-model="form.name" type="text" required class="input mt-1" />
                    <p class="mt-1 text-sm text-danger">{{ form.errors.name }}</p>
                </div>
                <div>
                    <label class="block text-sm text-ink-muted">Code</label>
                    <input v-model="form.code" type="text" required class="input mt-1" />
                    <p class="mt-1 text-sm text-danger">{{ form.errors.code }}</p>
                </div>
            </div>
            <button type="submit" :disabled="form.processing" class="btn btn-primary mt-3">
                Add position
            </button>
        </form>

        <EmptyState
            v-if="positions.length === 0"
            class="mt-6"
            title="No positions yet"
            description="Add the first Position to begin organizing HR reference data."
        />

        <ul v-else class="mt-6 space-y-2">
            <li
                v-for="p in positions"
                :key="p.id"
                class="flex items-center justify-between gap-3 rounded-xs border border-line p-4 text-sm"
            >
                <div>
                    <p class="font-medium">
                        {{ p.name }} <span class="font-normal text-ink-muted">({{ p.code }})</span>
                    </p>
                    <div class="mt-1"><StatusBadge :status="p.status" /></div>
                </div>
                <button
                    v-if="canManage"
                    type="button"
                    class="shrink-0 text-sm underline"
                    @click="toggleStatus(p)"
                >
                    {{ p.status === 'active' ? 'Archive' : 'Reactivate' }}
                </button>
            </li>
        </ul>
    </main>
</template>
