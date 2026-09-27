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
    <main class="mx-auto max-w-sm p-8 font-sans text-slate-900">
        <h1 class="text-xl font-semibold">Sign in</h1>
        <p v-if="hostSchool" class="mt-1 text-sm text-slate-600" data-testid="host-school">
            {{ hostSchool.name }}
        </p>

        <p
            v-if="sessionEnded"
            role="status"
            class="mt-4 rounded border border-slate-300 bg-slate-50 px-3 py-2 text-sm text-slate-700"
            data-testid="session-ended"
        >
            Your session has ended. Please sign in again.
        </p>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <div>
                <label class="block text-sm text-slate-600" for="email">Email</label>
                <input
                    id="email"
                    v-model="form.email"
                    type="email"
                    autocomplete="username"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                />
                <p v-if="form.errors.email" class="mt-1 text-sm text-red-600">
                    {{ form.errors.email }}
                </p>
            </div>

            <div>
                <label class="block text-sm text-slate-600" for="password">Password</label>
                <input
                    id="password"
                    v-model="form.password"
                    type="password"
                    autocomplete="current-password"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                />
            </div>

            <button
                ref="submitButton"
                type="submit"
                :disabled="form.processing"
                class="w-full rounded bg-slate-900 px-3 py-2 text-white focus:ring-2 focus:ring-amber-400 focus:ring-offset-2 focus:outline-none disabled:opacity-50"
            >
                Sign in
            </button>
        </form>

        <section
            v-if="demo && demo.accounts.length > 0"
            class="mt-8 rounded border border-amber-300 bg-amber-50 p-4"
            aria-labelledby="demo-accounts-heading"
            data-testid="demo-accounts"
        >
            <h2 id="demo-accounts-heading" class="text-sm font-semibold text-amber-900">
                Demo accounts
            </h2>
            <p class="mt-1 text-xs text-amber-900">
                Local DDEV demo only. Choosing an account fills the form above; then press Sign in.
            </p>

            <div v-for="[group, accounts] in demoGroups" :key="group" class="mt-4">
                <h3 class="text-xs font-medium tracking-wide text-amber-800 uppercase">
                    {{ group }}
                </h3>
                <ul class="mt-2 space-y-2">
                    <li v-for="account in accounts" :key="account.email">
                        <button
                            type="button"
                            class="w-full rounded border bg-white px-3 py-2 text-left hover:border-amber-500"
                            :class="
                                selectedEmail === account.email
                                    ? 'border-amber-500'
                                    : 'border-amber-200'
                            "
                            :data-demo-email="account.email"
                            @click="useDemoAccount(account)"
                        >
                            <span class="block text-sm font-medium text-slate-900">{{
                                account.persona
                            }}</span>
                            <span class="block text-xs text-slate-600">{{ account.email }}</span>
                            <span class="mt-1 block text-xs text-slate-500">{{
                                account.hint
                            }}</span>
                        </button>
                    </li>
                </ul>
            </div>
        </section>
    </main>
</template>
