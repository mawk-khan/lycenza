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
    <main class="mx-auto max-w-2xl p-8 font-sans text-ink">
        <a class="text-sm underline" href="/app/hr">← HR</a>

        <div class="mt-2 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Employee categories</h1>
                <p class="mt-1 text-sm text-ink-muted">
                    Teaching / Non-Teaching / Contract / Visiting classification.
                </p>
            </div>
            <button
                v-if="canManage"
                type="button"
                class="btn btn-primary shrink-0"
                @click="showForm = !showForm"
            >
                {{ showForm ? 'Cancel' : 'Add category' }}
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
                class="flex items-center justify-between gap-3 rounded-xs border border-line p-4 text-sm"
            >
                <div>
                    <p class="font-medium">
                        {{ c.name }} <span class="font-normal text-ink-muted">({{ c.code }})</span>
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
