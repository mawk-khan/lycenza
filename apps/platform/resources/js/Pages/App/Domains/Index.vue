<script setup lang="ts">
// Phase 0O.8A (ADR 0054 section 10.4): the selected School's custom domains.
// A domain becomes live only after its DNS ownership record verifies, it
// points at the Lycenza edge and a secure connection is proven -- none of
// that is a button here. Adding, a new record value, the primary choice and
// removal need a fresh MFA code; "Check now" is rate-limited and queued.
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { postJson } from '../../../csrf';

interface DnsRecord {
    type: string;
    name: string;
    value: string;
}

interface Domain {
    id: string;
    hostname: string;
    state: string;
    label: string;
    isPrimary: boolean;
    attention: string | null;
    dnsRecord: DnsRecord | null;
    challengeExpiresAt: string | null;
    certificateNotAfter: string | null;
    lastCheckedAt: string | null;
    createdAt: string;
}

const props = defineProps<{
    enabled: boolean;
    domains: Domain[];
    routing: { cnameTarget: string | null; addresses: string[] };
    canManage: boolean;
    hasMfaFactor: boolean;
    maxDomains: number;
}>();

const hostname = ref('');
const code = ref('');
const error = ref('');
const notice = ref('');
const busy = ref(false);

const live = computed(() => props.domains.filter((d) => !['revoked', 'expired'].includes(d.state)));
const active = computed(() => props.domains.filter((d) => d.state === 'active'));

const attentionText: Record<string, string> = {
    ownership: 'The DNS ownership record is missing or changed.',
    routing: 'The domain no longer points at Lycenza.',
    tls: 'The secure connection (certificate) is not valid.',
};

async function run(action: () => Promise<unknown>, done: string) {
    error.value = '';
    notice.value = '';
    busy.value = true;
    try {
        await action();
        notice.value = done;
        code.value = '';
        router.reload({ only: ['domains'] });
    } catch (e) {
        error.value = (e as Error).message;
    } finally {
        busy.value = false;
    }
}

function add() {
    return run(async () => {
        await postJson('/app/settings/domains', { hostname: hostname.value, mfa_code: code.value });
        hostname.value = '';
    }, 'Domain added. Publish the DNS record below, then choose Check now.');
}

function regenerate(domain: Domain) {
    return run(
        () => postJson(`/app/settings/domains/${domain.id}/challenge`, { mfa_code: code.value }),
        'A new record value was created; the previous one no longer works.',
    );
}

function makePrimary(domain: Domain) {
    return run(
        () => postJson(`/app/settings/domains/${domain.id}/primary`, { mfa_code: code.value }),
        `${domain.hostname} is now the primary domain.`,
    );
}

function remove(domain: Domain) {
    let replacement: string | null = null;
    const others = active.value.filter((d) => d.id !== domain.id);
    if (domain.isPrimary && others.length > 0) {
        replacement = others[0].id;
        if (
            !confirm(`Remove ${domain.hostname}? ${others[0].hostname} becomes the primary domain.`)
        ) {
            return;
        }
    } else if (
        !confirm(`Remove ${domain.hostname}? It stops working at once and cannot be restored.`)
    ) {
        return;
    }

    return run(
        () =>
            postJson(`/app/settings/domains/${domain.id}/revoke`, {
                mfa_code: code.value,
                replacement_id: replacement,
            }),
        `${domain.hostname} was removed.`,
    );
}

function check(domain: Domain) {
    return run(
        () => postJson(`/app/settings/domains/${domain.id}/check`, {}),
        'Check requested. Refresh this page in a moment to see the result.',
    );
}

function day(value: string | null): string {
    return value ? value.slice(0, 16).replace('T', ' ') : '—';
}
</script>

