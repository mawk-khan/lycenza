<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import EmptyState from '../../../../Components/EmptyState.vue';
import StatusBadge from '../../../../Components/StatusBadge.vue';

interface CategoryRow {
    id: string;
    name: string;
    code: string;
    status: 'active' | 'inactive';
}

interface Props {
    categories: CategoryRow[];
    canManage: boolean;
}

defineProps<Props>();

const showForm = ref(false);
const form = useForm({ name: '', code: '' });

function submit(): void {
    form.post('/app/hr/categories', {
        onSuccess: () => {
            showForm.value = false;
            form.reset();
        },
    });
}

function toggleStatus(category: CategoryRow): void {
    const action = category.status === 'active' ? 'archive' : 'reactivate';
    router.post(`/app/hr/categories/${category.id}/${action}`, {}, { preserveScroll: true });
}
</script>

<template>
    <main class="mx-auto max-w-2xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app/hr">← HR</a>

        <div class="mt-2 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Employee categories</h1>
                <p class="mt-1 text-sm text-slate-500">
                    Teaching / Non-Teaching / Contract / Visiting classification.
                </p>
            </div>
            <button
                v-if="canManage"
                type="button"
                class="shrink-0 rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800"
                @click="showForm = !showForm"
            >
                {{ showForm ? 'Cancel' : 'Add category' }}
            </button>
        </div>

        <form
            v-if="showForm"
            class="mt-6 rounded border border-slate-200 p-4"
            @submit.prevent="submit"
        >
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div>
                    <label class="block text-sm text-slate-600">Name</label>
                    <input
                        v-model="form.name"
                        type="text"
                        required
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                    />
                    <p class="mt-1 text-sm text-red-600">{{ form.errors.name }}</p>
                </div>
                <div>
                    <label class="block text-sm text-slate-600">Code</label>
                    <input
                        v-model="form.code"
                        type="text"
                        required
                        class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                    />
                    <p class="mt-1 text-sm text-red-600">{{ form.errors.code }}</p>
                </div>
            </div>
            <button
                type="submit"
                :disabled="form.processing"
                class="mt-3 rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
            >
                Add category
            </button>
        </form>

        <EmptyState
            v-if="categories.length === 0"
            class="mt-6"
            title="No categories yet"
            description="Add the first Employee Category to begin classifying Employees."
        />

        <ul v-else class="mt-6 space-y-2">
            <li
                v-for="c in categories"
                :key="c.id"
                class="flex items-center justify-between gap-3 rounded border border-slate-200 p-4 text-sm"
            >
                <div>
                    <p class="font-medium">
                        {{ c.name }} <span class="font-normal text-slate-500">({{ c.code }})</span>
                    </p>
                    <div class="mt-1"><StatusBadge :status="c.status" /></div>
                </div>
                <button
                    v-if="canManage"
                    type="button"
                    class="shrink-0 text-sm underline"
                    @click="toggleStatus(c)"
                >
                    {{ c.status === 'active' ? 'Archive' : 'Reactivate' }}
                </button>
            </li>
        </ul>
    </main>
</template>
