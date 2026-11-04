<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

interface Props {
    emailPreference: 'enabled' | 'disabled' | null;
    emailChannelEnabled: boolean;
}

const props = defineProps<Props>();

// null ("inherit") is rendered as ON -- brief §14/§16's default:
// optional email is permitted unless the recipient explicitly turns
// it off.
const emailOn = ref(props.emailPreference !== 'disabled');
const saving = ref(false);

function save() {
    saving.value = true;
    router.put(
        '/app/communications/preferences',
        { channel: 'email', enabled: emailOn.value },
        { onFinish: () => (saving.value = false), preserveScroll: true },
    );
}
</script>

<template>
    <main class="mx-auto max-w-2xl p-8 font-sans text-ink">
        <a class="text-xs text-ink-muted underline" href="/app/communications"
            >← Communication Hub</a
        >
        <h1 class="mt-2 text-xl font-semibold">Communication Preferences</h1>
        <p class="mt-1 text-xs text-ink-muted">
            These apply only to your membership at this school. A school policy or a
            <em>required</em> communication may still reach you regardless of these settings.
        </p>

        <div class="mt-6 space-y-4">
            <div class="rounded-xs border border-line p-4">
                <h2 class="text-sm font-semibold">In-app</h2>
                <p class="mt-1 text-xs text-ink-muted">
                    School communications always appear in your Communication Hub. This cannot be
                    turned off -- it is the school's canonical communication record, not a
                    notification you can opt out of.
                </p>
                <span
                    class="mt-2 inline-block rounded-xs bg-graphite-200 px-1.5 py-0.5 text-xs font-medium text-ink-muted"
                >
                    Always available
                </span>
            </div>

            <div class="rounded-xs border border-line p-4">
                <h2 class="text-sm font-semibold">Email</h2>
                <p class="mt-1 text-xs text-ink-muted">
                    Receive a copy of optional (non-required) school communications by email, in
                    addition to seeing them in your Communication Hub.
                </p>
                <label class="mt-2 flex items-center gap-2 text-sm text-ink">
                    <input v-model="emailOn" type="checkbox" class="input w-auto" />
                    Send me optional communications by email
                </label>
                <p v-if="!emailChannelEnabled" class="mt-1 text-xs text-ink-muted">
                    Email delivery is not currently available at this school -- this preference will
                    take effect automatically once it is.
                </p>
            </div>
        </div>

        <button type="button" :disabled="saving" class="btn btn-primary mt-6" @click="save">
            Save Preferences
        </button>
    </main>
</template>
