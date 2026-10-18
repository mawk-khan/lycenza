<script setup lang="ts">
// ORG.5 (ADR 0076 §31): the review step of enabling or disabling one
// optional module for one School -- what changes, a reason, explicit
// confirmation and a fresh authentication code. Disabling removes access
// only: no data, file, configuration or role grant is deleted, and
// enabling the module again restores access.
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

interface Props {
    school: { id: string; name: string; slug: string; status: string; closed: boolean };
    module: { key: string; label: string; description: string; enabled: boolean };
    action: 'enable' | 'disable';
    missingDependencies: string[];
    impact: {
        dependentsEnabled: string[];
        capabilityCount: number;
        activeGrants: number;
        navigation: string[];
    } | null;
    reasons: { value: string; label: string }[];
    mfaEnrolled: boolean;
}

const props = defineProps<Props>();

const title = computed(() =>
    props.action === 'enable'
        ? `Enable ${props.module.label}`
        : `Disable access to ${props.module.label}`,
);

const blocked = computed(
    () =>
        props.school.closed ||
        (props.action === 'enable' && props.missingDependencies.length > 0) ||
        (props.action === 'disable' && (props.impact?.dependentsEnabled.length ?? 0) > 0),
);

const form = useForm({
    reason_code: '',
    confirmed: false,
    mfa_code: '',
});

function submit() {
    form.post(
        `/app/platform/schools/${props.school.id}/modules/${props.module.key}/${props.action}`,
        { onFinish: () => form.reset('mfa_code') },
    );
}

function moduleError(): string | undefined {
    return (form.errors as Record<string, string | undefined>).module;
}
</script>

<template>
    <main class="mx-auto max-w-lg p-4 font-sans text-slate-900 sm:p-8">
        <a class="text-sm underline" :href="`/app/platform/schools/${school.id}`"
            >← {{ school.name }}</a
        >
        <h1 class="mt-2 text-xl font-semibold" data-testid="module-title">{{ title }}</h1>

        <dl class="mt-6 space-y-2 text-sm">
            <div>
                <dt class="text-slate-500">School</dt>
                <dd class="font-medium">{{ school.name }} ({{ school.slug }})</dd>
            </div>
            <div>
                <dt class="text-slate-500">Module</dt>
                <dd>{{ module.label }} — {{ module.description }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Currently</dt>
                <dd data-testid="module-state">{{ module.enabled ? 'Enabled' : 'Disabled' }}</dd>
            </div>
        </dl>

        <p v-if="action === 'enable'" class="mt-4 text-sm text-slate-600">
            Staff who already hold roles for this module regain access immediately. Nothing is
            granted to anyone new; School roles stay as they are.
        </p>
        <div
            v-else-if="impact"
            class="mt-4 space-y-2 text-sm text-slate-600"
            data-testid="module-impact"
        >
            <p>
                Access ends immediately for everyone in this School. No data, file, configuration or
                role grant is deleted, and enabling the module again restores access. Queued work
                for this module stops when it next runs.
            </p>
            <p>
                {{ impact.capabilityCount }} permission(s) stop taking effect;
                {{ impact.activeGrants }} active role grant(s) carry at least one of them.
            </p>
            <p v-if="impact.navigation.length">
                Hidden from navigation: {{ impact.navigation.join(', ') }}.
            </p>
        </div>

        <p v-if="school.closed" class="mt-4 text-sm text-red-700" data-testid="module-blocked">
            A closed School's modules cannot be changed. Reopen it first.
        </p>
        <p
            v-else-if="action === 'enable' && missingDependencies.length"
            class="mt-4 text-sm text-red-700"
            data-testid="module-blocked"
        >
            Enable {{ missingDependencies.join(', ') }} first.
        </p>
        <p
            v-else-if="action === 'disable' && impact && impact.dependentsEnabled.length"
            class="mt-4 text-sm text-red-700"
            data-testid="module-blocked"
        >
            Disable {{ impact.dependentsEnabled.join(', ') }} first: they depend on this module.
        </p>
        <p v-if="moduleError()" class="mt-4 text-sm text-red-700" data-testid="module-error">
            {{ moduleError() }}
        </p>

        <p
            v-if="!mfaEnrolled"
            role="status"
            class="mt-6 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm"
        >
            This action requires multi-factor authentication. Enroll a factor under
            <a class="underline" href="/app/account/security">Account security</a> first.
        </p>

        <form v-else-if="!blocked" class="mt-6 space-y-4" @submit.prevent="submit">
            <div>
                <label class="block text-sm text-slate-600" for="reason_code">Reason</label>
                <select
                    id="reason_code"
                    v-model="form.reason_code"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                >
                    <option value="" disabled>Choose a reason</option>
                    <option v-for="r in reasons" :key="r.value" :value="r.value">
                        {{ r.label }}
                    </option>
                </select>
                <p v-if="form.errors.reason_code" class="mt-1 text-sm text-red-700">
                    {{ form.errors.reason_code }}
                </p>
            </div>
            <label class="flex items-start gap-2 text-sm">
                <input v-model="form.confirmed" type="checkbox" class="mt-1" />
                <span>I confirm: {{ title.toLowerCase() }} — {{ school.name }}.</span>
            </label>
            <p v-if="form.errors.confirmed" class="text-sm text-red-700">
                {{ form.errors.confirmed }}
            </p>
            <div>
                <label class="block text-sm text-slate-600" for="mfa_code">
                    Authentication code (or a recovery code)
                </label>
                <input
                    id="mfa_code"
                    v-model="form.mfa_code"
                    autocomplete="one-time-code"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                />
                <p v-if="form.errors.mfa_code" class="mt-1 text-sm text-red-700">
                    {{ form.errors.mfa_code }}
                </p>
            </div>
            <div class="flex items-center gap-4">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded bg-slate-900 px-3 py-2 text-white disabled:opacity-50"
                >
                    {{ title }}
                </button>
                <a class="text-sm underline" :href="`/app/platform/schools/${school.id}`">Cancel</a>
            </div>
        </form>
    </main>
</template>