<template>
    <main class="mx-auto max-w-3xl p-8 font-sans text-slate-900">
        <a class="text-sm underline" href="/app">← Dashboard</a>
        <h1 class="mt-2 text-xl font-semibold">Custom domains</h1>
        <p class="mt-1 text-sm text-slate-600">
            Serve this School's pages on a web address you own, for example
            <code>erp.yourschool.org</code>. A domain goes live only after its DNS record is
            verified, it points at Lycenza and a secure connection is confirmed. At most
            {{ maxDomains }} domains; one is primary and the others redirect to it.
        </p>
        <p v-if="!enabled" class="mt-3 text-sm" data-testid="domains-disabled">
            Custom domains are not enabled for this deployment.
        </p>

        <div
            v-if="error"
            class="mt-4 rounded border border-red-300 bg-red-50 p-3 text-sm"
            role="alert"
        >
            {{ error }}
        </div>
        <div
            v-if="notice"
            class="mt-4 rounded border border-emerald-300 bg-emerald-50 p-3 text-sm"
            role="status"
        >
            {{ notice }}
        </div>

        <section v-if="canManage && enabled" class="mt-6 text-sm">
            <p v-if="!hasMfaFactor" class="text-slate-700">
                Changing domains needs multi-factor authentication. Enroll a factor under
                <a class="underline" href="/app/account/security">Account security</a> first.
            </p>
            <label class="block">
                <span class="font-medium">Authentication code</span>
                <span class="text-slate-600"> (needed to add, change or remove a domain)</span>
                <input
                    v-model="code"
                    class="mt-1 block w-40 rounded border px-2 py-1"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    data-testid="mfa-code"
                />
            </label>
            <form v-if="live.length < maxDomains" class="mt-3 flex gap-2" @submit.prevent="add">
                <input
                    v-model="hostname"
                    class="flex-1 rounded border px-2 py-1"
                    placeholder="erp.yourschool.org"
                    aria-label="Domain name"
                    data-testid="new-hostname"
                />
                <button
                    class="rounded bg-slate-800 px-3 py-1 text-white"
                    :disabled="busy"
                    type="submit"
                >
                    Add domain
                </button>
            </form>
        </section>

        <section
            v-if="canManage && enabled && (routing.cnameTarget || routing.addresses.length)"
            class="mt-6 text-sm"
        >
            <h2 class="font-medium">Point the domain at Lycenza</h2>
            <p v-if="routing.cnameTarget" class="mt-1">
                Add a <strong>CNAME</strong> record for your domain with the value
                <code data-testid="cname-target">{{ routing.cnameTarget }}</code
                >.
            </p>
            <p v-if="routing.addresses.length" class="mt-1">
                For a root (apex) domain, add <strong>A/AAAA</strong> records for exactly:
                <code>{{ routing.addresses.join(', ') }}</code
                >.
            </p>
        </section>

        <ul class="mt-6 space-y-4">
            <li
                v-for="domain in domains"
                :key="domain.id"
                class="rounded border p-3 text-sm"
                data-testid="domain"
            >
                <div class="flex items-center justify-between gap-2">
                    <span class="font-medium">
                        {{ domain.hostname }}
                        <span v-if="domain.isPrimary" class="ml-1 rounded bg-slate-200 px-1 text-xs"
                            >Primary</span
                        >
                    </span>
                    <span data-testid="domain-status">{{ domain.label }}</span>
                </div>
                <p v-if="domain.attention" class="mt-1 text-amber-800">
                    {{ attentionText[domain.attention] }}
                </p>

                <div v-if="domain.dnsRecord" class="mt-2 rounded bg-slate-50 p-2">
                    <p>
                        DNS ownership record (keep it published for as long as you use the domain):
                    </p>
                    <p class="mt-1">
                        Type <code>{{ domain.dnsRecord.type }}</code
                        >, name
                        <code class="break-all">{{ domain.dnsRecord.name }}</code>
                    </p>
                    <p class="mt-1">
                        Value
                        <code class="break-all" data-testid="txt-value">{{
                            domain.dnsRecord.value
                        }}</code>
                    </p>
                    <p v-if="domain.challengeExpiresAt" class="mt-1 text-slate-600">
                        Verify before {{ day(domain.challengeExpiresAt) }} UTC.
                    </p>
                </div>

                <p class="mt-2 text-slate-600">
                    Added {{ day(domain.createdAt) }} · Last checked {{ day(domain.lastCheckedAt) }}
                    <template v-if="domain.certificateNotAfter">
                        · Certificate valid until {{ day(domain.certificateNotAfter) }}
                    </template>
                </p>

                <div
                    v-if="canManage && enabled && !['revoked', 'expired'].includes(domain.state)"
                    class="mt-2 flex flex-wrap gap-3"
                >
                    <button class="underline" :disabled="busy" @click="check(domain)">
                        Check now
                    </button>
                    <button
                        v-if="domain.state === 'pending_verification'"
                        class="underline"
                        :disabled="busy"
                        @click="regenerate(domain)"
                    >
                        New record value
                    </button>
                    <button
                        v-if="domain.state === 'active' && !domain.isPrimary"
                        class="underline"
                        :disabled="busy"
                        @click="makePrimary(domain)"
                    >
                        Make primary
                    </button>
                    <button class="text-red-700 underline" :disabled="busy" @click="remove(domain)">
                        Remove
                    </button>
                </div>
            </li>
        </ul>
        <p v-if="!domains.length" class="mt-6 text-sm text-slate-600">No custom domain yet.</p>
    </main>
</template>
