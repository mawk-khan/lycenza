<script setup lang="ts">
// The default layout for every Inertia page (registered once in app.ts).
// It shows the signed-in account and a Log out control whenever the
// request is authenticated -- based only on the shared `auth.user` prop
// (HandleInertiaRequests::share()), never on a School, role or
// capability, so it renders with no School selected too. Guests (the
// login/MFA pages) see nothing extra. Log out goes through the existing
// POST /logout (LoginController::destroy(), CSRF-protected via the
// XSRF-TOKEN cookie like every other Inertia form); no new endpoint.
//
// Phase 0N.3: the elevated-access banner is its own component, rendered
// after the account bar; it never gates the account bar or Log out.
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ElevationBanner from '../Components/ElevationBanner.vue';

interface AuthUser {
    id: string;
    name: string;
    email: string;
}

const page = usePage<{ auth?: { user: AuthUser | null } }>();
const user = computed(() => page.props.auth?.user ?? null);
</script>

<template>
    <header
        v-if="user"
        class="border-b border-slate-200 bg-slate-50 font-sans text-sm text-slate-700"
        data-testid="account-bar"
    >
        <div
            class="mx-auto flex max-w-5xl flex-wrap items-center justify-end gap-x-4 gap-y-2 px-4 py-2"
        >
            <span class="min-w-0 truncate">
                <span class="font-medium text-slate-900">{{ user.name }}</span>
                <span class="ml-1 text-slate-500">{{ user.email }}</span>
            </span>
            <a class="underline" href="/app/account/security">Account security</a>
            <a class="underline" href="/app/account/api-tokens">API tokens</a>
            <Link
                href="/logout"
                method="post"
                as="button"
                class="rounded border border-slate-300 bg-white px-3 py-1 font-medium text-slate-900 hover:bg-slate-100 focus:ring-2 focus:ring-amber-400 focus:ring-offset-2 focus:outline-none"
                data-testid="logout"
            >
                Log out
            </Link>
        </div>
    </header>
    <ElevationBanner v-if="user" />
    <slot />
</template>
