<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface DemoAccount {
    persona: string;
    email: string;
    hint: string;
    group: string;
}

// `demo` is provided by the server ONLY in a local DDEV demo environment
// (App\Support\Demo\DemoLoginPanel); everywhere else it is null and the
// panel below never renders. Shortcuts only prefill this form -- signing
// in still goes through the normal POST /login.
const props = defineProps<{
    demo?: { password: string; accounts: DemoAccount[] } | null;
    // True for one page load after an open signed-in page found its
    // session gone (expired, or signed out elsewhere).
    sessionEnded?: boolean;
    // Phase 0O.8A (ADR 0054 section 8.5): on a School's own web address, that
    // School's name (branding only -- signing in still needs membership).
    hostSchool?: { name: string } | null;
    // Phase 0O.10A (ADR 0056): the platform recovery page, or null when
    // account recovery is not enabled.
    recoveryUrl?: string | null;
    statusMessage?: string | null;
}>();

const form = useForm({
    email: '',
    password: '',
});

const submitButton = ref<HTMLButtonElement | null>(null);
const selectedEmail = ref<string | null>(null);

const demoGroups = computed(() => {
    const groups = new Map<string, DemoAccount[]>();
    for (const account of props.demo?.accounts ?? []) {
        groups.set(account.group, [...(groups.get(account.group) ?? []), account]);
    }
    return [...groups.entries()];
});

function useDemoAccount(account: DemoAccount) {
    if (!props.demo) {
        return;
    }
    form.email = account.email;
    form.password = props.demo.password;
    form.clearErrors();
    selectedEmail.value = account.email;
    submitButton.value?.focus();
}

function submit() {
    form.post('/login');
}
</script>

<template>
    <main class="mx-auto max-w-sm p-8 font-sans text-ink">
        <h1 class="text-xl font-semibold">Sign in</h1>
        <p v-if="hostSchool" class="mt-1 text-sm text-ink-muted" data-testid="host-school">
            {{ hostSchool.name }}
        </p>

        <p
            v-if="sessionEnded"
            role="status"
            class="mt-4 rounded-xs border border-control-line bg-graphite-100 px-3 py-2 text-sm text-ink"
            data-testid="session-ended"
        >
            Your session has ended. Please sign in again.
        </p>

        <p
            v-if="statusMessage"
            role="status"
            class="mt-4 rounded-xs border border-graphite-300 bg-graphite-100 px-3 py-2 text-sm text-ink"
            data-testid="status-message"
        >
            {{ statusMessage }}
        </p>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <div>
                <label class="block text-sm text-ink-muted" for="email">Email</label>
                <input
                    id="email"
                    v-model="form.email"
                    type="email"
                    autocomplete="username"
                    class="input mt-1"
                />
                <p v-if="form.errors.email" class="mt-1 text-sm text-danger">
                    {{ form.errors.email }}
                </p>
            </div>

            <div>
                <label class="block text-sm text-ink-muted" for="password">Password</label>
                <input
                    id="password"
                    v-model="form.password"
                    type="password"
                    autocomplete="current-password"
                    class="input mt-1"
                />
            </div>

            <button
                ref="submitButton"
                type="submit"
                :disabled="form.processing"
                class="btn btn-primary w-full focus:ring-2 focus:ring-accent-300 focus:ring-offset-2 focus:outline-none"
            >
                Sign in
            </button>
        </form>

        <p v-if="recoveryUrl" class="mt-4 text-sm">
            <a :href="recoveryUrl" class="text-ink underline" data-testid="forgot-password"
                >Forgot password?</a
            >
        </p>

        <section
            v-if="demo && demo.accounts.length > 0"
            class="mt-8 rounded-xs border border-accent-300 bg-accent-100 p-4"
            aria-labelledby="demo-accounts-heading"
            data-testid="demo-accounts"
        >
            <h2 id="demo-accounts-heading" class="text-sm font-semibold text-ink">Demo accounts</h2>
            <p class="mt-1 text-xs text-ink">
                Local DDEV demo only. Choosing an account fills the form above; then press Sign in.
            </p>

            <div v-for="[group, accounts] in demoGroups" :key="group" class="mt-4">
                <h3 class="text-xs font-medium tracking-wide text-ink uppercase">
                    {{ group }}
                </h3>
                <ul class="mt-2 space-y-2">
                    <li v-for="account in accounts" :key="account.email">
                        <button
                            type="button"
                            class="w-full rounded-xs border bg-white px-3 py-2 text-left hover:border-accent-300"
                            :class="
                                selectedEmail === account.email
                                    ? 'border-accent-300'
                                    : 'border-accent-300'
                            "
                            :data-demo-email="account.email"
                            @click="useDemoAccount(account)"
                        >
                            <span class="block text-sm font-medium text-ink">{{
                                account.persona
                            }}</span>
                            <span class="block text-xs text-ink-muted">{{ account.email }}</span>
                            <span class="mt-1 block text-xs text-ink-muted">{{
                                account.hint
                            }}</span>
                        </button>
                    </li>
                </ul>
            </div>
        </section>
    </main>
</template>
