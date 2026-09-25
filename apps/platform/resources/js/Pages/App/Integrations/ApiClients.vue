<script setup lang="ts">
// Phase 0O.3 (ADR 0049 section 3): partner API clients of the selected
// School. A partner client is a non-human integration bound to this School
// for good; it reaches only partner routes approved for its scopes -- and
// none is approved in production yet. A credential is shown exactly once
// (on issue or rotation). Issue, rotate and revoke need a fresh MFA code.
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { postJson } from '../../../csrf';

interface Credential {
    id: string;
    keyId: string;
    issuedAt: string;
    expiresAt: string;
    supersededAt: string | null;
    revokedAt: string | null;
    lastUsedAt: string | null;
    usable: boolean;
}

interface Client {
    id: string;
    name: string;
    scopes: string[];
    status: string;
    createdAt: string | null;
    revokedAt: string | null;
    credentials: Credential[];
}

const props = defineProps<{
    clients: Client[];
    scopes: string[];
    canManage: boolean;
    defaultDays: number;
    maxDays: number;
    overlapHours: number;
}>();

const name = ref('');
const selected = ref<string[]>([]);
const days = ref<number>(props.defaultDays);
const code = ref('');
const error = ref('');
const secret = ref<string | null>(null);
const busy = ref(false);

async function run(action: () => Promise<{ credential?: string }>) {
    error.value = '';
    busy.value = true;
    try {
        const result = await action();
        secret.value = result.credential ?? null;
        code.value = '';
        router.reload({ only: ['clients'] });
    } catch (e) {
        error.value = (e as Error).message;
    } finally {
        busy.value = false;
    }
}

function issue() {
    return run(() =>
        postJson('/app/integrations/api-clients', {
            name: name.value,
            scopes: selected.value,
            lifetime_days: days.value,
            mfa_code: code.value,
        }),
    );
}

function rotate(id: string) {
    return run(() =>
        postJson(`/app/integrations/api-clients/${id}/rotate`, {
            lifetime_days: days.value,
            mfa_code: code.value,
        }),
    );
}

function revoke(id: string) {
    if (confirm('Revoke this client and all its credentials? Requests fail from now on.')) {
        return run(() =>
            postJson(`/app/integrations/api-clients/${id}/revoke`, { mfa_code: code.value }),
        );
    }
}

function day(value: string | null): string {
    return value ? value.slice(0, 16).replace('T', ' ') : '—';
}
</script>

<template>
    <main class="mx-auto max-w-3xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app">← Dashboard</a>
        <h1 class="mt-2 text-xl font-semibold">Integrations: API clients</h1>
        <p class="mt-1 text-sm text-slate-600">
            A partner client belongs to this School only. Rotation keeps the previous credential
            working for at most {{ overlapHours }} hours; revocation is immediate.
        </p>
        <p v-if="!scopes.length" class="mt-2 text-sm" data-testid="no-scopes">
            No partner API scope is approved yet, so no partner client can be issued.
        </p>

        <div
            v-if="secret"
            class="mt-4 rounded border border-amber-300 bg-amber-50 p-3 text-sm"
            data-testid="issued-credential"
        >
            <p class="font-medium">Copy this credential now. It will not be shown again.</p>
            <code class="mt-2 block break-all">{{ secret }}</code>
            <button class="mt-2 underline" @click="secret = null">I have copied it</button>
        </div>

        <div v-if="canManage" class="mt-4 text-sm">
            <input
                v-model="code"
                aria-label="Authentication code"
                autocomplete="one-time-code"
                placeholder="Current authentication code (needed to issue, rotate or revoke)"
                class="w-full rounded border border-slate-300 px-3 py-2"
            />
        </div>

        <ul class="mt-6 space-y-4 text-sm" data-testid="api-clients">
            <li v-for="c in clients" :key="c.id" class="rounded border border-slate-200 p-3">
                <div class="flex justify-between gap-4">
                    <span class="font-medium">{{ c.name }}</span>
                    <span>{{ c.status }}</span>
                </div>
                <p class="text-slate-600">Scopes: {{ c.scopes.join(', ') }}</p>
                <ul class="mt-1 space-y-0.5 text-slate-600">
                    <li v-for="k in c.credentials" :key="k.id">
                        {{ k.keyId }} · issued {{ day(k.issuedAt) }} · expires
                        {{ day(k.expiresAt) }} · last used {{ day(k.lastUsedAt) }} ·
                        {{ k.usable ? 'usable' : 'not usable' }}
                    </li>
                </ul>
                <div v-if="canManage && c.status === 'active'" class="mt-2 flex gap-4">
                    <button class="underline" :disabled="busy" @click="rotate(c.id)">Rotate</button>
                    <button class="underline" :disabled="busy" @click="revoke(c.id)">Revoke</button>
                </div>
            </li>
        </ul>
        <p v-if="!clients.length" class="mt-2 text-sm text-slate-600">No API clients.</p>

        <form
            v-if="canManage && scopes.length"
            class="mt-6 space-y-2 text-sm"
            @submit.prevent="issue"
        >
            <h2 class="text-sm font-medium text-slate-500">Issue a client</h2>
            <input
                v-model="name"
                aria-label="Client name"
                placeholder="Partner / integration name"
                class="w-full rounded border border-slate-300 px-3 py-2"
            />
            <fieldset class="flex gap-4">
                <label v-for="s in scopes" :key="s"
                    ><input v-model="selected" type="checkbox" :value="s" /> {{ s }}</label
                >
            </fieldset>
            <label class="block"
                >Credential lifetime (days, at most {{ maxDays }})
                <input
                    v-model.number="days"
                    type="number"
                    min="1"
                    :max="maxDays"
                    class="ml-2 w-24 rounded border border-slate-300 px-2 py-1"
            /></label>
            <button
                type="submit"
                :disabled="busy"
                class="rounded border border-slate-300 px-3 py-2"
            >
                Issue client
            </button>
        </form>
        <p v-if="error" class="mt-2 text-sm text-red-700" data-testid="error">{{ error }}</p>
    </main>
</template>
