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

interface TimingPolicy {
    channel: string;
    enabled: boolean;
    quietHoursStart: string | null;
    quietHoursEnd: string | null;
}

interface Props {
    policies: ChannelPolicy[];
    emailChannelEnabled: boolean;
    timingPolicy: TimingPolicy;
    schoolTimezone: string;
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

const quietHoursEnabled = ref(props.timingPolicy.enabled);
const quietHoursStart = ref(props.timingPolicy.quietHoursStart ?? '20:00');
const quietHoursEnd = ref(props.timingPolicy.quietHoursEnd ?? '07:00');
const savingTiming = ref(false);
const timingError = ref<string | null>(null);

function saveTiming() {
    savingTiming.value = true;
    timingError.value = null;
    router.put(
        '/app/communications/settings/timing',
        {
            channel: 'email',
            enabled: quietHoursEnabled.value,
            quiet_hours_start: quietHoursStart.value,
            quiet_hours_end: quietHoursEnd.value,
        },
        {
            preserveScroll: true,
            onFinish: () => (savingTiming.value = false),
            onError: (errors: Record<string, string>) => {
                const first = Object.values(errors)[0];
                timingError.value =
                    typeof first === 'string' ? first : 'Could not save quiet hours.';
            },
        },
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

        <div class="mt-10 rounded border border-slate-200 p-4">
            <h2 class="text-sm font-semibold">Email delivery timing</h2>
            <p class="mt-1 text-xs text-slate-400">
                Delays EMAIL transport during a daily quiet window. The in-app Communication Hub
                record is never delayed -- this only affects when email is sent. Required and
                Critical communications are still delayed like any other -- quiet hours are not
                bypassed automatically.
            </p>

            <label class="mt-3 flex items-center gap-2 text-sm">
                <input
                    v-model="quietHoursEnabled"
                    type="checkbox"
                    class="rounded border-slate-300"
                />
                Enabled
            </label>

            <div class="mt-3 flex items-center gap-4 text-sm">
                <label class="flex items-center gap-2">
                    From
                    <input
                        v-model="quietHoursStart"
                        type="time"
                        :disabled="!quietHoursEnabled"
                        class="rounded border border-slate-300 px-2 py-1 text-sm disabled:opacity-50"
                    />
                </label>
                <label class="flex items-center gap-2">
                    Until
                    <input
                        v-model="quietHoursEnd"
                        type="time"
                        :disabled="!quietHoursEnabled"
                        class="rounded border border-slate-300 px-2 py-1 text-sm disabled:opacity-50"
                    />
                </label>
            </div>
            <p class="mt-2 text-xs text-slate-400">
                Times are in this school's timezone ({{ schoolTimezone }}). A window that crosses
                midnight (e.g. 8:00 PM to 7:00 AM) is supported.
            </p>

            <p v-if="timingError" class="mt-2 text-xs text-red-600">{{ timingError }}</p>

            <button
                type="button"
                :disabled="savingTiming"
                class="mt-4 rounded bg-slate-900 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50"
                @click="saveTiming"
            >
                Save Timing Settings
            </button>
        </div>
    </main>
</template>
