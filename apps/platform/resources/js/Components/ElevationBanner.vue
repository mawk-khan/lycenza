<script setup lang="ts">
// Phase 0N.3 (ADR 0044 section 12): the persistent elevated-access banner,
// rendered by AccountLayout on every page. Shown only while the shared
// `elevation` prop is set (HandleInertiaRequests: a signed-in request with
// a valid platform elevation) -- the target School's name and the fixed
// expiry, the time left, and Exit. It states plainly that elevation grants
// no School permissions.
import { Link, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

interface Elevation {
    schoolName: string | null;
    expiresAt: string;
}

const page = usePage<{ elevation?: Elevation | null }>();
const elevation = computed(() => page.props.elevation ?? null);

const now = ref(Date.now());
let timer: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    timer = setInterval(() => (now.value = Date.now()), 15000);
});
onBeforeUnmount(() => clearInterval(timer));

const minutesLeft = computed(() => {
    if (!elevation.value) return 0;
    const ms = new Date(elevation.value.expiresAt).getTime() - now.value;
    return Math.max(0, Math.ceil(ms / 60000));
});
const expiresAtLabel = computed(() =>
    elevation.value
        ? new Date(elevation.value.expiresAt).toLocaleTimeString([], {
              hour: '2-digit',
              minute: '2-digit',
          })
        : '',
);
</script>

<template>
    <div
        v-if="elevation"
        role="status"
        class="border-b border-amber-400 bg-amber-100 font-sans text-sm text-amber-950"
        data-testid="elevation-banner"
    >
        <div
            class="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-x-4 gap-y-2 px-4 py-2"
        >
            <p class="min-w-0">
                <span class="font-semibold">Elevated access:</span>
                {{ elevation.schoolName }} — ends {{ expiresAtLabel }} ({{ minutesLeft }} min left).
                Elevated School context — no School permissions are granted automatically.
            </p>
            <Link
                href="/app/platform/elevation/exit"
                method="post"
                as="button"
                class="rounded border border-amber-600 bg-white px-3 py-1 font-medium text-amber-950 hover:bg-amber-50 focus:ring-2 focus:ring-amber-500 focus:ring-offset-2 focus:outline-none"
                data-testid="elevation-exit"
            >
                Exit elevated access
            </Link>
        </div>
    </div>
</template>
