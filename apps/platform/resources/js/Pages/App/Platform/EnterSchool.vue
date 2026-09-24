<script setup lang="ts">
// Phase 0N.3 (ADR 0044): step 1 of platform elevation. The School is
// named by its exact verified domain or identifier -- there is no School
// list, search or suggestion here by design.
import { useForm } from '@inertiajs/vue3';

interface Props {
    // Phase 0N.5: set when entering under ONE School Group's authority
    // (the School then comes fixed from that Group's member list).
    group: { id: string; name: string } | null;
    reasons: { value: string; label: string }[];
    mfaEnrolled: boolean;
    hasActiveElevation: boolean;
    maxMinutes: number;
    old: { target: string; reason_code: string };
    errors: Record<string, string>;
}

const props = defineProps<Props>();

const form = useForm({
    target: props.old.target,
    reason_code: props.old.reason_code,
});

function submit() {
    form.post(
        props.group
            ? `/app/groups/${props.group.id}/elevation/confirm`
            : '/app/platform/elevation/confirm',
    );
}
</script>

<template>
    <main class="mx-auto max-w-lg p-8 font-sans text-slate-900">
        <h1 class="text-xl font-semibold">Enter a School (elevated access)</h1>
        <p v-if="group" class="mt-1 text-sm text-slate-600" data-testid="authority-group">
            Under the authority of the School Group {{ group.name }}.
        </p>
        <p class="mt-2 text-sm text-slate-600">
            Elevated access establishes one School's context for at most
            {{ maxMinutes }} minutes. It grants no School permissions, and every entry is recorded.
        </p>

        <p
            v-if="!mfaEnrolled"
            role="status"
            class="mt-6 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm"
            data-testid="mfa-required"
        >
            Entering a School requires multi-factor authentication. Enroll a factor under
            <a class="underline" href="/app/account/security">Account security</a> first.
        </p>

        <p
            v-else-if="hasActiveElevation"
            role="status"
            class="mt-6 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm"
        >
            You already have elevated access active. Exit it before entering another School.
        </p>

        <form v-else class="mt-6 space-y-4" @submit.prevent="submit">
            <div>
                <label class="block text-sm text-slate-600" for="target">
                    School domain or identifier (exact)
                </label>
                <input
                    id="target"
                    v-model="form.target"
                    autocomplete="off"
                    spellcheck="false"
                    :readonly="!!group"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 read-only:bg-slate-100"
                />
                <p v-if="errors.target" class="mt-1 text-sm text-red-700">{{ errors.target }}</p>
            </div>
            <div>
                <label class="block text-sm text-slate-600" for="reason_code">Reason</label>
                <select
                    id="reason_code"
                    v-model="form.reason_code"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                >
                    <option value="" disabled>Choose a reason</option>
                    <option v-for="r in reasons" :key="r.value" :value="r.value">
                        {{ r.label }}
                    </option>
                </select>
                <p v-if="errors.reason_code" class="mt-1 text-sm text-red-700">
                    {{ errors.reason_code }}
                </p>
            </div>
            <div class="flex items-center gap-4">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded bg-slate-900 px-3 py-2 text-white disabled:opacity-50"
                >
                    Continue
                </button>
                <a class="text-sm underline" :href="group ? `/app/groups/${group.id}` : '/app'"
                    >Cancel</a
                >
            </div>
        </form>
    </main>
</template>
