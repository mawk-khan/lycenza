<script setup lang="ts">
// The default layout for every Inertia page (registered once in app.ts).
// Guests (the login/MFA pages, `/`) see only the page.
//
// For a signed-in request it renders the account bar (Log out goes through
// the existing POST /logout, CSRF-protected via the XSRF-TOKEN cookie like
// every other Inertia form) and, since SWC.1 (ADR 0073 section 6), the
// shared navigation shell. Everything it shows comes from shared props
// (HandleInertiaRequests::share()): `auth.user`, `product` and the
// capability-driven `navigation` built by App\Support\Navigation\
// NavigationBuilder. It decides nothing itself -- no School, role or
// capability logic -- and hiding a link is never authorization.
//
// Responsive (ADR 0073 section 6.5): a persistent side navigation from the
// `md` breakpoint; below it, a "Menu" disclosure button controls the same
// navigation. Closed, the navigation is `hidden` (display: none), so none
// of its links are focusable. Escape closes it and returns focus to the
// button; following a link closes it.
//
// Phase 0N.3: the elevated-access banner is its own component, rendered
// after the account bar; it never gates the account bar or Log out.
import { Link as InertiaLink, router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import ElevationBanner from '../Components/ElevationBanner.vue';
import NavigationGroupList from '../Components/Navigation/NavigationGroupList.vue';
import { GUARDIAN_GROUP_KEYS, PLATFORM_GROUP_KEYS, type Navigation } from '../types/navigation';

interface AuthUser {
    id: string;
    name: string;
    email: string;
}

const page = usePage<{
    auth?: { user: AuthUser | null };
    product?: { name: string };
    navigation?: Navigation | null;
}>();
const user = computed(() => page.props.auth?.user ?? null);
const productName = computed(() => page.props.product?.name ?? '');
const navigation = computed(() => (user.value ? (page.props.navigation ?? null) : null));

const guardianGroups = computed(
    () => navigation.value?.groups.filter((g) => GUARDIAN_GROUP_KEYS.includes(g.key)) ?? [],
);
const schoolGroups = computed(
    () =>
        navigation.value?.groups.filter(
            (g) => !GUARDIAN_GROUP_KEYS.includes(g.key) && !PLATFORM_GROUP_KEYS.includes(g.key),
        ) ?? [],
);
const platformGroups = computed(
    () => navigation.value?.groups.filter((g) => PLATFORM_GROUP_KEYS.includes(g.key)) ?? [],
);

const menuOpen = ref(false);
const menuButton = ref<HTMLButtonElement | null>(null);

function closeMenu(returnFocus: boolean) {
    if (!menuOpen.value) return;
    menuOpen.value = false;
    if (returnFocus) {
        nextTick(() => menuButton.value?.focus());
    }
}

function onKeydown(event: KeyboardEvent) {
    if (event.key === 'Escape') {
        closeMenu(true);
    }
}

// Escape is handled at the document level (only while the menu is open), so
// no static container needs a keyboard handler of its own.
let stopNavigateListener: (() => void) | undefined;
onMounted(() => {
    stopNavigateListener = router.on('navigate', () => closeMenu(false));
    document.addEventListener('keydown', onKeydown);
});
onBeforeUnmount(() => {
    stopNavigateListener?.();
    document.removeEventListener('keydown', onKeydown);
});

const accountLinkClass =
    'underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-500';
</script>

<template>
    <template v-if="user">
        <a
            href="#main-content"
            class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded focus:bg-white focus:px-3 focus:py-2 focus:text-sm focus:text-slate-900 focus:shadow"
            >Skip to content</a
        >
        <header
            class="border-b border-slate-200 bg-slate-50 font-sans text-sm text-slate-700"
            data-testid="account-bar"
        >
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-2">
                <button
                    v-if="navigation"
                    ref="menuButton"
                    type="button"
                    class="rounded border border-slate-300 bg-white px-3 py-1 font-medium text-slate-900 hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-500 md:hidden"
                    aria-controls="primary-navigation"
                    :aria-expanded="menuOpen ? 'true' : 'false'"
                    data-testid="menu-button"
                    @click="menuOpen = !menuOpen"
                >
                    Menu
                </button>
                <InertiaLink
                    :href="navigation?.home.href ?? '/app'"
                    class="font-semibold text-slate-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-500"
                    data-testid="product-home"
                    >{{ productName }}</InertiaLink
                >
                <span
                    v-if="navigation?.school"
                    class="min-w-0 truncate text-slate-600"
                    data-testid="shell-school"
                    >{{ navigation.school.name }}</span
                >
                <div class="ml-auto flex min-w-0 max-w-full flex-wrap items-center gap-x-4 gap-y-2">
                    <span class="min-w-0 max-w-full truncate">
                        <span class="font-medium text-slate-900">{{ user.name }}</span>
                        <span class="ml-1 text-slate-500">{{ user.email }}</span>
                    </span>
                    <nav
                        v-if="navigation && navigation.account.length"
                        aria-label="Account"
                        class="flex gap-x-4"
                    >
                        <InertiaLink
                            v-for="item in navigation.account"
                            :key="item.key"
                            :href="item.href"
                            :class="accountLinkClass"
                            :aria-current="item.active ? 'page' : undefined"
                            :data-nav-key="item.key"
                            >{{ item.label }}</InertiaLink
                        >
                    </nav>
                    <InertiaLink
                        href="/logout"
                        method="post"
                        as="button"
                        class="rounded border border-slate-300 bg-white px-3 py-1 font-medium text-slate-900 hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-500"
                        data-testid="logout"
                    >
                        Log out
                    </InertiaLink>
                </div>
            </div>
        </header>
        <ElevationBanner />
        <div class="md:flex">
            <div
                v-if="navigation"
                id="primary-navigation"
                :class="[
                    menuOpen ? 'block' : 'hidden',
                    'border-b border-slate-200 bg-slate-50 px-3 py-4 font-sans text-sm md:block md:w-60 md:shrink-0 md:border-r md:border-b-0',
                ]"
                data-testid="primary-navigation"
            >
                <nav aria-label="Home">
                    <InertiaLink
                        :href="navigation.home.href"
                        :aria-current="navigation.home.active ? 'page' : undefined"
                        :class="[
                            'block rounded px-2 py-1 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-amber-500',
                            navigation.home.active
                                ? 'bg-slate-900 font-medium text-white'
                                : 'text-slate-700 hover:bg-slate-200',
                        ]"
                        data-nav-key="home"
                        >Home</InertiaLink
                    >
                </nav>
                <nav v-if="guardianGroups.length" aria-label="Guardian portal" class="mt-4">
                    <NavigationGroupList :groups="guardianGroups" />
                </nav>
                <nav v-if="schoolGroups.length" aria-label="School" class="mt-4">
                    <NavigationGroupList :groups="schoolGroups" />
                </nav>
                <nav v-if="platformGroups.length" aria-label="Platform" class="mt-4">
                    <NavigationGroupList :groups="platformGroups" />
                </nav>
            </div>
            <div id="main-content" tabindex="-1" class="min-w-0 flex-1 focus:outline-none">
                <slot />
            </div>
        </div>
    </template>
    <slot v-else />
</template>
