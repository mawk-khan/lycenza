<script setup lang="ts">
// SIS 2.1 (MC-4): correct one existing Guardian contact -- its value and
// label. The type, primary and active state do not change here; an inactive
// contact stays inactive until it is explicitly reactivated on the Guardian
// page. The value is shown as the Guardian page already shows it.
import { Head, useForm } from '@inertiajs/vue3';
import TextField from '../../../Components/Form/TextField.vue';

interface Props {
    guardian: { id: string; firstName: string; middleName: string | null; lastName: string | null };
    contact: {
        id: string;
        type: 'email' | 'mobile';
        value: string;
        label: string | null;
        isPrimary: boolean;
        isActive: boolean;
        version: string;
    };
}

const props = defineProps<Props>();

const guardianName = [props.guardian.firstName, props.guardian.middleName, props.guardian.lastName]
    .filter(Boolean)
    .join(' ');
const back = `/app/guardians/${props.guardian.id}`;
const isEmail = props.contact.type === 'email';

const form = useForm({
    value: props.contact.value,
    label: props.contact.label ?? '',
    expected_version: props.contact.version,
});

function submit(): void {
    form.put(`/app/contacts/${props.contact.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Correct contact" />
    <main class="mx-auto max-w-xl px-4 py-6 font-sans text-slate-900 sm:p-8">
        <a class="text-sm underline" :href="back">← {{ guardianName }}</a>
        <h1 class="mt-2 text-xl font-semibold">
            Correct {{ isEmail ? 'email address' : 'mobile number' }}
        </h1>
        <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
            <dt class="text-slate-500">Guardian</dt>
            <dd>{{ guardianName }}</dd>
            <dt class="text-slate-500">Type</dt>
            <dd>{{ isEmail ? 'Email' : 'Mobile' }}</dd>
            <dt class="text-slate-500">Status</dt>
            <dd>
                {{ contact.isActive ? 'Active' : 'Inactive'
                }}{{ contact.isPrimary ? ' · Primary' : '' }}
            </dd>
        </dl>
        <p class="mt-3 text-sm text-slate-600">
            For a typing mistake. The corrected value replaces the old one everywhere this Guardian
            is reached; the change is recorded in the audit log without the value.
            <template v-if="!contact.isActive">
                This contact stays inactive: reactivate it on the Guardian page to use it.
            </template>
        </p>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <TextField
                id="contact_value"
                v-model="form.value"
                :type="isEmail ? 'email' : 'tel'"
                :label="isEmail ? 'Email address' : 'Mobile number'"
                required
                :maxlength="255"
                :hint="isEmail ? undefined : 'Country code first, no spaces, e.g. +919876543210.'"
                :error="form.errors.value"
            />
            <TextField
                id="contact_label"
                v-model="form.label"
                label="Label (optional)"
                :maxlength="255"
                :error="form.errors.label"
            />

            <div class="flex flex-wrap items-center gap-3">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                >
                    {{ form.processing ? 'Saving…' : 'Save correction' }}
                </button>
                <a class="text-sm underline" :href="back">Cancel</a>
            </div>
        </form>
    </main>
</template>
