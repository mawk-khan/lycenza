<script setup lang="ts">
// Phase 0O.3 (ADR 0049 section 2): the signed-in person's own API tokens.
// A token is shown exactly once, right after it is issued; afterwards only
// its metadata is listed. Issuing needs an enrolled MFA factor and a fresh
// code; revoking needs only this session and takes effect on the next API
// request.
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { postJson } from '../../csrf';

interface Token {
    id: string;
    name: string;
    scopes: string[];
    createdAt: string | null;
    expiresAt: string | null;
    lastUsedAt: string | null;
}

const props = defineProps<{
    tokens: Token[];
    scopes: string[];
    defaultDays: number;
    maxDays: number;
    hasMfaFactor: boolean;
}>();

const name = ref('');
const selected = ref<string[]>(['api.read']);
const days = ref<number>(props.defaultDays);
const code = ref('');
const error = ref('');
const issued = ref<{ token: string; expiresAt: string | null } | null>(null);
const busy = ref(false);

async function issue() {
    error.value = '';
    busy.value = true;
    try {
        issued.value = await postJson<{ token: string; expiresAt: string | null }>(
            '/app/account/api-tokens',
            {
                name: name.value,
                scopes: selected.value,
                lifetime_days: days.value,
                mfa_code: code.value,
            },
        );
        name.value = '';
        code.value = '';
        router.reload({ only: ['tokens'] });
    } catch (e) {
        error.value = (e as Error).message;
    } finally {
        busy.value = false;
    }
}

async function revoke(id: string) {
    if (!confirm('Revoke this token? Requests using it fail from now on.')) {
        return;
    }
    error.value = '';
    try {
        await postJson(`/app/account/api-tokens/${id}`, {}, 'DELETE');
        router.reload({ only: ['tokens'] });
    } catch (e) {
        error.value = (e as Error).message;
    }
}

function day(value: string | null): string {
    return value ? value.slice(0, 10) : '—';
}
</script>

<template>
    <main class="mx-auto max-w-2xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app">← Dashboard</a>
        <h1 class="mt-2 text-xl font-semibold">API tokens</h1>
        <p class="mt-1 text-sm text-slate-600">
            A token lets a program call the School OS API as you. It can never do more than you can
            do yourself, it never carries platform or Group authority, and it always expires (at
            most {{ maxDays }} days).
        </p>

        <div
            v-if="issued"
            class="mt-4 rounded border border-amber-300 bg-amber-50 p-3 text-sm"
            data-testid="issued-token"
        >
            <p class="font-medium">Copy this token now. It will not be shown again.</p>
            <code class="mt-2 block break-all">{{ issued.token }}</code>
            <p class="mt-1 text-slate-600">Expires {{ day(issued.expiresAt) }}.</p>
            <button class="mt-2 underline" @click="issued = null">I have copied it</button>
        </div>

        <h2 class="mt-6 text-sm font-medium text-slate-500">Active tokens</h2>
        <ul class="mt-2 space-y-1 text-sm" data-testid="api-tokens">
            <li v-for="t in tokens" :key="t.id" class="flex justify-between gap-4">
                <span
                    >{{ t.name }} · {{ t.scopes.join(', ') }} · expires {{ day(t.expiresAt) }} ·
                    last used {{ day(t.lastUsedAt) }}</span
                >
                <button class="underline" @click="revoke(t.id)">Revoke</button>
            </li>
        </ul>
        <p v-if="!tokens.length" class="mt-2 text-sm text-slate-600">No active tokens.</p>

        <h2 class="mt-6 text-sm font-medium text-slate-500">Issue a token</h2>
        <p v-if="!hasMfaFactor" class="mt-2 text-sm" data-testid="mfa-needed">
            Issuing a token needs multi-factor authentication. Enroll a factor under
            <a class="underline" href="/app/account/security">Account security</a> first.
        </p>
        <form v-else class="mt-2 space-y-2 text-sm" @submit.prevent="issue">
            <input
                v-model="name"
                aria-label="Token name"
                placeholder="What will use this token?"
                class="w-full rounded border border-slate-300 px-3 py-2"
            />
            <fieldset class="flex gap-4">
                <label v-for="s in scopes" :key="s"
                    ><input v-model="selected" type="checkbox" :value="s" /> {{ s }}</label
                >
            </fieldset>
            <label class="block"
                >Lifetime (days, at most {{ maxDays }})
                <input
                    v-model.number="days"
                    type="number"
                    min="1"
                    :max="maxDays"
                    class="ml-2 w-24 rounded border border-slate-300 px-2 py-1"
            /></label>
            <input
                v-model="code"
                aria-label="Authentication code"
                autocomplete="one-time-code"
                placeholder="Current authentication code"
                class="w-full rounded border border-slate-300 px-3 py-2"
            />
            <button
                type="submit"
                :disabled="busy"
                class="rounded border border-slate-300 px-3 py-2"
            >
                Issue token
            </button>
        </form>
        <p v-if="error" class="mt-2 text-sm text-red-700" data-testid="error">{{ error }}</p>
    </main>
</template>
