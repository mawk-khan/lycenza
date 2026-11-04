<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

interface Props {
    // ORG.7E-1: the Profile for a Profile reader, else the operational overview.
    backHref: string;
    employee: {
        id: string;
        employeeNumber: string;
        fullName: string;
        workEmail: string | null;
        workPhone: string | null;
        // HR.2A: the stale-form token; the save refuses a form opened before a newer change.
        version: string;
    };
}

const props = defineProps<Props>();

const form = useForm({
    full_name: props.employee.fullName,
    work_email: props.employee.workEmail ?? '',
    work_phone: props.employee.workPhone ?? '',
    version: props.employee.version,
});

// ORG.7E-1: a refusal not tied to one field (e.g. the Employee also works on
// a Campus outside the editor's scope).
const employeeError = computed(() => (form.errors as Record<string, string | undefined>).employee);

function submit(): void {
    form.put(`/app/hr/employees/${props.employee.id}`);
}
</script>

<template>
    <main class="mx-auto max-w-xl p-8 font-sans text-ink">
        <a class="text-sm underline" :href="backHref">← {{ employee.fullName }}</a>
        <h1 class="mt-2 text-xl font-semibold">Edit employee</h1>
        <p class="mt-1 text-sm text-ink-muted">{{ employee.employeeNumber }}</p>

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

            <div class="flex items-center gap-3 pt-2">
                <button type="submit" :disabled="form.processing" class="btn btn-primary">
                    {{ form.processing ? 'Saving…' : 'Save' }}
                </button>
                <a class="text-sm underline" :href="backHref">Cancel</a>
            </div>
        </form>
    </main>
</template>
