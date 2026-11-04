<script setup lang="ts">
// ORG.7E-1 (ADR 0076 §43): Campus onboarding -- a new Employee, their first
// employment and first assignment, created together or not at all. Core work
// identity only: no account link, personal, document or pay field.
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

interface Option {
    id: string;
    name: string;
}

interface DepartmentOption extends Option {
    campusId: string | null;
}

interface CodeOption {
    value: string;
    label: string;
}

interface Props {
    campuses: Option[];
    departments: DepartmentOption[];
    positions: Option[];
    employmentTypes: CodeOption[];
    staffTypes: CodeOption[];
}

const props = defineProps<Props>();

const form = useForm({
    full_name: '',
    work_email: '',
    work_phone: '',
    employment_type: 'permanent',
    staff_type: '',
    starts_on: '',
    campus_id: props.campuses.length === 1 ? props.campuses[0].id : '',
    position_id: '',
    department_id: '',
});

const departmentChoices = computed(() =>
    props.departments.filter((d) => d.campusId === null || d.campusId === form.campus_id),
);

// A refusal not tied to one field (e.g. the person cannot be onboarded here).
const employeeError = computed(() => (form.errors as Record<string, string | undefined>).employee);

function submit(): void {
    form.post('/app/hr/employees/onboard');
}
</script>

<template>
    <main class="mx-auto max-w-xl p-8 font-sans text-ink">
        <a class="text-sm underline" href="/app/hr/employees">← Staff directory</a>
        <h1 class="mt-2 text-xl font-semibold">Onboard an employee</h1>
        <p class="mt-1 text-sm text-ink-muted">
            Creates the employee, their employment and their first assignment together. Personal
            details, documents and the sign-in account are added later by whole-School HR.
        </p>

        <p
            v-if="employeeError"
            role="alert"
            class="mt-4 rounded-xs border border-danger/40 bg-danger-tint p-3 text-sm text-danger"
        >
            {{ employeeError }}
        </p>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <div>
                <label class="block text-sm text-ink-muted" for="full-name">Full name</label>
                <input
                    id="full-name"
                    v-model="form.full_name"
                    type="text"
                    required
                    class="input mt-1"
                />
                <p class="mt-1 text-sm text-danger">{{ form.errors.full_name }}</p>
            </div>
            <div>
                <label class="block text-sm text-ink-muted" for="work-email">Work email</label>
                <input id="work-email" v-model="form.work_email" type="email" class="input mt-1" />
                <p class="mt-1 text-sm text-danger">{{ form.errors.work_email }}</p>
            </div>
            <div>
                <label class="block text-sm text-ink-muted" for="work-phone">Work phone</label>
                <input id="work-phone" v-model="form.work_phone" type="text" class="input mt-1" />
                <p class="mt-1 text-sm text-danger">{{ form.errors.work_phone }}</p>
            </div>
            <div>
                <label class="block text-sm text-ink-muted" for="employment-type"
                    >Employment type</label
                >
                <select
                    id="employment-type"
                    v-model="form.employment_type"
                    required
                    class="input mt-1"
                >
                    <option v-for="t in employmentTypes" :key="t.value" :value="t.value">
                        {{ t.label }}
                    </option>
                </select>
                <p class="mt-1 text-sm text-danger">{{ form.errors.employment_type }}</p>
            </div>
            <div>
                <label class="block text-sm text-ink-muted" for="staff-type">Staff type</label>
                <select id="staff-type" v-model="form.staff_type" required class="input mt-1">
                    <option value="" disabled>Select a staff type</option>
                    <option v-for="t in staffTypes" :key="t.value" :value="t.value">
                        {{ t.label }}
                    </option>
                </select>
                <p class="mt-1 text-sm text-danger">{{ form.errors.staff_type }}</p>
            </div>
            <div>
                <label class="block text-sm text-ink-muted" for="starts-on">Start date</label>
                <input
                    id="starts-on"
                    v-model="form.starts_on"
                    type="date"
                    required
                    class="input mt-1"
                />
                <p class="mt-1 text-sm text-ink-muted">Today or earlier.</p>
                <p class="mt-1 text-sm text-danger">{{ form.errors.starts_on }}</p>
            </div>
            <div>
                <label class="block text-sm text-ink-muted" for="campus">Campus</label>
                <select id="campus" v-model="form.campus_id" required class="input mt-1">
                    <option value="" disabled>Choose a campus</option>
                    <option v-for="c in campuses" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
                <p class="mt-1 text-sm text-danger">{{ form.errors.campus_id }}</p>
            </div>
            <div>
                <label class="block text-sm text-ink-muted" for="position">Position</label>
                <select id="position" v-model="form.position_id" required class="input mt-1">
                    <option value="" disabled>Choose a position</option>
                    <option v-for="p in positions" :key="p.id" :value="p.id">{{ p.name }}</option>
                </select>
                <p class="mt-1 text-sm text-danger">{{ form.errors.position_id }}</p>
            </div>
            <div>
                <label class="block text-sm text-ink-muted" for="department"
                    >Department (optional)</label
                >
                <select id="department" v-model="form.department_id" class="input mt-1">
                    <option value="">None</option>
                    <option v-for="d in departmentChoices" :key="d.id" :value="d.id">
                        {{ d.name }}
                    </option>
                </select>
            </div>

            <div class="flex items-center gap-3 pt-2">
                <button type="submit" :disabled="form.processing" class="btn btn-primary">
                    {{ form.processing ? 'Saving…' : 'Onboard employee' }}
                </button>
                <a class="text-sm underline" href="/app/hr/employees">Cancel</a>
            </div>
        </form>
    </main>
</template>
