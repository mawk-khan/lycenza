<script setup lang="ts">
import { Head, Link as InertiaLink, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { Navigation } from '../../types/navigation';
import { PLATFORM_GROUP_KEYS } from '../../types/navigation';

// Phase 0N.3: why the last platform elevation ended (one-request flash).
const elevationNotices: Record<string, string> = {
    started: 'Elevated access started.',
    exited: 'Elevated access ended.',
    logout: 'Elevated access ended.',
    ended: 'Elevated access has already ended.',
    expired: 'Elevated access expired.',
    actor_disabled: 'Elevated access ended: this account is disabled.',
    capability_revoked: 'Elevated access ended: this account may no longer enter Schools.',
    school_ineligible: 'Elevated access ended: that School is no longer available.',
    membership_conflict:
        'Elevated access ended: you are a member of that School — select it from your School list instead.',
    mfa_factor_revoked: 'Elevated access ended: multi-factor authentication was removed.',
    school_left_group: 'Elevated access ended: that School is no longer in the School Group.',
    group_authority_revoked: 'Elevated access ended: your School Group authority was revoked.',
    group_inactive: 'Elevated access ended: the School Group was archived.',
    school_suspended: 'Elevated access ended: that School was suspended.',
};

function endElevation() {
    router.post('/app/platform/elevation/exit');
}

interface Membership {
    schoolId: string;
    schoolName: string;
    isActive: boolean;
    available: boolean;
}

interface Props {
    // Set for one request when a School page sent the User back here
    // because no valid School was selected (RequireSchoolContext).
    schoolContextNotice: 'select' | 'not_saved' | null;
    platformAccount: boolean;
    platformElevation: {
        canStart: boolean;
        isElevated: boolean;
        activeElsewhere: { schoolName: string; expiresAt: string } | null;
        notice: string | null;
    };
    activeSchool: { id: string; name: string } | null;
    memberships: Membership[];
}

const props = defineProps<Props>();

// SWC.1 (ADR 0073 section 6.3): the overview is the shared, server-built
// navigation -- the same entries as the side navigation, nothing more.
const page = usePage<{ product: { name: string }; navigation: Navigation | null }>();
const productName = computed(() => page.props.product.name);
const overview = computed(() => page.props.navigation?.groups ?? []);
const hasSchoolModules = computed(() =>
    overview.value.some((group) => !PLATFORM_GROUP_KEYS.includes(group.key)),
);

function activate(schoolId: string) {
    router.post(`/app/schools/${schoolId}/activate`);
}
</script>

<template>
    <Head title="Home" />
    <main class="mx-auto max-w-5xl p-8 font-sans text-slate-900">
        <header>
            <h1 class="text-2xl font-semibold" data-testid="dashboard-heading">
                {{ productName }}
            </h1>
            <p class="mt-1 text-slate-600" data-testid="active-school">
                {{ props.activeSchool ? props.activeSchool.name : 'No School selected' }}
            </p>
        </header>

        <p
            v-if="schoolContextNotice && !activeSchool"
            role="status"
            class="mt-6 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm"
            data-testid="school-context-notice"
        >
            <template v-if="schoolContextNotice === 'not_saved'"
                >Nothing was saved: no School is selected.
            </template>
            <template v-if="memberships.length > 0">Select a School to continue.</template>
            <template v-else>That page belongs to a School, and this account has none.</template>
        </p>

        <p
            v-if="platformElevation.notice && elevationNotices[platformElevation.notice]"
            role="status"
            class="mt-6 rounded border border-slate-300 bg-slate-50 px-3 py-2 text-sm"
            data-testid="elevation-notice"
        >
            {{ elevationNotices[platformElevation.notice] }}
        </p>

        <section v-if="platformElevation.isElevated" class="mt-6" data-testid="elevated-state">
            <h2 class="text-sm font-medium text-slate-500">Elevated access</h2>
            <p class="mt-1 text-sm">
                Elevated access is active (see the banner above). It grants no School permissions,
                and School pages are not available under elevated access. Exit elevated access
                before selecting one of your own Schools.
            </p>
        </section>

        <section
            v-else-if="platformElevation.activeElsewhere"
            class="mt-6"
            data-testid="elevated-elsewhere"
        >
            <h2 class="text-sm font-medium text-slate-500">Elevated access</h2>
            <p class="mt-1 text-sm">
                Elevated access into {{ platformElevation.activeElsewhere.schoolName }} is active in
                another session until
                {{ new Date(platformElevation.activeElsewhere.expiresAt).toLocaleTimeString() }}.
            </p>
            <button type="button" class="mt-2 text-sm underline" @click="endElevation">
                End it now
            </button>
        </section>

        <section v-else-if="platformElevation.canStart" class="mt-6" data-testid="enter-school">
            <h2 class="text-sm font-medium text-slate-500">Platform</h2>
            <a class="mt-1 inline-block text-sm underline" href="/app/platform/elevation">
                Enter a School (elevated access)
            </a>
        </section>

        <section v-if="memberships.length === 0" class="mt-6" data-testid="no-school-state">
            <h2 class="text-sm font-medium text-slate-500">No School access</h2>
            <p class="mt-1 text-sm">
                This account is not an active member of any School, so no School pages or School
                data are available to it.
            </p>
            <p v-if="platformAccount" class="mt-2 text-sm" data-testid="platform-account">
                This is a platform account. Platform access does not include access to any School.
            </p>
        </section>

        <section
            v-else-if="!platformElevation.isElevated && (memberships.length > 1 || !activeSchool)"
            class="mt-6"
        >
            <h2 class="text-sm font-medium text-slate-500">
                {{ activeSchool ? 'Switch School' : 'Select a School' }}
            </h2>
            <ul class="mt-2 space-y-1">
                <li v-for="m in memberships" :key="m.schoolId">
                    <button
                        type="button"
                        class="text-sm underline disabled:text-slate-400 disabled:no-underline"
                        :disabled="m.isActive || !m.available"
                        @click="activate(m.schoolId)"
                    >
                        {{ m.schoolName }}<span v-if="m.isActive"> (active)</span
                        ><span v-else-if="!m.available" data-testid="school-unavailable">
                            (unavailable)</span
                        >
                    </button>
                </li>
            </ul>
        </section>

        <p
            v-if="activeSchool && !platformElevation.isElevated && !hasSchoolModules"
            class="mt-8 text-sm text-slate-600"
            data-testid="no-modules"
        >
            No modules are available to your account in this School.
        </p>

        <section
            v-if="overview.length"
            class="mt-8"
            aria-labelledby="overview-heading"
            data-testid="overview"
        >
            <h2 id="overview-heading" class="text-lg font-semibold">Your areas</h2>
            <div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <section
                    v-for="group in overview"
                    :key="group.key"
                    class="rounded border border-slate-200 p-4"
                    :aria-labelledby="`overview-${group.key}`"
                >
                    <h3 :id="`overview-${group.key}`" class="text-sm font-semibold">
                        {{ group.label }}
                    </h3>
                    <ul class="mt-2 space-y-1 text-sm">
                        <li v-for="item in group.items" :key="item.key">
                            <InertiaLink
                                :href="item.href"
                                class="underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-500"
                                >{{
                                    item.section ? `${item.section}: ${item.label}` : item.label
                                }}</InertiaLink
                            >
                        </li>
                    </ul>
                </section>
            </div>
        </section>
    </main>
</template>
