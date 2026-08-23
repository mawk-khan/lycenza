<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

interface ChannelPolicy {
    channel: string;
    optionalAllowed: boolean;
    requiredAllowed: boolean;
    recipientCanOptOut: boolean;
    isOverride: boolean;
}

interface Props {
    policies: ChannelPolicy[];
    emailChannelEnabled: boolean;
}

const props = defineProps<Props>();

const emailPolicy = props.policies.find((p) => p.channel === 'email');

const optionalAllowed = ref(emailPolicy?.optionalAllowed ?? true);
const requiredAllowed = ref(emailPolicy?.requiredAllowed ?? true);
const recipientCanOptOut = ref(emailPolicy?.recipientCanOptOut ?? true);
const saving = ref(false);

function save() {
    saving.value = true;
    router.put(
        '/app/communications/settings/channels',
        {
            channel: 'email',
            optional_allowed: optionalAllowed.value,
            required_allowed: requiredAllowed.value,
            recipient_can_opt_out: recipientCanOptOut.value,
        },
        { onFinish: () => (saving.value = false), preserveScroll: true },
    );
}
</script>

<template>
    <main class="mx-auto max-w-2xl p-8 font-sans text-slate-900">
        <a class="text-xs text-slate-400 underline" href="/app/communications"
            >← Communication Hub</a
        >
        <h1 class="mt-2 text-xl font-semibold">Communication Channels</h1>
        <p class="mt-1 text-xs text-slate-500">
            Controls which channels this school permits and whether members may opt out of optional
            communications on them.
        </p>

        <div class="mt-6 space-y-4">
            <div class="rounded border border-slate-200 p-4">
                <h2 class="text-sm font-semibold">In-app</h2>
                <p class="mt-1 text-xs text-slate-500">School communication record.</p>
                <span
                    class="mt-2 inline-block rounded bg-emerald-100 px-1.5 py-0.5 text-xs font-medium text-emerald-700"
                >
                    Enabled
                </span>
                <p class="mt-1 text-xs text-slate-400">
                    Always on -- not configurable. Every member sees their school communications in
                    the Communication Hub.
                </p>
            </div>

            <div class="rounded border border-slate-200 p-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold">Email</h2>
                    <span
                        v-if="!emailChannelEnabled"
                        class="rounded bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-700"
                    >
                        Not available at this instance
                    </span>
                </div>
                <p class="mt-1 text-xs text-slate-400">
                    This school-level policy only takes effect if email delivery is available at
                    all; it can never turn email on when it is globally disabled.
                </p>

                <div class="mt-3 space-y-2 text-sm">
                    <label class="flex items-center gap-2">
                        <input
                            v-model="optionalAllowed"
                            type="checkbox"
                            class="rounded border-slate-300"
                        />
                        Optional communications may use email
                    </label>
                    <label class="flex items-center gap-2">
                        <input
                            v-model="requiredAllowed"
                            type="checkbox"
                            class="rounded border-slate-300"
                        />
                        Required communications may use email
                    </label>
                    <label class="flex items-center gap-2">
                        <input
                            v-model="recipientCanOptOut"
                            type="checkbox"
                            :disabled="!optionalAllowed"
                            class="rounded border-slate-300"
                        />
                        Members may opt out of optional email
                    </label>
                </div>
            </div>
        </div>

        <button
            type="button"
            :disabled="saving"
            class="mt-6 rounded bg-slate-900 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50"
            @click="save"
        >
            Save Channel Settings
        </button>
    </main>
</template>
