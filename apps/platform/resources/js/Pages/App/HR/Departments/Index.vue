<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import EmptyState from '../../../../Components/EmptyState.vue';
import StatusBadge from '../../../../Components/StatusBadge.vue';

interface DepartmentRow {
    id: string;
    name: string;
    code: string;
    status: 'active' | 'inactive';
    campusId: string | null;
    parentDepartmentId: string | null;
    parentName: string | null;
}

interface CampusOption {
    id: string;
    name: string;
}

interface Props {
    departments: DepartmentRow[];
    campuses: CampusOption[];
    canManage: boolean;
}

defineProps<Props>();

const showForm = ref(false);
const form = useForm({ name: '', code: '', campus_id: '', parent_department_id: '' });

function submit(): void {
    form.post('/app/hr/departments', {
        onSuccess: () => {
            showForm.value = false;
            form.reset();
        },
    });
}

function toggleStatus(department: DepartmentRow): void {
    const action = department.status === 'active' ? 'archive' : 'reactivate';
    router.post(`/app/hr/departments/${department.id}/${action}`, {}, { preserveScroll: true });
}
</script>

<template>
    <main class="mx-auto max-w-2xl p-8 font-sans text-ink">
        <a class="text-sm underline" href="/app/hr">← HR</a>

        <div class="mt-2 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Departments</h1>
                <p class="mt-1 text-sm text-ink-muted">Organizational Department reference data.</p>
            </div>
            <button
                v-if="canManage"
                type="button"
                class="btn btn-primary shrink-0"
                @click="showForm = !showForm"
            >
                {{ showForm ? 'Cancel' : 'Add department' }}
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
                <div>
                    <label class="block text-sm text-ink-muted"
                        >Campus (optional -- blank means School-wide)</label
                    >
                    <select v-model="form.campus_id" class="input mt-1">
                        <option value="">School-wide</option>
                        <option v-for="c in campuses" :key="c.id" :value="c.id">
                            {{ c.name }}
                        </option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm text-ink-muted">Parent department (optional)</label>
                    <select v-model="form.parent_department_id" class="input mt-1">
                        <option value="">None (top-level)</option>
                        <option v-for="d in departments" :key="d.id" :value="d.id">
                            {{ d.name }}
                        </option>
                    </select>
                </div>
            </div>
            <button type="submit" :disabled="form.processing" class="btn btn-primary mt-3">
                Add department
            </button>
        </form>

        <EmptyState
            v-if="departments.length === 0"
            class="mt-6"
            title="No departments yet"
            description="Add the first Department to begin organizing HR reference data."
        />

        <ul v-else class="mt-6 space-y-2">
            <li
                v-for="d in departments"
                :key="d.id"
                class="flex items-start justify-between gap-3 rounded-xs border border-line p-4 text-sm"
            >
                <div>
                    <p class="font-medium">
                        {{ d.name }} <span class="font-normal text-ink-muted">({{ d.code }})</span>
                    </p>
                    <p class="mt-0.5 text-ink-muted">
                        {{ d.parentName ? `Under ${d.parentName}` : 'Top-level' }}
                    </p>
                    <div class="mt-1"><StatusBadge :status="d.status" /></div>
                </div>
                <button
                    v-if="canManage"
                    type="button"
                    class="shrink-0 text-sm underline"
                    @click="toggleStatus(d)"
                >
                    {{ d.status === 'active' ? 'Archive' : 'Reactivate' }}
                </button>
            </li>
        </ul>
    </main>
</template>
