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
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watchEffect } from 'vue';
import ElevationBanner from '../Components/ElevationBanner.vue';
import FlashMessage from '../Components/FlashMessage.vue';
import RefusalMessage from '../Components/RefusalMessage.vue';
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
    brand?: {
        label: string | null;
        accent: string;
        foreground: string;
        accentStrong: string | null;
        source: string;
    } | null;
}>();
// ORG.12F (ADR 0080 §11.6, amended by ADR 0082 §8): the School's effective
// brand -- an optional label chip here, and (DS-2) the accent tokens below,
// which colour the shell chrome only: the bar, the active navigation item, the
// focus ring and the progress bar. Never page text or page backgrounds. Set
// through the DOM (allowed by the CSP), never as markup; the foreground and
// the strong step are derived on the server for contrast. On the system
// default the design-system steel applies.
const brand = computed(() =>
    page.props.brand && page.props.brand.source !== 'system' ? page.props.brand : null,
);
// DS-1 (ADR 0082 §2.4): a School (or chosen Network) accent replaces the
// design-system accent tokens on <html> -- through the CSSOM, which the CSP
// allows -- so every accent-* utility follows it; resources/css/app.css
// derives the ramp. `accentStrong` is computed on the server
// (BrandColor::strong(), >= 4.5:1 on the ground) and is null when nothing
// supplies an accent, in which case the Industry steel stays.
const ACCENT_PROPERTIES = ['--ds-accent', '--ds-accent-strong'] as const;
watchEffect(() => {
    const root = document.documentElement;
    const accent = page.props.brand?.accentStrong ? page.props.brand : null;
    if (accent?.accentStrong) {
        root.style.setProperty('--ds-accent', accent.accent);
        root.style.setProperty('--ds-accent-strong', accent.accentStrong);
        root.setAttribute('data-brand-accent', '');
    } else {
        ACCENT_PROPERTIES.forEach((property) => root.style.removeProperty(property));
        root.removeAttribute('data-brand-accent');
    }
});
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

// DS-2 (ADR 0082 §8): the shell in the design tokens. The bar is the deep
// accent step, so it follows a School accent (DS-1); controls on it use the
// light ramp and a light focus ring. Contrast is guaranteed by the tokens
// (DesignTokensTest), not by these classes.
const barFocus =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-300';
const barButtonClass = `rounded-xs border border-accent-400 px-3 py-1 font-medium text-ground hover:bg-accent-800 active:bg-accent-700 ${barFocus}`;
const accountLinkClass = `underline underline-offset-2 hover:text-accent-200 ${barFocus}`;
const homeLinkClass = (active: boolean) => [
    'block rounded-xs px-2 py-1 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent-strong',
    active ? 'bg-accent-strong font-medium text-on-accent' : 'text-ink hover:bg-graphite-200',
];
</script>

<template>
    <template v-if="user">
        <a
            href="#main-content"
            class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded-xs focus:bg-white focus:px-3 focus:py-2 focus:text-sm focus:text-ink focus:shadow focus:outline-2 focus:outline-accent-strong"
            >Skip to content</a
        >
        <header
            class="bg-accent-900 font-sans text-sm text-ground print:hidden"
            data-testid="account-bar"
        >
            <div class="flex min-h-11 flex-wrap items-center gap-x-4 gap-y-2 px-4 py-1.5">
                <button
                    v-if="navigation"
                    ref="menuButton"
                    type="button"
                    :class="[barButtonClass, 'md:hidden']"
                    aria-controls="primary-navigation"
                    :aria-expanded="menuOpen ? 'true' : 'false'"
                    data-testid="menu-button"
                    @click="menuOpen = !menuOpen"
                >
                    Menu
                </button>
                <InertiaLink
                    :href="navigation?.home.href ?? '/app'"
                    :class="[
                        'font-heading text-base font-semibold tracking-[0.08em] whitespace-nowrap uppercase',
                        barFocus,
                    ]"
                    data-testid="product-home"
                    >{{ productName }}</InertiaLink
                >
                <span
                    v-if="navigation?.school"
                    class="min-w-0 truncate text-accent-200"
                    data-testid="shell-school"
                    >{{ navigation.school.name }}</span
                >
                <span
                    v-if="brand?.label"
                    class="rounded-xs px-2 py-0.5 text-xs font-medium"
                    :style="{ backgroundColor: brand.accent, color: brand.foreground }"
                    data-testid="shell-brand"
                    >{{ brand.label }}</span
                >
                <div class="ml-auto flex min-w-0 max-w-full flex-wrap items-center gap-x-4 gap-y-2">
                    <span class="min-w-0 max-w-full truncate">
                        <span class="font-medium">{{ user.name }}</span>
                        <span class="ml-1 text-accent-200">{{ user.email }}</span>
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
                        :class="barButtonClass"
                        data-testid="logout"
                    >
                        Log out
                    </InertiaLink>
                </div>
            </div>
        </header>
        <ElevationBanner />
        <div class="bg-ground md:flex md:min-h-[calc(100vh-2.75rem)]">
            <div
                v-if="navigation"
                id="primary-navigation"
                :class="[
                    menuOpen ? 'block' : 'hidden',
                    // SIS 11.4: printed pages (the report print view) carry no navigation.
                    'border-b border-line bg-ground px-3 py-4 font-sans text-sm md:block md:w-60 md:shrink-0 md:border-r md:border-b-0 print:hidden',
                ]"
                data-testid="primary-navigation"
            >
                <nav aria-label="Home">
                    <InertiaLink
                        :href="navigation.home.href"
                        :aria-current="navigation.home.active ? 'page' : undefined"
                        :class="homeLinkClass(navigation.home.active)"
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
            <!-- DS-5 (ADR 0082 §11): every page is on the tokens, so the page area
                 is the design's ground; secondary text (ink-muted) is 5.87:1 on it. -->
            <div
                id="main-content"
                tabindex="-1"
                class="min-w-0 flex-1 bg-ground focus:outline-none"
            >
                <FlashMessage />
                <RefusalMessage />
                <slot />
            </div>
        </div>
    </template>
    <slot v-else />
</template>
