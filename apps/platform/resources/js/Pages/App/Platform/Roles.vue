<script setup lang="ts">
// Phase 0N.7 (ADR 0046): grant or revoke a runtime-assignable platform role
// for an exactly identified person. The root role is never offered; there
// is no role builder, capability editor or search. ORG.1A (ADR 0076 §24,
// V-2): every grant and revoke carries a fresh authentication code,
// re-verified by the server. ORG.4 (ADR 0076 §6-§8): Platform Manager and
// Platform Auditor, never both for one person; Platform Admin is root.
import { router, useForm } from '@inertiajs/vue3';

interface Grant {
    id: string;
    userName: string;
    userEmail: string;
    grantedAt: string;
}

interface PlatformRole {
    key: string;
    name: string;
    description: string;
    grants: Grant[];
}

const props = defineProps<{
    roles: PlatformRole[];
    exclusive: Record<string, string>;
}>();

const form = useForm({ user: '', role: props.roles[0]?.key ?? '', mfa_code: '' });

function grant() {
    form.post('/app/platform/roles/grants', {
        onSuccess: () => form.reset('user'),
        onFinish: () => form.reset('mfa_code'),
    });
}

function roleName(key: string): string {
    return props.roles.find((r) => r.key === key)?.name ?? key;
}

// A refused role or revoke comes back outside this form's own fields.
function fieldError(field: 'role' | 'grant'): string | undefined {
    return (form.errors as Record<string, string | undefined>)[field];
}

function revoke(role: PlatformRole, id: string) {
    if (confirm(`Revoke this ${role.name} grant? Its access ends now.`)) {
        router.post(
            `/app/platform/roles/grants/${id}/revoke`,
            { mfa_code: form.mfa_code },
            {
                onError: (errors) => form.setError(errors),
                onFinish: () => form.reset('mfa_code'),
            },
        );
    }
}
</script>

<template>
    <main class="mx-auto max-w-2xl p-4 font-sans text-slate-900 sm:p-8">
        <a class="text-sm underline" href="/app">← Dashboard</a>
        <h1 class="mt-2 text-xl font-semibold">Platform roles</h1>
        <p class="mt-1 text-sm text-slate-600">
            <strong>Platform Admin</strong> is the root role. It is provisioned outside the
            application and can never be granted or revoked here. The roles below are granted to an
            exactly identified person; none of them gives access to any School.
        </p>

        <section
            v-for="role in roles"
            :key="role.key"
            class="mt-6"
            :aria-labelledby="`role-${role.key}`"
            data-testid="platform-role"
        >
            <h2 :id="`role-${role.key}`" class="text-sm font-medium text-slate-700">
                {{ role.name }}
            </h2>
            <p class="mt-1 text-sm text-slate-600">{{ role.description }}</p>
            <p v-if="exclusive[role.key]" class="mt-1 text-xs text-slate-500">
                Never held together with {{ roleName(exclusive[role.key]) }}.
            </p>
            <ul class="mt-2 space-y-1 text-sm" :data-testid="`role-grants-${role.key}`">
                <li v-for="g in role.grants" :key="g.id" class="flex justify-between gap-4">
                    <span>{{ g.userName }} ({{ g.userEmail }})</span>
                    <button class="underline" @click="revoke(role, g.id)">Revoke</button>
                </li>
            </ul>
            <p v-if="!role.grants.length" class="mt-2 text-sm text-slate-600">No active grants.</p>
        </section>

        <div class="mt-8">
            <label class="block text-sm text-slate-600" for="mfa_code">
                Authentication code (or a recovery code) — required to grant or revoke
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
            <p v-if="fieldError('grant')" class="mt-1 text-sm text-red-700">
                {{ fieldError('grant') }}
            </p>
        </div>

        <form class="mt-4 flex flex-wrap gap-2" @submit.prevent="grant">
            <label class="sr-only" for="grant-role">Role to grant</label>
            <select
                id="grant-role"
                v-model="form.role"
                class="rounded border border-slate-300 px-3 py-2 text-sm"
            >
                <option v-for="role in roles" :key="role.key" :value="role.key">
                    {{ role.name }}
                </option>
            </select>
            <input
                v-model="form.user"
                aria-label="Person to grant"
                placeholder="Exact email or account id"
                class="min-w-0 flex-1 rounded border border-slate-300 px-3 py-2"
            />
            <button type="submit" class="rounded border border-slate-300 px-3 py-2 text-sm">
                Grant {{ roleName(form.role) }}
            </button>
        </form>
        <p v-if="form.errors.user" class="mt-1 text-sm text-red-700">{{ form.errors.user }}</p>
        <p v-if="fieldError('role')" class="mt-1 text-sm text-red-700">{{ fieldError('role') }}</p>
    </main>
</template>
