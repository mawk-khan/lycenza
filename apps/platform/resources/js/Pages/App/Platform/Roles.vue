<script setup lang="ts">
// Phase 0N.7 (ADR 0046): grant or revoke the one runtime-assignable
// platform role, Platform Auditor, for an exactly identified person. The
// root role is never offered; there is no role picker, capability editor
// or search. ORG.1A (ADR 0076 §24, V-2): every grant and revoke carries a
// fresh authentication code, re-verified by the server.
import { router, useForm } from '@inertiajs/vue3';

interface Grant {
    id: string;
    userName: string;
    userEmail: string;
    grantedAt: string;
}

defineProps<{
    role: { key: string; name: string };
    grants: Grant[];
}>();

const form = useForm({ user: '', mfa_code: '' });

function grant() {
    form.post('/app/platform/roles/grants', {
        onSuccess: () => form.reset(),
        onFinish: () => form.reset('mfa_code'),
    });
}

// A refused role comes back on `role`, outside this form's own fields.
function roleError(): string | undefined {
    return (form.errors as Record<string, string | undefined>).role;
}

function revoke(id: string) {
    if (confirm('Revoke this Platform Auditor grant? Their audit access ends now.')) {
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

// A refused revoke comes back on `grant`.
function grantError(): string | undefined {
    return (form.errors as Record<string, string | undefined>).grant;
}
</script>

<template>
    <main class="mx-auto max-w-2xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app">← Dashboard</a>
        <h1 class="mt-2 text-xl font-semibold">Platform roles</h1>
        <p class="mt-1 text-sm text-slate-600">
            {{ role.name }} is the only platform role that can be granted here. It reviews the
            platform audit log and nothing else. Platform Super Admin is provisioned outside the
            application and cannot be granted or revoked here.
        </p>

        <h2 class="mt-6 text-sm font-medium text-slate-500">{{ role.name }} grants</h2>
        <ul class="mt-2 space-y-1 text-sm" data-testid="role-grants">
            <li v-for="g in grants" :key="g.id" class="flex justify-between gap-4">
                <span>{{ g.userName }} ({{ g.userEmail }})</span>
                <button class="underline" @click="revoke(g.id)">Revoke</button>
            </li>
        </ul>
        <p v-if="!grants.length" class="mt-2 text-sm text-slate-600">No active grants.</p>

        <div class="mt-6">
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
            <p v-if="grantError()" class="mt-1 text-sm text-red-700">{{ grantError() }}</p>
        </div>

        <form class="mt-4 flex gap-2" @submit.prevent="grant">
            <input
                v-model="form.user"
                aria-label="Person to grant"
                placeholder="Exact email or account id"
                class="w-full rounded border border-slate-300 px-3 py-2"
            />
            <button type="submit" class="rounded border border-slate-300 px-3 py-2 text-sm">
                Grant {{ role.name }}
            </button>
        </form>
        <p v-if="form.errors.user" class="mt-1 text-sm text-red-700">{{ form.errors.user }}</p>
        <p v-if="roleError()" class="mt-1 text-sm text-red-700">{{ roleError() }}</p>
    </main>
</template>
